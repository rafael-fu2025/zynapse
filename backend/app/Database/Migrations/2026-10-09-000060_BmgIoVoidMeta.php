<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgIoVoidMeta — manual I/O rows become voidable and richer.
 *
 * `facilities_bmg_inputs` / `facilities_bmg_outputs` were insert-only.
 * Staff entry is human: fat fingers happen, so a row is now VOIDED
 * (never edited in place, never deleted) — `voided_at`/`voided_by`/
 * `void_reason` mark it and every aggregation gains
 * `voided_at IS NULL`. Corrections are new rows; the ledger keeps both,
 * which is the audit trail.
 *
 * `facilities_bmg_inputs.category_id` — the waste type the operator
 * actually loaded, when it differs from the batch/unit default.
 * `facilities_bmg_outputs.recorded_at` — the staff-entered harvest
 * moment (inputs already carried one); NULL falls back to created_at.
 *
 * Idempotent: re-runs are no-ops.
 */
final class BmgIoVoidMeta extends Migration
{
    private const INPUTS  = 'facilities_bmg_inputs';
    private const OUTPUTS = 'facilities_bmg_outputs';

    public function up(): void
    {
        if ($this->db->tableExists(self::INPUTS)) {
            $this->addColumns(self::INPUTS, [
                'category_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'batch_id'],
                'voided_at'   => ['type' => 'DATETIME', 'null' => true, 'after' => 'recorded_at'],
                'voided_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'voided_at'],
                'void_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'voided_by_user_id'],
            ]);
            if ($this->db->fieldExists('category_id', self::INPUTS)
                && ! $this->foreignKeyToCategoryExists()) {
                $this->forge->addForeignKey('category_id', 'facilities_waste_categories', 'id', '', 'SET NULL');
                $this->forge->processIndexes(self::INPUTS);
            }
        }

        if ($this->db->tableExists(self::OUTPUTS)) {
            $this->addColumns(self::OUTPUTS, [
                'recorded_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'output_weight_kg'],
                'voided_at'   => ['type' => 'DATETIME', 'null' => true, 'after' => 'note'],
                'voided_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'voided_at'],
                'void_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'voided_by_user_id'],
            ]);
        }
    }

    public function down(): void
    {
        foreach ([self::INPUTS, self::OUTPUTS] as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            $drop = array_values(array_filter(
                ['voided_at', 'voided_by_user_id', 'void_reason', 'category_id', 'recorded_at'],
                fn (string $field): bool => $this->db->fieldExists($field, $table),
            ));
            if ($drop !== []) {
                $this->forge->dropColumn($table, $drop);
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $columns
     */
    private function addColumns(string $table, array $columns): void
    {
        $missing = array_filter(
            array_keys($columns),
            fn (string $field): bool => ! $this->db->fieldExists($field, $table),
        );
        if ($missing === []) {
            return;
        }
        $this->forge->addColumn($table, array_intersect_key($columns, array_flip($missing)));
    }

    private function foreignKeyToCategoryExists(): bool
    {
        $r = $this->db->query(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . '   AND TABLE_NAME = ' . $this->db->escape(self::INPUTS)
            . "   AND COLUMN_NAME = 'category_id'"
            . "   AND REFERENCED_TABLE_NAME = 'facilities_waste_categories'"
        );
        return $r->getNumRows() > 0;
    }
}
