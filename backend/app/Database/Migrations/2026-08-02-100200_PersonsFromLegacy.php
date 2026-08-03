<?php
/**
 * PersonsFromLegacy — Phase 1.3 of the patient-registry consolidation.
 *
 * Backfills `persons` and `patient_identifiers` from the existing
 * `patients_students` and `patients_employees` tables. Idempotent.
 *
 * Mapping rules:
 *   - One `persons` row per existing registry row.
 *   - `kind` is 'student' for students_students, 'employee' for employees.
 *   - `user_id` is copied (may be NULL).
 *   - Shared columns (first_name, last_name, middle_name, qr_code, rfid_tag,
 *     date_of_birth, gender, address, archived_at, created_at, updated_at)
 *     are copied from the legacy row.
 *   - One `patient_identifiers` row per person, with `identifier` set to
 *     the legacy student_number / employee_number.
 *
 * Idempotency:
 *   - If a `persons` row with the same `user_id` already exists (and the
 *     user_id is not NULL), we skip — the first run wins.
 *   - If a `patient_identifiers` row with the same (kind, identifier,
 *     archived_at) already exists, we skip.
 *
 * The migration runs in a single transaction so a partial backfill is
 * rolled back atomically.
 */
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class PersonsFromLegacy extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('persons') || ! $this->db->tableExists('patient_identifiers')) {
            // Phase 1.1 / 1.2 must run first.
            return;
        }

        // Bail if the backfill has already happened (cheap heuristic:
        // the row count of patient_identifiers is at least the legacy
        // row count for the matching kinds).
        $existing = (int) $this->db->table('patient_identifiers')->countAllResults();
        $legacy   = (int) $this->db->table('patients_students')->countAllResults()
                   + (int) $this->db->table('patients_employees')->countAllResults();
        if ($existing >= $legacy && $existing > 0) {
            return;
        }

        $this->db->transStart();

        // --- Students --------------------------------------------------------
        $rows = $this->db->table('patients_students')
            ->select('id, user_id, student_number, first_name, last_name, middle_name, qr_code, rfid_tag, date_of_birth, gender, address, archived_at, created_at, updated_at')
            ->get()->getResultArray();

        foreach ($rows as $r) {
            $existingPersonId = null;
            if ($r['user_id'] !== null) {
                $existing = $this->db->table('persons')
                    ->select('id')
                    ->where('user_id', (int) $r['user_id'])
                    ->get()->getRowArray();
                if ($existing !== null) {
                    $existingPersonId = (int) $existing['id'];
                }
            }

            if ($existingPersonId === null) {
                $this->db->table('persons')->insert([
                    'kind'         => 'student',
                    'user_id'      => $r['user_id'] !== null ? (int) $r['user_id'] : null,
                    'first_name'   => (string) $r['first_name'],
                    'last_name'    => (string) $r['last_name'],
                    'middle_name'  => $r['middle_name'] !== null ? (string) $r['middle_name'] : null,
                    'qr_code'      => $r['qr_code'] !== null ? (string) $r['qr_code'] : null,
                    'rfid_tag'     => $r['rfid_tag'] !== null ? (string) $r['rfid_tag'] : null,
                    'date_of_birth'=> $r['date_of_birth'] !== null ? (string) $r['date_of_birth'] : null,
                    'gender'       => $r['gender'] !== null ? (string) $r['gender'] : null,
                    'address'      => $r['address'] !== null ? (string) $r['address'] : null,
                    'archived_at'  => $r['archived_at'] !== null ? (string) $r['archived_at'] : null,
                    'created_at'   => (string) $r['created_at'],
                    'updated_at'   => (string) $r['updated_at'],
                ]);
                $existingPersonId = (int) $this->db->insertID();
            }

            // Insert the (kind='student', identifier) row if missing.
            $exists = $this->db->table('patient_identifiers')
                ->select('id')
                ->where('kind', 'student')
                ->where('identifier', (string) $r['student_number'])
                ->where('archived_at', $r['archived_at'])
                ->get()->getRowArray();
            if ($exists === null) {
                $this->db->table('patient_identifiers')->insert([
                    'persons_id'  => $existingPersonId,
                    'kind'        => 'student',
                    'identifier'  => (string) $r['student_number'],
                    'is_primary'  => 1,
                    'archived_at' => $r['archived_at'] !== null ? (string) $r['archived_at'] : null,
                    'created_at'  => (string) $r['created_at'],
                    'updated_at'  => (string) $r['updated_at'],
                ]);
            }
        }

        // --- Employees -------------------------------------------------------
        $rows = $this->db->table('patients_employees')
            ->select('id, user_id, employee_number, first_name, last_name, middle_name, qr_code, rfid_tag, date_of_birth, gender, address, archived_at, created_at, updated_at')
            ->get()->getResultArray();

        foreach ($rows as $r) {
            $existingPersonId = null;
            if ($r['user_id'] !== null) {
                $existing = $this->db->table('persons')
                    ->select('id')
                    ->where('user_id', (int) $r['user_id'])
                    ->get()->getRowArray();
                if ($existing !== null) {
                    $existingPersonId = (int) $existing['id'];
                }
            }

            if ($existingPersonId === null) {
                $this->db->table('persons')->insert([
                    'kind'         => 'employee',
                    'user_id'      => $r['user_id'] !== null ? (int) $r['user_id'] : null,
                    'first_name'   => (string) $r['first_name'],
                    'last_name'    => (string) $r['last_name'],
                    'middle_name'  => $r['middle_name'] !== null ? (string) $r['middle_name'] : null,
                    'qr_code'      => $r['qr_code'] !== null ? (string) $r['qr_code'] : null,
                    'rfid_tag'     => $r['rfid_tag'] !== null ? (string) $r['rfid_tag'] : null,
                    'date_of_birth'=> $r['date_of_birth'] !== null ? (string) $r['date_of_birth'] : null,
                    'gender'       => $r['gender'] !== null ? (string) $r['gender'] : null,
                    'address'      => $r['address'] !== null ? (string) $r['address'] : null,
                    'archived_at'  => $r['archived_at'] !== null ? (string) $r['archived_at'] : null,
                    'created_at'   => (string) $r['created_at'],
                    'updated_at'   => (string) $r['updated_at'],
                ]);
                $existingPersonId = (int) $this->db->insertID();
            }

            $exists = $this->db->table('patient_identifiers')
                ->select('id')
                ->where('kind', 'employee')
                ->where('identifier', (string) $r['employee_number'])
                ->where('archived_at', $r['archived_at'])
                ->get()->getRowArray();
            if ($exists === null) {
                $this->db->table('patient_identifiers')->insert([
                    'persons_id'  => $existingPersonId,
                    'kind'        => 'employee',
                    'identifier'  => (string) $r['employee_number'],
                    'is_primary'  => 1,
                    'archived_at' => $r['archived_at'] !== null ? (string) $r['archived_at'] : null,
                    'created_at'  => (string) $r['created_at'],
                    'updated_at'  => (string) $r['updated_at'],
                ]);
            }
        }

        $this->db->transComplete();
    }

    public function down(): void
    {
        // Down is a no-op: rolling back the backfill would leave the legacy
        // tables in an inconsistent state with the new tables. To roll back
        // Phase 1 fully, drop `persons` and `patient_identifiers` (the down()
        // methods of PersonParent / PatientIdentifiers handle that).
    }
}
