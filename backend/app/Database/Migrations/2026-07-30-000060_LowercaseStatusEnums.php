<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * LowercaseStatusEnums — panel revision (July 2026):
 *
 * Consistent lowercase field values system-wide. Status ENUMs that
 * predated the convention (PascalCase) are normalized to lowercase
 * snake_case; reorders / medicine batches / queue / counselling were
 * already lowercase and are untouched.
 *
 *   clinic_appointments   : scheduled, checked_in, completed, cancelled, no_show
 *   clinic_encounters     : open, closed, referred
 *   referral_referrals    : submitted, acknowledged, under_review, closed
 *   facilities_bmg_units  : idle, processing, awaiting_output, cancelled, maintenance
 *   facilities_bmg_batches: processing, awaiting_output, idle, cancelled
 *
 * MySQL rejects ENUMs whose members differ only by case (ci collation),
 * so each column is widened to VARCHAR, rewritten, then narrowed back
 * to the lowercase ENUM. `facilities_bmg_batches.active_unit_id` is a
 * STORED generated column whose expression names the old PascalCase
 * values — it is dropped (with its UNIQUE guard index) and recreated
 * with the lowercase expression.
 */
final class LowercaseStatusEnums extends Migration
{
    public function up(): void
    {
        // ---- clinic_appointments -----------------------------------
        $this->db->query('ALTER TABLE `clinic_appointments` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            UPDATE clinic_appointments SET status = CASE BINARY status
                WHEN 'CheckedIn' THEN 'checked_in'
                WHEN 'NoShow'    THEN 'no_show'
                ELSE LOWER(status)
            END
        SQL);
        $this->db->query(
            "ALTER TABLE `clinic_appointments` MODIFY `status`"
            . " ENUM('scheduled','checked_in','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled'"
        );

        // ---- clinic_encounters --------------------------------------
        $this->db->query('ALTER TABLE `clinic_encounters` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query('UPDATE clinic_encounters SET status = LOWER(status)');
        $this->db->query(
            "ALTER TABLE `clinic_encounters` MODIFY `status`"
            . " ENUM('open','closed','referred') NOT NULL DEFAULT 'open'"
        );

        // ---- referral_referrals -------------------------------------
        $this->db->query('ALTER TABLE `referral_referrals` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            UPDATE referral_referrals SET status = CASE BINARY status
                WHEN 'UnderReview' THEN 'under_review'
                ELSE LOWER(status)
            END
        SQL);
        $this->db->query(
            "ALTER TABLE `referral_referrals` MODIFY `status`"
            . " ENUM('submitted','acknowledged','under_review','closed') NOT NULL DEFAULT 'submitted'"
        );

        // ---- facilities_bmg_units -----------------------------------
        $this->db->query('ALTER TABLE `facilities_bmg_units` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            UPDATE facilities_bmg_units SET status = CASE BINARY status
                WHEN 'AwaitingOutput' THEN 'awaiting_output'
                ELSE LOWER(status)
            END
        SQL);
        $this->db->query(
            "ALTER TABLE `facilities_bmg_units` MODIFY `status`"
            . " ENUM('idle','processing','awaiting_output','cancelled','maintenance') NOT NULL DEFAULT 'idle'"
        );

        // ---- facilities_bmg_batches ---------------------------------
        // The generated `active_unit_id` column + UNIQUE index encode the
        // old PascalCase values; rebuild them around the status rewrite.
        $this->db->query('ALTER TABLE `facilities_bmg_batches` DROP INDEX `active_unit_id`');
        $this->db->query('ALTER TABLE `facilities_bmg_batches` DROP COLUMN `active_unit_id`');

        $this->db->query('ALTER TABLE `facilities_bmg_batches` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            UPDATE facilities_bmg_batches SET status = CASE BINARY status
                WHEN 'AwaitingOutput' THEN 'awaiting_output'
                ELSE LOWER(status)
            END
        SQL);
        $this->db->query(
            "ALTER TABLE `facilities_bmg_batches` MODIFY `status`"
            . " ENUM('processing','awaiting_output','idle','cancelled') NOT NULL DEFAULT 'processing'"
        );

        $this->db->query(<<<'SQL'
            ALTER TABLE facilities_bmg_batches
            ADD COLUMN active_unit_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN status IN ('processing', 'awaiting_output') THEN unit_id ELSE NULL END
                ) STORED
        SQL);
        $this->db->query('CREATE UNIQUE INDEX `active_unit_id` ON `facilities_bmg_batches` (`active_unit_id`)');
    }

