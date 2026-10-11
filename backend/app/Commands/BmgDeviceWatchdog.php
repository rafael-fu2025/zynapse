<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Facilities\Policies\BmgPolicy;
use Modules\Facilities\Services\BmgService;
use Config\Services;

/**
 * BmgDeviceWatchdog — scheduled sweep over the mechanized BMG fleet.
 *
 * Four rules (see `BmgService::deviceWatchdogUnchecked`); the first
 * three dedupe per episode, the fourth notifies once per command:
 *   - OFFLINE: an active bound device silent beyond the threshold
 *     (default 20 min, BMG_OFFLINE_AFTER_MIN).
 *   - RTC INVALID: a device reporting `rtc_valid = false`.
 *   - SCHEDULE MISSED: an enabled schedule slot with no run near it
 *     while its batch is active (grace BMG_MISSED_RUN_GRACE_MIN).
 *   - COMMAND EXPIRED: a start/stop/run_now whose TTL (BMG_COMMAND_TTL_MIN)
 *     passed before the board picked it up. An offline board never polls,
 *     so only this sweep can tell staff the drum did not turn.
 *
 *   php spark synapse:bmg-device-watchdog                 (env default)
 *   php spark synapse:bmg-device-watchdog --minutes=20    (explicit)
 *   php spark synapse:bmg-device-watchdog --hours=48      (legacy scale)
 *
 * The firmware heartbeats on a ~10 min cadence, so the offline
 * threshold must sit above it; the old hours-scale default still works
 * via `--hours` for report-only firmware. Schedule every 15 min.
 */
final class BmgDeviceWatchdog extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:bmg-device-watchdog';
    protected $description = 'Sweep BMG devices: offline, RTC-invalid, missed scheduled runs, and expired commands. Deduped per episode.';
    protected $usage       = 'synapse:bmg-device-watchdog [--minutes <20>] [--hours <n>]';
    protected $options     = [
        '--minutes' => 'Offline threshold in minutes (default BMG_OFFLINE_AFTER_MIN or 20).',
        '--hours'   => 'Legacy hours-scale threshold; wins over --minutes when both are given.',
    ];

    public function run(array $params): int
    {
        $minutes = max(1, (int) (getenv('BMG_OFFLINE_AFTER_MIN') ?: 20));
        if (array_key_exists('minutes', $params) && is_numeric((string) $params['minutes'])) {
            $minutes = max(1, (int) $params['minutes']);
        }
        // --hours is the legacy flag (pre-heartbeat report-only firmware
        // was silent for days); it stays authoritative when supplied.
        if (array_key_exists('hours', $params) && is_numeric((string) $params['hours'])) {
            $minutes = max(1, (int) $params['hours']) * 60;
        }

        $count = (new BmgService(new BmgPolicy(), Services::auditOutbox(), null, Services::notificationOutbox()))
            ->deviceWatchdogUnchecked($minutes);

        CLI::write($count === 0
            ? "Watchdog sweep complete — nothing to flag (offline threshold {$minutes} min)."
            : "Watchdog sweep complete — {$count} notification(s) raised.", $count === 0 ? 'green' : 'yellow');
        return 0;
    }
}
