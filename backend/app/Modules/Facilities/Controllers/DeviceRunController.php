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
 * DeviceRunController — the board's run ingest
 * (`POST /api/v1/devices/bmg/runs`, device-token auth).
 *
 * One row per motor run, scheduled or manual (run-now command),
 * idempotent on `run_uid` — a retried POST resolves to the original
 * row with 200 + `"created": false`, never a duplicate. When the unit
 * has an active batch the run attaches to it AND writes the matching
 * turning process log (cadence alerts + analytics keep working); when
 * it does not, the run is still stored and flagged for staff instead
 * of being rejected.
 *
 * Response is the flat device shape:
 *   {"run_uid": "...", "created": true, "batch_id": 123|null}
 */
final class DeviceRunController extends ApiController
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
            'run_uid'          => 'required|max_length[64]|alpha_dash',
            'origin'           => 'required|in_list[scheduled,manual]',
            'result'           => 'required|in_list[completed,failed,aborted]',
            'duration_seconds' => 'required|is_natural_no_zero|less_than[86400]',
            'turns_count'      => 'permit_empty|is_natural|less_than_equal_to[10000]',
            'note'             => 'permit_empty|max_length[500]',
            // RTC-stamped run window (UTC epoch seconds) — same sanity
            // contract as the turn-session surface; firmware without a
            // working DS3231 omits them and the service falls back to
            // server receipt time.
            'started_at_epoch' => 'permit_empty|is_natural',
            'ended_at_epoch'   => 'permit_empty|is_natural',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $result = $this->service->recordDeviceRun($device, [
            'run_uid'          => (string) $payload['run_uid'],
            'origin'           => (string) $payload['origin'],
            'result'           => (string) $payload['result'],
            'duration_seconds' => (int) $payload['duration_seconds'],
            'turns_count'      => isset($payload['turns_count']) && $payload['turns_count'] !== '' ? (int) $payload['turns_count'] : null,
            'note'             => (string) ($payload['note'] ?? ''),
            'started_at_epoch' => isset($payload['started_at_epoch']) && $payload['started_at_epoch'] !== '' ? (int) $payload['started_at_epoch'] : null,
            'ended_at_epoch'   => isset($payload['ended_at_epoch']) && $payload['ended_at_epoch'] !== '' ? (int) $payload['ended_at_epoch'] : null,
        ]);

        // 201 for a fresh run, 200 for an idempotent replay.
        return $this->deviceOk($result, $result['created'] ? 201 : 200);
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
