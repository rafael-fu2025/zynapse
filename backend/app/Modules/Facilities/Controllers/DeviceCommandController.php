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
 * DeviceCommandController — the outbound half of the ESP32 unit-start
 * integration. The board polls its pending command feed on its own
 * cadence (`GET /api/v1/devices/bmg/commands`) and ACKs each command
 * after actuating (`POST /api/v1/devices/bmg/commands/ack`).
 *
 * Reached through the `device_auth` filter (static per-device token),
 * NOT the human `api_auth` JWT surface — same disjoint posture as the
 * turn-session ingest. Commands are queued by BmgService when an
 * operator starts a batch on the drum the board is bolted to.
 */
final class DeviceCommandController extends ApiController
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

    public function index(): ResponseInterface
    {
        /** @var array<string, mixed> $device bound by DeviceAuthFilter */
        $device = CurrentDevice::require();

        return $this->ok($this->service->listDeviceCommands($device));
    }

    public function ack(): ResponseInterface
    {
        /** @var array<string, mixed> $device bound by DeviceAuthFilter */
        $device = CurrentDevice::require();

        $payload = $this->request->getJSON(true) ?? [];
        $rules   = ['command_id' => 'required|is_natural_no_zero'];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure([
                ['code' => 'request.validation_failed', 'message' => 'command_id is required.', 'field' => 'command_id'],
            ]);
        }

        return $this->ok($this->service->ackDeviceCommand($device, (int) $payload['command_id']));
    }
}
