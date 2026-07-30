<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Pagination\KeysetPaginator;
use App\Services\Analytics\TriageAssistant;
use App\Services\Audit\AuditOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\DTOs\EncounterDto;
use Modules\Clinic\DTOs\VitalsDto;
use Modules\Clinic\Policies\ClinicPolicy;

final class ClinicService extends BaseService
{
    public function __construct(
        private readonly ClinicPolicy $policy,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function listEncounters(?string $cursor, int $limit, ?string $status = null): array
    {
        $this->policy->check('list');

        $builder = $this->db->table('clinic_encounters')
            ->select('id, patient_school_id, appointment_id, chief_complaint, triage_priority, triage_override, diagnosis, status, attending_user_id, started_at, closed_at, created_at')
            ->where('archived_at', null)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        if ($status !== null && in_array($status, ['open', 'closed', 'referred'], true)) {
            $builder->where('status', $status);
        }

        KeysetPaginator::apply($builder, $cursor, $limit);

        $rows = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        return [
            'data'  => array_map(static fn (array $r) => EncounterDto::fromRow($r)->toArray(), $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    public function createEncounter(string $patientSchoolId, string $chiefComplaint): EncounterDto
    {
        $this->policy->check('create');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($patientSchoolId, $chiefComplaint, $userId): EncounterDto {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('clinic_encounters')->insert([
                'patient_school_id' => $patientSchoolId,
                'chief_complaint'   => $chiefComplaint,
                'status'            => 'open',
                'attending_user_id' => $userId,
                'started_at'        => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'clinic.encounter_opened',
                'clinic_encounters',
                $id,
                $userId,
                ['next_status' => 'open'],
            );

            $row = $this->db->table('clinic_encounters')->where('id', $id)->get()->getRowArray();
            return EncounterDto::fromRow($row);
        });
    }

    /**
     * Bulk import encounters (Phase 10 — promised since Phase 5).
     *
     * All-or-nothing: every row is validated first; any invalid row
     * aborts the whole import with per-row errors. Inserts run in a
     * single transaction with ONE summary audit event (no PII beyond
     * what single-create already stores).
     *
     * @param list<array{patient_school_id:string, chief_complaint:string}> $rows
     * @return array{imported:int, first_id:int, last_id:int}
     */
    public function bulkImportEncounters(array $rows): array
    {
        $this->policy->check('create');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($rows, $userId): array {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $firstId = 0;
            $lastId  = 0;
            foreach ($rows as $row) {
                $this->db->table('clinic_encounters')->insert([
                    'patient_school_id' => $row['patient_school_id'],
                    'chief_complaint'   => $row['chief_complaint'],
                    'status'            => 'open',
                    'attending_user_id' => $userId,
                    'started_at'        => $now,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
                $lastId = (int) $this->db->insertID();
                if ($firstId === 0) {
                    $firstId = $lastId;
                }
            }

            $this->audit->enqueue(
                'clinic.encounters_imported',
                'clinic_encounters',
                $lastId,
                $userId,
                ['resource_code' => 'rows#' . count($rows)],
            );

            return ['imported' => count($rows), 'first_id' => $firstId, 'last_id' => $lastId];
        });
    }

    public function recordVitals(int $encounterId, array $vitals): VitalsDto
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($encounterId, $vitals, $userId): VitalsDto {
            $enc = $this->selectForUpdate('clinic_encounters', ['id' => $encounterId, 'archived_at' => null]);

            if ($enc === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Encounter #{$encounterId} not found."],
                ]);
            }

            $this->policy->check('recordVitals', $enc);

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('clinic_vitals')->insert([
                'encounter_id'        => $encounterId,
                'bp_systolic'         => $vitals['bp_systolic']  ?? null,
                'bp_diastolic'        => $vitals['bp_diastolic'] ?? null,
                'pulse_bpm'           => $vitals['pulse_bpm']    ?? null,
                'temp_c'              => $vitals['temp_c']       ?? null,
                'spo2_pct'            => $vitals['spo2_pct']     ?? null,
                'weight_kg'           => $vitals['weight_kg']    ?? null,
                'height_cm'           => $vitals['height_cm']    ?? null,
                'recorded_by_user_id' => $userId,
                'recorded_at'         => $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            $this->audit->enqueue(
                'clinic.vitals_recorded',
                'clinic_vitals',
                (int) $this->db->insertID(),
                $userId,
                ['resource_code' => 'encounter#' . $encounterId],
            );

            $row = $this->db->table('clinic_vitals')
                ->where('encounter_id', $encounterId)
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()->getRowArray();

            return VitalsDto::fromRow($row);
        });
    }

    public function closeEncounter(int $encounterId): EncounterDto
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($encounterId, $userId): EncounterDto {
            $enc = $this->selectForUpdate('clinic_encounters', ['id' => $encounterId, 'archived_at' => null]);

            if ($enc === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Encounter #{$encounterId} not found."],
                ]);
            }

            $this->policy->check('close', $enc);

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('clinic_encounters')
                ->where('id', $encounterId)
                ->update([
                    'status'     => 'closed',
                    'closed_at'  => $now,
                    'updated_at' => $now,
                ]);

            // Panel revision: closing the visit completes its linked
            // appointment (scheduling layer follows the encounter).
            if (isset($enc['appointment_id']) && $enc['appointment_id'] !== null) {
                $appt = $this->selectForUpdate('clinic_appointments', [
                    'id'          => (int) $enc['appointment_id'],
                    'archived_at' => null,
                ]);
                if ($appt !== null && (string) $appt['status'] === 'checked_in') {
                    $this->db->table('clinic_appointments')
                        ->where('id', (int) $appt['id'])
                        ->update(['status' => 'completed', 'updated_at' => $now]);
                    $this->audit->enqueue(
                        'clinic.appointment_completed',
                        'clinic_appointments',
                        (int) $appt['id'],
                        $userId,
                        ['previous_status' => 'checked_in', 'next_status' => 'completed', 'reason_code' => 'encounter_closed'],
                    );
                }
            }

            $this->audit->enqueue(
                'clinic.encounter_closed',
                'clinic_encounters',
                $encounterId,
                $userId,
                ['previous_status' => 'open', 'next_status' => 'closed'],
            );

            $row = $this->db->table('clinic_encounters')->where('id', $encounterId)->get()->getRowArray();
            return EncounterDto::fromRow($row);
        });
    }

    // ---------------------------------------------- triage + diagnosis

    /**
     * Set triage priority and/or diagnosis on an encounter (partial update).
     *
     * @param array<string, mixed> $input
     */
    public function setAssessment(int $encounterId, array $input): EncounterDto
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($encounterId, $input, $userId): EncounterDto {
            $enc = $this->selectForUpdate('clinic_encounters', ['id' => $encounterId, 'archived_at' => null]);
            if ($enc === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Encounter #{$encounterId} not found."],
                ]);
            }
            $this->policy->check('setAssessment', $enc);

            $update = ['updated_at' => $this->utcNow()];
            if (array_key_exists('triage_priority', $input) && $input['triage_priority'] !== null && $input['triage_priority'] !== '') {
                $update['triage_priority'] = (string) $input['triage_priority'];
            }
            if (array_key_exists('triage_override', $input)) {
                $update['triage_override'] = empty($input['triage_override']) ? 0 : 1;
            }
            if (array_key_exists('diagnosis', $input)) {
                $update['diagnosis'] = $input['diagnosis'] !== '' && $input['diagnosis'] !== null ? (string) $input['diagnosis'] : null;
            }

            $this->db->table('clinic_encounters')->where('id', $encounterId)->update($update);
            $this->audit->enqueue('clinic.encounter_assessed', 'clinic_encounters', $encounterId, $userId, [
                'outcome' => (string) ($update['triage_priority'] ?? 'diagnosis'),
            ]);

            $row = $this->db->table('clinic_encounters')->where('id', $encounterId)->get()->getRowArray();
            return EncounterDto::fromRow($row);
        });
    }

    // ------------------------------------------------------ treatments

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTreatments(int $encounterId): array
    {
        $this->policy->check('treatmentsRead');

        $rows = $this->db->table('clinic_treatments t')
            ->select('t.id, t.encounter_id, t.treatment_type, t.description, t.medicine_id, t.quantity_used, t.administered_by_user_id, t.administered_at, m.generic_name, m.unit', false)
            ->join('clinic_medicines m', 'm.id = t.medicine_id', 'left')
            ->where('t.encounter_id', $encounterId)
            ->orderBy('t.created_at', 'ASC')->orderBy('t.id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'              => (int) $r['id'],
            'encounter_id'    => (int) $r['encounter_id'],
            'treatment_type'  => (string) $r['treatment_type'],
            'description'     => (string) $r['description'],
            'medicine_id'     => $r['medicine_id'] !== null ? (int) $r['medicine_id'] : null,
            'medicine_name'   => $r['generic_name'] !== null ? (string) $r['generic_name'] : null,
            'unit'            => $r['unit'] !== null ? (string) $r['unit'] : null,
            'quantity_used'   => $r['quantity_used'] !== null ? (int) $r['quantity_used'] : null,
            'administered_at' => (string) $r['administered_at'],
        ], $rows);
    }

    /**
     * Record a treatment on an OPEN encounter. `medication` consumes
     * `quantity` units FEFO within the same transaction (gated by
     * encounters.write — administering care, not inventory management).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addTreatment(int $encounterId, array $input): array
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($encounterId, $input, $userId): array {
            $enc = $this->selectForUpdate('clinic_encounters', ['id' => $encounterId, 'archived_at' => null]);
            if ($enc === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Encounter #{$encounterId} not found."],
                ]);
            }
            $this->policy->check('addTreatment', $enc);

            if ((string) $enc['status'] !== 'open') {
                throw new ApiException('statemachine.clinic.encounter_closed', 409, [
                    ['code' => 'statemachine.clinic.encounter_closed', 'message' => 'Treatments can only be added to an open encounter.'],
                ]);
            }

            $type       = (string) $input['treatment_type'];
            $now        = $this->utcNow();
            $batchId    = null;
            $medicineId = null;
            $qty        = null;

            if ($type === 'medication') {
                $medicineId = (int) $input['medicine_id'];
                $qty        = (int) $input['quantity'];
                $batchId    = $this->consumeFefo($medicineId, $qty, $userId, $encounterId, $now);
            }

            $this->db->table('clinic_treatments')->insert([
                'encounter_id'            => $encounterId,
                'treatment_type'          => $type,
                'description'             => (string) $input['description'],
                'batch_id'                => $batchId,
                'medicine_id'             => $medicineId,
                'quantity_used'           => $qty,
                'administered_by_user_id' => $userId,
                'administered_at'         => $now,
                'created_at'              => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('clinic.treatment_recorded', 'clinic_treatments', $id, $userId, ['outcome' => $type]);

            $row = $this->db->table('clinic_treatments')->where('id', $id)->get()->getRowArray();
            return [
                'id'              => (int) $row['id'],
                'encounter_id'    => (int) $row['encounter_id'],
                'treatment_type'  => (string) $row['treatment_type'],
                'description'     => (string) $row['description'],
                'medicine_id'     => $row['medicine_id'] !== null ? (int) $row['medicine_id'] : null,
                'quantity_used'   => $row['quantity_used'] !== null ? (int) $row['quantity_used'] : null,
                'administered_at' => (string) $row['administered_at'],
            ];
        });
    }

    /**
     * FEFO consumption inside the caller's transaction. Returns the id of
     * the earliest-expiring batch touched (representative pointer; the
     * transactions ledger holds the full per-batch breakdown).
     */
    private function consumeFefo(int $medicineId, int $quantity, int $userId, int $encounterId, string $now): int
    {
        if ($quantity < 1) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'quantity must be at least 1.', 'field' => 'quantity'],
            ]);
        }

        $med = $this->selectForUpdate('clinic_medicines', ['id' => $medicineId, 'archived_at' => null]);
        if ($med === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Medicine #{$medicineId} not found."],
            ]);
        }

        $today   = substr($now, 0, 10);
        $batches = $this->db->query(
            'SELECT `id`, `quantity_remaining` FROM `clinic_medicine_batches`'
            . ' WHERE `medicine_id` = ? AND `status` = ? AND `quantity_remaining` > 0 AND `expiration_date` >= ?'
            . ' ORDER BY `expiration_date` ASC, `id` ASC FOR UPDATE',
            [$medicineId, 'active', $today],
        )->getResultArray();

        $available = array_sum(array_map(static fn (array $b): int => (int) $b['quantity_remaining'], $batches));
        if ($available < $quantity) {
            throw new ApiException('statemachine.inventory.insufficient_stock', 409, [
                ['code' => 'statemachine.inventory.insufficient_stock', 'message' => "Only {$available} unexpired unit(s) on hand.", 'field' => 'quantity'],
            ]);
        }

        $remaining    = $quantity;
        $firstBatchId = 0;
        $balance      = $this->lastMedicineBalance($medicineId);
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $bid    = (int) $batch['id'];
            $take   = min($remaining, (int) $batch['quantity_remaining']);
            $newQty = (int) $batch['quantity_remaining'] - $take;
            if ($firstBatchId === 0) {
                $firstBatchId = $bid;
            }

            $this->db->table('clinic_medicine_batches')->where('id', $bid)->update([
                'quantity_remaining' => $newQty,
                'status'             => $newQty === 0 ? 'depleted' : 'active',
            ]);
            $balance -= $take;
            $this->db->table('clinic_medicine_transactions')->insert([
                'medicine_id'          => $medicineId,
                'batch_id'             => $bid,
                'type'                 => 'dispensed',
                'quantity'             => $take,
                'balance_after'        => $balance,
                'reference_type'       => 'encounter',
                'reference_id'         => $encounterId,
                'performed_by_user_id' => $userId,
                'note'                 => 'treatment dispense',
                'created_at'           => $now,
            ]);
            $remaining -= $take;
        }

        return $firstBatchId;
    }

    /**
     * Last running-balance value on the medicine's ledger (row-locked;
     * runs inside the caller's transaction). Seeds the `balance_after`
     * chain for the rows about to be appended.
     */
    private function lastMedicineBalance(int $medicineId): int
    {
        $row = $this->db->query(
            'SELECT `balance_after` FROM `clinic_medicine_transactions`'
            . ' WHERE `medicine_id` = ? ORDER BY `id` DESC LIMIT 1 FOR UPDATE',
            [$medicineId],
        )->getRowArray();

        return $row !== null && $row['balance_after'] !== null ? (int) $row['balance_after'] : 0;
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    // ------------------------------------------------- triage assist

    /**
     * Deterministic triage suggestion for an encounter: gathers the
     * chief complaint, latest vitals, and the patient's allergies, runs
     * the heuristic, persists the advisory prediction, and returns it.
     *
     * @return array<string, mixed>
     */
    public function suggestTriage(int $encounterId): array
    {
        $this->policy->check('triageUse');
        $userId = \App\Auth\CurrentUser::assert();

        $enc = $this->db->table('clinic_encounters')
            ->where('id', $encounterId)->where('archived_at', null)
            ->get()->getRowArray();
        if ($enc === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Encounter #{$encounterId} not found."],
            ]);
        }

        $vitals = $this->db->table('clinic_vitals')
            ->where('encounter_id', $encounterId)
            ->orderBy('id', 'DESC')->limit(1)
            ->get()->getRowArray();

        // Allergies via the clinic patient registry (same module domain):
        // encounter.patient_school_id maps to patients_students.student_number.
        $allergies = $this->db->table('patient_allergies a')
            ->select('a.allergen, a.severity')
            ->join('patients_students s', 's.id = a.student_id')
            ->where('s.student_number', (string) $enc['patient_school_id'])
            ->get()->getResultArray();

        $result = (new TriageAssistant())->analyze(
            (string) $enc['chief_complaint'],
            $vitals,
            $allergies,
        );

        $now = $this->utcNow();
        $this->db->table('clinic_triage_predictions')->insert([
            'encounter_id'       => $encounterId,
            'patient_school_id'  => (string) $enc['patient_school_id'],
            'input_text'         => (string) $enc['chief_complaint'],
            'predicted_priority' => $result['predicted_priority'],
            'confidence_score'   => $result['confidence_score'],
            'model_version'      => $result['model_version'],
            'features_used'      => json_encode($result['features_used'], JSON_THROW_ON_ERROR),
            'created_at'         => $now,
        ]);
        $id = (int) $this->db->insertID();

        $this->audit->enqueue('clinic.triage_suggested', 'clinic_triage_predictions', $id, $userId, [
            'outcome' => $result['predicted_priority'],
        ]);

        return [
            'id'                 => $id,
            'encounter_id'       => $encounterId,
            'predicted_priority' => $result['predicted_priority'],
            'confidence_score'   => (float) $result['confidence_score'],
            'model_version'      => $result['model_version'],
            'features_used'      => $result['features_used'],
            'staff_decision'     => null,
        ];
    }

    /**
     * Record a staff accept/override on a prediction and write the
     * resulting priority to the encounter.
     *
     * @return array<string, mixed>
     */
    public function decideTriage(int $predictionId, string $decision, ?string $staffPriority): array
    {
        $this->policy->check('triageUse');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($predictionId, $decision, $staffPriority, $userId): array {
            $pred = $this->selectForUpdate('clinic_triage_predictions', ['id' => $predictionId]);
            if ($pred === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Prediction #{$predictionId} not found."],
                ]);
            }

            if ($decision === 'overridden' && ($staffPriority === null || $staffPriority === '')) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'staff_priority is required when overriding.', 'field' => 'staff_priority'],
                ]);
            }

            $finalPriority = $decision === 'accepted'
                ? (string) $pred['predicted_priority']
                : (string) $staffPriority;
            $now = $this->utcNow();

            $this->db->table('clinic_triage_predictions')->where('id', $predictionId)->update([
                'staff_decision'     => $decision,
                'staff_priority'     => $decision === 'overridden' ? $staffPriority : null,
                'decided_by_user_id' => $userId,
                'decided_at'         => $now,
            ]);

            $this->db->table('clinic_encounters')->where('id', (int) $pred['encounter_id'])->update([
                'triage_priority' => $finalPriority,
                'triage_override' => $decision === 'overridden' ? 1 : 0,
                'updated_at'      => $now,
            ]);

            $this->audit->enqueue('clinic.triage_decided', 'clinic_triage_predictions', $predictionId, $userId, [
                'outcome' => $decision . ':' . $finalPriority,
            ]);

            return [
                'id'             => $predictionId,
                'encounter_id'   => (int) $pred['encounter_id'],
                'staff_decision' => $decision,
                'final_priority' => $finalPriority,
            ];
        });
    }
}