    public function down(): void
    {
        // ---- facilities_bmg_batches ---------------------------------
        $this->db->query('ALTER TABLE `facilities_bmg_batches` DROP INDEX `active_unit_id`');
        $this->db->query('ALTER TABLE `facilities_bmg_batches` DROP COLUMN `active_unit_id`');
        $this->db->query('ALTER TABLE `facilities_bmg_batches` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            UPDATE facilities_bmg_batches SET status = CASE BINARY status
                WHEN 'awaiting_output' THEN 'AwaitingOutput'
                WHEN 'processing'      THEN 'Processing'
                WHEN 'idle'            THEN 'Idle'
                WHEN 'cancelled'       THEN 'Cancelled'
                ELSE status
            END
        SQL);
        $this->db->query(
            "ALTER TABLE `facilities_bmg_batches` MODIFY `status`"
            . " ENUM('Processing','AwaitingOutput','Idle','Cancelled') NOT NULL DEFAULT 'Processing'"
        );
        $this->db->query(<<<'SQL'
            ALTER TABLE facilities_bmg_batches
            ADD COLUMN active_unit_id BIGINT UNSIGNED
                GENERATED ALWAYS AS (
                    CASE WHEN status IN ('Processing', 'AwaitingOutput') THEN unit_id ELSE NULL END
                ) STORED
        SQL);
        $this->db->query('CREATE UNIQUE INDEX `active_unit_id` ON `facilities_bmg_batches` (`active_unit_id`)');

        // ---- facilities_bmg_units -----------------------------------
        $this->db->query('ALTER TABLE `facilities_bmg_units` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            UPDATE facilities_bmg_units SET status = CASE BINARY status
                WHEN 'awaiting_output' THEN 'AwaitingOutput'
                WHEN 'processing'      THEN 'Processing'
                WHEN 'idle'            THEN 'Idle'
                WHEN 'cancelled'       THEN 'Cancelled'
                WHEN 'maintenance'     THEN 'Maintenance'
                ELSE status
            END
        SQL);
        $this->db->query(
            "ALTER TABLE `facilities_bmg_units` MODIFY `status`"
            . " ENUM('Idle','Processing','AwaitingOutput','Cancelled','Maintenance') NOT NULL DEFAULT 'Idle'"
        );

        // ---- referral_referrals -------------------------------------
        $this->db->query('ALTER TABLE `referral_referrals` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            UPDATE referral_referrals SET status = CASE BINARY status
                WHEN 'submitted'    THEN 'Submitted'
                WHEN 'acknowledged' THEN 'Acknowledged'
                WHEN 'under_review' THEN 'UnderReview'
                WHEN 'closed'       THEN 'Closed'
                ELSE status
            END
        SQL);
        $this->db->query(
            "ALTER TABLE `referral_referrals` MODIFY `status`"
            . " ENUM('Submitted','Acknowledged','UnderReview','Closed') NOT NULL DEFAULT 'Submitted'"
        );

        // ---- clinic_encounters --------------------------------------
        $this->db->query('ALTER TABLE `clinic_encounters` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            UPDATE clinic_encounters SET status = CASE BINARY status
                WHEN 'open'     THEN 'Open'
                WHEN 'closed'   THEN 'Closed'
                WHEN 'referred' THEN 'Referred'
                ELSE status
            END
        SQL);
        $this->db->query(
            "ALTER TABLE `clinic_encounters` MODIFY `status`"
            . " ENUM('Open','Closed','Referred') NOT NULL DEFAULT 'Open'"
        );

        // ---- clinic_appointments ------------------------------------
        $this->db->query('ALTER TABLE `clinic_appointments` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query(<<<'SQL'
            UPDATE clinic_appointments SET status = CASE BINARY status
                WHEN 'scheduled'  THEN 'Scheduled'
                WHEN 'checked_in' THEN 'CheckedIn'
                WHEN 'completed'  THEN 'Completed'
                WHEN 'cancelled'  THEN 'Cancelled'
                WHEN 'no_show'    THEN 'NoShow'
                ELSE status
            END
        SQL);
        $this->db->query(
            "ALTER TABLE `clinic_appointments` MODIFY `status`"
            . " ENUM('Scheduled','CheckedIn','Completed','Cancelled','NoShow') NOT NULL DEFAULT 'Scheduled'"
        );
    }
}
