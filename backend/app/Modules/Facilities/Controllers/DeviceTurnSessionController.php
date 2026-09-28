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
 * DeviceTurnSessionController — ingest surface for automated BMG
 * hardware (the mechanized compost-drum tumbler).
 *
 * Reached through the `device_auth` filter (static per-device token),
 * NOT the human `api_auth` JWT surface. The controller only validates
 * the payload; identity, tenant, and batch resolution all happen in
 * `BmgService::recordDeviceTurnSession`.
 */
final class DeviceTurnSessionController extends ApiController
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
            'session_uid'      => 'required|max_length[64]|alpha_dash',
            'turns_count'      => 'required|is_natural_no_zero|less_than_equal_to[10000]',
            'duration_seconds' => 'required|is_natural|less_than[86400]',
            'sets_count'       => 'permit_empty|is_natural|less_than_equal_to[50]',
            'firmware'         => 'permit_empty|max_length[32]',
            'note'             => 'permit_empty|max_length[500]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $result = $this->service->recordDeviceTurnSession($device, [
            'session_uid'      => (string) $payload['session_uid'],
            'turns_count'      => (int) $payload['turns_count'],
            'duration_seconds' => (int) $payload['duration_seconds'],
            'sets_count'       => isset($payload['sets_count']) && $payload['sets_count'] !== '' ? (int) $payload['sets_count'] : null,
            'firmware'         => (string) ($payload['firmware'] ?? ''),
            'observation_note' => (string) ($payload['note'] ?? ''),
        ]);

        // 201 for a fresh session, 200 for an idempotent replay.
        return $this->ok($result, null, $result['created'] ? 201 : 200);
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
