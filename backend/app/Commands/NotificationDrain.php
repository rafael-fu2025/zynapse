<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * NotificationDrain — moves `notification_outbox` rows to their channel
 * destinations (Phase 9). The only wired channel is `inapp` → the
 * `notifications` table; email/SMS transports plug in here later.
 *
 *   php spark synapse:notify-drain [--batch=500] [--max-batches=10]
 *
 * Same guarantees as the audit drain: batch transaction, row locking,
 * poison-row ejection via attempt_count/last_error.
 */
final class NotificationDrain extends BaseCommand
{
    private const MAX_ATTEMPTS = 5;

    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:notify-drain';
    protected $description = 'Drain notification_outbox into per-channel destinations (inapp).';
    protected $usage       = 'synapse:notify-drain [--batch=500] [--max-batches=10]';
    protected $arguments   = [];
    protected $options     = [
        '--batch'      => 'Rows per batch',
        '--max-batches'=> 'Max batches per run',
    ];

    public function run(array $params): int
    {
        $batch      = max(1, (int) ($params['batch'] ?? 500));
        $maxBatches = max(1, (int) ($params['max-batches'] ?? 10));

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

                    CLI::write("Batch rolled back at outbox row #{$failingRowId}: {$reason}", 'yellow');
                    continue;
                }

                CLI::write('Batch failed before any row was claimed: ' . $t::class, 'red');
                break;
            }
        }

        $color = $failed > 0 ? 'yellow' : 'green';
        CLI::write("Drained {$drained} notification(s) ({$failed} failed batch(es)).", $color);
        return $failed > 0 && $drained === 0 ? 1 : 0;
    }
}
