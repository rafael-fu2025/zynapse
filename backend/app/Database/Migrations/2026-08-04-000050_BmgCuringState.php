<?php

declare(strict_types=1);

namespace App\Database\Migrations;

/**
 * BmgCuringState — panel revision (August 2026):
 *
 * Industry treats curing as a distinct lifecycle phase: 1–3 months
 * of low-frequency monitoring after the thermophilic peak. Previously
 * the BMG state machine went `AwaitingOutput → Idle/Finished` in one
 * step, losing that distinction.
 *
 * This migration widens the `status` ENUMs on both `facilities_bmg_units`
 * and `facilities_bmg_batches` to add `'curing'`. The DB-side invariant
 * on `facilities_bmg_batches.active_unit_id` (generated column + UNIQUE
 * index that enforces "one active batch per unit") is also widened to
 * include `curing` so a unit cannot start a new batch while a prior one
 * is still curing.
 *
 * Pattern: drop the active_unit_id index + generated column → widen the
 * ENUM → recreate the column with the wider expression + index. This
 * mirrors the approach taken in `LowercaseStatusEnums`.
 *
 * Forward compat: VARCHAR(32) widen-then-narrow cycle means existing
 * rows need no data rewrite (the new value is purely additive).
 */
use CodeIgniter\Database\Migration;

final class BmgCuringState extends Migration
{
    public function up(): void
    {
        // ---- facilities_bmg_units: widen ENUM.
        if ($this->db->tableExists('facilities_bmg_units')) {
            $this->db->query('ALTER TABLE `facilities_bmg_units` MODIFY `status` VARCHAR(32) NOT NULL');
            $this->db->query(<<<'SQL'
                ALTER TABLE `facilities_bmg_units`
                    MODIFY `status`
                    ENUM('idle','processing','awaiting_output','curing','cancelled','maintenance')
                    NOT NULL DEFAULT 'idle'
            SQL);
        }

        // ---- facilities_bmg_batches: drop generated col + UNIQUE first.
        if ($this->db->tableExists('facilities_bmg_batches')) {
            // Drop the UNIQUE index and the generated column so we can
            // safely MODIFY the underlying status ENUM. The expression
            // names the active statuses — it must match the widened set.
            $this->db->query('ALTER TABLE `facilities_bmg_batches` DROP INDEX `active_unit_id`');
            $this->db->query('ALTER TABLE `facilities_bmg_batches` DROP COLUMN `active_unit_id`');

            $this->db->query('ALTER TABLE `facilities_bmg_batches` MODIFY `status` VARCHAR(32) NOT NULL');
            $this->db->query(<<<'SQL'
                ALTER TABLE `facilities_bmg_batches`
                    MODIFY `status`
                    ENUM('processing','awaiting_output','curing','idle','cancelled')
                    NOT NULL DEFAULT 'processing'
            SQL);

            // Recreate the generated column with `curing` included in
            // the "active" set, then the UNIQUE index that enforces it.
            $this->db->query(<<<'SQL'
                ALTER TABLE facilities_bmg_batches
                ADD COLUMN active_unit_id BIGINT UNSIGNED
                    GENERATED ALWAYS AS (
                        CASE WHEN status IN ('processing', 'awaiting_output', 'curing') THEN unit_id ELSE NULL END
                    ) STORED
            SQL);
            $this->db->query('CREATE UNIQUE INDEX `active_unit_id` ON `facilities_bmg_batches` (`active_unit_id`)');
        }
    }

    public function down(): void
    {
        // Reverse only what we changed: strip 'curing' rows back to
        // 'awaiting_output' (the closest upstream state), then narrow
        // the ENUMs and regenerate the invariant column.
        if ($this->db->tableExists('facilities_bmg_batches')) {
            $this->db->query('ALTER TABLE `facilities_bmg_batches` DROP INDEX `active_unit_id`');
            $this->db->query('ALTER TABLE `facilities_bmg_batches` DROP COLUMN `active_unit_id`');

            // Collapse curing rows before narrowing the ENUM, otherwise
            // the MODIFY ENUM below would fail with "Data truncated".
            $this->db->query("UPDATE `facilities_bmg_batches` SET `status` = 'awaiting_output' WHERE `status` = 'curing'");
            $this->db->query("UPDATE `facilities_bmg_units` SET `status` = 'awaiting_output' WHERE `status` = 'curing'");

            $this->db->query('ALTER TABLE `facilities_bmg_batches` MODIFY `status` VARCHAR(32) NOT NULL');
            $this->db->query(<<<'SQL'
                ALTER TABLE `facilities_bmg_batches`
                    MODIFY `status`
                    ENUM('processing','awaiting_output','idle','cancelled')
                    NOT NULL DEFAULT 'processing'
            SQL);

            $this->db->query(<<<'SQL'
                ALTER TABLE facilities_bmg_batches
                ADD COLUMN active_unit_id BIGINT UNSIGNED
                    GENERATED ALWAYS AS (
                        CASE WHEN status IN ('processing', 'awaiting_output') THEN unit_id ELSE NULL END
                    ) STORED
            SQL);
            $this->db->query('CREATE UNIQUE INDEX `active_unit_id` ON `facilities_bmg_batches` (`active_unit_id`)');
        }

        if ($this->db->tableExists('facilities_bmg_units')) {
            $this->db->query('ALTER TABLE `facilities_bmg_units` MODIFY `status` VARCHAR(32) NOT NULL');
            $this->db->query(<<<'SQL'
                ALTER TABLE `facilities_bmg_units`
                    MODIFY `status`
                    ENUM('idle','processing','awaiting_output','cancelled','maintenance')
                    NOT NULL DEFAULT 'idle'
            SQL);
        }
    }
}
