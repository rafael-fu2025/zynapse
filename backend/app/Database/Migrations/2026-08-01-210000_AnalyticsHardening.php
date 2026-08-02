<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Analytics query indexes and generated-report lifecycle metadata. */
final class AnalyticsHardening extends Migration
{
    public function up(): void
    {
        $this->db->query('CREATE INDEX `idx_ce_report_created` ON `clinic_encounters` (`created_at`, `archived_at`, `status`)');
        $this->db->query('CREATE INDEX `idx_cc_report_scanned` ON `clinic_checkins` (`scanned_at`, `outcome`)');
        $this->db->query('CREATE INDEX `idx_ca_report_date` ON `counselling_appointments` (`appointment_date`, `status`, `type`)');
        $this->db->query('CREATE INDEX `idx_cs_report_started` ON `counselling_sessions` (`started_at`)');
        $this->db->query('CREATE INDEX `idx_cmt_report_created` ON `clinic_medicine_transactions` (`created_at`, `type`, `medicine_id`)');
        $this->db->query('CREATE INDEX `idx_rr_report_created` ON `referral_referrals` (`created_at`, `archived_at`, `status`)');
        $this->db->query('CREATE INDEX `idx_fbb_report_finished` ON `facilities_bmg_batches` (`finished_at`, `archived_at`)');

        $this->forge->addColumn('generated_reports', [
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'null' => false,
                'default' => 'completed',
                'after' => 'format',
            ],
            'error_message' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'ai_summary'],
            'started_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'generated_at'],
            'completed_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'started_at'],
            'expires_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'completed_at'],
        ]);
        $this->db->query('UPDATE `generated_reports` SET `completed_at` = `generated_at`, `expires_at` = DATE_ADD(`generated_at`, INTERVAL 30 DAY) WHERE `completed_at` IS NULL');
        $this->db->query('CREATE INDEX `idx_gr_status_created` ON `generated_reports` (`status`, `created_at`, `id`)');
        $this->db->query('CREATE INDEX `idx_gr_expires` ON `generated_reports` (`expires_at`)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX `idx_gr_expires` ON `generated_reports`');
        $this->db->query('DROP INDEX `idx_gr_status_created` ON `generated_reports`');
        $this->forge->dropColumn('generated_reports', ['status', 'error_message', 'started_at', 'completed_at', 'expires_at']);

        $this->db->query('DROP INDEX `idx_fbb_report_finished` ON `facilities_bmg_batches`');
        $this->db->query('DROP INDEX `idx_rr_report_created` ON `referral_referrals`');
        $this->db->query('DROP INDEX `idx_cmt_report_created` ON `clinic_medicine_transactions`');
        $this->db->query('DROP INDEX `idx_cs_report_started` ON `counselling_sessions`');
        $this->db->query('DROP INDEX `idx_ca_report_date` ON `counselling_appointments`');
        $this->db->query('DROP INDEX `idx_cc_report_scanned` ON `clinic_checkins`');
        $this->db->query('DROP INDEX `idx_ce_report_created` ON `clinic_encounters`');
    }
}
