<?php
/**
 * TenantsAndTenantId — Phase 6 of the patient-registry consolidation.
 *
 * Adds:
 *   1. A `tenants` table (single row seeded: Foundation University).
 *   2. A `tenant_id` column on every domain table (default 1, NOT NULL).
 *   3. A `CurrentTenant` service that returns 1 for now (single-tenant)
 *      and is wired to a request-scoped binding in a multi-tenant future.
 *
 * Domain tables that gain a tenant_id column:
 *   persons, patient_identifiers, patients_students, patients_employees,
 *   patient_allergies, patient_contacts, clinic_encounters, clinic_appointments,
 *   clinic_queue_entries, clinic_checkins, clinic_treatments, clinic_inventory,
 *   clinic_medicines, clinic_medicine_batches, clinic_medicine_transactions,
 *   clinic_reorder_requests, clinic_staff_schedules, clinic_triage_predictions,
 *   clinic_medicine_forecasts, clinic_departments, counselling_sessions,
 *   counselling_appointments, counselling_scheduling_analytics,
 *   facilities_bmg_units, facilities_bmg_batches, facilities_bmg_process_logs,
 *   facilities_waste_categories, referral_referrals, notification_outbox,
 *   audit_outbox, audit_events, report_summaries.
 *
 * For SYNAPSE, every existing row gets tenant_id = 1. The migration
 * is idempotent: re-runs are no-ops.
 */
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class TenantsAndTenantId extends Migration
{
    /** @var list<string> */
    private const DOMAIN_TABLES = [
        'persons',
        'patient_identifiers',
        'patients_students',
        'patients_employees',
        'patient_allergies',
        'patient_contacts',
        'clinic_encounters',
        'clinic_appointments',
        'clinic_queue_entries',
        'clinic_checkins',
        'clinic_treatments',
        'clinic_inventory',
        'clinic_medicines',
        'clinic_medicine_batches',
        'clinic_medicine_transactions',
        'clinic_reorder_requests',
        'clinic_staff_schedules',
        'clinic_triage_predictions',
        'clinic_medicine_forecasts',
        'clinic_departments',
        'counselling_sessions',
        'counselling_appointments',
        'counselling_scheduling_analytics',
        'facilities_bmg_units',
        'facilities_bmg_batches',
        'facilities_bmg_process_logs',
        'facilities_waste_categories',
        'referral_referrals',
        'notification_outbox',
        'audit_outbox',
        'audit_events',
        'report_summaries',
    ];

    public function up(): void
    {
        // 1. tenants table.
        if (! $this->db->tableExists('tenants')) {
            $this->forge->addField([
                'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
                'name'       => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
                'slug'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'is_active'  => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
                'created_at' => ['type' => 'DATETIME', 'null' => false],
                'updated_at' => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('slug');
            $this->forge->createTable('tenants');

            $now = date('Y-m-d H:i:s');
            $this->db->table('tenants')->insert([
                'id'         => 1,
                'name'       => 'Foundation University',
                'slug'       => 'foundation-university',
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 2. tenant_id on every domain table.
        foreach (self::DOMAIN_TABLES as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            if (! $this->db->fieldExists('tenant_id', $table)) {
                $this->forge->addColumn($table, [
                    'tenant_id' => [
                        'type'       => 'INT',
                        'unsigned'   => true,
                        'null'       => false,
                        'default'    => 1,
                        'after'      => 'id',
                    ],
                ]);
                $this->forge->addKey('tenant_id', false, false, 'idx_' . $table . '_tenant');
            }
        }
    }

    public function down(): void
    {
        foreach (self::DOMAIN_TABLES as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            if ($this->db->fieldExists('tenant_id', $table)) {
                $this->forge->dropColumn($table, 'tenant_id');
            }
        }
        if ($this->db->tableExists('tenants')) {
            $this->forge->dropTable('tenants', true);
        }
    }
}
