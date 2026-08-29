<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Link newly created referrals to the clinical workflow that issued them. */
final class ReferralSourceContext extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('referral_referrals', [
            'source_encounter_id' => [
                'type' => 'BIGINT', 'unsigned' => true, 'null' => true,
                'after' => 'patient_school_id',
            ],
            'source_session_id' => [
                'type' => 'BIGINT', 'unsigned' => true, 'null' => true,
                'after' => 'source_encounter_id',
            ],
        ]);
        $this->db->query('CREATE INDEX `idx_referral_source_encounter` ON `referral_referrals` (`source_encounter_id`, `created_at`)');
        $this->db->query('CREATE INDEX `idx_referral_source_session` ON `referral_referrals` (`source_session_id`, `created_at`)');
        $this->db->query('ALTER TABLE `referral_referrals` ADD CONSTRAINT `fk_referral_source_encounter` FOREIGN KEY (`source_encounter_id`) REFERENCES `clinic_encounters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE');
        $this->db->query('ALTER TABLE `referral_referrals` ADD CONSTRAINT `fk_referral_source_session` FOREIGN KEY (`source_session_id`) REFERENCES `counselling_sessions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE');
    }

    public function down(): void
    {
        $linked = (int) $this->db->table('referral_referrals')
            ->groupStart()->where('source_encounter_id IS NOT NULL', null, false)
            ->orWhere('source_session_id IS NOT NULL', null, false)->groupEnd()
            ->countAllResults();
        if ($linked > 0) {
            throw new \RuntimeException("Cannot remove referral source context while {$linked} linked referral(s) exist.");
        }
        $this->db->query('ALTER TABLE `referral_referrals` DROP FOREIGN KEY `fk_referral_source_encounter`');
        $this->db->query('ALTER TABLE `referral_referrals` DROP FOREIGN KEY `fk_referral_source_session`');
        $this->db->query('DROP INDEX `idx_referral_source_encounter` ON `referral_referrals`');
        $this->db->query('DROP INDEX `idx_referral_source_session` ON `referral_referrals`');
        $this->forge->dropColumn('referral_referrals', ['source_encounter_id', 'source_session_id']);
    }
}
