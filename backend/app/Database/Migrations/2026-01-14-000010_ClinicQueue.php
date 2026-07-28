<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicQueue — walk-in queue recycled from legacy `synapse_ag`
 * consultations queue columns (Phase 14).
 *
 * One entry per encounter per day, with a STABLE 1-based position
 * (`UNIQUE(queue_date, position)`). Lifecycle:
 *   waiting → called → in_session → done
 *   called  → skipped            (no-show at the door)
 *
 * The public waiting-room feed exposes ONLY position + a display
 * name (minimum disclosure, matching the legacy design note).
 */
final class ClinicQueue extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'encounter_id'      => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'queue_date'        => ['type' => 'DATE', 'null' => false],
            'position'          => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'status'            => ['type' => 'ENUM', 'constraint' => ['waiting', 'called', 'in_session', 'done', 'skipped'], 'null' => false, 'default' => 'waiting'],
            'called_at'         => ['type' => 'DATETIME', 'null' => true],
            'called_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'started_at'        => ['type' => 'DATETIME', 'null' => true],
            'finished_at'       => ['type' => 'DATETIME', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => false],
            'updated_at'        => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('encounter_id');
        $this->forge->addUniqueKey(['queue_date', 'position']);
        $this->forge->addKey(['queue_date', 'status']);
        $this->forge->addForeignKey('encounter_id', 'clinic_encounters', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('called_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_queue_entries');
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_queue_entries', true);
    }
}
