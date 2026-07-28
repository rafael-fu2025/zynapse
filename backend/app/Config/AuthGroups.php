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
        'facilities_op'    => 'Facilities Operator',
        'audit_reader'     => 'Audit Reader',
        // Phase 13: student self-service placeholder. The student
        // portal proper (login + book + QR) is still deferred; the
        // group exists today so the canonical demo account can log
        // in and see the surfaces it is allowed to touch.
        'student'          => 'Student',
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
            'reports.read',
            'referrals.create',
            'referrals.read',
            'notifications.read',
            'employee.portal.read',
        ],
        'counsellor' => [
            'counselling.records.create',
            'counselling.records.read',
            'counselling.records.write',
            'counselling.schedule.read',
            'counselling.schedule.manage',
            'reports.read',
            'clinic.patients.read',
            'referrals.create',
            'referrals.read',
            'referrals.acknowledge',
            'notifications.read',
            'employee.portal.read',
        ],
        'facilities_op' => [
            'facilities.units.read',
            'facilities.bmg.transition',
            'facilities.bmg.record_output',
            'facilities.inventory.write',
            'facilities.bmg.logs.read',
            'facilities.bmg.logs.record',
            'facilities.categories.manage',
            'facilities.bmg.io.record',
            'notifications.read',
            'employee.portal.read',
        ],
        'audit_reader' => [
            'audit.read',
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
        ],
    ];

    /** Admin wildcard — checked in `PermissionService::resolve()`. */
    public string $adminWildcard = '*';

    /** Permission codes are case-sensitive; protect from typos in seeders. */
    public bool $caseSensitivePermissions = true;
}