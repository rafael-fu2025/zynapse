<?php

declare(strict_types=1);

namespace App\Services\FuMis;

/**
 * FuMisProfileMapper — MIS `data` payload → Synapse `users` columns.
 *
 * !!! THIS IS THE SINGLE FIELD-MAPPING FILE !!!
 *
 * The HTML docs describe response fields only prose-wise ("The student
 * informations of the user") — no actual JSON keys. Every assumption
 * below is marked `CONFIRM-FIELD` and must be locked in against real
 * sandbox responses (student login, employee login, one list retrieval)
 * captured on campus WiFi. Only this file should need to change then.
 *
 * The mapper is deliberately total: unknown/missing fields are dropped
 * (never fatal), and each output key is null when the source is absent,
 * so the upsert in FuMisAuthService can distinguish "not provided" from
 * "empty".
 */
final class FuMisProfileMapper
{
    /**
     * Map an MIS student `data` object to Synapse users columns.
     *
     * Real sandbox sample confirmed (2026-09-08):
     *   "student_id": "20230001"
     *   "first_name": "JUAN"
     *   "middle_name": "REYES "
     *   "last_name": "DELA CRUZ"
     *   "program": "BSIT"
     *   "level": "3"
     *   "department": "CCS"
     *
     * @param array<string, mixed> $data
     * @return array{
     *     identifier: string,
     *     first_name: ?string, middle_name: ?string, last_name: ?string,
     *     course: ?string, year_level: ?int, section: ?string,
     *     department: ?string,
     * }
     */
    public function mapStudent(array $data): array
    {
        // Tolerate both root data payload and nested data.data wrapper.
        $source = is_array($data['data'] ?? null) ? $data['data'] : $data;

        // Confirmed keys from real sandbox payload:
        $identifier = $this->string($source, 'student_id', 'id', 'studentId', 'student_number');

        return [
            'identifier'  => $identifier,
            'first_name'  => $this->string($source, 'first_name', 'firstName', 'fname'),
            'middle_name' => $this->string($source, 'middle_name', 'middleName', 'middlename'),
            'last_name'   => $this->string($source, 'last_name', 'lastName', 'lname'),
            'course'      => $this->string($source, 'program', 'course', 'program_name'),
            'year_level'  => $this->int($source, 'level', 'year_level', 'yearLevel'),
            'department'  => $this->string($source, 'department', 'department_name', 'department_code'),
            'section'     => $this->string($source, 'section', 'block', 'block_section'),
        ];
    }

    /**
     * Map an MIS employee `data` object to Synapse users columns.
     *
     * @param array<string, mixed> $data
     * @return array{
     *     identifier: string,
     *     first_name: ?string, middle_name: ?string, last_name: ?string,
     *     department: ?string, position: ?string,
     *     employment_status: ?string, is_teaching: ?bool,
     * }
     */
    public function mapEmployee(array $data): array
    {
        // Tolerate both root data payload and nested data.data wrapper.
        $source = is_array($data['data'] ?? null) ? $data['data'] : $data;

        // CONFIRM-FIELD: employee id key.
        $identifier = $this->string($source, 'employee_id', 'id', 'employeeId', 'employee_number');

        // CONFIRM-FIELD: department — the students API documents
        // "Department Name" and the employees API "Department Code";
        // Synapse `users.department` is free text, both fit.
        $department = $this->string($source, 'department', 'department_name', 'departmentName', 'department_code');

        // CONFIRM-FIELD: employment status values — Synapse ENUMs
        // ('active','inactive','on_leave'); pass through lowercased and
        // let the upsert clamp unknown values to null.
        $employmentStatus = $this->string($source, 'employment_status', 'employmentStatus', 'status');
        if ($employmentStatus !== null) {
            $employmentStatus = strtolower($employmentStatus);
            if (! in_array($employmentStatus, ['active', 'inactive', 'on_leave'], true)) {
                $employmentStatus = null;
            }
        }

        // CONFIRM-FIELD: teaching flag key and boolean shape
        // (true/false vs "yes"/"no" vs 1/0).
        $isTeaching = $this->bool($source, 'is_teaching', 'isTeaching', 'teaching', 'is_faculty');

        return [
            'identifier'         => $identifier,
            'first_name'         => $this->string($source, 'first_name', 'firstName', 'fname'),
            'middle_name'        => $this->string($source, 'middle_name', 'middleName', 'middlename'),
            'last_name'          => $this->string($source, 'last_name', 'lastName', 'lname'),
            'department'         => $department,
            'position'           => $this->string($source, 'position', 'job_title', 'position_name'),
            'employment_status'  => $employmentStatus,
            'is_teaching'        => $isTeaching,
        ];
    }

    /**
     * First non-empty string among candidate keys. null when absent.
     */
    private function string(array $data, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_int($value) && $value > 0) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * First parseable integer among candidate keys. null when absent.
     */
    private function int(array $data, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * First truthy-ish value among candidate keys. null when absent.
     */
    private function bool(array $data, string ...$keys): ?bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value) && ($value === 0 || $value === 1)) {
                return $value === 1;
            }
            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if (in_array($normalized, ['true', 'yes', 'y', '1'], true)) {
                    return true;
                }
                if (in_array($normalized, ['false', 'no', 'n', '0'], true)) {
                    return false;
                }
            }
        }

        return null;
    }
}
