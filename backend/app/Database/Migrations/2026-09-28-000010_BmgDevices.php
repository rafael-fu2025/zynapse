<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgDevices — registry of automated BMG hardware (the mechanized
 * compost-drum tumbler controller) and its ingest credentials.
 *
 * Each device authenticates to `POST /api/v1/devices/bmg/turn-sessions`
 * with a static bearer token (`X-Device-Token: dev_<64 hex>`). Only the
 * SHA-256 hash of the token is stored — the plaintext is shown exactly
 * once by `synapse:bmg-device-register` and never lives server-side.
 *
 *   - `unit_id` — which drum the device is bolted to. Nullable so a
 *     device can be registered before it is assigned; an unbound device
 *     is rejected at ingest time (`device.not_bound`) rather than here,
 *     because binding is an operational act, not a schema one.
 *   - `linked_user_id` — the machine user every device write is
 *     attributed to (`recorded_by_user_id` on process logs stays NOT
 *     NULL with its FK to users; the audit chain and notification
 *     outbox likewise key off a real user id). The user carries the
 *     minimal `bmg_device` group and has no usable password.
 *   - `token_prefix` — first characters of the plaintext token, so an
 *     operator can identify WHICH credential a request used without
 *     revealing it.
 *   - `status` — `active` / `disabled`; revocation is instant because
 *     the filter checks the column on every request.
 *
 * `status` uses VARCHAR + CHECK (the `LowercaseStatusEnums` pattern)
 * instead of an ENUM literal so future states don't require a schema
 * rewrite. Idempotent: re-runs are no-ops.
 */
final class BmgDevices extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('facilities_bmg_devices')) {
            return;
        }

        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'unit_id'        => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'code'           => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'display_name'   => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'token_hash'     => ['type' => 'CHAR', 'constraint' => 64, 'null' => false],
            'token_prefix'   => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'active'],
            'linked_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'firmware'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'last_seen_at'   => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => false],
            'updated_at'     => ['type' => 'DATETIME', 'null' => false],
            'archived_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey('tenant_id', false, false, 'idx_bmg_devices_tenant');
        $this->forge->addForeignKey('unit_id', 'facilities_bmg_units', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('linked_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('facilities_bmg_devices');

        if ($this->constraintDoesNotExist('facilities_bmg_devices', 'chk_bmg_dev_status')) {
            $this->db->query(<<<'SQL'
                ALTER TABLE `facilities_bmg_devices`
                    ADD CONSTRAINT `chk_bmg_dev_status`
                    CHECK (`status` IN ('active','disabled'))
            SQL);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('facilities_bmg_devices')) {
            return;
        }
        $this->db->query('ALTER TABLE `facilities_bmg_devices` DROP CONSTRAINT `chk_bmg_dev_status`');
        $this->forge->dropTable('facilities_bmg_devices', true);
    }

    private function constraintDoesNotExist(string $table, string $constraint): bool
    {
        $r = $this->db->query(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE()'
            . '   AND TABLE_NAME = ' . $this->db->escape($table)
            . '   AND CONSTRAINT_NAME = ' . $this->db->escape($constraint)
        );
        return $r->getNumRows() === 0;
    }
}
