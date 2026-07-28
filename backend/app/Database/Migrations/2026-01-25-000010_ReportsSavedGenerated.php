<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ReportsSavedGenerated — saved report configurations + generated report
 * archive (Phase P6, recycled from legacy synapse_ag report_configurations
 * / generated_reports).
 *
 * A configuration is a named, admin-managed report template (module +
 * parameters + optional cron). Running one produces a CSV file on disk
 * plus a `generated_reports` row holding the metadata, an optional
 * deterministic narrative (`ai_summary`), and the parameters used. Cron
 * scheduling is stored but executed manually for now.
 */
final class ReportsSavedGenerated extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------- configurations
        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'name'               => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => false],
            'module'             => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'report_type'        => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'export'],
            'parameters'         => ['type' => 'JSON', 'null' => true],
            'schedule_cron'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'is_active'          => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'created_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at'         => ['type' => 'DATETIME', 'null' => false],
            'updated_at'         => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('module');
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->addForeignKey('created_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('report_configurations');

        // ---------------------------------------------- generated archive
        $this->forge->addField([
            'id'                   => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'config_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'module'               => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'file_path'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'format'               => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'csv'],
            'row_count'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'parameters_used'      => ['type' => 'JSON', 'null' => true],
            'ai_summary'           => ['type' => 'TEXT', 'null' => true],
            'generated_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'generated_at'         => ['type' => 'DATETIME', 'null' => false],
            'created_at'           => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('config_id');
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->addForeignKey('config_id', 'report_configurations', 'id', '', 'SET NULL');
        $this->forge->addForeignKey('generated_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('generated_reports');
    }

    public function down(): void
    {
        $this->forge->dropTable('generated_reports', true);
        $this->forge->dropTable('report_configurations', true);
    }
}
