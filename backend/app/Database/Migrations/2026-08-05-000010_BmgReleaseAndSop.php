<?php

declare(strict_types=1);

namespace App\Database\Migrations;

/**
 * BmgReleaseAndSop — facilities audit fixes (August 2026).
 *
 * Adds the terminal `released` batch state + final quality/maturity
 * gate fields, a process-log `event_type` (turning/aeration actions),
 * and a training/SOP register.
 *
 *   1. `facilities_bmg_batches`:
 *        - widen `status` ENUM with `'released'`
 *        - `released_at`, `released_by_user_id`
 *        - `quality_grade`   VARCHAR(16) — BMG_QUALITY_GRADES
 *        - `maturity_level`  VARCHAR(16) — BMG_MATURITY_LEVELS
 *   2. `facilities_bmg_process_logs`: `event_type` VARCHAR(24)
 *      (observation / turning / aeration / moisture_adjustment /
 *      other) so operators record the action, not just a note.
 *   3. New `facilities_sop_documents` — training/SOP register
 *      (title, version, category, document_ref, owner, active).
 *
 * The `active_unit_id` generated column + UNIQUE index must be
 * dropped/recreated around the ENUM widen (same pattern as
 * `BmgCuringState`). A `released` batch is terminal (NOT active),
 * so it is excluded from the generated column expression.
 */
use CodeIgniter\Database\Migration;

final class BmgReleaseAndSop extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        // ------------------------------------------------------ batches
        if ($db->tableExists('facilities_bmg_batches')) {
            $db->query('ALTER TABLE `facilities_bmg_batches` DROP INDEX `active_unit_id`');
            $db->query('ALTER TABLE `facilities_bmg_batches` DROP COLUMN `active_unit_id`');

            $db->query('ALTER TABLE `facilities_bmg_batches` MODIFY `status` VARCHAR(32) NOT NULL');
            $db->query(<<<'SQL'
                ALTER TABLE `facilities_bmg_batches`
                    MODIFY `status`
                    ENUM('processing','awaiting_output','curing','idle','cancelled','released')
                    NOT NULL DEFAULT 'processing'
            SQL);

            if (! $db->fieldExists('released_at', 'facilities_bmg_batches')) {
                $db->query('ALTER TABLE `facilities_bmg_batches` ADD COLUMN `released_at` DATETIME NULL DEFAULT NULL AFTER `cancelled_at`');
            }
            if (! $db->fieldExists('released_by_user_id', 'facilities_bmg_batches')) {
                $db->query('ALTER TABLE `facilities_bmg_batches` ADD COLUMN `released_by_user_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `released_at`');
            }
            if (! $db->fieldExists('quality_grade', 'facilities_bmg_batches')) {
                $db->query('ALTER TABLE `facilities_bmg_batches` ADD COLUMN `quality_grade` VARCHAR(16) NULL DEFAULT NULL AFTER `released_by_user_id`');
            }
            if (! $db->fieldExists('maturity_level', 'facilities_bmg_batches')) {
                $db->query('ALTER TABLE `facilities_bmg_batches` ADD COLUMN `maturity_level` VARCHAR(16) NULL DEFAULT NULL AFTER `quality_grade`');
            }

            // Recreate the invariant column — `released` is terminal so it
            // stays OUT of the "active" set.
            $db->query(<<<'SQL'
                ALTER TABLE facilities_bmg_batches
                ADD COLUMN active_unit_id BIGINT UNSIGNED
                    GENERATED ALWAYS AS (
                        CASE WHEN status IN ('processing', 'awaiting_output', 'curing') THEN unit_id ELSE NULL END
                    ) STORED
            SQL);
            $db->query('CREATE UNIQUE INDEX `active_unit_id` ON `facilities_bmg_batches` (`active_unit_id`)');

            // FK for released_by + checks (idempotent guard for reruns).
            $db->query('ALTER TABLE `facilities_bmg_batches` ADD CONSTRAINT `facilities_bmg_batches_released_by_user_id_foreign` FOREIGN KEY (`released_by_user_id`) REFERENCES `users` (`id`)');
        }

        // --------------------------------------------- process log events
        if ($db->tableExists('facilities_bmg_process_logs')
            && ! $db->fieldExists('event_type', 'facilities_bmg_process_logs')) {
            $db->query('ALTER TABLE `facilities_bmg_process_logs` ADD COLUMN `event_type` VARCHAR(24) NULL DEFAULT NULL AFTER `log_date`');
        }

        // --------------------------------------------------- SOP register
        if (! $db->tableExists('facilities_sop_documents')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
                'title' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
                'document_ref' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'category' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'version' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'owner_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'notes' => ['type' => 'TEXT', 'null' => true],
                'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'created_at' => ['type' => 'DATETIME', 'null' => false],
                'updated_at' => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('tenant_id');
            $this->forge->addKey('document_ref');
            $this->forge->createTable('facilities_sop_documents');
            $db->query('ALTER TABLE `facilities_sop_documents` ADD CONSTRAINT `facilities_sop_documents_owner_user_id_foreign` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`)');
        }
    }

    public function down(): void
    {
        $db = $this->db;

        if ($db->tableExists('facilities_sop_documents')) {
            $db->query('ALTER TABLE `facilities_sop_documents` DROP FOREIGN KEY `facilities_sop_documents_owner_user_id_foreign`');
            $this->forge->dropTable('facilities_sop_documents', true);
        }

        if ($db->tableExists('facilities_bmg_process_logs') && $db->fieldExists('event_type', 'facilities_bmg_process_logs')) {
            $db->query('ALTER TABLE `facilities_bmg_process_logs` DROP COLUMN `event_type`');
        }

        if ($db->tableExists('facilities_bmg_batches')) {
            $db->query('ALTER TABLE `facilities_bmg_batches` DROP INDEX `active_unit_id`');
            $db->query('ALTER TABLE `facilities_bmg_batches` DROP COLUMN `active_unit_id`');

            // Collapse released rows back to awaiting_output before narrowing.
            $db->query("UPDATE `facilities_bmg_batches` SET `status` = 'awaiting_output' WHERE `status` = 'released'");

            $db->query('ALTER TABLE `facilities_bmg_batches` MODIFY `status` VARCHAR(32) NOT NULL');
            $db->query(<<<'SQL'
                ALTER TABLE `facilities_bmg_batches`
                    MODIFY `status`
                    ENUM('processing','awaiting_output','curing','idle','cancelled')
                    NOT NULL DEFAULT 'processing'
            SQL);

            foreach (['released_by_user_id', 'released_at', 'quality_grade', 'maturity_level'] as $col) {
                if ($db->fieldExists($col, 'facilities_bmg_batches')) {
                    $db->query("ALTER TABLE `facilities_bmg_batches` DROP COLUMN `{$col}`");
                }
            }

            $db->query(<<<'SQL'
                ALTER TABLE facilities_bmg_batches
                ADD COLUMN active_unit_id BIGINT UNSIGNED
                    GENERATED ALWAYS AS (
                        CASE WHEN status IN ('processing', 'awaiting_output', 'curing') THEN unit_id ELSE NULL END
                    ) STORED
            SQL);
            $db->query('CREATE UNIQUE INDEX `active_unit_id` ON `facilities_bmg_batches` (`active_unit_id`)');
        }
    }
}
