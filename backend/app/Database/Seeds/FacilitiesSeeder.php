<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * FacilitiesSeeder — DEV/STAGING ONLY.
 *
 * Resets the Facilities (BMG) module to a known, demoable state and
 * inserts a canonical reference dataset:
 *
 *   1. Wipes every BMG-related table (in FK-safe order):
 *        - facilities_bmg_inputs        (child of batches)
 *        - facilities_bmg_outputs       (child of batches)
 *        - facilities_bmg_process_logs  (child of batches)
 *        - facilities_bmg_batches       (child of units, references cats)
 *        - facilities_bmg_units         (references cats via default_category_id)
 *        - facilities_waste_categories  (no children)
 *      Also drops the matching rows from audit_outbox so the seeder is
 *      re-runnable without piling up orphan audit entries.
 *
 *   2. Seeds three realistic waste categories (Food Scraps, Yard &
 *      Garden, Mixed Food + Yard) — covering the common case for a
 *      school canteen / facilities team.
 *
 *   3. Seeds four BMG drums (DRM-01..04) at known locations, all Idle,
 *      with a default category pre-picked. No in-flight batches — that
 *      lets the operator demo the full Start → Output → Finish flow
 *      without inheriting test data.
 *
 * Refuses to run in production. Idempotent: re-running yields the same
 * end state. Invoke with:
 *
 *   php spark db:seed FacilitiesSeeder
 */
final class FacilitiesSeeder extends Seeder
{
    /** @var list<array{code:string,name:string,description:string,expected_yield_pct:float,reference_duration_days:int}> */
    private const CATEGORIES = [
        [
            'code'                   => 'FOOD-SCRP',
            'name'                   => 'Food Scraps',
            'description'            => 'Cafeteria and kitchen food waste (vegetable peelings, leftovers, expired pre-prep).',
            'expected_yield_pct'     => 45.0,
            'reference_duration_days' => 30,
        ],
        [
            'code'                   => 'YARD-GRDN',
            'name'                   => 'Yard & Garden Waste',
            'description'            => 'Leaves, grass clippings, prunings, and other green waste from landscaping.',
            'expected_yield_pct'     => 60.0,
            'reference_duration_days' => 45,
        ],
        [
            'code'                   => 'MIXED-FY',
            'name'                   => 'Mixed Food + Yard',
            'description'            => 'Co-collected food scraps and yard waste — the standard brown-bin mix.',
            'expected_yield_pct'     => 50.0,
            'reference_duration_days' => 35,
        ],
    ];

    /** @var list<array{code:string,display_name:string,location_code:string,spec_capacity_kg:float,default_category_code:string,notes:string}> */
    private const UNITS = [
        [
            'code'                => 'DRM-01',
            'display_name'        => 'Drum 01 — Cafeteria',
            'location_code'       => 'Cafeteria, west loading dock',
            'spec_capacity_kg'    => 120.0,
            'default_category_code' => 'FOOD-SCRP',
            'notes'               => 'Primary food-scraps drum. Cleared twice weekly.',
        ],
        [
            'code'                => 'DRM-02',
            'display_name'        => 'Drum 02 — North Campus',
            'location_code'       => 'North Campus, near the canteen annex',
            'spec_capacity_kg'    => 120.0,
            'default_category_code' => 'MIXED-FY',
            'notes'               => 'Mixed-bin drum for the north cluster.',
        ],
        [
            'code'                => 'DRM-03',
            'display_name'        => 'Drum 03 — Grounds Shed',
            'location_code'       => 'Grounds maintenance shed',
            'spec_capacity_kg'    => 200.0,
            'default_category_code' => 'YARD-GRDN',
            'notes'               => 'Heavy-duty yard waste drum. Highest capacity on site.',
        ],
        [
            'code'                => 'DRM-04',
            'display_name'        => 'Drum 04 — South Hall',
            'location_code'       => 'South Hall, rear service road',
            'spec_capacity_kg'    => 120.0,
            'default_category_code' => 'FOOD-SCRP',
            'notes'               => 'Overflow drum; swap with DRM-01 during peak weeks.',
        ],
    ];

    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('FacilitiesSeeder must never run in production.');
        }

        $this->wipe();
        $this->seedCategories();
        $this->seedUnits();

        // Surface the canonical login so the developer can hit the
        // dashboard immediately after running the seeder.
        fwrite(STDOUT, "FacilitiesSeeder: 3 categories + 4 drums inserted.\n");
        fwrite(STDOUT, "  Admin login:  admin@synapse.dev / DevPassw0rd!\n");
    }

    private function wipe(): void
    {
        $db = $this->db;

        // We use DELETE rather than TRUNCATE: TRUNCATE bypasses FK
        // actions and MySQL refuses to truncate a table that is
        // referenced by a FK. DELETE respects the cascade / SET NULL
        // rules and works regardless of dependency direction.

        // Child-of-batch tables first (FKs to facilities_bmg_batches).
        $db->table('facilities_bmg_inputs')->emptyTable();
        $db->table('facilities_bmg_outputs')->emptyTable();
        $db->table('facilities_bmg_process_logs')->emptyTable();

        // Batches reference units + categories; clear before either.
        $db->table('facilities_bmg_batches')->emptyTable();

        // Units reference categories (default_category_id, nullable).
        $db->table('facilities_bmg_units')->emptyTable();

        // Categories have no children — clear last so the units can be
        // inserted with their default_category_id FKs already pointing
        // at valid rows.
        $db->table('facilities_waste_categories')->emptyTable();

        // Match the same set of rows in audit_outbox so re-seeding
        // doesn't pile up orphan audit entries. We do NOT touch
        // audit_events — those are append-only and immutable by design.
        $db->table('audit_outbox')
            ->groupStart()
                ->like('action_code', 'bmg.', 'after')
                ->orWhereIn('entity_type', [
                    'facilities_bmg_units',
                    'facilities_bmg_batches',
                    'facilities_bmg_process_logs',
                    'facilities_bmg_inputs',
                    'facilities_bmg_outputs',
                    'facilities_waste_categories',
                ])
            ->groupEnd()
            ->delete();
    }

    private function seedCategories(): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = array_map(
            static fn (array $c) => $c + ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            self::CATEGORIES,
        );
        $this->db->table('facilities_waste_categories')->insertBatch($rows);
    }

    private function seedUnits(): void
    {
        // Resolve the just-inserted category codes to their new ids so
        // the FK is honoured.
        $catByCode = [];
        foreach ($this->db->table('facilities_waste_categories')->get()->getResultArray() as $row) {
            $catByCode[(string) $row['code']] = (int) $row['id'];
        }

        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach (self::UNITS as $u) {
            $catCode = $u['default_category_code'];
            $rows[] = [
                'code'                => $u['code'],
                'display_name'        => $u['display_name'],
                'location_code'       => $u['location_code'],
                'status'              => BMG_STATE_IDLE,
                'spec_capacity_kg'    => $u['spec_capacity_kg'],
                'default_category_id' => $catByCode[$catCode] ?? null,
                'notes'               => $u['notes'],
                'archived_at'         => null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }
        $this->db->table('facilities_bmg_units')->insertBatch($rows);
    }
}
