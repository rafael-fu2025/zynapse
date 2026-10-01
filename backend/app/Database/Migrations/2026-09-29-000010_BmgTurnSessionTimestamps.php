<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgTurnSessionTimestamps — honest session times for device-reported
 * turning sessions.
 *
 * The ingest path stamps `log_date`/`created_at` at RECEIPT time, but
 * the device's offline queue can deliver a session up to 24 h late — a
 * session run at 23:50 and delivered at 00:10 currently lands on the
 * wrong Manila day. The ESP32 now stamps the session with its DS3231
 * RTC (`session_started_at` / `session_ended_at`, UTC epochs over the
 * wire, stored as UTC DATETIMEs here); the service uses the start for
 * `log_date` when the payload carries sane values.
 *
 * Both columns nullable — manual logs and legacy device rows (no RTC
 * or pre-upgrade firmware) stay valid. Idempotent: re-runs are no-ops.
 */
final class BmgTurnSessionTimestamps extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('facilities_bmg_process_logs')) {
            return;
        }

        $fields = [];

        if (! $this->db->fieldExists('session_started_at', 'facilities_bmg_process_logs')) {
            $fields['session_started_at'] = [
                'type'   => 'DATETIME',
                'null'   => true,
                'after'  => 'duration_seconds',
            ];
        }
        if (! $this->db->fieldExists('session_ended_at', 'facilities_bmg_process_logs')) {
            $fields['session_ended_at'] = [
                'type'   => 'DATETIME',
                'null'   => true,
                'after'  => 'session_started_at',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('facilities_bmg_process_logs', $fields);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('facilities_bmg_process_logs')) {
            return;
        }

        $drop = [];
        foreach (['session_started_at', 'session_ended_at'] as $col) {
            if ($this->db->fieldExists($col, 'facilities_bmg_process_logs')) {
                $drop[] = $col;
            }
        }
        if ($drop !== []) {
            $this->forge->dropColumn('facilities_bmg_process_logs', $drop);
        }
    }
}
