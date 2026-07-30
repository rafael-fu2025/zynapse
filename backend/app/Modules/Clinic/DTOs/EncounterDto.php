<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

/**
 * EncounterDto — the clinic visit record.
 *
 * Status contract (panel revision, July 2026):
 *   - `open`     : visit in progress — vitals, treatments and medicine
 *                  dispensing are allowed ONLY in this state.
 *   - `closed`   : visit finished; terminal for clinic actions.
 *   - `referred` : visit handed off to another module; terminal.
 *
 * `appointment_id` links back to the scheduling layer when the visit
 * was opened by an appointment check-in; NULL for walk-ins.
 */
final class EncounterDto extends BaseDTO
{
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function jsonSerialize(): array
    {
        return [
            'id'                => (int)    $this->row['id'],
            'patient_school_id' => (string) $this->row['patient_school_id'],
            'appointment_id'    => ($this->row['appointment_id'] ?? null) !== null ? (int) $this->row['appointment_id'] : null,
            'chief_complaint'   => (string) $this->row['chief_complaint'],
            'triage_priority'   => ($this->row['triage_priority'] ?? null) !== null ? (string) $this->row['triage_priority'] : null,
            'triage_override'   => (bool) ($this->row['triage_override'] ?? false),
            'diagnosis'         => ($this->row['diagnosis'] ?? null) !== null ? (string) $this->row['diagnosis'] : null,
            'status'            => (string) $this->row['status'],
            'attending_user_id' => (int)    $this->row['attending_user_id'],
            'started_at'        => (string) $this->row['started_at'],
            'closed_at'         => $this->row['closed_at'] !== null ? (string) $this->row['closed_at'] : null,
        ];
    }
}