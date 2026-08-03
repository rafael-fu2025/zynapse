<?php
/**
 * PatientLookupService — Phase 3 of the patient-registry consolidation.
 *
 * Resolves a patient by (qr_code, rfid_tag, student_number, or
 * employee_number) using the new `patient_identifiers` + `persons` join.
 * Falls through to the legacy tables when PatientRegistry::$legacyMode
 * is true AND the new path returns nothing.
 *
 * Designed to be a drop-in replacement for the inline lookup in
 * CheckinService, AppointmentService, ClinicService, QueueService,
 * ReferralService, CounsellingService, ScheduleService, ReportService.
 * Once a service migrates to this class, the legacy fallback can be
 * turned off in production (Phase 5) and the inline legacy code can
 * be removed.
 *
 * @phpstan-type PatientRow array<string, mixed>
 */
declare(strict_types=1);

namespace App\Modules\Clinic\Services;

use App\Modules\Clinic\DTOs\PatientDto;
use Config\PatientRegistry;
use Config\Services;

/**
 * @phpstan-import-type PatientRow from PatientLookupService
 */
final class PatientLookupService
{
    /**
     * Resolve a patient by a raw identifier value. This is the
     * common case for services that receive a `patient_school_id`
     * (the clinical-row key). Tries patient_identifiers first; on
     * miss, falls through to the legacy tables when
     * `PatientRegistry::$legacyMode` is true.
     *
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    public function findByIdentifier(string $identifier): array
    {
        $registry = $this->registry();

        if ($registry->newMode) {
            $row = $this->resolveFromPatientIdentifiersById($identifier);
            if ($row !== null) {
                return [$row['kind'], $row];
            }
        }

        if ($registry->legacyMode) {
            $row = $this->resolveFromLegacyTablesById($identifier);
            if ($row !== null) {
                return [$row['kind'], $row];
            }
        }

        return [null, null];
    }

    /**
     * @param 'qr'|'rfid'|'manual' $method  The scan method.
     * @param string               $identifier  The raw scanned value.
     * @return array{
     *     0: string|null,                              // 'student'|'employee'|null
     *     1: array<string, mixed>|null                 // legacy-shaped row, or null on miss
     * }
     */
    public function findForCheckin(string $method, string $identifier): array
    {
        $column  = $this->columnForMethod($method);
        $registry = $this->registry();

        // 1. Try the new path.
        if ($registry->newMode) {
            $row = $this->resolveFromPatientIdentifiers($identifier, $column);
            if ($row !== null) {
                return [$row['kind'], $row];
            }
        }

        // 2. Legacy fallback.
        if ($registry->legacyMode) {
            $row = $this->resolveFromLegacyTables($identifier, $column);
            if ($row !== null) {
                return [$row['kind'], $row];
            }
        }

        return [null, null];
    }

    /**
     * New-path lookup by identifier (no method/scan column).
     * Resolves the person via the `patient_identifiers.identifier`
     * column and joins to `persons` + the legacy child table for
     * type-specific fields.
     *
     * @return array<string, mixed>|null
     */
    private function resolveFromPatientIdentifiersById(string $identifier): ?array
    {
        $db = Services::database();
        $sql = "
            SELECT
                pi.id        AS patient_identifier_id,
                pi.kind      AS kind,
                pi.identifier AS identifier,
                p.id         AS persons_id,
                p.user_id    AS user_id,
                p.first_name, p.last_name, p.middle_name,
                p.qr_code, p.rfid_tag,
                p.date_of_birth, p.gender, p.address,
                p.archived_at, p.created_at, p.updated_at,
                ps.student_number, ps.course, ps.year_level,
                ps.section, ps.blood_type, ps.consecutive_no_shows,
                pe.employee_number, pe.department, pe.position,
                pe.date_hired, pe.employment_status, pe.hr_synced_at,
                pe.emergency_contact_name, pe.emergency_contact_phone,
                pe.is_teaching
            FROM `patient_identifiers` pi
            JOIN `persons` p ON p.id = pi.persons_id
            LEFT JOIN `patients_students`  ps ON ps.persons_id = p.id AND pi.kind = 'student'
            LEFT JOIN `patients_employees` pe ON pe.persons_id = p.id AND pi.kind = 'employee'
            WHERE pi.identifier = ?
              AND (pi.kind = 'student' OR pi.kind = 'employee')
            LIMIT 1
        ";
        $query = $db->query($sql, [$identifier]);
        $row = $query !== false ? $query->getRowArray() : null;
        return $row !== null && ! empty($row) ? $row : null;
    }

