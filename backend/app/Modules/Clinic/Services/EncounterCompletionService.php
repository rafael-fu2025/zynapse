<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;

/** Shared in-transaction completion coordinator for Clinic queue and encounter actions. */
final class EncounterCompletionService extends BaseService
{
    public function __construct(private readonly AuditOutboxService $audit)
    {
        parent::__construct();
    }

    /** @return array<string, mixed> */
    public function complete(int $encounterId, int $userId, string $now, string $reasonCode): array
    {
        $encounter = $this->selectForUpdate('clinic_encounters', ['tenant_id' => CurrentTenant::id(), 'id' => $encounterId, 'archived_at' => null]);
        if ($encounter === null) {
            throw new ApiException('resource.not_found', 404, [[
                'code' => 'resource.not_found', 'message' => "Encounter #{$encounterId} not found.",
            ]]);
        }
        if ((string) $encounter['status'] === 'referred') {
            throw new ApiException('statemachine.clinic.encounter_referred', 409, [[
                'code' => 'statemachine.clinic.encounter_referred', 'message' => 'A referred encounter cannot be completed again.',
            ]]);
        }

        if ((string) $encounter['status'] === 'open') {
            $this->db->table('clinic_encounters')->where('clinic_encounters.tenant_id', CurrentTenant::id())->where('id', $encounterId)->update([
                'status' => 'closed', 'closed_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->enqueue('clinic.encounter_closed', 'clinic_encounters', $encounterId, $userId, [
                'previous_status' => 'open', 'next_status' => 'closed', 'reason_code' => $reasonCode,
            ]);
        }

        $queue = $this->db->query(
            'SELECT `id`, `status` FROM `clinic_queue_entries` WHERE `encounter_id` = ? ORDER BY `id` DESC LIMIT 1 FOR UPDATE',
            [$encounterId],
        )->getRowArray();
        if ($queue !== null && in_array((string) $queue['status'], ['waiting', 'called', 'in_session'], true)) {
            $this->db->table('clinic_queue_entries')->where('clinic_queue_entries.tenant_id', CurrentTenant::id())->where('id', (int) $queue['id'])->update([
                'status' => 'done', 'finished_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->enqueue('clinic.queue_done', 'clinic_queue_entries', (int) $queue['id'], $userId, [
                'previous_status' => (string) $queue['status'], 'next_status' => 'done', 'reason_code' => $reasonCode,
            ]);
        }

        if ($encounter['appointment_id'] !== null) {
            $appointment = $this->selectForUpdate('clinic_appointments', ['tenant_id' => CurrentTenant::id(), 'id' => (int) $encounter['appointment_id'], 'archived_at' => null]);
            if ($appointment !== null && (string) $appointment['status'] === 'checked_in') {
                $this->db->table('clinic_appointments')->where('clinic_appointments.tenant_id', CurrentTenant::id())->where('id', (int) $appointment['id'])->update([
                    'status' => 'completed', 'updated_at' => $now,
                ]);
                $this->audit->enqueue('clinic.appointment_completed', 'clinic_appointments', (int) $appointment['id'], $userId, [
                    'previous_status' => 'checked_in', 'next_status' => 'completed', 'reason_code' => $reasonCode,
                ]);
            }
        }

        return $this->db->table('clinic_encounters')->where('clinic_encounters.tenant_id', CurrentTenant::id())->where('id', $encounterId)->get()->getRowArray();
    }
}
