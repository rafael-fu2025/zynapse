<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * NotificationOutbox — transactional outbox for notifications (Phase 9).
 *
 * Mirrors the audit outbox pattern mandated by the directive
 * ("Transactional Outbox Pattern for Audit and Notifications"):
 *
 *   - `notification_outbox`: written in the SAME transaction as the
 *     business change. Context is whitelisted — never PII.
 *   - `notifications`: in-app landing zone drained by
 *     `synapse:notify-drain`; the SPA reads it per user. Additional
 *     channels (email/SMS) plug into the same drain later.
 */
final class NotificationOutbox extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'channel'           => ['type' => 'ENUM', 'constraint' => ['inapp'], 'null' => false, 'default' => 'inapp'],
            'recipient_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'template_code'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'context_json'      => ['type' => 'JSON', 'null' => true],
            'created_at'        => ['type' => 'DATETIME(6)', 'null' => false],
            'processed_at'      => ['type' => 'DATETIME(6)', 'null' => true],
            'attempt_count'     => ['type' => 'TINYINT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'last_error'        => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['processed_at', 'created_at'], false, false, 'ix_notify_outbox_unsent');
        $this->forge->createTable('notification_outbox');

        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'recipient_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'template_code'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'context_json'      => ['type' => 'JSON', 'null' => true],
            'read_at'           => ['type' => 'DATETIME', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['recipient_user_id', 'created_at', 'id']);
        $this->forge->addForeignKey('recipient_user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('notifications');
    }

    public function down(): void
    {
        $this->forge->dropTable('notifications', true);
        $this->forge->dropTable('notification_outbox', true);
    }
}
