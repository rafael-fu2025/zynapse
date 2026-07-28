<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds refresh-token rotation chain support.
 *
 * `auth_refresh_tokens` already stores `replaced_by_hash`. This migration
 * adds a `family_id` so we can detect a replay (a token already used
 * after rotation) and revoke the entire chain at once. The application
 * worker / login controller enforces replay detection.
 */
final class RefreshTokenRotationChain extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('auth_refresh_tokens', [
            'family_id' => [
                'type'       => 'CHAR',
                'constraint' => 36,
                'null'       => true,
                'after'      => 'user_id',
            ],
        ]);
        $this->forge->addKey(['family_id', 'revoked_at'], false, false, 'ix_refresh_family');

        // Backfill: any existing rows get a fresh family id (random UUID).
        $db = $this->db;
        $rows = $db->table('auth_refresh_tokens')
            ->select('id')
            ->where('family_id', null)
            ->get()->getResultArray();

        foreach ($rows as $r) {
            $uuid = $this->uuidv4();
            $db->table('auth_refresh_tokens')
                ->where('id', $r['id'])
                ->update(['family_id' => $uuid]);
        }
    }

    public function down(): void
    {
        $this->forge->dropKey('auth_refresh_tokens', 'ix_refresh_family');
        $this->forge->dropColumn('auth_refresh_tokens', 'family_id');
    }

    private function uuidv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
