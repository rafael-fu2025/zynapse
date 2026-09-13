<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\AuthGroups as ShieldAuthGroups;

/**
 * RBAC — Shield groups + dynamic permissions.
 *
 * Roles are coarse-grained. The fine-grained permissions matrix lives in
 * the `permissions` table (populated by the PermissionsAndGroupsSeeder)
 * and is checked via the `authorize()` helper in API controllers
 * (PermissionService::userHas). NEVER hardcode role checks; always
 * reference permissions by code.
 *
 * 2026-09 RBAC REWORK (13-role catalog): the undifferentiated `admin`
 * wildcard tier was split into a platform owner (`superadmin`, the ONLY
 * wildcard holder) plus one administrator per operating unit — Clinic,
 * Guidance, BMG/Facilities. Renames from the old catalog happened via
 * migration `2026-09-12-000010_RbacRoleRework` (memberships reference
 * group ids, so they survive): admin→clinic_admin,
 * clinical_supervisor→guidance_supervisor.
 *
 * Governance (server-enforced in UserAdminService, see
 * App\Services\Rbac\PrivilegedRoles):
 *   - Granting/revoking any privileged role requires
 *     `rbac.privileged.manage` (superadmin only).
 *   - No user can revoke their own last privileged role.
 *   - Kiosk machine accounts are created/reset only by clinic_admin
 *     or superadmin.
 */
class AuthGroups extends ShieldAuthGroups
{
    /** @var array<string, string> group => display label */
    public array $groups = [
        // PLATFORM — Platform Owner. The ONLY wildcard holder; minted
        // exclusively via `synapse:promote-superadmin` (never UI).
        'superadmin'          => 'Platform Owner',

        // CLINIC UNIT
        'clinic_admin'        => 'Clinic Administrator',
        'clinic_staff'        => 'Clinic Staff',
        'kiosk'               => 'Kiosk Station',

        // GUIDANCE UNIT
        'guidance_admin'      => 'Guidance Administrator',
        // RBAC_SECURITY_REVIEW R4: counselling oversight / break-glass
        // role (renamed from clinical_supervisor — memberships carried
        // over through the rename migration).
        'guidance_supervisor' => 'Guidance Supervisor',
        'counsellor'          => 'Guidance Counsellor',

        // BMG / FACILITIES UNIT
        'bmg_admin'           => 'BMG Administrator',
        'facilities_op'       => 'BMG Operator',

        // CROSS-CUTTING / READ-ONLY
        'audit_reader'        => 'Audit Reader',
        // Phase 19 (ACTOR_ACCESS_ANALYSIS): read-only analytics role.
        // Browses cross-module reports and exports CSV without holding
        // any clinical or operational write permission.
        'report_viewer'       => 'Report Viewer',

        // SELF-SERVICE
        // Phase 13: student self-service. Scoped to own data via the
        // `/me/student-*` endpoints (linked by the UNIQUE
        // `patients_students.user_id`).
        'student'             => 'Student',
        // Identity-consolidation: default role for auto-created
        // employee patient accounts. Mirrors `student` — self-scoped
        // portal read + notifications, no write perms (staff handles
        // mutations on the employee's behalf).
        'employee'            => 'Employee',
    ];

    /** @var array<string, string> role => group */
    public array $defaultGroupUsers = [
        'clinic_admin' => 'clinic_admin',
    ];

