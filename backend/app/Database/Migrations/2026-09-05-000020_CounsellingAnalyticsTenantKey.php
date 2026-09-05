<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CounsellingAnalyticsTenantKey — tenant-scopes the analytics unique key
 * (counselling audit 2026-09-03, F5).
 *
 * `counselling_scheduling_analytics` had `tenant_id` added by
 * `TenantsAndTenantId` (DEFAULT 1), but:
 *   1. The unique key was (`counsellor_user_id`, `day_of_week`, `time_slot`)
 *      without `tenant_id`, causing collisions across tenants on recompute.
 *   2. `addKey('tenant_id')` ran after `createTable()`, so no index existed.
 *
 * Replaces the unique constraint with (`tenant_id`, `counsellor_user_id`,
 * `day_of_week`, `time_slot`) and adds `idx_counselling_analytics_tenant`.
 */
final class CounsellingAnalyticsTenantKey extends Migration
{
    public function up(): void
    {
        // 1. Add standalone index on counsellor_user_id first so MySQL's FK
        // constraint (counselling_scheduling_analytics_counsellor_user_id_foreign)
        // remains backed while the old multi-column unique key is swapped.
        $this->db->query(
            'ALTER TABLE `counselling_scheduling_analytics` '
            . 'ADD INDEX `idx_counselling_analytics_counsellor` (`counsellor_user_id`)'
        );

        // 2. Add tenant-scoped unique key.
        $this->db->query(
            'ALTER TABLE `counselling_scheduling_analytics` '
            . 'ADD UNIQUE KEY `uq_counselling_analytics_tenant_slot` '
            . '(`tenant_id`, `counsellor_user_id`, `day_of_week`, `time_slot`)'
        );

        // 3. Add standalone tenant index.
        $this->db->query(
            'ALTER TABLE `counselling_scheduling_analytics` '
            . 'ADD INDEX `idx_counselling_analytics_tenant` (`tenant_id`)'
        );

        // 4. Now safe to drop legacy non-tenant unique key.
        $this->db->query(
            'ALTER TABLE `counselling_scheduling_analytics` '
            . 'DROP INDEX `counsellor_user_id_day_of_week_time_slot`'
        );
    }

    public function down(): void
    {
        $this->db->query(
            'ALTER TABLE `counselling_scheduling_analytics` '
            . 'ADD UNIQUE KEY `counsellor_user_id_day_of_week_time_slot` '
            . '(`counsellor_user_id`, `day_of_week`, `time_slot`)'
        );
        $this->db->query('ALTER TABLE `counselling_scheduling_analytics` DROP INDEX `idx_counselling_analytics_counsellor`');
        $this->db->query('ALTER TABLE `counselling_scheduling_analytics` DROP INDEX `idx_counselling_analytics_tenant`');
        $this->db->query('ALTER TABLE `counselling_scheduling_analytics` DROP INDEX `uq_counselling_analytics_tenant_slot`');
    }
}
