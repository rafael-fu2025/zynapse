<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ReferralPriority — clinic staff flag how urgently a referred student
 * needs Guidance attention (visibility/sorting only; it never changes
 * the referral state machine or auto-creates queue entries).
 */
final class ReferralPriority extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('priority', 'referral_referrals')) {
            $this->forge->addColumn('referral_referrals', [
                'priority' => [
                    'type'       => 'ENUM',
                    'constraint' => ['low', 'normal', 'high', 'urgent'],
                    'null'       => false,
                    'default'    => 'normal',
                    'after'      => 'status',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('priority', 'referral_referrals')) {
            $this->forge->dropColumn('referral_referrals', 'priority');
        }
    }
}