    /**
     * @var array<string, string[]> group => permission codes.
     * Codes are the canonical identifiers stored in `permissions.code`.
     */
    public array $groupPermissions = [
        // Granted every permission via the wildcard resolved in
        // PermissionService (holder group: `superadmin` only). Explicit
        // memberships in auth_groups_permissions still serve as
        // documentation.
        'superadmin' => [],

        // Clinic unit administrator — explicit matrix, NO wildcard.
        // Full clinic + referrals lifecycle, kiosk content, user
        // provisioning for non-privileged roles, saved-report authoring.
        // NOT: audit.*, api_apps.*, rbac.privileged.manage,
        // counselling.*, facilities.*. (`clinic.encounters.soft_delete`
        // and `clinic.departments.manage` are deliberately NOT in this
        // matrix — they stay superadmin-only.)
        'clinic_admin' => [
            'clinic.encounters.create',
            'clinic.encounters.read',
            'clinic.encounters.write',
            'clinic.appointments.read',
            'clinic.appointments.write',
            'clinic.patients.read',
            'clinic.patients.write',
            'clinic.queue.read',
            'clinic.queue.manage',
            'clinic.checkin.record',
            'clinic.checkin.read',
            'clinic.triage.use',
            'clinic.treatments.read',
            'clinic.inventory.read',
            'clinic.inventory.write',
            'clinic.inventory.forecast',
            'clinic.inventory.delete',
            'clinic.reorders.read',
            'clinic.reorders.manage',
            'clinic.schedules.manage',
            'reports.configure',
            'referrals.create',
            'referrals.read',
            'referrals.acknowledge',
            'referrals.review',
            'referrals.close',
            'referrals.issue_qr',
            'kiosk.content.manage',
            'rbac.manage',
            'rbac.read',
            'reports.read',
            'reports.export',
            'notifications.read',
            'employee.portal.read',
            'portal.appointments.read',
            'portal.queue.read',
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
        ],
        'kiosk' => [
            // Can submit either destination through the destination-aware
            // kiosk orchestrator. No record-read or queue-management grants.
            'kiosk.checkin.submit',
        ],

        // Guidance unit administrator — all counselling permissions
        // (including the records.read_any oversight break-glass and
        // soft_delete), read-only clinic patient directory, referral
        // lifecycle, non-privileged user provisioning.
        // NOT: clinic.* write codes, facilities.*, api_apps.*,
        // rbac.privileged.manage.
        'guidance_admin' => [
            'counselling.records.create',
            'counselling.records.read',
            'counselling.records.write',
            'counselling.records.read_any',
            'counselling.records.soft_delete',
            'counselling.schedule.read',
            'counselling.schedule.manage',
            'counselling.schedule.team_manage',
            'counselling.queue.read',
            'counselling.queue.manage',
            // Guidance content (2026-09 parity plan, Phase A): kiosk
            // announcements + CMO service catalogue.
            'counselling.announcements.manage',
            'counselling.services.manage',
            // Surveys engine (Phase B): builder/publish + response access.
            'counselling.surveys.manage',
            'counselling.responses.read',
            'counselling.responses.read_any',
            'clinic.patients.read',
            'referrals.create',
            'referrals.read',
            'referrals.acknowledge',
            'referrals.review',
            'referrals.close',
            'referrals.issue_qr',
            'rbac.manage',
            'rbac.read',
            'reports.read',
            'reports.export',
            'notifications.read',
            'employee.portal.read',
        ],
        // Guidance oversight / break-glass (renamed from
        // clinical_supervisor — matrix unchanged). No rbac.manage: a unit
        // lead without account-provisioning power (separation of duties).
        'guidance_supervisor' => [
            // Explicit grants (NOT via the wildcard) so note access is
            // deliberate and audited by CounsellingService
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
            // Survey responses (Phase B): oversight over student survey
            // /interview submissions.
            'counselling.responses.read',
            'counselling.responses.read_any',
            'notifications.read',
            'employee.portal.read',
        ],
        'counsellor' => [
            'counselling.records.create',
            'counselling.records.read',
            'counselling.records.write',
            'counselling.schedule.read',
            'counselling.schedule.manage',
            'counselling.queue.read',
            'counselling.queue.manage',
            // Survey responses (Phase B): counsellors read submissions
            // (no read_any — oversight-only, mirroring records semantics).
            'counselling.responses.read',
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

        // BMG unit administrator — full facilities.* configuration and
        // operations (units, categories, batch lifecycle, logs, I/O).
        // Operators run the drums; the admin configures them.
        'bmg_admin' => [
            'facilities.units.read',
            'facilities.units.manage',
            'facilities.categories.manage',
            'facilities.bmg.transition',
            'facilities.bmg.record_output',
            'facilities.bmg.logs.read',
            'facilities.bmg.logs.record',
            'facilities.bmg.io.record',
            'rbac.manage',
            'rbac.read',
            'reports.read',
            'reports.export',
            'notifications.read',
            'employee.portal.read',
        ],
        // 2026-09 RBAC rework: scope tightened — units/categories
        // configuration moved to bmg_admin; operators run the drums.
        'facilities_op' => [
            'facilities.units.read',
            'facilities.bmg.transition',
            'facilities.bmg.record_output',
            'facilities.bmg.logs.read',
            'facilities.bmg.logs.record',
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
        // authoring) is clinic_admin + superadmin only.
        'report_viewer' => [
            'reports.read',
            'reports.export',
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

    /** Superadmin wildcard — injected by PermissionService::allForUser. */
    public string $adminWildcard = '*';

    /** Permission codes are case-sensitive; protect from typos in seeders. */
    public bool $caseSensitivePermissions = true;
}
