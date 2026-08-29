<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Metadata for tenant-managed kiosk photos and videos. */
final class KioskMediaLibrary extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'public_id' => ['type' => 'CHAR', 'constraint' => 36, 'null' => false],
            'kind' => ['type' => 'ENUM', 'constraint' => ['photo', 'video'], 'null' => false],
            'label' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'original_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'stored_name' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => false],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => false],
            'size_bytes' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'uploaded_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => false],
            'archived_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('public_id');
        $this->forge->addUniqueKey('stored_name');
        $this->forge->addKey(['tenant_id', 'archived_at', 'created_at']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('uploaded_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('kiosk_media_assets');
    }

    public function down(): void
    {
        $count = (int) $this->db->table('kiosk_media_assets')->countAllResults();
        if ($count > 0) {
            throw new \RuntimeException("Cannot roll back kiosk media while {$count} asset row(s) exist.");
        }
        $this->forge->dropTable('kiosk_media_assets', true);
    }
}
