<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgDeviceWatchdog — dedupe marker for the device-silence watchdog
 * (`synapse:bmg-device-watchdog`).
 *
 * `silence_notified_at` records when the operator audience was last
 * notified that THIS device went silent. The watchdog notifies once per
 * silence episode: it re-arms automatically because the notify
 * condition is `silence_notified_at IS NULL OR silence_notified_at <
 * last_seen_at` — the moment the device checks in again (last_seen_at
 * advances past the marker), a future silence is a fresh episode.
 *
 * Nullable — devices never notified read as NULL. Idempotent.
 */
final class BmgDeviceWatchdog extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('facilities_bmg_devices')) {
            return;
        }

        if (! $this->db->fieldExists('silence_notified_at', 'facilities_bmg_devices')) {
            $this->forge->addColumn('facilities_bmg_devices', [
                'silence_notified_at' => [
                    'type'   => 'DATETIME',
                    'null'   => true,
                    'after'  => 'last_seen_at',
                ],
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('facilities_bmg_devices')) {
            return;
        }

        if ($this->db->fieldExists('silence_notified_at', 'facilities_bmg_devices')) {
            $this->forge->dropColumn('facilities_bmg_devices', ['silence_notified_at']);
        }
    }
}
