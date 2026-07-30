<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ReferralProvider — panel revision (July 2026):
 *
 * Referral workflows need a defined "provider" concept: the staff
 * member who HANDLES the referral on the receiving side (nurse,
 * counsellor, …) — distinct from `issuer_user_id`, the person who
 * raised it. Nullable: a freshly submitted referral has no provider
 * until someone on the target module acknowledges it.
 */
final class ReferralProvider extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('referral_referrals', [
            'provider_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'issuer_user_id'],
        ]);
        $this->db->query(
            'ALTER TABLE `referral_referrals`'
            . ' ADD CONSTRAINT `fk_rr_provider` FOREIGN KEY (`provider_user_id`)'
            . ' REFERENCES `users`(`id`) ON DELETE RESTRICT'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE `referral_referrals` DROP FOREIGN KEY `fk_rr_provider`');
        $this->forge->dropColumn('referral_referrals', 'provider_user_id');
    }
}
