<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * DropReferralQrColumns — removes the referral QR hand-off feature.
 *
 * The per-referral QR token (issue → print → scan-verify) is retired:
 * nothing in the referral lifecycle consumed it, and the queue handoff
 * plus the booking path cover the physical hand-off. `qr_token_hash`
 * (HMAC of the one-time token), `qr_expires_at`, and `qr_revoked_at`
 * are dropped along with the UNIQUE index on the hash. The columns were
 * nullable from day one, so no data migration is needed — dropping them
 * cannot break an existing row.
 *
 * The appointment QR feature (clinic visits) keeps its own columns and
 * its `APPOINTMENT_HMAC_KEY` fallback to `REFERRAL_HMAC_KEY`.
 */
final class DropReferralQrColumns extends Migration
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const COLUMNS = [
        'qr_token_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
        'qr_expires_at' => ['type' => 'DATETIME', 'null' => true],
        'qr_revoked_at' => ['type' => 'DATETIME', 'null' => true],
    ];

    public function up(): void
    {
        if (! $this->db->tableExists('referral_referrals')) {
            return;
        }

        // The single-column UNIQUE index is dropped explicitly so the
        // intent stays visible; dropping the column alone would remove
        // it implicitly on MariaDB.
        if ($this->indexExists('referral_referrals', 'qr_token_hash')) {
            $this->forge->dropKey('referral_referrals', 'qr_token_hash');
        }

        foreach (array_keys(self::COLUMNS) as $col) {
            if ($this->db->fieldExists($col, 'referral_referrals')) {
                $this->forge->dropColumn('referral_referrals', $col);
            }
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('referral_referrals')) {
            return;
        }

        foreach (self::COLUMNS as $col => $def) {
            if (! $this->db->fieldExists($col, 'referral_referrals')) {
                $this->forge->addColumn('referral_referrals', [$col => $def]);
            }
        }

        if (! $this->indexExists('referral_referrals', 'qr_token_hash')) {
            $this->db->query('ALTER TABLE `referral_referrals` ADD UNIQUE INDEX `qr_token_hash` (`qr_token_hash`)');
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = $this->db->query("
            SELECT INDEX_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$table}'
              AND INDEX_NAME = '{$indexName}'
        ")->getRowArray();
        return $row !== null;
    }
}
