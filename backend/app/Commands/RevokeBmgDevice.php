<?php

declare(strict_types=1);

namespace App\Commands;

use App\Exceptions\ApiException;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;
use Modules\Facilities\Policies\BmgPolicy;
use Modules\Facilities\Services\BmgService;

/**
 * RevokeBmgDevice — instantly disables (or re-enables) a BMG device's
 * ingest token. Thin CLI wrapper over `BmgService::setDeviceStatus` —
 * the same code path the Facilities UI uses.
 *
 *   php spark synapse:bmg-device-revoke b8-1f-3f-d7-ec-18
 *   php spark synapse:bmg-device-revoke b8-1f-3f-d7-ec-18 --enable   (re-enable)
 *
 * Disabling flips `facilities_bmg_devices.status`; DeviceAuthFilter
 * checks that column on EVERY request, so revocation takes effect on
 * the next call without any cache or redeploy. In-flight requests that
 * already passed the filter may still complete (window of one request);
 * the service re-checks the row under lock inside its transaction.
 * Idempotent.
 */
final class RevokeBmgDevice extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:bmg-device-revoke';
    protected $description = 'Disable (or re-enable with --enable) a BMG device\'s ingest token. Idempotent.';
    protected $usage       = 'synapse:bmg-device-revoke <device-code> [--enable]';
    protected $arguments   = [
        'code' => 'Device code (see synapse:bmg-device-register).',
    ];
    protected $options     = [
        '--enable' => 'Re-enable a previously revoked device instead of revoking it.',
    ];

    public function run(array $params): int
    {
        $code = trim((string) ($params[0] ?? ''));
        if ($code === '') {
            CLI::error('Usage: php spark synapse:bmg-device-revoke <device-code> [--enable]');
            return 1;
        }

        $db = Services::database();
        $device = $db->table('facilities_bmg_devices')->where('code', $code)->get()->getRowArray();
        if ($device === null) {
            CLI::error("No device with code '{$code}' is registered.");
            return 1;
        }

        $status = CLI::getOption('enable') !== null ? 'active' : 'disabled';
        if ((string) $device['status'] === $status) {
            CLI::write("Device '{$code}' is already {$status} — nothing to do.", 'yellow');
            return 0;
        }

        try {
            (new BmgService(new BmgPolicy(), Services::auditOutbox(), null, Services::notificationOutbox()))
                ->setDeviceStatusUnchecked((int) $device['id'], $status);
        } catch (ApiException $e) {
            CLI::error($e->getMessage());
            return 1;
        }

        CLI::write("Device '{$code}' is now {$status}.", 'green');
        return 0;
    }
}
