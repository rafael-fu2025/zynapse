<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Referrals — the bridge contract between clinic and counselling modules.
 *
 * Strict isolation rule:
 *   - This table is the ONLY thing that may reference both `clinic_*`
 *     and `counselling_*` entities. It does so by `patient_school_id`
 *     (a non-PII opaque identifier) and by source/target module kinds.
 *   - No SQL JOIN of `clinic_encounters` with `counselling_records` is
 *     ever permitted. This schema codifies that boundary.
 *
 * QR tokens:
 *   - Generated with 128-bit CSPRNG; only the keyed HMAC-SHA256 hash is
 *     persisted. Verifier looks up by hash and returns minimum-disclosure
 *     status only.
 */
final class Referrals extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'patient_school_id'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'source_module'       => ['type' => 'ENUM', 'constraint' => ['clinic','counselling'], 'null' => false],
            'target_module'       => ['type' => 'ENUM', 'constraint' => ['clinic','counselling'], 'null' => false],
            'artifact_type'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'issuer_user_id'      => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'status'              => [
                'type'       => 'ENUM',
                'constraint' => [
                    REFERRAL_STATUS_SUBMITTED,
                    REFERRAL_STATUS_ACKNOWLEDGED,
                    REFERRAL_STATUS_UNDER_REVIEW,
                    REFERRAL_STATUS_CLOSED,
                ],
                'null'       => false,
                'default'    => REFERRAL_STATUS_SUBMITTED,
            ],
            'reason_code'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'notes_cipher'        => ['type' => 'VARBINARY', 'constraint' => 8192, 'null' => true],
            'notes_nonce'         => ['type' => 'BINARY',    'constraint' => 12,    'null' => true],
            'notes_key_version'   => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'qr_token_hash'       => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'qr_expires_at'       => ['type' => 'DATETIME', 'null' => true],
            'qr_revoked_at'       => ['type' => 'DATETIME', 'null' => true],
            'created_at'          => ['type' => 'DATETIME', 'null' => false],
            'updated_at'          => ['type' => 'DATETIME', 'null' => false],
            'archived_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('qr_token_hash');
        $this->forge->addKey(['patient_school_id', 'status']);
        $this->forge->addKey(['source_module', 'target_module']);
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('issuer_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('referral_referrals');
    }

    public function down(): void
    {
        $this->forge->dropTable('referral_referrals', true);
    }
}