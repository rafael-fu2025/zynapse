<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\AuthGroups as ShieldAuthGroups;

/**
 * RBAC — Shield groups + dynamic permissions.
 *
 * Roles are coarse-grained. The fine-grained permissions matrix lives in
 * the `permissions` table (populated by migrations) and is checked via
 * `$user->can('clinic.encounters.create')` and the `authorize()` helper.
 *
 * NEVER hardcode role checks; always reference permissions by code.
 */
class AuthGroups extends ShieldAuthGroups
{
    /** @var array<string, string> group => display label */
    public array $groups = [
        'admin'            => 'Administrator',
        'clinic_staff'     => 'Clinic Staff',
        'counsellor'       => 'Counsellor',
        'kiosk'            => 'Kiosk Station',
        'facilities_op'    => 'Facilities Operator',
        'audit_reader'     => 'Audit Reader',
        // Phase 19 (ACTOR_ACCESS_ANALYSIS): read-only analytics role.
        // Browses cross-module reports and exports CSV without holding
        // any clinical or operational write permission.
        'report_viewer'    => 'Report Viewer',
        // RBAC_SECURITY_REVIEW R4: clinical oversight / break-glass role.
        // Holds counselling.records.* EXPLICITLY (redundant with the admin
        // wildcard since the R1 exclusion was lifted) so oversight roles
        // keep a deliberate, audited grant.
        'clinical_supervisor' => 'Clinical Supervisor',
        // Phase 13: student self-service placeholder. The student
        // portal proper (login + book + QR) is still deferred; the
        // group exists today so the canonical demo account can log
        // in and see the surfaces it is allowed to touch.
        'student'          => 'Student',
        // Identity-consolidation: default role for auto-created
        // employee patient accounts. Mirrors `student` — self-scoped
        // portal read + notifications, no write perms (staff handles
        // mutations on the employee's behalf).
        'employee'         => 'Employee',
    ];

    /** @var array<string, string> role => group */
    public array $defaultGroupUsers = [
        'admin' => 'admin',
    ];

