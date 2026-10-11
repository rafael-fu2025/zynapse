<?php

declare(strict_types=1);

namespace Modules\Facilities\Controllers;

use App\Auth\CurrentDevice;
use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Facilities\Policies\BmgPolicy;
use Modules\Facilities\Services\Bmg\ScheduleService;

/**
 * DeviceScheduleController — the board's schedule pull
 * (`GET /api/v1/devices/bmg/schedule`, device-token auth).
 *
 * The firmware polls on a ~10 min cadence and applies the payload ONLY
 * when the returned `version` differs from its flash copy, so this
 * endpoint must be cheap and its body must be the flat contract shape
 * (`ApiResponse::device`, not the human envelope). Polling proves
 * liveness — ScheduleService stamps nothing here; the filter's ingest
 * paths and `listDeviceCommands` own `last_seen_at`. Kept stamp-free
 * deliberately: a schedule poll is not proof the motor path works.
 *
 * Errors arrive in the standard envelope (ApiExceptionFilter) — the
 * firmware treats any non-200 as "keep current schedule".
 */
final class DeviceScheduleController extends ApiController
{
    private readonly ScheduleService $schedules;

    public function __construct(?ScheduleService $schedules = null)
    {
        $this->schedules = $schedules ?? new ScheduleService(
            Services::database(),
            new BmgPolicy(),
            Services::auditOutbox(),
        );
    }

    public function show(): ResponseInterface
    {
        /** @var array<string, mixed> $device bound by DeviceAuthFilter */
        $device = CurrentDevice::require();

        return $this->deviceOk($this->schedules->forDevice($device));
    }
}