    /**
     * Legacy fallback by identifier. Returns the matching legacy
     * students or employees row directly.
     *
     * @return array<string, mixed>|null
     */
    private function resolveFromLegacyTablesById(string $identifier): ?array
    {
        $db = Services::database();
        $row = $db->table('patients_students')
            ->where('student_number', $identifier)
            ->where('archived_at', null)
            ->get()->getRowArray();
        if ($row !== null) {
            return $row;
        }
        $row = $db->table('patients_employees')
            ->where('employee_number', $identifier)
            ->where('archived_at', null)
            ->get()->getRowArray();
        return $row !== null ? $row : null;
    }

    /**
     * New-path lookup. Returns a row in the legacy column shape so
     * callers don't need to change.
     *
     * @return array<string, mixed>|null
     */
    private function resolveFromPatientIdentifiers(string $identifier, ?string $column): ?array
    {
        $db = Services::database();
        $where = '';
        $binds = [];
        if ($column === null) {
            $where = 'pi.identifier = ?';
            $binds[] = $identifier;
        } else {
            $where = 'p.' . $column . ' = ?';
            $binds[] = $identifier;
        }

        $sql = "
            SELECT
                pi.id        AS patient_identifier_id,
                pi.kind      AS kind,
                pi.identifier AS identifier,
                p.id         AS persons_id,
                p.user_id    AS user_id,
                p.first_name, p.last_name, p.middle_name,
                p.qr_code, p.rfid_tag,
                p.date_of_birth, p.gender, p.address,
                p.archived_at, p.created_at, p.updated_at,
                ps.student_number, ps.course, ps.year_level,
                ps.section, ps.blood_type, ps.consecutive_no_shows,
                pe.employee_number, pe.department, pe.position,
                pe.date_hired, pe.employment_status, pe.hr_synced_at,
                pe.emergency_contact_name, pe.emergency_contact_phone,
                pe.is_teaching
            FROM `patient_identifiers` pi
            JOIN `persons` p ON p.id = pi.persons_id
            LEFT JOIN `patients_students`  ps ON ps.user_id = p.user_id AND pi.kind = 'student'
            LEFT JOIN `patients_employees` pe ON pe.user_id = p.user_id AND pi.kind = 'employee'
            WHERE {$where}
              AND (pi.kind = 'student' OR pi.kind = 'employee')
            LIMIT 1
        ";
        $query = $db->query($sql, $binds);
        $row = $query !== false ? $query->getRowArray() : null;
        return $row !== null && ! empty($row) ? $row : null;
    }

    /**
     * Legacy-table lookup. Returns a row in the same shape the existing
     * services expect. Used only when PatientRegistry::$legacyMode
     * is true and the new path missed.
     *
     * @return array<string, mixed>|null
     */
    private function resolveFromLegacyTables(string $identifier, ?string $column): ?array
    {
        $db = Services::database();
        $col = $column ?? 'student_number';
        $row = $db->table('patients_students')
            ->where($col, $identifier)
            ->where('archived_at', null)
            ->get()->getRowArray();
        if ($row !== null) {
            return $row;
        }
        $row = $db->table('patients_employees')
            ->where($col === 'student_number' ? 'employee_number' : $col, $identifier)
            ->where('archived_at', null)
            ->get()->getRowArray();
        return $row !== null ? $row : null;
    }

    private function columnForMethod(string $method): ?string
    {
        return match ($method) {
            'qr'   => 'qr_code',
            'rfid' => 'rfid_tag',
            default => null,
        };
    }

    private function registry(): PatientRegistry
    {
        /** @var PatientRegistry $r */
        $r = config('Config\\PatientRegistry');
        return $r;
    }
}
