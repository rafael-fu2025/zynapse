<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Facilities\Policies\BmgPolicy;
use Modules\Facilities\Services\BmgService;
use Config\Services;

/**
 * BmgDeviceWatchdog — scheduled sweep that flags BMG devices gone
 * silent. Thin CLI wrapper over `BmgService::deviceWatchdogUnchecked`.
 *
 *   php spark synapse:bmg-device-watchdog            (48 h default)
 *   php spark synapse:bmg-device-watchdog --hours=24 (stricter)
 *
 * A device "went silent" when its bound unit holds an active batch and
 * the device hasn't checked in (last_seen_at) for longer than the
 * threshold. Silence between sessions is normal — the device only
 * reports when a session runs — so the default 48 h sits well above the
 * 4-day turning cadence the TURNING_DUE alert covers. Notifications are
 * deduped per silence episode (silence_notified_at); schedule hourly.
 */
final class BmgDeviceWatchdog extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:bmg-device-watchdog';
    protected $description = 'Flag BMG devices silent beyond the threshold (bound to a drum with an active batch). Deduped per silence episode.';
    protected $usage       = 'synapse:bmg-device-watchdog [--hours <48>]';
    protected $options     = [
        '--hours' => 'Silence threshold in hours (default 48).',
    ];

    public function run(array $params): int
    {
        $hours = 48;
        if (array_key_exists('hours', $params) && is_numeric((string) $params['hours'])) {
            $hours = max(1, (int) $params['hours']);
        }

        $count = (new BmgService(new BmgPolicy(), Services::auditOutbox(), null, Services::notificationOutbox()))
            ->deviceWatchdogUnchecked($hours);

        CLI::write($count === 0
            ? "Watchdog sweep complete — no silent devices beyond {$hours} h."
            : "Watchdog sweep complete — {$count} silent device(s) notified.", $count === 0 ? 'green' : 'yellow');
        return 0;
    }
}
