<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

final class AppointmentDto extends BaseDTO
{
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    /**
     * Decorate the DTO with name lookups so the SPA can render
     * `patient_name` + `provider_name` instead of raw 8-digit school
     * IDs and provider user ids. Resolved separately from `fromRow`
     * so the DTO stays trivially testable (pure array → array) and
     * the join queries can be batched at the service layer.
     *
     * @param array{patient_name?: ?string, patient_kind?: ?string, provider_name?: ?string} $names
     */
    public function withNames(array $names): self
    {
        $clone = clone $this;
        foreach (['patient_name', 'patient_kind', 'provider_name'] as $k) {
            if (array_key_exists($k, $names)) {
                $clone->row[$k] = $names[$k];
            }
        }
        return $clone;
    }

    public function jsonSerialize(): array
    {
        return [
            'id'                => (int)    $this->row['id'],
            'patient_school_id' => (string) $this->row['patient_school_id'],
            'patient_name'      => isset($this->row['patient_name'])  ? ($this->row['patient_name']  !== null ? (string) $this->row['patient_name']  : null) : null,
            'patient_kind'      => isset($this->row['patient_kind'])  ? ($this->row['patient_kind']  !== null ? (string) $this->row['patient_kind']  : null) : null,
            'provider_user_id'  => (int)    $this->row['provider_user_id'],
            'provider_name'     => isset($this->row['provider_name']) ? ($this->row['provider_name'] !== null ? (string) $this->row['provider_name'] : null) : null,
            'scheduled_at'      => (string) $this->row['scheduled_at'],
            'status'            => (string) $this->row['status'],
            'reason'            => $this->row['reason'] !== null ? (string) $this->row['reason'] : null,
            'created_at'        => (string) $this->row['created_at'],
        ];
    }
}
