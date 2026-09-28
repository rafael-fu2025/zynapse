<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgTurnSessionColumns — structured output for device-reported
 * turning sessions on `facilities_bmg_process_logs`.
 *
 *   - `session_uid` — idempotency key minted by the device per turning
 *     session (16 random bytes as hex). UNIQUE per tenant; retries of
 *     the same session (offline queue, WiFi blips) collapse onto the
 *     first insert. Manual rows leave it NULL — MySQL/MariaDB UNIQUE
 *     indexes admit unlimited NULLs, so no existing row collides.
 *   - `turns_count` — rotations performed in the session.
 *   - `duration_seconds` — wall-clock length of the session.
 *
 * All columns nullable — manual logs remain valid and no existing
 * query or constraint is touched. Idempotent: re-runs are no-ops.
 */
final class BmgTurnSessionColumns extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('facilities_bmg_process_logs')) {
            return;
        }

        $fields = [];

        if (! $this->db->fieldExists('session_uid', 'facilities_bmg_process_logs')) {
            $fields['session_uid'] = [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'calibration_status',
            ];
        }
        if (! $this->db->fieldExists('turns_count', 'facilities_bmg_process_logs')) {
            $fields['turns_count'] = [
                'type'       => 'INT',
                'unsigned'  => true,
                'constraint' => 5,
                'null'      => true,
                'after'     => 'session_uid',
            ];
        }
        if (! $this->db->fieldExists('duration_seconds', 'facilities_bmg_process_logs')) {
            $fields['duration_seconds'] = [
                'type'       => 'INT',
                'unsigned'  => true,
                'constraint' => 5,
                'null'      => true,
                'after'     => 'turns_count',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('facilities_bmg_process_logs', $fields);
        }

        // Idempotency key: one session_uid per tenant. The device path
        // checks this inside its transaction before inserting, so the
        // index is the final guard against double-reporting.
        if (! $this->indexExists('facilities_bmg_process_logs', 'uk_pl_tenant_session')) {
            $this->db->query(
                'ALTER TABLE `facilities_bmg_process_logs`'
                . ' ADD UNIQUE KEY `uk_pl_tenant_session` (`tenant_id`, `session_uid`)'
            );
        }

        // Device-attributed reads (drum page shows the tumbler's rows).
        if (! $this->indexExists('facilities_bmg_process_logs', 'idx_pl_tenant_device')) {
            $this->db->query(
                'ALTER TABLE `facilities_bmg_process_logs`'
                . ' ADD KEY `idx_pl_tenant_device` (`tenant_id`, `device_id`)'
            );
        }

        if ($this->constraintDoesNotExist('facilities_bmg_process_logs', 'chk_pl_turns')) {
            $this->db->query(<<<'SQL'
                ALTER TABLE `facilities_bmg_process_logs`
                    ADD CONSTRAINT `chk_pl_turns`
                    CHECK (`turns_count` IS NULL OR (`turns_count` > 0 AND `turns_count` <= 10000))
            SQL);
        }
        if ($this->constraintDoesNotExist('facilities_bmg_process_logs', 'chk_pl_duration')) {
            $this->db->query(<<<'SQL'
                ALTER TABLE `facilities_bmg_process_logs`
                    ADD CONSTRAINT `chk_pl_duration`
                    CHECK (`duration_seconds` IS NULL OR (`duration_seconds` > 0 AND `duration_seconds` <= 86400))
            SQL);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('facilities_bmg_process_logs')) {
            return;
        }

        foreach (['uk_pl_tenant_session', 'idx_pl_tenant_device'] as $index) {
            if ($this->indexExists('facilities_bmg_process_logs', $index)) {
                $this->db->query("ALTER TABLE `facilities_bmg_process_logs` DROP INDEX `{$index}`");
            }
        }

        foreach (['chk_pl_turns', 'chk_pl_duration'] as $constraint) {
            if ($this->constraintExists('facilities_bmg_process_logs', $constraint)) {
                $this->db->query("ALTER TABLE `facilities_bmg_process_logs` DROP CONSTRAINT `{$constraint}`");
            }
        }

        $drop = [];
        foreach (['session_uid', 'turns_count', 'duration_seconds'] as $col) {
            if ($this->db->fieldExists($col, 'facilities_bmg_process_logs')) {
                $drop[] = $col;
            }
        }
        if ($drop !== []) {
            $this->forge->dropColumn('facilities_bmg_process_logs', $drop);
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $r = $this->db->query(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS'
            . ' WHERE INDEX_SCHEMA = DATABASE()'
            . '   AND TABLE_NAME = ' . $this->db->escape($table)
            . '   AND INDEX_NAME = ' . $this->db->escape($index)
        );
        return $r->getNumRows() > 0;
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $r = $this->db->query(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE()'
            . '   AND TABLE_NAME = ' . $this->db->escape($table)
            . '   AND CONSTRAINT_NAME = ' . $this->db->escape($constraint)
        );
        return $r->getNumRows() > 0;
    }

    private function constraintDoesNotExist(string $table, string $constraint): bool
    {
        return ! $this->constraintExists($table, $constraint);
    }
}
