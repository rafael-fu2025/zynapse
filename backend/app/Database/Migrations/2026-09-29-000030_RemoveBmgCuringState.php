<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * RemoveBmgCuringState — retires the `curing` lifecycle state.
 *
 * The operator-facing transition (`POST /facilities/batches/{id}/curing`)
 * was removed from the UI, leaving the state unreachable while it stayed
 * wired into the enums, the invariant column and the alert engine. This
 * migration takes it out of the domain.
 *
 * Lifecycle after this migration:
 *
 *   idle → processing → awaiting_output → released
 *                      └─→ cancelled        (from any active state)
 *
 * Data mapping: `curing` → `awaiting_output`. `moveToCuring` required
 * `awaiting_output` as its precondition, so that is the exact state those
 * rows were in immediately before the transition, and it leaves them in a
 * state the operator can still Finish or Cancel. Rows moved this way are
 * NOT recoverable — `down()` restores the schema only.
 *
 * `facilities_bmg_batch_updates` is deliberately untouched: its
 * `update_type` ENUM keeps the `curing` member (and `curing_note` its
 * column) so the historical, append-only ledger stays readable. The
 * backend simply stops writing new `curing` entries.
 */
final class RemoveBmgCuringState extends Migration
{
    /** Backfill target for rows parked in the retired state. */
    private const RETIRED = 'curing';

    private const REPLACEMENT = 'awaiting_output';

    public function up(): void
    {
        $this->backfill();
        $this->narrowBatches();
        $this->narrowUnits();
    }

    public function down(): void
    {
        $this->widenBatches();
        $this->widenUnits();
    }

    /**
     * Retire the rows BEFORE the ENUM loses the member — MySQL would
     * otherwise coerce them to '' (the empty enum member).
     */
    private function backfill(): void
    {
        if ($this->db->tableExists('facilities_bmg_batches')) {
            $this->db->table('facilities_bmg_batches')
                ->where('status', self::RETIRED)
                ->update(['status' => self::REPLACEMENT]);
        }

        if ($this->db->tableExists('facilities_bmg_units')) {
            $this->db->table('facilities_bmg_units')
                ->where('status', self::RETIRED)
                ->update(['status' => self::REPLACEMENT]);
        }
    }

    /**
     * The generated column's expression names the active statuses, so it
     * (and its UNIQUE index — the at-most-one-batch-per-drum guarantee)
     * must be dropped before `status` can be retyped, and recreated the
     * moment it can. Never leave the table in between.
     */
    private function narrowBatches(): void
    {
        if (! $this->db->tableExists('facilities_bmg_batches')) {
            return;
        }

        $this->db->query('ALTER TABLE `facilities_bmg_batches` DROP INDEX `active_unit_id`');
        $this->db->query('ALTER TABLE `facilities_bmg_batches` DROP COLUMN `active_unit_id`');

        $this->db->query('ALTER TABLE `facilities_bmg_batches` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            ALTER TABLE `facilities_bmg_batches`
                MODIFY `status`
                ENUM('processing','awaiting_output','idle','cancelled','released')
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

    private function narrowUnits(): void
    {
        if (! $this->db->tableExists('facilities_bmg_units')) {
            return;
        }

        $this->db->query('ALTER TABLE `facilities_bmg_units` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            ALTER TABLE `facilities_bmg_units`
                MODIFY `status`
                ENUM('idle','processing','awaiting_output','cancelled','maintenance')
                NOT NULL DEFAULT 'idle'
        SQL);
    }

    /**
     * Reinstates the retired state for a rollback. Rows already
     * rewritten by `backfill()` stay at `awaiting_output` — the original
     * distribution is not reconstructible without a restore.
     */
    private function widenBatches(): void
    {
        if (! $this->db->tableExists('facilities_bmg_batches')) {
            return;
        }

        $this->db->query('ALTER TABLE `facilities_bmg_batches` DROP INDEX `active_unit_id`');
        $this->db->query('ALTER TABLE `facilities_bmg_batches` DROP COLUMN `active_unit_id`');

        $this->db->query('ALTER TABLE `facilities_bmg_batches` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            ALTER TABLE `facilities_bmg_batches`
                MODIFY `status`
                ENUM('processing','awaiting_output','curing','idle','cancelled','released')
                NOT NULL DEFAULT 'processing'
        SQL);

        $this->db->query(<<<'SQL'
            ALTER TABLE facilities_bmg_batches
            ADD COLUMN active_unit_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN status IN ('processing', 'awaiting_output', 'curing') THEN unit_id ELSE NULL END
                ) STORED
        SQL);
        $this->db->query('CREATE UNIQUE INDEX `active_unit_id` ON `facilities_bmg_batches` (`active_unit_id`)');
    }

    private function widenUnits(): void
    {
        if (! $this->db->tableExists('facilities_bmg_units')) {
            return;
        }

        $this->db->query('ALTER TABLE `facilities_bmg_units` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            ALTER TABLE `facilities_bmg_units`
                MODIFY `status`
                ENUM('idle','processing','awaiting_output','curing','cancelled','maintenance')
                NOT NULL DEFAULT 'idle'
        SQL);
    }
}
