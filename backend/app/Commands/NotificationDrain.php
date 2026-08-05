<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Notify\NotificationDrainService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * NotificationDrain — moves `notification_outbox` rows to their channel
 * destinations (Phase 9). The only wired channel is `inapp` → the
 * `notifications` table; email/SMS transports plug in here later.
 *
 *   php spark synapse:notify-drain [--batch=500] [--max-batches=10]
 *
 * Same guarantees as the audit drain: batch transaction, row locking,
 * poison-row ejection via attempt_count/last_error. The drain loop now
 * lives in NotificationDrainService so the opportunistic auto-drainer
 * (post_system) shares it.
 */
final class NotificationDrain extends BaseCommand
{
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

        $result = (new NotificationDrainService())->drain($batch, $maxBatches);

        $color = $result['failed'] > 0 ? 'yellow' : 'green';
        CLI::write("Drained {$result['drained']} notification(s) ({$result['failed']} failed batch(es)).", $color);
        return $result['failed'] > 0 && $result['drained'] === 0 ? 1 : 0;
    }
}
