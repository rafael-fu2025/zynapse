<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ReportSummaries — deterministic template-NLG narrative log (Phase P2c,
 * recycled from legacy synapse_ag ai_generated_summaries).
 *
 * Stores the generated narrative plus the normalized figures it was
 * built from. No patient identifiers are stored (module aggregates only).
 */
final class ReportSummaries extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'module'            => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false],
            'period_start'      => ['type' => 'DATE', 'null' => false],
            'period_end'        => ['type' => 'DATE', 'null' => false],
            'input_data'        => ['type' => 'JSON', 'null' => true],
            'generated_summary' => ['type' => 'TEXT', 'null' => false],
            'generation_method' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false],
            'model_used'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'generated_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['module', 'created_at']);
        $this->forge->addForeignKey('generated_by_user_id', 'users', 'id', '', 'SET NULL');
        $this->forge->createTable('report_summaries');
    }

    public function down(): void
    {
        $this->forge->dropTable('report_summaries', true);
    }
}
