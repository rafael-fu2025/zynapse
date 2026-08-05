<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\ClinicService;

final class ClinicController extends ApiController
{
    private readonly ClinicService $service;

    public function __construct(?ClinicService $service = null)
    {
        $this->service = $service ?? new ClinicService(new ClinicPolicy(), Services::auditOutbox(), Services::notificationOutbox());
    }

    public function listEncounters(): ResponseInterface
    {
        $cursor = (string) ($this->request->getGet('cursor') ?? '');
        $limit  = (int)    ($this->request->getGet('limit')  ?? 25);
        $status = $this->request->getGet('status');
        $status = is_string($status) && in_array($status, ['open', 'closed', 'referred'], true) ? $status : null;
        $page = $this->service->listEncounters($cursor !== '' ? $cursor : null, $limit, $status);

        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    public function createEncounter(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'patient_school_id' => 'required|max_length[32]',
            'chief_complaint'   => 'required|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->createEncounter(
            (string) $payload['patient_school_id'],
            (string) $payload['chief_complaint'],
        );
        return $this->ok($dto->toArray(), null, 201);
    }

    public function recordVitals(int $encounterId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'bp_systolic'  => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[300]',
            'bp_diastolic' => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[200]',
            'pulse_bpm'    => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[300]',
            'temp_c'       => 'permit_empty|decimal|greater_than_equal_to[20]|less_than_equal_to[45]',
            'spo2_pct'     => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[100]',
            'weight_kg'    => 'permit_empty|decimal|greater_than_equal_to[0]|less_than_equal_to[600]',
            'height_cm'    => 'permit_empty|decimal|greater_than_equal_to[0]|less_than_equal_to[300]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        $dto = $this->service->recordVitals($encounterId, $payload);
        return $this->ok($dto->toArray(), null, 201);
    }

    public function listVitals(int $encounterId): ResponseInterface
    {
        return $this->ok($this->service->listVitals($encounterId));
    }

    public function closeEncounter(int $encounterId): ResponseInterface
    {
        $dto = $this->service->closeEncounter($encounterId);
        return $this->ok($dto->toArray());
    }

    /**
     * Mark an open encounter as no-show (panel revision, August 2026).
     *
     * Cascades atomically: the encounter closes with outcome='no_show',
     * a linked appointment that was scheduled or checked_in advances
     * to no_show, and the linked queue entry (if present) lands on
     * done + outcome='no_show'. The provider receives an in-app
     * notification in the same transaction.
     */
    public function markNoShow(int $encounterId): ResponseInterface
    {
        $dto = $this->service->markNoShow($encounterId);
        return $this->ok($dto->toArray());
    }

    public function setAssessment(int $encounterId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'triage_priority' => 'permit_empty|in_list[low,medium,high,urgent]',
            'triage_override' => 'permit_empty',
            'diagnosis'       => 'permit_empty|max_length[5000]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        return $this->ok($this->service->setAssessment($encounterId, $payload)->toArray());
    }

    public function listTreatments(int $encounterId): ResponseInterface
    {
        return $this->ok($this->service->listTreatments($encounterId));
    }

    public function suggestTriage(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = ['encounter_id' => 'required|is_natural_no_zero'];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        return $this->ok($this->service->suggestTriage((int) $payload['encounter_id']), null, 201);
    }

    public function decideTriage(int $predictionId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'decision'       => 'required|in_list[accepted,overridden]',
            'staff_priority' => 'permit_empty|in_list[low,medium,high,urgent]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        return $this->ok($this->service->decideTriage(
            $predictionId,
            (string) $payload['decision'],
            isset($payload['staff_priority']) && $payload['staff_priority'] !== '' ? (string) $payload['staff_priority'] : null,
        ));
    }

    public function addTreatment(int $encounterId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'treatment_type' => 'required|in_list[medication,first_aid,procedure,referral,other]',
            'description'    => 'required|max_length[2000]',
            'medicine_id'    => 'permit_empty|is_natural_no_zero',
            'quantity'       => 'permit_empty|is_natural_no_zero',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        if (($payload['treatment_type'] ?? '') === 'medication'
            && (empty($payload['medicine_id']) || empty($payload['quantity']))) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'medicine_id and quantity are required for a medication treatment.', 'field' => 'medicine_id'],
            ]);
        }
        return $this->ok($this->service->addTreatment($encounterId, $payload), null, 201);
    }

    /**
     * Bulk CSV import. Body: `text/csv` with header row
     * `patient_school_id,chief_complaint`. Hard cap 500 rows. Strictly
     * all-or-nothing — the first pass validates every row and reports
     * ALL failures with 1-based row numbers before anything is written.
     */
    public function importEncounters(): ResponseInterface
    {
        $raw = (string) $this->request->getBody();
        if (trim($raw) === '') {
            throw ApiException::validationFailure([
                ['code' => 'import.empty', 'message' => 'CSV body is empty.'],
            ]);
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        $header = str_getcsv(array_shift($lines) ?? '');
        if (array_map('trim', $header) !== ['patient_school_id', 'chief_complaint']) {
            throw ApiException::validationFailure([
                ['code' => 'import.bad_header', 'message' => 'Header must be exactly: patient_school_id,chief_complaint'],
            ]);
        }
        if (count($lines) === 0) {
            throw ApiException::validationFailure([
                ['code' => 'import.empty', 'message' => 'No data rows.'],
            ]);
        }
        if (count($lines) > 500) {
            throw ApiException::validationFailure([
                ['code' => 'import.too_large', 'message' => 'Import is capped at 500 rows per request.'],
            ]);
        }

        $rows   = [];
        $errors = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue; // tolerate trailing blank lines
            }
            $cols = str_getcsv($line);
            $rowNo = $i + 2; // 1-based, after the header
            $sid   = trim((string) ($cols[0] ?? ''));
            $cc    = trim((string) ($cols[1] ?? ''));

            if ($sid === '' || strlen($sid) > 32) {
                $errors[] = ['code' => 'import.row_invalid', 'message' => "Row {$rowNo}: patient_school_id is required (max 32 chars).", 'field' => "row_{$rowNo}"];
            }
            if ($cc === '' || strlen($cc) > 255) {
                $errors[] = ['code' => 'import.row_invalid', 'message' => "Row {$rowNo}: chief_complaint is required (max 255 chars).", 'field' => "row_{$rowNo}"];
            }
            $rows[] = ['patient_school_id' => $sid, 'chief_complaint' => $cc];
        }

        if ($errors !== []) {
            throw ApiException::validationFailure($errors);
        }

        return $this->ok($this->service->bulkImportEncounters($rows), null, 201);
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