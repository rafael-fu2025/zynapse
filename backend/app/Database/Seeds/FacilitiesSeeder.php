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
        $this->seedActiveBatches();

        // Surface the canonical login so the developer can hit the
        // dashboard immediately after running the seeder.
        fwrite(STDOUT, "FacilitiesSeeder: 3 categories + 4 drums + 3 active batches inserted.\n");
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

    /**
     * Seed three in-flight batches so the "Processing Drums" card
     * and the per-unit "Active batch" column have data to show.
     *
     * Pins batches to the canonical DRM-01..04 units, transitions
     * each unit's status to match the batch lifecycle (Processing /
     * AwaitingOutput), and keeps one unit Idle so the operator can
     * demo the "Start batch" flow.
     *
     * Idempotent: the wipe() call empties `facilities_bmg_batches`
     * before we run, so re-seeding always yields the same end state.
     */
    private function seedActiveBatches(): void
    {
        $now = date('Y-m-d H:i:s');
        $startedAgo = (new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s');
        $awaitingAgo = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');

        // Map of unit_code => demo batch payload + post-batch unit status.
        $plan = [
            [
                'unit_code'        => 'DRM-01',
                'reference_code'   => 'BATCH-2026-0001',
                'status'           => BMG_STATE_PROCESSING,
                'unit_status'      => BMG_STATE_PROCESSING,
                'started_at'       => $startedAgo,
                'awaiting_output_at' => null,
                'finished_at'      => null,
                'total_input_kg'   => 80.0000,
                'output_kg'        => null,
                'input_items'      => json_encode([
                    ['category_code' => 'FOOD-SCRP', 'category_name' => 'Food Scraps', 'weight_kg' => 80.0],
                ], JSON_UNESCAPED_SLASHES),
                'output_items'     => null,
                'notes'            => 'Demo batch — cafeteria scraps, day 3 of composting cycle.',
            ],
            [
                'unit_code'        => 'DRM-02',
                'reference_code'   => 'BATCH-2026-0002',
                'status'           => BMG_STATE_PROCESSING,
                'unit_status'      => BMG_STATE_PROCESSING,
                'started_at'       => $startedAgo,
                'awaiting_output_at' => null,
                'finished_at'      => null,
                'total_input_kg'   => 120.0000,
                'output_kg'        => null,
                'input_items'      => json_encode([
                    ['category_code' => 'MIXED-FY', 'category_name' => 'Mixed Food + Yard', 'weight_kg' => 120.0],
                ], JSON_UNESCAPED_SLASHES),
                'output_items'     => null,
                'notes'            => 'Demo batch — north canteen mixed load, day 3 of composting cycle.',
            ],
            [
                'unit_code'        => 'DRM-03',
                'reference_code'   => 'BATCH-2026-0003',
                'status'           => BMG_STATE_AWAITING_OUTPUT,
                'unit_status'      => BMG_STATE_AWAITING_OUTPUT,
                'started_at'       => $startedAgo,
                'awaiting_output_at' => $awaitingAgo,
                'finished_at'      => null,
                'total_input_kg'   => 150.0000,
                'output_kg'        => null,
                'input_items'      => json_encode([
                    ['category_code' => 'YARD-GRDN', 'category_name' => 'Yard & Garden Waste', 'weight_kg' => 150.0],
                ], JSON_UNESCAPED_SLASHES),
                'output_items'     => null,
                'notes'            => 'Demo batch — grounds shed greens, ready to record output.',
            ],
            // DRM-04 is intentionally left Idle so the operator can
            // demo the "Start batch" flow against a fresh unit.
        ];

        $unitByCode = [];
        foreach ($this->db->table('facilities_bmg_units')->get()->getResultArray() as $row) {
            $unitByCode[(string) $row['code']] = (int) $row['id'];
        }

        $rows = [];
        $unitStatusUpdates = [];
        foreach ($plan as $b) {
            if (! isset($unitByCode[$b['unit_code']])) {
                continue;
            }
            $rows[] = [
                'unit_id'              => $unitByCode[$b['unit_code']],
                'reference_code'       => $b['reference_code'],
                'status'               => $b['status'],
                'total_input_weight_kg' => $b['total_input_kg'],
                'output_weight_kg'     => $b['output_kg'],
                'input_items'          => $b['input_items'],
                'output_items'         => $b['output_items'],
                'notes'                => $b['notes'],
                'started_by_user_id'   => 1,
                'finished_by_user_id'  => null,
                'started_at'           => $b['started_at'],
                'awaiting_output_at'   => $b['awaiting_output_at'],
                'finished_at'          => $b['finished_at'],
                'cancelled_at'         => null,
                'archived_at'          => null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
            $unitStatusUpdates[$b['unit_code']] = $b['unit_status'];
        }

        if ($rows === []) {
            return;
        }

        $this->db->table('facilities_bmg_batches')->insertBatch($rows);

        // Synchronise the unit's status to match the freshly-seeded
        // batch so the BMG list and the "Processing Drums" card agree.
        foreach ($unitStatusUpdates as $code => $status) {
            $this->db->table('facilities_bmg_units')
                ->where('code', $code)
                ->update(['status' => $status, 'updated_at' => $now]);
        }
    }
}
