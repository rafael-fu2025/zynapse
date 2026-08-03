<?php

declare(strict_types=1);

namespace App\Database\Migrations;

/**
 * BmgLossesTaxonomy — panel revision (August 2026):
 *
 * Categorised loss tracking on BMG batches. Operators record where
 * mass went during the active run (evaporation, off-gas, sampling,
 * spill, cleaning, mechanical hold-up, other). The service computes
 * `total_loss_kg` denormalised onto `facilities_bmg_batches` so the
 * mass-balance invariant can show:
 *
 *     output_weight_kg + total_loss_kg + accumulated_in_process_kg
 *         ≤ total_input_weight_kg  ±  tolerance
 *
 * The new `accumulated_in_process_kg` is the operator-stated WIP
 * residue left on the unit when the batch is moved to curing or
 * finished — useful for trace-back across long cures.
 *
 * Schema notes:
 *   - `category_code` is stored as VARCHAR + CHECK (matches the
 *     `LowercaseStatusEnums` migration pattern) so adding a new
 *     taxonomy entry doesn't require a schema rewrite.
 *   - `total_loss_kg` is denormalised; the service recomputes it
 *     from SUM(weight_kg) on every insert, so the column never
 *     drifts from the row-level truth.
 *   - Soft delete only — never DELETE rows from
 *     `facilities_bmg_losses`; an audit outbox entry keeps the
 *     chain-of-custody intact.
 *   - All columns nullable/defaulted so legacy rows remain valid.
 */
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\Migration;

final class BmgLossesTaxonomy extends Migration
{
    private const LOSS_CODES = ['evaporation', 'off_gas', 'sampling', 'spill', 'cleaning', 'mechanical_holdup', 'other'];

    public function up(): void
    {
        // ---- 1. New losses table.
        if (! $this->db->tableExists('facilities_bmg_losses')) {
            $this->forge->addField([
                'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'batch_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'tenant_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
                'category_code'       => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
                'weight_kg'           => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => false],
                'note'                => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                // BIGINT UNSIGNED to match `users.id` (BIGINT UNSIGNED).
                // The historical INT UNSIGNED here broke the FK with
                // errno 150 on MySQL — INT and BIGINT are not interchangeable
                // for FK purposes.
                'recorded_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'recorded_at'         => ['type' => 'DATETIME', 'null' => false],
                'created_at'          => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey('batch_id');
            $this->forge->addKey('tenant_id');
            $this->forge->addForeignKey('batch_id', 'facilities_bmg_batches', 'id', '', 'CASCADE');
            $this->forge->addForeignKey('recorded_by_user_id', 'users', 'id', '', 'RESTRICT');
            $this->forge->createTable('facilities_bmg_losses');
        }

        // Range / enum guards. Idempotent: drop first, then re-add.
        // MariaDB 10.4 (XAMPP) does NOT support `DROP CHECK <name>` —
        // it expects `DROP CONSTRAINT <name>` (the constraint type
        // is a CHECK either way). MySQL 8+ accepts both. We try
        // DROP CONSTRAINT and swallow "constraint does not exist"
        // errors so the migration is safely idempotent on either DB.
        $dropCheck = function (string $name) {
            try {
                $this->db->query(
                    "ALTER TABLE `facilities_bmg_losses` DROP CONSTRAINT `{$name}`"
                );
            } catch (DatabaseException $e) {
                // ER_CHECK_CONSTRAINT_NOT_FOUND (3946) or
                // ER_CANT_DROP_FIELD_OR_KEY (1091): both fine,
                // the constraint simply isn't there yet.
            }
        };
        $dropCheck('chk_fbl_weight');
        $dropCheck('chk_fbl_category');
        $this->db->query(<<<'SQL'
            ALTER TABLE `facilities_bmg_losses`
                ADD CONSTRAINT `chk_fbl_weight`
                CHECK (`weight_kg` > 0)
        SQL);
        // Build the IN-list dynamically so adding a new category
        // remains a one-line edit (const above).
        $inList = "'" . implode("','", self::LOSS_CODES) . "'";
        $this->db->query(
            "ALTER TABLE `facilities_bmg_losses` "
            . "ADD CONSTRAINT `chk_fbl_category` "
            . "CHECK (`category_code` IN ({$inList}))"
        );

        // ---- 2. Denormalised accumulation columns on the batch.
        if ($this->db->tableExists('facilities_bmg_batches')) {
            $batchFields = [];
            if (! $this->db->fieldExists('total_loss_kg', 'facilities_bmg_batches')) {
                $batchFields['total_loss_kg'] = [
                    'type'       => 'DECIMAL',
                    'constraint' => '10,2',
                    'null'       => true,
                    'default'    => 0.00,
                    'after'      => 'output_weight_kg',
                ];
            }
            if (! $this->db->fieldExists('accumulated_in_process_kg', 'facilities_bmg_batches')) {
                $batchFields['accumulated_in_process_kg'] = [
                    'type'       => 'DECIMAL',
                    'constraint' => '10,2',
                    'null'       => true,
                    'default'    => 0.00,
                    'after'      => 'total_loss_kg',
                ];
            }
            if ($batchFields !== []) {
                $this->forge->addColumn('facilities_bmg_batches', $batchFields);
            }

            // Loss-total guard: must be ≥ 0 when set. NULL is allowed
            // (legacy / not yet computed).
            $dropCheck = function (string $name) {
                try {
                    $this->db->query(
                        "ALTER TABLE `facilities_bmg_batches` DROP CONSTRAINT `{$name}`"
                    );
                } catch (DatabaseException $e) {
                    // ignore
                }
            };
            $dropCheck('chk_fbb_loss_nonneg');
            $this->db->query(<<<'SQL'
                ALTER TABLE `facilities_bmg_batches`
                    ADD CONSTRAINT `chk_fbb_loss_nonneg`
                    CHECK (`total_loss_kg` IS NULL OR `total_loss_kg` >= 0)
            SQL);
            $dropCheck('chk_fbb_aip_nonneg');
            $this->db->query(<<<'SQL'
                ALTER TABLE `facilities_bmg_batches`
                    ADD CONSTRAINT `chk_fbb_aip_nonneg`
                    CHECK (`accumulated_in_process_kg` IS NULL OR `accumulated_in_process_kg` >= 0)
            SQL);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('facilities_bmg_batches')) {
            $dropCheck = function (string $name) {
                try {
                    $this->db->query(
                        "ALTER TABLE `facilities_bmg_batches` DROP CONSTRAINT `{$name}`"
                    );
                } catch (DatabaseException $e) {
                    // ignore
                }
            };
            $dropCheck('chk_fbb_loss_nonneg');
            $dropCheck('chk_fbb_aip_nonneg');
            $drop = [];
            foreach (['total_loss_kg', 'accumulated_in_process_kg'] as $col) {
                if ($this->db->fieldExists($col, 'facilities_bmg_batches')) {
                    $drop[] = $col;
                }
            }
            if ($drop !== []) {
                $this->forge->dropColumn('facilities_bmg_batches', $drop);
            }
        }

        if ($this->db->tableExists('facilities_bmg_losses')) {
            $dropCheck = function (string $name) {
                try {
                    $this->db->query(
                        "ALTER TABLE `facilities_bmg_losses` DROP CONSTRAINT `{$name}`"
                    );
                } catch (DatabaseException $e) {
                    // ignore
                }
            };
            $dropCheck('chk_fbl_weight');
            $dropCheck('chk_fbl_category');
            $this->forge->dropTable('facilities_bmg_losses', true);
        }
    }
}
