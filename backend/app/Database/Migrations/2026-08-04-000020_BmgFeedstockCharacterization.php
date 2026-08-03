<?php

declare(strict_types=1);

namespace App\Database\Migrations;

/**
 * BmgFeedstockCharacterization — panel revision (August 2026):
 *
 * Industry-standard feedstock characterization columns on
 * `facilities_bmg_inputs`. All columns are nullable so legacy
 * rows remain valid; new entries that supply values are
 * validated by CHECK constraints:
 *
 *   - `cn_ratio` — Carbon:Nitrogen ratio. Compost industry
 *     reference window is 20:1 to 40:1 for an active hot pile;
 *     we keep the wider 0.1–200 window to admit edge cases
 *     (high-N manures, high-C woody feedstocks) so the rule
 *     never silently drops a real reading.
 *
 *   - `bulk_density_kg_per_m3` — Mass per unit volume. Used to
 *     flag aeration problems (compaction > ~700 kg/m³).
 *
 *   - `ph` — pH of the feedstock at intake. Composting target
 *     6.5–8.5; CHECK allows full 0–14 to accept extreme readings.
 *
 * All three are reference metadata — they do NOT participate in
 * the mass-balance invariant; that remains composition-weight sum
 * vs `total_input_weight_kg`.
 */
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\Migration;

final class BmgFeedstockCharacterization extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('facilities_bmg_inputs')) {
            return;
        }

        $fields = [];

        if (! $this->db->fieldExists('cn_ratio', 'facilities_bmg_inputs')) {
            $fields['cn_ratio'] = [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
                'after'      => 'weight_kg',
            ];
        }
        if (! $this->db->fieldExists('bulk_density_kg_per_m3', 'facilities_bmg_inputs')) {
            $fields['bulk_density_kg_per_m3'] = [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'null'       => true,
                'after'      => 'cn_ratio',
            ];
        }
        if (! $this->db->fieldExists('ph', 'facilities_bmg_inputs')) {
            $fields['ph'] = [
                'type'       => 'DECIMAL',
                'constraint' => '4,2',
                'null'       => true,
                'after'      => 'bulk_density_kg_per_m3',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('facilities_bmg_inputs', $fields);
        }

        // Range guards. CHECK is nullable-tolerant: NULL passes.
        $this->db->query(<<<'SQL'
            ALTER TABLE `facilities_bmg_inputs`
                ADD CONSTRAINT `chk_fbi_cn_ratio`
                CHECK (`cn_ratio` IS NULL OR (`cn_ratio` >= 0.1 AND `cn_ratio` <= 200))
        SQL);
        $this->db->query(<<<'SQL'
            ALTER TABLE `facilities_bmg_inputs`
                ADD CONSTRAINT `chk_fbi_bulk_density`
                CHECK (`bulk_density_kg_per_m3` IS NULL OR `bulk_density_kg_per_m3` > 0)
        SQL);
        $this->db->query(<<<'SQL'
            ALTER TABLE `facilities_bmg_inputs`
                ADD CONSTRAINT `chk_fbi_ph`
                CHECK (`ph` IS NULL OR (`ph` >= 0 AND `ph` <= 14))
        SQL);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('facilities_bmg_inputs')) {
            return;
        }

        // Drop CHECKs first — required before dropping the columns they
        // reference. MariaDB 10.4 (XAMPP) doesn't support
        // `DROP CHECK <name>` — use `DROP CONSTRAINT` which works on
        // both MariaDB 10.4+ and MySQL 8+. Swallow "doesn't exist"
        // errors for idempotence on re-runs.
        $dropCheck = function (string $name) {
            try {
                $this->db->query(
                    "ALTER TABLE `facilities_bmg_inputs` DROP CONSTRAINT `{$name}`"
                );
            } catch (DatabaseException $e) {
                // ignore
            }
        };
        $dropCheck('chk_fbi_cn_ratio');
        $dropCheck('chk_fbi_bulk_density');
        $dropCheck('chk_fbi_ph');

        $drop = [];
        foreach (['cn_ratio', 'bulk_density_kg_per_m3', 'ph'] as $col) {
            if ($this->db->fieldExists($col, 'facilities_bmg_inputs')) {
                $drop[] = $col;
            }
        }
        if ($drop !== []) {
            $this->forge->dropColumn('facilities_bmg_inputs', $drop);
        }
    }
}
