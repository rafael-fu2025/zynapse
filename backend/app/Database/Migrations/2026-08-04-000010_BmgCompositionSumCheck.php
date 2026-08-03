<?php

declare(strict_types=1);

namespace App\Database\Migrations;

/**
 * BmgCompositionSumCheck — panel revision (August 2026):
 *
 * Defense-in-depth guard for the mass-balance invariant that links
 * a batch's `total_input_weight_kg` to the SUM of its composition
 * rows in `facilities_bmg_composition`. The application layer
 * (`BmgService::startBatch`) already enforces a ±0.01 tolerance when
 * the batch row is written, but it has no opportunity to verify the
 * composition rows once they're inserted individually afterwards.
 *
 * This migration adds BEFORE INSERT / UPDATE triggers on
 * `facilities_bmg_composition` that:
 *
 *   1. Read the parent batch's `total_input_weight_kg`.
 *   2. Compute the SUM(weight_kg) of all existing composition rows
 *      for the same `batch_id`.
 *   3. Compute the post-row SUM including the NEW row (or the NEW
 *      row weight on UPDATE).
 *   4. Raise SQLSTATE 45000 if the post-row SUM differs from the
 *      batch's `total_input_weight_kg` by more than 0.01 kg.
 *
 * The 0.01 kg tolerance matches the application-layer rule so the
 * two layers agree on the rounding policy.
 *
 * Idempotent: re-running drops and re-creates the triggers.
 */
use CodeIgniter\Database\Migration;

final class BmgCompositionSumCheck extends Migration
{
    /**
     * Tolerant floating-point comparison: |a - b| <= tolerance.
     * DECIMAL(10,2) inputs — keep tolerance as a literal so SQL
     * syntax stays portable across MySQL/MariaDB.
     */
    private const TOLERANCE_KG = '0.01';

    public function up(): void
    {
        // BEFORE INSERT: reject a composition row that would push the
        // running SUM beyond (total_input + tolerance).
        $this->db->query(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_fbc_sum_check_ins;
        SQL);
        $this->db->query(<<<SQL
            CREATE TRIGGER trg_fbc_sum_check_ins
            BEFORE INSERT ON facilities_bmg_composition
            FOR EACH ROW
            BEGIN
                DECLARE v_batch_total DECIMAL(10,2);
                DECLARE v_running_sum DECIMAL(10,2);
                DECLARE v_post_sum    DECIMAL(10,2);

                -- Skip check for legacy rows written before this trigger
                -- existed; tenant mismatch on parent is also caught here.
                SELECT total_input_weight_kg
                  INTO v_batch_total
                  FROM facilities_bmg_batches
                 WHERE id = NEW.batch_id;

                IF v_batch_total IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'BMG composition: parent batch not found';
                END IF;

                SELECT COALESCE(SUM(weight_kg), 0)
                  INTO v_running_sum
                  FROM facilities_bmg_composition
                 WHERE batch_id = NEW.batch_id;

                SET v_post_sum = v_running_sum + NEW.weight_kg;

                IF ABS(v_post_sum - v_batch_total) > {$this->tolerance()} THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'BMG composition sum exceeds batch total_input_weight_kg (tolerance 0.01 kg)';
                END IF;
            END
        SQL);

        // BEFORE UPDATE: catch weight edits that would push the sum
        // out of bounds. Uses (running_sum - OLD + NEW) to project.
        $this->db->query(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_fbc_sum_check_upd;
        SQL);
        $this->db->query(<<<SQL
            CREATE TRIGGER trg_fbc_sum_check_upd
            BEFORE UPDATE ON facilities_bmg_composition
            FOR EACH ROW
            BEGIN
                DECLARE v_batch_total DECIMAL(10,2);
                DECLARE v_running_sum DECIMAL(10,2);
                DECLARE v_post_sum    DECIMAL(10,2);

                SELECT total_input_weight_kg
                  INTO v_batch_total
                  FROM facilities_bmg_batches
                 WHERE id = NEW.batch_id;

                IF v_batch_total IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'BMG composition: parent batch not found';
                END IF;

                SELECT COALESCE(SUM(weight_kg), 0)
                  INTO v_running_sum
                  FROM facilities_bmg_composition
                 WHERE batch_id = NEW.batch_id;

                SET v_post_sum = v_running_sum - OLD.weight_kg + NEW.weight_kg;

                IF ABS(v_post_sum - v_batch_total) > {$this->tolerance()} THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'BMG composition sum exceeds batch total_input_weight_kg (tolerance 0.01 kg)';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS trg_fbc_sum_check_ins');
        $this->db->query('DROP TRIGGER IF EXISTS trg_fbc_sum_check_upd');
    }

    /**
     * Inline the tolerance as a DECIMAL literal in the trigger body.
     * Kept on the class constant for documentation; SQL has no
     * variable interpolation so we re-quote it for the heredoc.
     */
    private function tolerance(): string
    {
        return self::TOLERANCE_KG;
    }
}
