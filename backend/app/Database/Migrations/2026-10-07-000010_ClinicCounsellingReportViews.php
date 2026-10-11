<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicCounsellingReportViews — reusable staff reports as MySQL views.
 *
 * Purely additive DDL: two read-only SELECT views over existing domain
 * tables. No base-table rows are written, altered, or removed; down()
 * drops only the views. No triggers, no privilege changes.
 *
 *   - v_clinic_visit_report          one row per clinic encounter with the
 *                                    patient identity resolved from the
 *                                    consolidated `users` table.
 *   - v_counselling_workload_summary one row per counsellor with
 *                                    conditional counts over their
 *                                    counselling_appointments.
 *
 * Tenancy: both views expose `tenant_id`. Every consumer MUST scope
 * reads the same way as direct table reads, with a bound parameter:
 *
 *     ->where('tenant_id', CurrentTenant::id())
 *
 * Patient identity mirrors PatientLookupService::resolveFromUsersByIdentifier():
 * every patient IS a `users` row (kind student|employee) and the
 * `patient_school_id` free-text key matches users.student_number OR
 * users.employee_number (the OR-join of the app's lookup). Unregistered
 * IDs surface as patient_type 'unregistered' instead of being dropped.
 */
final class ClinicCounsellingReportViews extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
            CREATE OR REPLACE VIEW `v_clinic_visit_report` AS
            SELECT
                ce.id                                AS encounter_id,
                ce.tenant_id                         AS tenant_id,
                ce.patient_school_id                 AS school_id,
                COALESCE(p.kind, 'unregistered')     AS patient_type,
                p.id                                 AS patient_user_id,
                COALESCE(
                    CONCAT(p.last_name, ', ', p.first_name),
                    '(not in registry)'
                )                                    AS patient_name,
                COALESCE(p.course, p.department)     AS course_or_department,
                p.blood_type                         AS blood_type,
                ce.chief_complaint                   AS chief_complaint,
                ce.status                            AS encounter_status,
                u.username                           AS attended_by,
                ce.started_at                        AS started_at,
                ce.closed_at                         AS closed_at,
                TIMESTAMPDIFF(MINUTE, ce.started_at, ce.closed_at) AS visit_minutes
            FROM clinic_encounters ce
            LEFT JOIN users p
                   ON p.kind IN ('student', 'employee')
                  AND p.archived_at IS NULL
                  AND (p.student_number = ce.patient_school_id
                       OR p.employee_number = ce.patient_school_id)
            JOIN users u
                   ON u.id = ce.attending_user_id
            WHERE ce.archived_at IS NULL
            SQL);

        $this->db->query(<<<'SQL'
            CREATE OR REPLACE VIEW `v_counselling_workload_summary` AS
            SELECT
                u.username                                                AS counsellor,
                ca.tenant_id                                              AS tenant_id,
                COUNT(*)                                                  AS total_appointments,
                SUM(CASE WHEN ca.status = 'completed' THEN 1 ELSE 0 END)  AS completed,
                SUM(CASE WHEN ca.status = 'no_show'   THEN 1 ELSE 0 END)  AS no_shows,
                SUM(CASE WHEN ca.status = 'cancelled' THEN 1 ELSE 0 END)  AS cancelled,
                SUM(CASE WHEN ca.status IN ('scheduled', 'confirmed') THEN 1 ELSE 0 END) AS upcoming,
                SUM(CASE WHEN ca.type   = 'crisis'    THEN 1 ELSE 0 END)  AS crisis_cases,
                ROUND(SUM(CASE WHEN ca.status = 'no_show' THEN 1 ELSE 0 END) * 100.0
                      / COUNT(*), 1)                                      AS no_show_pct,
                MIN(ca.appointment_date)                                  AS first_appointment,
                MAX(ca.appointment_date)                                  AS last_appointment
            FROM counselling_appointments ca
            JOIN users u ON u.id = ca.counsellor_user_id
            GROUP BY u.username, ca.tenant_id
            SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP VIEW IF EXISTS `v_counselling_workload_summary`');
        $this->db->query('DROP VIEW IF EXISTS `v_clinic_visit_report`');
    }
}
