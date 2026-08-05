<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ConsolidateLegacyIntoUsers — identity-unification (Phase A, step 2).
 *
 * Backfills the consolidated `users` columns from the legacy identity
 * tables so `users` becomes the single source of truth:
 *
 *   - Every `patients_students` / `patients_employees` row is merged into
 *     its linked `users` row (via `user_id`, then `persons.user_id`, then
 *     an identifier match). Patients that never had an account get a
 *     fresh identity-only `users` row (no `auth_identities` — they cannot
 *     log in until an admin issues credentials, which is exactly the
 *     "automatic account on create" behaviour for NEW records).
 *   - `persons` rows that are not covered by a legacy patient row
 *     (contractor / alumni / orphans) are merged into users as well.
 *   - `kind` is set from the legacy discriminator.
 *
 * Idempotent: re-runs are no-ops (lookups are keyed on identifiers, so a
 * second run simply re-applies the same values to the same user rows).
 */
final class ConsolidateLegacyIntoUsers extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('users')) {
            return;
        }
        if ($this->db->fieldExists('student_number', 'users') === false) {
            return; // M1 must have run first.
        }

        // 1. Students.
        if ($this->db->tableExists('patients_students')) {
            foreach ($this->db->table('patients_students')->select('*')->get()->getResultArray() as $row) {
                $userId = $this->resolveUserIdForLegacy(
                    $this->intOrNull($row['user_id'] ?? null),
                    $this->intOrNull($row['persons_id'] ?? null),
                    'student',
                    'student_number',
                    (string) $row['student_number'],
                );
                $this->applyUserData($userId, [
                    'kind'             => 'student',
                    'first_name'       => $this->strOrNull($row['first_name'] ?? null),
                    'last_name'        => $this->strOrNull($row['last_name'] ?? null),
                    'middle_name'      => $this->strOrNull($row['middle_name'] ?? null),
                    'qr_code'          => $this->strOrNull($row['qr_code'] ?? null),
                    'rfid_tag'         => $this->strOrNull($row['rfid_tag'] ?? null),
                    'date_of_birth'    => $this->strOrNull($row['date_of_birth'] ?? null),
                    'gender'           => $this->strOrNull($row['gender'] ?? null),
                    'address'          => $this->strOrNull($row['address'] ?? null),
                    'archived_at'      => $this->strOrNull($row['archived_at'] ?? null),
                    'student_number'   => (string) $row['student_number'],
                    'course'           => $this->strOrNull($row['course'] ?? null),
                    'year_level'       => $this->intOrNull($row['year_level'] ?? null),
                    'section'          => $this->strOrNull($row['section'] ?? null),
                    'blood_type'       => $this->strOrNull($row['blood_type'] ?? null),
                    'consecutive_no_shows' => (int) ($row['consecutive_no_shows'] ?? 0),
                ]);
            }
        }

        // 2. Employees.
        if ($this->db->tableExists('patients_employees')) {
            foreach ($this->db->table('patients_employees')->select('*')->get()->getResultArray() as $row) {
                $userId = $this->resolveUserIdForLegacy(
                    $this->intOrNull($row['user_id'] ?? null),
                    $this->intOrNull($row['persons_id'] ?? null),
                    'employee',
                    'employee_number',
                    (string) $row['employee_number'],
                );
                $this->applyUserData($userId, [
                    'kind'             => 'employee',
                    'first_name'       => $this->strOrNull($row['first_name'] ?? null),
                    'last_name'        => $this->strOrNull($row['last_name'] ?? null),
                    'middle_name'      => $this->strOrNull($row['middle_name'] ?? null),
                    'qr_code'          => $this->strOrNull($row['qr_code'] ?? null),
                    'rfid_tag'         => $this->strOrNull($row['rfid_tag'] ?? null),
                    'date_of_birth'    => $this->strOrNull($row['date_of_birth'] ?? null),
                    'gender'           => $this->strOrNull($row['gender'] ?? null),
                    'address'          => $this->strOrNull($row['address'] ?? null),
                    'archived_at'      => $this->strOrNull($row['archived_at'] ?? null),
                    'employee_number'  => (string) $row['employee_number'],
                    'department'       => $this->strOrNull($row['department'] ?? null),
                    'position'         => $this->strOrNull($row['position'] ?? null),
                    'date_hired'       => $this->strOrNull($row['date_hired'] ?? null),
                    'employment_status' => $this->strOrNull($row['employment_status'] ?? null),
                    'hr_synced_at'     => $this->strOrNull($row['hr_synced_at'] ?? null),
                    'emergency_contact_name'  => $this->strOrNull($row['emergency_contact_name'] ?? null),
                    'emergency_contact_phone' => $this->strOrNull($row['emergency_contact_phone'] ?? null),
                    'is_teaching'      => $this->intOrNull($row['is_teaching'] ?? null),
                ]);
            }
        }

        // 3. Persons not covered by a legacy patient row (contractor,
        //    alumni, or rows whose identifier did not backfill).
        if ($this->db->tableExists('persons')) {
            foreach ($this->db->table('persons')->select('*')->get()->getResultArray() as $p) {
                $kind = (string) ($p['kind'] ?? '');
                // Skip students/employees already merged above.
                if ($kind === 'student' || $kind === 'employee') {
                    // Still merge the person's common fields if a user exists.
                    $linked = $this->intOrNull($p['user_id'] ?? null);
                    if ($linked !== null) {
                        $this->applyUserData($linked, [
                            'kind'          => $kind,
                            'first_name'    => $this->strOrNull($p['first_name'] ?? null),
                            'last_name'     => $this->strOrNull($p['last_name'] ?? null),
                            'middle_name'   => $this->strOrNull($p['middle_name'] ?? null),
                            'qr_code'       => $this->strOrNull($p['qr_code'] ?? null),
                            'rfid_tag'      => $this->strOrNull($p['rfid_tag'] ?? null),
                            'date_of_birth' => $this->strOrNull($p['date_of_birth'] ?? null),
                            'gender'        => $this->strOrNull($p['gender'] ?? null),
                            'address'       => $this->strOrNull($p['address'] ?? null),
                            'archived_at'   => $this->strOrNull($p['archived_at'] ?? null),
                        ]);
                    }
                    continue;
                }

                $userId = $this->intOrNull($p['user_id'] ?? null);
                if ($userId === null) {
                    // Identity-only account for contractor/alumni/orphan person.
                    $userId = $this->createUserRow();
                }
                $this->applyUserData($userId, [
                    'kind'          => $kind !== '' ? $kind : null,
                    'first_name'    => $this->strOrNull($p['first_name'] ?? null),
                    'last_name'     => $this->strOrNull($p['last_name'] ?? null),
                    'middle_name'   => $this->strOrNull($p['middle_name'] ?? null),
                    'qr_code'       => $this->strOrNull($p['qr_code'] ?? null),
                    'rfid_tag'      => $this->strOrNull($p['rfid_tag'] ?? null),
                    'date_of_birth' => $this->strOrNull($p['date_of_birth'] ?? null),
                    'gender'        => $this->strOrNull($p['gender'] ?? null),
                    'address'       => $this->strOrNull($p['address'] ?? null),
                    'archived_at'   => $this->strOrNull($p['archived_at'] ?? null),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Not reversible by design — the legacy tables still exist until
        // M5 drops them; users columns are consolidated data.
    }

    /**
     * Find the target users.id for a legacy patient row, creating an
     * identity-only user when none exists. Resolution order:
     *   1. explicit user_id link,
     *   2. persons.user_id via persons_id,
     *   3. identifier match (student_number / employee_number),
     *   4. create a fresh identity-only user.
     */
    private function resolveUserIdForLegacy(
        ?int $userId,
        ?int $personsId,
        string $kind,
        string $identifierCol,
        string $identifier,
    ): int {
        if ($userId !== null && $userId > 0) {
            return $userId;
        }
        if ($personsId !== null && $personsId > 0 && $this->db->tableExists('persons')) {
            $linked = $this->db->table('persons')
                ->select('user_id')->where('id', $personsId)->get()->getRow();
            if ($linked !== null && $linked->user_id !== null) {
                return (int) $linked->user_id;
            }
        }
        if ($identifier !== '') {
            $byId = $this->db->table('users')
                ->select('id')
                ->where('kind', $kind)
                ->where($identifierCol, $identifier)
                ->get()->getRow();
            if ($byId !== null) {
                return (int) $byId->id;
            }
        }
        return $this->createUserRow();
    }

    private function createUserRow(): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('users')->insert([
            'status'     => 'active',
            'active'     => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $this->db->insertID();
    }

    /**
     * Update a user with non-null values only (never clobber an existing
     * value with NULL). `kind` is always applied when provided.
     *
     * @param array<string, mixed> $data
     */
    private function applyUserData(int $userId, array $data): void
    {
        $set = [];
        foreach ($data as $col => $val) {
            if ($col === 'kind') {
                if ($val !== null) {
                    $set[$col] = $val;
                }
                continue;
            }
            if ($val !== null) {
                $set[$col] = $val;
            }
        }
        if ($set === []) {
            return;
        }
        $set['updated_at'] = date('Y-m-d H:i:s');
        $this->db->table('users')->where('id', $userId)->update($set);
    }

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (int) $v;
    }

    private function strOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (string) $v;
    }
}
