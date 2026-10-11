<?php

declare(strict_types=1);

namespace Modules\Facilities\Controllers;

use App\Auth\CurrentDevice;
use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Facilities\Policies\BmgPolicy;
use Modules\Facilities\Services\BmgService;

/**
 * DeviceHeartbeatController — the board's liveness + telemetry post
 * (`POST /api/v1/devices/bmg/heartbeat`, device-token auth).
 *
 * Every field is optional: a stripped-down firmware that only proves
 * "I am alive" is still a valid heartbeat. The service updates the
 * device row (last_seen_at, heartbeat columns, measured RTC drift)
 * inside one small transaction — no unit/batch resolution on this
 * path, so an unbound board still reports liveness.
 *
 * Response is the flat device shape `{"ok":true}`.
 */
final class DeviceHeartbeatController extends ApiController
{
    private readonly BmgService $service;

    public function __construct(?BmgService $service = null)
    {
        $this->service = $service ?? new BmgService(
            new BmgPolicy(),
            Services::auditOutbox(),
            null,
            Services::notificationOutbox(),
        );
    }

    public function store(): ResponseInterface
    {
        /** @var array<string, mixed> $device bound by DeviceAuthFilter */
        $device = CurrentDevice::require();

        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'firmware'        => 'permit_empty|max_length[32]',
            'uptime_seconds'  => 'permit_empty|is_natural|less_than_equal_to[4294967295]',
            'rtc_epoch'       => 'permit_empty|is_natural',
            'rtc_valid'       => 'permit_empty|in_list[0,1]',
            'rssi_dbm'        => 'permit_empty|is_integer|greater_than_equal_to[-120]|less_than_equal_to[0]',
            'last_run_result' => 'permit_empty|max_length[32]|alpha_dash',
            'motor_state'     => 'permit_empty|max_length[16]|alpha_dash',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $this->service->recordDeviceHeartbeat($device, [
            'firmware'        => trim((string) ($payload['firmware'] ?? '')),
            'uptime_seconds'  => isset($payload['uptime_seconds']) && $payload['uptime_seconds'] !== '' ? (int) $payload['uptime_seconds'] : null,
            'rtc_epoch'       => isset($payload['rtc_epoch']) && $payload['rtc_epoch'] !== '' ? (int) $payload['rtc_epoch'] : null,
            'rtc_valid'       => isset($payload['rtc_valid']) && $payload['rtc_valid'] !== '' ? (bool) (int) $payload['rtc_valid'] : null,
            'rssi_dbm'        => isset($payload['rssi_dbm']) && $payload['rssi_dbm'] !== '' ? (int) $payload['rssi_dbm'] : null,
            'last_run_result' => trim((string) ($payload['last_run_result'] ?? '')),
            'motor_state'     => trim((string) ($payload['motor_state'] ?? '')),
        ]);

        return $this->deviceOk(['ok' => true]);
    }

    private function collectErrors(): array
    {
        $errs = [];
        foreach ($this->validation->getErrors() as $field => $msg) {
            $errs[] = ['code' => 'validation.field', 'message' => (string) $msg, 'field' => (string) $field];
        }
        return $errs;
    }
}
