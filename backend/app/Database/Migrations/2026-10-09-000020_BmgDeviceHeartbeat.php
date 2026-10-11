<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgDeviceHeartbeat — the device-side telemetry columns the
 * `POST /api/v1/devices/bmg/heartbeat` endpoint maintains on
 * `facilities_bmg_devices`.
 *
 *   - `last_heartbeat_at` — the explicit heartbeat receipt time. Kept
 *     separate from `last_seen_at` (which any ingest or command poll
 *     refreshes) so the dashboard's offline indicator measures exactly
 *     what the firmware advertises, not incidental traffic.
 *   - `last_uptime_seconds` / `last_rssi_dbm` — most recent reported
 *     uptime and WiFi signal; current-value columns, not a time series.
 *   - `rtc_valid` / `rtc_offset_seconds` — the DS3231 health flag and
 *     the drift the server measured at receipt (device epoch minus
 *     server epoch, only stamped when the device says its RTC is
 *     valid). Clock drift on the dashboard reads from here.
 *   - `last_run_result` / `motor_state` — what the device last did and
 *     what the motor is doing now, mirrored verbatim from the payload.
 *   - `rtc_notified_at` — dedupe anchor for the RTC-invalid watchdog
 *     notification, the same per-episode posture as
 *     `silence_notified_at`.
 *
 * All nullable: a device that has never heartbeated reads as "unknown",
 * not "zero". Idempotent.
 */
final class BmgDeviceHeartbeat extends Migration
{
    private const TABLE = 'facilities_bmg_devices';

    private const COLUMNS = [
        'last_heartbeat_at'   => ['type' => 'DATETIME', 'null' => true],
        'last_uptime_seconds' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        'last_rssi_dbm'       => ['type' => 'SMALLINT', 'null' => true],
        'rtc_valid'           => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
        'rtc_offset_seconds'  => ['type' => 'INT', 'null' => true],
        'last_run_result'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
        'motor_state'         => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
        'rtc_notified_at'     => ['type' => 'DATETIME', 'null' => true],
    ];

    public function up(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        $missing = array_filter(
            array_keys(self::COLUMNS),
            fn (string $field): bool => ! $this->db->fieldExists($field, self::TABLE),
        );
        if ($missing === []) {
            return;
        }

        $columns = [];
        foreach ($missing as $field) {
            $columns[$field] = self::COLUMNS[$field];
        }
        $columns['last_heartbeat_at']['after'] = 'last_seen_at';
        $this->forge->addColumn(self::TABLE, $columns);
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        $present = array_values(array_filter(
            array_keys(self::COLUMNS),
            fn (string $field): bool => $this->db->fieldExists($field, self::TABLE),
        ));
        if ($present !== []) {
            $this->forge->dropColumn(self::TABLE, $present);
        }
    }
}
