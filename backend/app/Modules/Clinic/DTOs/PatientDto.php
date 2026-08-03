<?php
/**
 * PatientDto — unified DTO for the patient-registry consolidation.
 *
 * The new canonical "person" representation. Built from a join of the
 * `persons` table and the `patient_identifiers` table. Used by every
 * service that resolves a patient by identifier after Phase 3.
 *
 * Shape mirrors the legacy StudentDto / EmployeeDto closely enough that
 * the frontend can consume it without a breaking change. New fields
 * (`kind`, `patient_identifier_id`, `persons_id`) are added at the top
 * level so the SPA can start using them in a follow-up.
 *
 * @property-read string $kind 'student'|'employee'|'contractor'|'alumni'
 * @property-read ?int   $patient_identifier_id
 * @property-read int    $persons_id
 */
declare(strict_types=1);

namespace App\Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

final class PatientDto extends BaseDTO
{
    public function __construct(private readonly array $row) {}

    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function jsonSerialize(): array
    {
        $out = [
            'kind'                  => (string) ($this->row['kind'] ?? ''),
            'persons_id'            => (int)    ($this->row['persons_id'] ?? 0),
            'patient_identifier_id' => isset($this->row['patient_identifier_id']) && $this->row['patient_identifier_id'] !== null
                ? (int) $this->row['patient_identifier_id']
                : null,
            'identifier'            => (string) ($this->row['identifier'] ?? ''),
            'user_id'               => isset($this->row['user_id']) && $this->row['user_id'] !== null
                ? (int) $this->row['user_id']
                : null,
            'first_name'            => (string) ($this->row['first_name'] ?? ''),
            'last_name'             => (string) ($this->row['last_name'] ?? ''),
            'middle_name'           => isset($this->row['middle_name']) && $this->row['middle_name'] !== null
                ? (string) $this->row['middle_name']
                : null,
            'date_of_birth'         => isset($this->row['date_of_birth']) && $this->row['date_of_birth'] !== null
                ? (string) $this->row['date_of_birth']
                : null,
            'gender'                => isset($this->row['gender']) && $this->row['gender'] !== null
                ? (string) $this->row['gender']
                : null,
            'address'               => isset($this->row['address']) && $this->row['address'] !== null
                ? (string) $this->row['address']
                : null,
            'has_qr'                => isset($this->row['qr_code']) && $this->row['qr_code'] !== null,
            'has_rfid'              => isset($this->row['rfid_tag']) && $this->row['rfid_tag'] !== null,
            'archived'              => isset($this->row['archived_at']) && $this->row['archived_at'] !== null,
            'created_at'            => (string) ($this->row['created_at'] ?? ''),
        ];

        // Type-specific extras (preserved from the legacy DTOs).
        $kind = $out['kind'];
        if ($kind === 'student') {
            $out['student_number']       = $this->row['student_number']       ?? null;
            $out['course']               = $this->row['course']               ?? null;
            $out['year_level']           = isset($this->row['year_level']) && $this->row['year_level'] !== null ? (int) $this->row['year_level'] : null;
            $out['section']              = $this->row['section']              ?? null;
            $out['blood_type']           = $this->row['blood_type']           ?? null;
            $out['consecutive_no_shows'] = isset($this->row['consecutive_no_shows']) ? (int) $this->row['consecutive_no_shows'] : 0;
        } elseif ($kind === 'employee') {
            $out['employee_number']        = $this->row['employee_number']        ?? null;
            $out['department']             = $this->row['department']             ?? null;
            $out['position']               = $this->row['position']               ?? null;
            $out['date_hired']             = $this->row['date_hired']             ?? null;
            $out['employment_status']      = $this->row['employment_status']      ?? null;
            $out['hr_synced_at']           = $this->row['hr_synced_at']           ?? null;
            $out['emergency_contact_name'] = $this->row['emergency_contact_name'] ?? null;
            $out['emergency_contact_phone']= $this->row['emergency_contact_phone']?? null;
            $out['is_teaching']            = isset($this->row['is_teaching']) ? (bool) $this->row['is_teaching'] : false;
        }

        return $out;
    }
}
