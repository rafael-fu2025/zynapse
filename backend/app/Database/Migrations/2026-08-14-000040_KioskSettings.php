<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Shared tenant kiosk configuration edited by web or mobile administrators. */
final class KioskSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'settings_json' => ['type' => 'MEDIUMTEXT', 'null' => false],
            'revision' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'updated_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('updated_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('kiosk_settings');
    }

    public function down(): void
    {
        $this->forge->dropTable('kiosk_settings', true);
    }
}
