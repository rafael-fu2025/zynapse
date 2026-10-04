<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Clinic\Services\QueueSkipSweepService;

/**
 * QueueSkipSweep — resolve expired clinic skip windows to no-show
 * (October 2026 panel revision).
 *
 *   php spark synapse:queue-skip-sweep
 *
 * A patient skipped from the clinic queue keeps a 60-minute recall
 * window; when it lapses without the patient returning, the visit must
 * become no-show even though nobody has the page open. Cron runs this
 * every minute (see backend/README.md); the `post_system` hook runs the
 * same sweep on a cooldown for dev/demo machines without a scheduler.
 *
 * Idempotent: the service re-validates every candidate under a row lock,
 * so overlapping runs (cron + hook, or two ticks that straddle a
 * deadline) produce at most one no-show per entry and never a duplicate
 * notification.
 */
final class QueueSkipSweep extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:queue-skip-sweep';
    protected $description = 'Mark skipped clinic patients no-show once their 60-minute recall window expires.';
    protected $usage       = 'synapse:queue-skip-sweep';

    public function run(array $params): int
    {
        $before = $this->expiredOpenCount();

        // Force past the cooldown: an explicit CLI run is the operator
        // asking for the sweep now.
        $resolved = (new QueueSkipSweepService())->maybeRun(true);

        $after = $this->expiredOpenCount();

        CLI::write(sprintf(
            'Skip sweep complete. Expired windows: %d → %d (resolved %d).',
            $before,
            $after,
            $resolved,
        ), 'green');

        return 0;
    }

    private function expiredOpenCount(): int
    {
        return (int) db_connect()->table('clinic_queue_entries')
            ->where('status', 'skipped')
            ->where('skip_deadline_at <=', gmdate('Y-m-d H:i:s'))
            ->countAllResults();
    }
}
