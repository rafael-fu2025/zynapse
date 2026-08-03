<?php

declare(strict_types=1);

namespace Modules\Clinic\DTOs;

use App\Modules\Shared\BaseDTO;

final class StudentDto extends BaseDTO
{
    /**
     * @param array<int, array<string, mixed>> $allergies
     * @param array<int, array<string, mixed>> $contacts
     */
    public function __construct(
        private readonly array $row,
        private readonly ?array $allergies = null,
        private readonly ?array $contacts = null,
    ) {}

    public static function fromRow(array $row, ?array $allergies = null, ?array $contacts = null): self
    {
        return new self($row, $allergies, $contacts);
    }

    public function jsonSerialize(): array
    {
        $out = [
            // Phase 2.2: unified-identity fields surfaced via StudentSelfService.
            'kind'                  => isset($this->row['person_kind']) ? (string) $this->row['person_kind'] : 'student',
            'persons_id'            => isset($this->row['persons_id']) && $this->row['persons_id'] !== null ? (int) $this->row['persons_id'] : null,
            'patient_identifier_id' => isset($this->row['patient_identifier_id']) && $this->row['patient_identifier_id'] !== null ? (int) $this->row['patient_identifier_id'] : null,
            'identifier'            => (string) $this->row['student_number'],
            'id'                    => (int)    $this->row['id'],
            // Phase 13: link to `users.id` via the UNIQUE
            // `patients_students.user_id` added in
            // `StudentUserLink`. Nullable so legacy rows without
            // the column still serialize.
            'user_id'               => isset($this->row['user_id']) && $this->row['user_id'] !== null ? (int) $this->row['user_id'] : null,
            'student_number'        => (string) $this->row['student_number'],
            'first_name'           => (string) $this->row['first_name'],
            'last_name'            => (string) $this->row['last_name'],
            'middle_name'          => $this->row['middle_name'] !== null ? (string) $this->row['middle_name'] : null,
            'course'               => $this->row['course'] !== null ? (string) $this->row['course'] : null,
            'year_level'           => $this->row['year_level'] !== null ? (int) $this->row['year_level'] : null,
            'section'              => $this->row['section'] !== null ? (string) $this->row['section'] : null,
            'date_of_birth'        => $this->row['date_of_birth'] !== null ? (string) $this->row['date_of_birth'] : null,
            'gender'               => $this->row['gender'] !== null ? (string) $this->row['gender'] : null,
            'blood_type'           => $this->row['blood_type'] !== null ? (string) $this->row['blood_type'] : null,
            'has_qr'               => isset($this->row['qr_code']) && $this->row['qr_code'] !== null,
            'has_rfid'             => isset($this->row['rfid_tag']) && $this->row['rfid_tag'] !== null,
            'consecutive_no_shows' => isset($this->row['consecutive_no_shows']) ? (int) $this->row['consecutive_no_shows'] : 0,
            'archived'             => isset($this->row['archived_at']) && $this->row['archived_at'] !== null,
            'created_at'           => (string) $this->row['created_at'],
        ];

        if ($this->allergies !== null) {
            $out['allergies'] = array_map(static fn (array $a): array => [
                'id'       => (int)    $a['id'],
                'allergen' => (string) $a['allergen'],
                'severity' => (string) $a['severity'],
                'reaction' => $a['reaction'] !== null ? (string) $a['reaction'] : null,
            ], $this->allergies);
        }
        if ($this->contacts !== null) {
            $out['contacts'] = array_map(static fn (array $c): array => [
                'id'           => (int)    $c['id'],
                'contact_name' => (string) $c['contact_name'],
                'relationship' => (string) $c['relationship'],
                'phone'        => (string) $c['phone'],
                'is_primary'   => (bool)   $c['is_primary'],
            ], $this->contacts);
        }

        return $out;
    }
}
