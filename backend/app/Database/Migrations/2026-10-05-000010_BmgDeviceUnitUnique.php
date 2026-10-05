<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgDeviceUnitUnique — a drum integrates exactly ONE registered ESP32
 * and an ESP32 is bolted to at most one drum (1:1).
 *
 * The `unit_id` column on `facilities_bmg_devices` already carried the
 * device→drum FK; without a UNIQUE index the registry would happily
 * pin two boards to the same drum, and the drum-side "assign an ESP32"
 * flow (create / edit) could double-book hardware. The unique index is
 * the final guard behind the row locks the service takes — same
 * posture as `active_unit_id` on batches.
 *
 * Idempotent: legacy rows that violate the invariant (two devices on
 * one drum) are reconciled first — the most recently registered board
 * keeps the binding, the rest are released. Re-runs are no-ops.
 */
final class BmgDeviceUnitUnique extends Migration
{
    private const TABLE    = 'facilities_bmg_devices';
    private const INDEX    = 'uq_bmg_devices_unit';

    public function up(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        // Release all but the newest device on any over-bound drum so the
        // unique index below cannot fail on legacy data.
        $this->db->query(<<<'SQL'
            UPDATE `facilities_bmg_devices` d
            JOIN (
                SELECT `unit_id`, MAX(`id`) AS keep_id
                FROM `facilities_bmg_devices`
                WHERE `unit_id` IS NOT NULL
                GROUP BY `unit_id`
                HAVING COUNT(*) > 1
            ) dup ON d.`unit_id` = dup.`unit_id` AND d.`id` <> dup.`keep_id`
            SET d.`unit_id` = NULL, d.`updated_at` = NOW()
        SQL);

        if ($this->uniqueIndexDoesNotExist(self::TABLE, self::INDEX)) {
            $this->db->query(
                'ALTER TABLE `' . self::TABLE . '` ADD UNIQUE KEY `' . self::INDEX . '` (`unit_id`)'
            );
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }
        if (! $this->uniqueIndexDoesNotExist(self::TABLE, self::INDEX)) {
            $this->db->query('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::INDEX . '`');
        }
    }

    private function uniqueIndexDoesNotExist(string $table, string $index): bool
    {
        // information_schema.STATISTICS carries INDEX_NAME — there is no
        // STATISTICS_NAME column in MariaDB or MySQL.
        $r = $this->db->query(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . '   AND TABLE_NAME = ' . $this->db->escape($table)
            . '   AND INDEX_NAME = ' . $this->db->escape($index)
        );
        return $r->getNumRows() === 0;
    }
}
