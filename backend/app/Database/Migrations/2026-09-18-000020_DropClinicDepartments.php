<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * DropClinicDepartments — teardown of the redundant department lookup
 * (2026-09-18).
 *
 * `clinic_departments` was a curated picker source for the employee
 * `department` field. It is redundant: that field is owned by the FU MIS
 * integration and is overwritten on every employee login
 * (`FuMisAuthService::upsertEmployee`) and every HR batch sync
 * (`PatientService::hrSyncEmployees`). The MIS already publishes an
 * authoritative department registry (`GET /api/v1/employees/departments`)
 * which Synapse does not consume, and the local rows never matched MIS
 * department codes — so the picker could not stay consistent with the
 * values it existed to populate.
 *
 *   1. Drop `clinic_departments`.
 *   2. Delete the `clinic.departments.manage` permission code and its
 *      `auth_groups_permissions` mappings.
 *
 * `users.department` is deliberately KEPT — it is the MIS-owned free-text
 * field, still displayed in the employee table and still searchable.
 *
 * The applied migrations that created this (2026-01-22-000010_ClinicDepartments,
 * 2026-08-02-100700_TenantsAndTenantId) are NOT rewritten — migration
 * history stays append-only.
 *
 * Idempotent: re-runs are no-ops.
 */
final class DropClinicDepartments extends Migration
{
    /** Permission code minted for the department CRUD surface. */
    private const PERMISSION = 'clinic.departments.manage';

    public function up(): void
    {
        // ---- 1. The lookup table -------------------------------------
        $this->forge->dropTable('clinic_departments', true);

        // ---- 2. The permission code ----------------------------------
        if ($this->db->tableExists('auth_groups_permissions')) {
            $this->db->table('auth_groups_permissions')
                ->where('permission_code', self::PERMISSION)
                ->delete();
        }
        if ($this->db->tableExists('permissions')) {
            $this->db->table('permissions')
                ->where('code', self::PERMISSION)
                ->delete();
        }
    }

    /**
     * Not reversible: the table's rows were a hand-maintained picker list
     * with no source of truth to restore them from, and the application
     * code that read them is gone. Recreating an empty table would leave
     * an unreachable schema.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
}
