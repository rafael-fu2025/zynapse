<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

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