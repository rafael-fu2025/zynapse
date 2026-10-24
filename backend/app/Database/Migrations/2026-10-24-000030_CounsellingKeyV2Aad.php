<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CounsellingKeyV2Aad — introduces key version 2: same key-management
 * model, envelopes bound to their row via AES-GCM AAD (2026-10 audit G-8).
 *
 * v2 envelopes bind "{table}|{entity_id}|{tenant_id}" as additional
 * authenticated data, so a ciphertext relocated between rows or tenants
 * fails to decrypt even under the correct key.
 *
 * Rollout (see backend/README.md "Field encryption"):
 *   1. Set COUNSELLING_KEY_V2 in env — same 64-hex value as
 *      COUNSELLING_KEY to adopt AAD with existing material, or fresh
 *      material to also rotate the key.
 *   2. Flip COUNSELLING_KEY_VERSION=2. New writes bind AAD immediately.
 *   3. (Optional) php spark synapse:crypto-reencrypt to migrate existing
 *      v1 rows in place; natural writes also converge to v2 over time.
 *
 * v1 rows remain decryptable (AAD-less) forever — the version column is
 * the discriminator, exactly like the audit-chain v1/v2 cutover.
 */
final class CounsellingKeyV2Aad extends Migration
{
    public function up(): void
    {
        $exists = $this->db->table('counselling_key_versions')->where('version', 2)->countAllResults();
        if ($exists === 0) {
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('counselling_key_versions')->insert([
                'version'      => 2,
                'key_ref'      => 'COUNSELLING_KEY_V2',
                'status'       => 'active',
                'activated_at' => $now,
                'created_at'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('counselling_key_versions')->where('version', 2)->delete();
    }
}
