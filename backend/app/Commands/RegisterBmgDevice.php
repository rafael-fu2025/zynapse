<?php

declare(strict_types=1);

namespace App\Commands;

use App\Exceptions\ApiException;
use App\Services\CurrentTenant;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;
use Modules\Facilities\Policies\BmgPolicy;
use Modules\Facilities\Services\BmgService;

/**
 * RegisterBmgDevice — registers an automated BMG device (the mechanized
 * compost-drum tumbler) and mints its ingest token. Thin CLI wrapper
 * over `BmgService::registerDevice` / `::regenerateDeviceToken` — the
 * same code path the Facilities UI uses.
 *
 *   php spark synapse:bmg-device-register --mac b8:1f:3f:d7:ec:18 --unit drum-01 --name "Compost Tumbler 1"
 *   php spark synapse:bmg-device-register --code compost-tumbler-2            (no MAC known)
 *   php spark synapse:bmg-device-register --mac … --regenerate                (lost token; mints a new one)
 *
 * The device `code` defaults to the normalized MAC. The plaintext token
 * (`dev_<64 hex>`) is printed EXACTLY ONCE; only its SHA-256 hash is
 * stored. A lost token is recovered with `--regenerate`, never by
 * reading it back. Idempotent: re-running without `--regenerate`
 * refuses rather than silently rekeying.
 */
final class RegisterBmgDevice extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:bmg-device-register';
    protected $description = 'Register a BMG device (mechanized tumbler) and mint its ingest token. Prints the token exactly once.';
    protected $usage       = 'synapse:bmg-device-register --mac <aa:bb:…> | --code <slug> [--unit <unit-code>] [--name <display>] [--regenerate]';
    protected $options     = [
        '--mac'        => 'Device ESP chip MAC (e.g. b8:1f:3f:d7:ec:18). Default source of the device code.',
        '--code'       => 'Explicit device code override (3–32 chars: lowercase letters, digits, dots, dashes, underscores).',
        '--unit'       => 'BMG unit code to bind the device to (optional — can be bound later).',
        '--name'       => 'Display name (defaults to "BMG Device <code>").',
        '--regenerate' => 'If the device already exists, mint a NEW token (old one stops working immediately).',
    ];

    public function run(array $params): int
    {
        $code = $this->resolveCode($params);
        if (is_int($code)) {
            return $code; // usage/validation error already reported
        }

        $db = Services::database();
        $device = $db->table('facilities_bmg_devices')->where('code', $code)->get()->getRowArray();

        [$regenGiven] = $this->rawOption($params, 'regenerate');
        if ($device !== null && ! $regenGiven) {
            CLI::error("Device '{$code}' is already registered. Pass --regenerate to mint a new token (the old one stops working).");
            return 1;
        }

        $unitId = $this->resolveUnitId($params);
        if (is_int($unitId) && $unitId === -1) {
            return 1; // unit lookup error already reported
        }

        $service = new BmgService(new BmgPolicy(), Services::auditOutbox(), null, Services::notificationOutbox());

        try {
            $result = $device !== null
                ? $service->regenerateDeviceTokenUnchecked((int) $device['id'])
                : $service->registerDeviceUnchecked([
                    'code'         => $code,
                    'display_name' => (string) ($this->rawOption($params, 'name')[1] ?? ''),
                    'unit_id'      => $unitId,
                ]);
        } catch (ApiException $e) {
            CLI::error($this->humanMessage($e));
            return 1;
        }

        $deviceRow = $result['device'];
        CLI::write("Device '{$deviceRow['code']}' registered (user id {$deviceRow['id']}).", 'green');
        if ($deviceRow['unit_id'] !== null) {
            CLI::write("Bound to unit id {$deviceRow['unit_id']}.", 'dark_gray');
        } else {
            CLI::write('NOT bound to a unit yet — reports will be rejected until it is bound.', 'yellow');
        }
        CLI::write('');
        CLI::write('==================== DEVICE TOKEN (shown ONCE — copy it now) ====================', 'red');
        CLI::write($result['token'], 'green');
        CLI::write('=================================================================================', 'red');
        CLI::write('Stored server-side only as a SHA-256 hash. Put this token in DEVICE_TOKEN in the sketch.', 'dark_gray');

        return 0;
    }

    /**
     * Resolve --unit (a BMG unit CODE) to its id, tenant-scoped.
     *
     * @return int|null the unit id, null when unbound, or -1 on error
     */
    private function resolveUnitId(array $params): ?int
    {
        [$unitGiven, $unitCode] = $this->rawOption($params, 'unit');
        if (! $unitGiven || (string) $unitCode === '') {
            return null;
        }

        $unit = Services::database()->table('facilities_bmg_units')
            ->where('code', (string) $unitCode)
            ->where('tenant_id', CurrentTenant::id())
            ->where('archived_at', null)
            ->get()->getRowArray();
        if ($unit === null) {
            CLI::error("No active BMG unit with code '{$unitCode}' exists in the active tenant.");
            return -1;
        }

        return (int) $unit['id'];
    }

    /**
     * Surface the first human message from an ApiException; fall back
     * to its error code.
     */
    private function humanMessage(ApiException $e): string
    {
        $first = $e->errors[0]['message'] ?? null;

        return is_string($first) && $first !== '' ? $first : $e->getMessage();
    }

    /**
     * Resolve the device code from --code or --mac. The MAC normalizes
     * to dash-separated lowercase hex pairs (b8-1f-3f-d7-ec-18), which
     * matches the observed chip and fits VARCHAR(32).
     *
     * @return string|int the code, or an exit code on error
     */
    private function resolveCode(array $params)
    {
        [$codeGiven, $codeValue] = $this->rawOption($params, 'code');
        [$macGiven, $macValue]   = $this->rawOption($params, 'mac');

        $code = trim((string) ($codeGiven ? $codeValue : ''));
        $mac  = trim((string) ($macGiven ? $macValue : ''));

        if ($code === '' && $mac === '') {
            CLI::error('Provide --mac <aa:bb:cc:dd:ee:ff> or --code <slug>.');
            return 1;
        }

        if ($code === '') {
            $pairs = preg_split('/[:\-\s]+/', strtolower($mac)) ?: [];
            $pairs = array_values(array_filter($pairs, static fn (string $p): bool => $p !== ''));
            $pairsAreHex = count($pairs) === 6 && in_array(
                false,
                array_map(static fn (string $p): bool => preg_match('/^[0-9a-f]{2}$/', $p) === 1, $pairs),
                true,
            ) === false;
            if (! $pairsAreHex) {
                CLI::error('--mac must be six hex byte pairs (e.g. b8:1f:3f:d7:ec:18).');
                return 1;
            }
            $code = implode('-', $pairs);
        }

        if (! preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $code)) {
            CLI::error('Device code must be 3–32 chars: lowercase letters, digits, dots, dashes, underscores.');
            return 1;
        }

        return $code;
    }

    /**
     * Read a long option from the parsed params — same contract as
     * PromoteSuperadmin::rawOption (supports `--name value` and
     * `--name=value`).
     *
     * @return array{0:bool, 1:?string} [provided, value]
     */
    private function rawOption(array $params, string $name): array
    {
        if (array_key_exists($name, $params)) {
            $value = $params[$name];

            return [true, is_string($value) ? $value : null];
        }

        $prefix = $name . '=';
        foreach ($params as $key => $value) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                return [true, substr($key, strlen($prefix))];
            }
        }

        $option = CLI::getOption($name);
        if ($option !== null) {
            return [true, is_string($option) ? $option : null];
        }

        return [false, null];
    }
}
