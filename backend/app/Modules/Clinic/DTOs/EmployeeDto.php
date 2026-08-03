<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

final class EmployeeDto extends BaseDTO
{
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function jsonSerialize(): array
    {
        return [
            // Phase 2.2: unified-identity fields surfaced via EmployeeSelfService.
            'kind'                   => isset($this->row['person_kind']) ? (string) $this->row['person_kind'] : 'employee',
            'persons_id'             => isset($this->row['persons_id']) && $this->row['persons_id'] !== null ? (int) $this->row['persons_id'] : null,
            'patient_identifier_id'  => isset($this->row['patient_identifier_id']) && $this->row['patient_identifier_id'] !== null ? (int) $this->row['patient_identifier_id'] : null,
            'identifier'             => (string) $this->row['employee_number'],
            'id'                     => (int)    $this->row['id'],
            // `user_id` is the Phase 11 link to `users.id`. We expose
            // it as nullable so DTOs built from a row missing the
            // column (legacy callers) still serialize.
            'user_id'                => isset($this->row['user_id']) && $this->row['user_id'] !== null ? (int) $this->row['user_id'] : null,
            'employee_number'        => (string) $this->row['employee_number'],
            'first_name'              => (string) $this->row['first_name'],
            'last_name'               => (string) $this->row['last_name'],
            'middle_name'             => $this->row['middle_name'] !== null ? (string) $this->row['middle_name'] : null,
            'department'              => $this->row['department'] !== null ? (string) $this->row['department'] : null,
            'position'                => $this->row['position'] !== null ? (string) $this->row['position'] : null,
            'date_hired'              => $this->row['date_hired'] !== null ? (string) $this->row['date_hired'] : null,
            'employment_status'       => (string) $this->row['employment_status'],
            'hr_synced_at'            => isset($this->row['hr_synced_at']) && $this->row['hr_synced_at'] !== null ? (string) $this->row['hr_synced_at'] : null,
            'emergency_contact_name'  => $this->row['emergency_contact_name'] !== null ? (string) $this->row['emergency_contact_name'] : null,
            'emergency_contact_phone' => $this->row['emergency_contact_phone'] !== null ? (string) $this->row['emergency_contact_phone'] : null,
            // Phase 14: profile fields the employee can self-update
            // (address, gender, date_of_birth) now round-trip
            // through the DTO. `isset()` guards keep legacy
            // callers that don't SELECT the column working.
            'address'                 => isset($this->row['address']) && $this->row['address'] !== null ? (string) $this->row['address'] : null,
            'gender'                  => isset($this->row['gender']) && $this->row['gender'] !== null ? (string) $this->row['gender'] : null,
            'date_of_birth'           => isset($this->row['date_of_birth']) && $this->row['date_of_birth'] !== null ? (string) $this->row['date_of_birth'] : null,
            'has_qr'                  => isset($this->row['qr_code']) && $this->row['qr_code'] !== null,
            'has_rfid'                => isset($this->row['rfid_tag']) && $this->row['rfid_tag'] !== null,
            'is_teaching'             => isset($this->row['is_teaching']) && (int) $this->row['is_teaching'] === 1,
            'archived'                => isset($this->row['archived_at']) && $this->row['archived_at'] !== null,
            'created_at'              => (string) $this->row['created_at'],
        ];
    }
}
