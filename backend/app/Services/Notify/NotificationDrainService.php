<?php

declare(strict_types=1);

namespace App\Services\Notify;

use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * NotificationDrainService — moves `notification_outbox` rows to their
 * channel destinations (Phase 9). The only wired channel is `inapp` →
 * the `notifications` table; email/SMS transports plug in here later.
 *
 * Same guarantees as the audit drain: batch transaction, row locking,
 * poison-row ejection via attempt_count/last_error. Shared by the CLI
 * worker (`synapse:notify-drain`) and the opportunistic in-request
 * auto-drainer (NotificationAutoDrainService).
 */
final class NotificationDrainService
{
    public const MAX_ATTEMPTS = 5;

    /**
     * @return array{drained: int, failed: int}
     */
    public function drain(int $batch = 500, int $maxBatches = 10): array
    {
        $db = Database::connect();
        $drained = 0;
        $failed  = 0;

        for ($i = 0; $i < $maxBatches; $i++) {
            $failingRowId = null;
            $db->transBegin();

            try {
                $rows = $db->query(
                    'SELECT * FROM notification_outbox'
                    . ' WHERE processed_at IS NULL AND attempt_count < ?'
                    . ' ORDER BY id ASC'
                    . ' LIMIT ' . $batch
                    . ' FOR UPDATE',
                    [self::MAX_ATTEMPTS],
                )->getResultArray();

                if ($rows === []) {
                    $db->transCommit();
                    break;
                }

                foreach ($rows as $r) {
                    $failingRowId = (int) $r['id'];
                    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

                    if ($r['channel'] === 'inapp') {
                        $db->table('notifications')->insert([
                            'recipient_user_id' => $r['recipient_user_id'],
                            'template_code'     => $r['template_code'],
                            'context_json'      => $r['context_json'],
                            'read_at'           => null,
                            'created_at'        => $now,
                        ]);
                    }
                    // Unknown channels are marked processed with a note so
                    // they never wedge the queue.

                    $db->table('notification_outbox')
                        ->where('id', $r['id'])
                        ->update([
                            'processed_at'  => $now,
                            'attempt_count' => (int) $r['attempt_count'] + 1,
                            'last_error'    => $r['channel'] === 'inapp' ? null : 'channel_not_wired',
                        ]);

                    $drained++;
                }

                $db->transCommit();
            } catch (\Throwable $t) {
                $db->transRollback();
                $failed++;

                if ($failingRowId !== null) {
                    $reason = $t::class . ': ' . substr(
                        (string) preg_replace('/\s+/', ' ', $t->getMessage()),
                        0,
                        160,
                    );
                    $db->table('notification_outbox')
                        ->where('id', $failingRowId)
                        ->set('attempt_count', 'attempt_count + 1', false)
                        ->set('last_error', $reason)
                        ->update();

                    if (is_cli()) {
                        \CodeIgniter\CLI\CLI::write("Batch rolled back at outbox row #{$failingRowId}: {$reason}", 'yellow');
                    }
                    continue;
                }

                if (is_cli()) {
                    \CodeIgniter\CLI\CLI::write('Batch failed before any row was claimed: ' . $t::class, 'red');
                }
                break;
            }
        }

        return ['drained' => $drained, 'failed' => $failed];
    }
}
