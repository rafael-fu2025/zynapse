<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

/**
 * UserDto — unified person/patient representation post identity-consolidation.
 *
 * Reads a single `users` row (the canonical identity) and exposes both the
 * common person fields and the type-specific student/employee columns.
 * `id` IS the `users.id` — there is no separate patient row, so the legacy
 * `persons_id` / `patient_identifier_id` / `user_id` fields are gone.
 *
 * Shape is a superset of the legacy StudentDto/EmployeeDto so the SPA can
 * render students and employees with one schema (Phase C).
 */
final class UserDto extends BaseDTO
{
    public function __construct(
        private readonly array $row,
        private readonly array $allergies = [],
        private readonly array $contacts = [],
    ) {
    }

    public static function fromRow(array $row, array $allergies = [], array $contacts = []): self
    {
        return new self($row, $allergies, $contacts);
    }

    public function jsonSerialize(): array
    {
        $row = $this->row;

        $out = [
            'id'               => (int) ($row['id'] ?? 0),
            'kind'             => isset($row['kind']) && $row['kind'] !== null ? (string) $row['kind'] : null,
            'first_name'       => isset($row['first_name']) ? (string) $row['first_name'] : '',
            'last_name'        => isset($row['last_name']) ? (string) $row['last_name'] : '',
            'middle_name'      => $this->nullableString($row['middle_name'] ?? null),
            'date_of_birth'    => $this->nullableString($row['date_of_birth'] ?? null),
            'gender'           => $this->nullableString($row['gender'] ?? null),
            'address'          => $this->nullableString($row['address'] ?? null),
            'has_qr'           => isset($row['qr_code']) && $row['qr_code'] !== null,
            'has_rfid'         => isset($row['rfid_tag']) && $row['rfid_tag'] !== null,
            'archived'         => isset($row['archived_at']) && $row['archived_at'] !== null,
            'created_at'       => (string) ($row['created_at'] ?? ''),
            'updated_at'       => (string) ($row['updated_at'] ?? ''),

            // Student-specific
            'student_number'     => $this->nullableString($row['student_number'] ?? null),
            'course'             => $this->nullableString($row['course'] ?? null),
            'year_level'         => isset($row['year_level']) && $row['year_level'] !== null ? (int) $row['year_level'] : null,
            'section'            => $this->nullableString($row['section'] ?? null),
            'blood_type'         => $this->nullableString($row['blood_type'] ?? null),
            'consecutive_no_shows' => (int) ($row['consecutive_no_shows'] ?? 0),

            // Employee-specific
            'employee_number'    => $this->nullableString($row['employee_number'] ?? null),
            'department'         => $this->nullableString($row['department'] ?? null),
            'position'           => $this->nullableString($row['position'] ?? null),
            'date_hired'         => $this->nullableString($row['date_hired'] ?? null),
            'employment_status'  => $this->nullableString($row['employment_status'] ?? null),
            'hr_synced_at'       => $this->nullableString($row['hr_synced_at'] ?? null),
            'emergency_contact_name'  => $this->nullableString($row['emergency_contact_name'] ?? null),
            'emergency_contact_phone' => $this->nullableString($row['emergency_contact_phone'] ?? null),
            'is_teaching'        => isset($row['is_teaching']) ? (bool) $row['is_teaching'] : null,
        ];

        if ($this->allergies !== []) {
            $out['allergies'] = array_map(
                static fn (array $a): array => [
                    'id'       => (int) $a['id'],
                    'allergen' => (string) $a['allergen'],
                    'severity' => (string) $a['severity'],
                    'reaction' => isset($a['reaction']) && $a['reaction'] !== null ? (string) $a['reaction'] : null,
                ],
                $this->allergies,
            );
        }
        if ($this->contacts !== []) {
            $out['contacts'] = array_map(
                static fn (array $c): array => [
                    'id'           => (int) $c['id'],
                    'contact_name' => (string) $c['contact_name'],
                    'relationship' => (string) $c['relationship'],
                    'phone'        => (string) $c['phone'],
                    'is_primary'   => (bool) $c['is_primary'],
                ],
                $this->contacts,
            );
        }

        return $out;
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (string) $v;
    }
}
