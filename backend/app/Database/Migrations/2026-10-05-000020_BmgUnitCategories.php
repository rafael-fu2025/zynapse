<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgUnitCategories — the waste categories a drum is designated for,
 * configured when the drum is created (panel revision: the category mix
 * moves from batch start to drum setup).
 *
 * A drum may serve MULTIPLE categories; `startBatch` then only accepts
 * compositions drawn from this set, and the start form pre-seeds one
 * weight row per configured category. `facilities_bmg_units.default_
 * category_id` is kept in sync as the FIRST selected category so the
 * suggest-a-drum heuristic and the batch `category_id` fallback keep
 * working unchanged.
 *
 * Idempotent: re-runs are no-ops.
 */
final class BmgUnitCategories extends Migration
{
    private const TABLE = 'facilities_bmg_unit_categories';

    public function up(): void
    {
        if ($this->db->tableExists(self::TABLE)) {
            return;
        }

        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'unit_id'     => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'category_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'created_at'  => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['unit_id', 'category_id']);
        $this->forge->addKey('tenant_id', false, false, 'idx_bmg_unit_cats_tenant');
        $this->forge->addKey('category_id', false, false, 'idx_bmg_unit_cats_category');
        // A drum row is never hard-deleted in production (soft archive),
        // but purge tooling may be — cascade keeps the pivot orphan-free.
        $this->forge->addForeignKey('unit_id', 'facilities_bmg_units', 'id', '', 'CASCADE');
        // Category hard-delete is refused by CategoryService while the
        // pivot still references it; RESTRICT is the DB-level backstop.
        $this->forge->addForeignKey('category_id', 'facilities_waste_categories', 'id', '', 'RESTRICT');
        $this->forge->createTable(self::TABLE);
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }
        $this->forge->dropTable(self::TABLE, true);
    }
}
