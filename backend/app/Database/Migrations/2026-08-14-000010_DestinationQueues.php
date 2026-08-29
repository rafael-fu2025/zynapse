<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Add destination-aware kiosk storage and a Counselling-owned queue.
 *
 * Clinic queue rows remain untouched. Guidance is the user-facing label;
 * `counselling` remains the internal module/database identifier.
 */
final class DestinationQueues extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('clinic_checkins', [
            'destination' => [
                'type' => 'ENUM',
                'constraint' => ['clinic', 'counselling'],
                'null' => false,
                'default' => 'clinic',
                'after' => 'station_id',
            ],
        ]);
        $this->db->query(
            'ALTER TABLE `clinic_checkins` MODIFY `outcome` ENUM('
            . "'counselling_confirmed','counselling_already','counselling_queued',"
            . "'clinic_appointment_confirmed','clinic_appointment_already','clinic_queued','duplicate'"
            . ') NOT NULL',
        );
        $this->db->query(
            'CREATE INDEX `idx_checkin_destination_patient_time`'
            . ' ON `clinic_checkins` (`destination`, `patient_school_id`, `scanned_at`)',
        );

        // Logical link only: Referrals is the bridge; no cross-module FK.
        $this->forge->addColumn('clinic_queue_entries', [
            'referral_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'null' => true,
                'after' => 'encounter_id',
            ],
        ]);
        $this->db->query('CREATE UNIQUE INDEX `uq_clinic_queue_referral` ON `clinic_queue_entries` (`referral_id`)');

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
            'patient_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'patient_school_id' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false],
            'counselling_appointment_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'counselling_session_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            // Logical bridge identifiers. No cross-module foreign keys.
            'referral_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'checkin_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'purpose' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'queue_date' => ['type' => 'DATE', 'null' => false],
            'position' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'status' => [
                'type' => 'ENUM',
                'constraint' => ['waiting', 'called', 'in_session', 'done', 'skipped'],
                'null' => false,
                'default' => 'waiting',
            ],
            'called_at' => ['type' => 'DATETIME', 'null' => true],
            'called_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'started_at' => ['type' => 'DATETIME', 'null' => true],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'queue_date', 'position']);
        $this->forge->addUniqueKey('counselling_appointment_id');
        $this->forge->addUniqueKey('referral_id');
        $this->forge->addUniqueKey('checkin_id');
        $this->forge->addKey(['queue_date', 'status']);
        $this->forge->addKey(['patient_school_id', 'queue_date']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('patient_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('counselling_session_id', 'counselling_sessions', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('called_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('counselling_queue_entries');

        // Database guards for concurrent kiosk submissions and the single
        // Guidance service slot. Terminal rows yield NULL and stop blocking a
        // legitimate later visit.
        $this->db->query(
            'ALTER TABLE `counselling_queue_entries` ADD `active_patient_school_id` VARCHAR(50)'
            . " GENERATED ALWAYS AS (CASE WHEN `status` IN ('waiting','called','in_session')"
            . ' THEN `patient_school_id` ELSE NULL END) STORED',
        );
        $this->db->query(
            'CREATE UNIQUE INDEX `uq_cqe_active_patient`'
            . ' ON `counselling_queue_entries` (`tenant_id`, `queue_date`, `active_patient_school_id`)',
        );
        $this->db->query(
            'ALTER TABLE `counselling_queue_entries` ADD `active_service_slot` TINYINT'
            . " GENERATED ALWAYS AS (CASE WHEN `status` IN ('called','in_session') THEN 1 ELSE NULL END) STORED",
        );
        $this->db->query(
            'CREATE UNIQUE INDEX `uq_cqe_active_service_slot`'
            . ' ON `counselling_queue_entries` (`tenant_id`, `queue_date`, `active_service_slot`)',
        );

        $this->forge->addColumn('referral_referrals', [
            'queue_handoff_destination' => [
                'type' => 'ENUM',
                'constraint' => ['clinic', 'counselling'],
                'null' => true,
                'after' => 'provider_user_id',
            ],
            'queue_handoff_entry_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'null' => true,
                'after' => 'queue_handoff_destination',
            ],
            'queue_handoff_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'queue_handoff_entry_id',
            ],
        ]);
        $this->db->query(
            'CREATE INDEX `idx_referral_queue_handoff`'
            . ' ON `referral_referrals` (`queue_handoff_destination`, `queue_handoff_entry_id`)',
        );
    }

    public function down(): void
    {
        $queueCount = (int) $this->db->table('counselling_queue_entries')->countAllResults();
        $handoffCount = (int) $this->db->table('referral_referrals')
            ->where('queue_handoff_entry_id IS NOT NULL', null, false)
            ->countAllResults();
        $newOutcomeCount = (int) $this->db->table('clinic_checkins')
            ->whereIn('outcome', ['counselling_queued', 'clinic_appointment_already'])
            ->countAllResults();
        if ($queueCount > 0 || $handoffCount > 0 || $newOutcomeCount > 0) {
            throw new \RuntimeException(
                "Cannot roll back destination queues while {$queueCount} Guidance queue row(s), "
                . "{$handoffCount} referral handoff(s), or {$newOutcomeCount} new check-in outcome(s) exist.",
            );
        }
        $this->db->query('DROP INDEX `idx_referral_queue_handoff` ON `referral_referrals`');
        $this->forge->dropColumn('referral_referrals', [
            'queue_handoff_at',
            'queue_handoff_entry_id',
            'queue_handoff_destination',
        ]);
        $this->forge->dropTable('counselling_queue_entries', true);
        $this->db->query('DROP INDEX `uq_clinic_queue_referral` ON `clinic_queue_entries`');
        $this->forge->dropColumn('clinic_queue_entries', 'referral_id');
        $this->db->query('DROP INDEX `idx_checkin_destination_patient_time` ON `clinic_checkins`');
        $this->db->query(
            'ALTER TABLE `clinic_checkins` MODIFY `outcome` ENUM('
            . "'counselling_confirmed','counselling_already','clinic_appointment_confirmed',"
            . "'clinic_queued','duplicate'"
            . ') NOT NULL',
        );
        $this->forge->dropColumn('clinic_checkins', 'destination');
    }
}
