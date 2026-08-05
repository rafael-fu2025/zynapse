<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\CheckinService;

/**
 * CheckinController — kiosk scan + today's check-in trail (Phase 17,
 * recycled from legacy synapse_ag Iot\CheckinController).
 */
final class CheckinController extends ApiController
{
    private readonly CheckinService $service;

    public function __construct(?CheckinService $service = null)
    {
        $this->service = $service ?? new CheckinService(new ClinicPolicy(), Services::auditOutbox());
    }

    public function scan(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'identifier' => 'permit_empty|max_length[255]',
            'method'     => 'permit_empty|in_list[qr,rfid,manual]',
            'station_id' => 'permit_empty|max_length[64]',
            'purpose'    => 'permit_empty|max_length[120]',
            'guest_name' => 'permit_empty|max_length[120]',
            'scanned_at' => 'permit_empty|valid_date[Y-m-d H:i:s]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        if (($payload['identifier'] ?? '') === '' && ($payload['guest_name'] ?? '') === '') {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Either identifier or guest_name is required.', 'field' => 'identifier'],
            ]);
        }

        return $this->ok($this->service->scan($payload), null, 201);
    }

    public function listToday(): ResponseInterface
    {
        return $this->ok($this->service->listToday());
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
