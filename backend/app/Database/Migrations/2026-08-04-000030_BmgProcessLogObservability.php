<?php

declare(strict_types=1);

namespace App\Database\Migrations;

/**
 * BmgProcessLogObservability — panel revision (August 2026):
 *
 * Observability columns on `facilities_bmg_process_logs`:
 *
 *   - `oxygen_pct` — Dissolved / pore-space O₂ saturation at the
 *     log time. Industry target window for aerobic composting is
 *     5–15 %. We accept 0–25 to admit sensor drift readings; the
 *     alert engine (see `BmgAlertEngine`) flags anything outside
 *     the 5–20 operational window.
 *
 *   - `device_id` — Sensor / scanner identifier that produced the
 *     reading. Used for chain-of-custody ("which probe recorded
 *     this?"). Free-form VARCHAR(64) to admit barcodes, MACs,
 *     handheld scanner serials.
 *
 *   - `calibration_status` — Last-known calibration state of the
 *     device at log time. ENUM('ok','due','overdue') so the alert
 *     engine can demote or skip `overdue` readings.
 *
 * All columns nullable — manual logs remain valid.
 */
use CodeIgniter\Database\Migration;

final class BmgProcessLogObservability extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('facilities_bmg_process_logs')) {
            return;
        }

        $fields = [];

        if (! $this->db->fieldExists('oxygen_pct', 'facilities_bmg_process_logs')) {
            $fields['oxygen_pct'] = [
                'type'       => 'DECIMAL',
                'constraint' => '4,2',
                'null'       => true,
                'after'      => 'moisture_level',
            ];
        }
        if (! $this->db->fieldExists('device_id', 'facilities_bmg_process_logs')) {
            $fields['device_id'] = [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'oxygen_pct',
            ];
        }
        if (! $this->db->fieldExists('calibration_status', 'facilities_bmg_process_logs')) {
            // ENUM stored as VARCHAR + CHECK (matches the
            // `LowercaseStatusEnums` migration pattern that avoids
            // `ENUM(...)` literals so future additions don't require
            // a schema rewrite).
            $fields['calibration_status'] = [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'null'       => true,
                'after'      => 'device_id',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('facilities_bmg_process_logs', $fields);
        }

        $this->db->query(<<<'SQL'
            ALTER TABLE `facilities_bmg_process_logs`
                ADD CONSTRAINT `chk_pl_oxygen`
                CHECK (`oxygen_pct` IS NULL OR (`oxygen_pct` >= 0 AND `oxygen_pct` <= 25))
        SQL);
        $this->db->query(<<<'SQL'
            ALTER TABLE `facilities_bmg_process_logs`
                ADD CONSTRAINT `chk_pl_calibration`
                CHECK (`calibration_status` IS NULL
                       OR `calibration_status` IN ('ok','due','overdue'))
        SQL);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('facilities_bmg_process_logs')) {
            return;
        }
        $this->db->query('ALTER TABLE `facilities_bmg_process_logs` DROP CHECK `chk_pl_oxygen`');
        $this->db->query('ALTER TABLE `facilities_bmg_process_logs` DROP CHECK `chk_pl_calibration`');
        $drop = [];
        foreach (['oxygen_pct', 'device_id', 'calibration_status'] as $col) {
            if ($this->db->fieldExists($col, 'facilities_bmg_process_logs')) {
                $drop[] = $col;
            }
        }
        if ($drop !== []) {
            $this->forge->dropColumn('facilities_bmg_process_logs', $drop);
        }
    }
}
