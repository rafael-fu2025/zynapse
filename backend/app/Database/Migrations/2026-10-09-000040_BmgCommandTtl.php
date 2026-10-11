<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgCommandTtl — commands expire.
 *
 * Adds `expires_at` to `facilities_bmg_device_commands` and admits
 * `run_now` to the command CHECK. A pending command older than its
 * expiry is failed by the feed (`listDeviceCommands`) before it can
 * reach a board that came back hours later: the operator pressed
 * "run now" minutes ago, not yesterday. `start`/`stop` carry the same
 * TTL — the board polls on a ~10 min cadence when online, so anything
 * older is stale by construction and can simply be re-issued.
 *
 * `NULL` expires_at (legacy rows, or a future command type that must
 * not expire) means "no deadline". Idempotent: re-runs are no-ops.
 */
final class BmgCommandTtl extends Migration
{
    private const TABLE      = 'facilities_bmg_device_commands';
    private const CONSTRAINT = 'chk_bmg_dev_cmd_command';
    private const NEW_CHECK  = "`command` IN ('start','stop','run_now')";
    private const OLD_CHECK  = "`command` IN ('start','stop')";

    public function up(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        if (! $this->db->fieldExists('expires_at', self::TABLE)) {
            $this->forge->addColumn(self::TABLE, [
                'expires_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'delivered_at'],
            ]);
        }

        $this->recreateCommandCheck(self::NEW_CHECK);
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        $this->recreateCommandCheck(self::OLD_CHECK);

        if ($this->db->fieldExists('expires_at', self::TABLE)) {
            $this->forge->dropColumn(self::TABLE, ['expires_at']);
        }
    }

    /**
     * Swap the command CHECK to the given definition. A pending legacy
     * `run_now` (down) can't exist in practice — the value was never
     * writable before this migration — so the downgrade re-check is
     * safe; the reconcile keeps it honest regardless.
     */
    private function recreateCommandCheck(string $check): void
    {
        if ($this->constraintExists(self::CONSTRAINT)) {
            $this->db->query('ALTER TABLE `' . self::TABLE . '` DROP CONSTRAINT `' . self::CONSTRAINT . '`');
        }
        $this->db->query(
            'ALTER TABLE `' . self::TABLE . '` ADD CONSTRAINT `' . self::CONSTRAINT . '` CHECK (' . $check . ')'
        );
    }

    private function constraintExists(string $constraint): bool
    {
        $r = $this->db->query(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE()'
            . '   AND TABLE_NAME = ' . $this->db->escape(self::TABLE)
            . '   AND CONSTRAINT_NAME = ' . $this->db->escape($constraint)
        );
        return $r->getNumRows() > 0;
    }
}