    /**
     * @var array<string, string[]> group => permission codes.
     * Codes are the canonical identifiers stored in `permissions.code`.
     */
    public array $groupPermissions = [
        'admin' => [
            // Granted every permission via the wildcard below; explicit
            // memberships still serve as documentation.
        ],
        'clinic_staff' => [
            'clinic.encounters.create',
            'clinic.encounters.read',
            'clinic.encounters.write',
            'clinic.inventory.read',
            'clinic.inventory.write',  // Phase 14: use case requires "Manage medicines / batches".
            'clinic.appointments.read',
            'clinic.appointments.write',
            'clinic.patients.read',
            'clinic.patients.write',
            'clinic.reorders.read',
            'clinic.reorders.manage',
            'clinic.queue.read',
            'clinic.queue.manage',
            'clinic.checkin.record',
            'clinic.checkin.read',
            'clinic.treatments.read',
            'clinic.triage.use',
            'clinic.inventory.forecast',
            // Staff schedules are the clinic's own recurring roster —
            // clinic staff manage their shifts (audit 2026-08-05).
            'clinic.schedules.manage',
            'reports.read',
            'referrals.create',
            'referrals.read',
            // Phase 19 (ACTOR_ACCESS_ANALYSIS): the Clinic Staff use case
            // "Receive Counselling Referral" requires acknowledge; the
            // bridge is bidirectional, so the full lifecycle (review /
            // close / issue QR) is granted to both bridge-side groups.
            // Per-direction restriction stays a ReferralPolicy record-level
            // decision (see policy docblock).
            'referrals.acknowledge',
            'referrals.review',
            'referrals.close',
            'referrals.issue_qr',
            'notifications.read',
            'employee.portal.read',
            'kiosk.content.manage',
        ],
        'counsellor' => [
            'counselling.records.create',
            'counselling.records.read',
            'counselling.records.write',
            'counselling.schedule.read',
            'counselling.schedule.manage',
            'counselling.queue.read',
            'counselling.queue.manage',
            'reports.read',
            'clinic.patients.read',
            'referrals.create',
            'referrals.read',
            'referrals.acknowledge',
            // Phase 19 (ACTOR_ACCESS_ANALYSIS): referral lifecycle beyond
            // acknowledge was previously admin-only; counsellors run the
            // target-side review/close and issue verification QRs.
            'referrals.review',
            'referrals.close',
            'referrals.issue_qr',
            'notifications.read',
            'employee.portal.read',
        ],
        'kiosk' => [
            // Can submit either destination through the destination-aware
            // kiosk orchestrator. No record-read or queue-management grants.
            'kiosk.checkin.submit',
        ],
        'facilities_op' => [
            'facilities.units.read',
            // Phase 19 (ACTOR_ACCESS_ANALYSIS): the BMG Staff use case
            // "Manage and create drums" maps to BmgPolicy `manage_units`;
            // previously admin-only by omission.
            'facilities.units.manage',
            'facilities.bmg.transition',
            'facilities.bmg.record_output',
            'facilities.bmg.logs.read',
            'facilities.bmg.logs.record',
            'facilities.categories.manage',
            'facilities.bmg.io.record',
            'notifications.read',
            'employee.portal.read',
        ],
        'audit_reader' => [
            'audit.read',
            // Phase 19 (ACTOR_ACCESS_ANALYSIS): the audit specialist can
            // export the (redacted) CSV, not just browse. Export events
            // are themselves audited.
            'audit.export',
            'notifications.read',
            'employee.portal.read',
        ],
        // Phase 19 (ACTOR_ACCESS_ANALYSIS): Report Viewer actor from the
        // use-case diagrams. Strictly read-only operational review —
        // `reports.read` + CSV export; `reports.configure` (saved-report
        // authoring) intentionally stays admin-only.
        'report_viewer' => [
            'reports.read',
            'reports.export',
            'notifications.read',
            'employee.portal.read',
        ],
        'clinical_supervisor' => [
            // Explicit grants (NOT via the wildcard) so R1's exclusion
            // permits note access; reads are audited by CounsellingService
            // (RBAC_SECURITY_REVIEW R2/R4).
            // records.read_any is the oversight break-glass for notes on
            // sessions the supervisor does not own (audit 2026-09-05, F2)
            // — counsellors are own-session only.
            'counselling.records.read',
            'counselling.records.write',
            'counselling.records.create',
            'counselling.records.read_any',
            // Session archive/unarchive (F15): correcting a note-on-the-
            // wrong-patient mistake must surface to oversight, not be
            // quietly cleaned up by whoever made the error — so plain
            // counsellors do NOT hold this.
            'counselling.records.soft_delete',
            'counselling.schedule.read',
            'counselling.schedule.team_manage',
            'counselling.queue.read',
            'counselling.queue.manage',
            'notifications.read',
            'employee.portal.read',
        ],
        'student' => [
            // Phase 13: students are scoped to their own data via the
            // `/me/student-*` endpoints (linked by the UNIQUE
            // `patients_students.user_id` added in
            // `StudentUserLink`). They get ONLY read access to
            // notifications + a dedicated `student.portal.read`
            // permission for the (future) student self-service
            // surface. No write perms; the staff handles every
            // mutation on the student's behalf.
            'notifications.read',
            'student.portal.read',
            'portal.appointments.read',
            'portal.appointments.manage',
            'portal.queue.read',
        ],
        'employee' => [
            // Identity-consolidation: default role for auto-created
            // employee patient accounts. Self-scoped portal read only.
            // referrals.read/create let TEACHING employees (faculty)
            // refer students to counselling; the service-level gate
            // still requires `is_teaching = 1` for clinic-originated
            // referrals, so non-teaching staff see the page but cannot
            // create one (friendly hint in the UI).
            'notifications.read',
            'employee.portal.read',
            'portal.appointments.read',
            'portal.appointments.manage',
            'portal.queue.read',
            'referrals.create',
            'referrals.read',
        ],
    ];

    /** Admin wildcard — checked in `PermissionService::resolve()`. */
    public string $adminWildcard = '*';

    /** Permission codes are case-sensitive; protect from typos in seeders. */
    public bool $caseSensitivePermissions = true;
}
