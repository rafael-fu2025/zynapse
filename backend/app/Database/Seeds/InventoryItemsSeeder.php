<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * InventoryItemsSeeder — DEV/STAGING ONLY.
 *
 * Wipes + seeds `clinic_inventory_items` with realistic school-clinic
 * consumables and 2 movements per item (1 initial receive + 1
 * recent dispense/adjustment) so the stock-history graph has data
 * on first load.
 *
 * Items: gloves, masks, ORS, paracetamol, bandages, alcohol, BP cuff
 * batteries, gauze, thermometer covers, cold packs.
 *
 * Reorder levels are set so 3 items sit BELOW the reorder level —
 * that exercises the `low-stock` filter and reorder auto-check.
 *
 * Refuses to run in production. Idempotent.
 */
final class InventoryItemsSeeder extends Seeder
{
    /**
     * @return list<array{sku:string, name:string, unit:string, quantity_on_hand:int, reorder_level:int}>
     */
    private function items(): array
    {
        return [
            ['sku' => 'GLV-M-100',     'name' => 'Disposable gloves (M, 100/box)',     'unit' => 'box',  'quantity_on_hand' => 4,  'reorder_level' => 5],  // low
            ['sku' => 'MSK-3PLY-50',   'name' => '3-ply surgical mask (50/box)',       'unit' => 'box',  'quantity_on_hand' => 12, 'reorder_level' => 6],
            ['sku' => 'ORS-21G',       'name' => 'ORS sachets (21g, 25/box)',          'unit' => 'box',  'quantity_on_hand' => 3,  'reorder_level' => 4],  // low
            ['sku' => 'PCM-500-100',   'name' => 'Paracetamol 500mg (100/btl)',        'unit' => 'btl',  'quantity_on_hand' => 14, 'reorder_level' => 5],
            ['sku' => 'BDG-25-PK',     'name' => 'Adhesive bandages 25x72mm (100/pk)', 'unit' => 'pack', 'quantity_on_hand' => 22, 'reorder_level' => 8],
            ['sku' => 'ALC-70-500',    'name' => 'Isopropyl alcohol 70% (500ml)',       'unit' => 'btl',  'quantity_on_hand' => 9,  'reorder_level' => 4],
            ['sku' => 'GAU-5CM',       'name' => 'Gauze roll 5cm (12/roll)',            'unit' => 'pack', 'quantity_on_hand' => 2,  'reorder_level' => 4],  // low
            ['sku' => 'BP-CUF-AA',     'name' => 'BP cuff battery (AA, 4/pk)',         'unit' => 'pack', 'quantity_on_hand' => 11, 'reorder_level' => 3],
            ['sku' => 'THM-CV-200',    'name' => 'Thermometer probe covers (200/box)', 'unit' => 'box',  'quantity_on_hand' => 5,  'reorder_level' => 2],
            ['sku' => 'CLD-PK-6',      'name' => 'Instant cold packs (6/pk)',           'unit' => 'pack', 'quantity_on_hand' => 7,  'reorder_level' => 3],
        ];
    }

    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('InventoryItemsSeeder must never run in production.');
        }

        $nurseId = $this->findNurseId();
        if ($nurseId === null) {
            throw new \RuntimeException('InventoryItemsSeeder: no clinic_staff user found. Run DevUserSeeder first.');
        }

        $this->wipe();
        $itemIds = $this->seedItems();
        $this->seedMovements($itemIds, $nurseId);

        fwrite(STDOUT, sprintf("InventoryItemsSeeder: %d items + %d movements inserted (3 below reorder level).\n",
            count($itemIds),
            count($itemIds) * 2,
        ));
    }

    private function findNurseId(): ?int
    {
        $row = $this->db->table('auth_groups_users agu')
            ->select('agu.user_id')
            ->join('auth_groups g', 'g.id = agu.group_id', 'inner', false)
            ->where('g.name', 'clinic_staff')
            ->orderBy('agu.user_id', 'ASC')
            ->limit(1)
            ->get()->getRowArray();
        return $row !== null ? (int) $row['user_id'] : null;
    }

    private function wipe(): void
    {
        // FK-safe: disable checks so reorder_requests (FK to items) and
        // movements are cleared in any order.
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->db->table('clinic_reorder_requests')->emptyTable();
            $this->db->table('clinic_inventory_movements')->emptyTable();
            $this->db->table('clinic_inventory_items')->emptyTable();
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->db->table('audit_outbox')
            ->groupStart()
                ->like('action_code', 'clinic.inventory_', 'after')
                ->orWhere('entity_type', 'clinic_inventory_items')
                ->orWhere('entity_type', 'clinic_inventory_movements')
            ->groupEnd()
            ->delete();
    }

    /**
     * @return list<int>
     */
    private function seedItems(): array
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($this->items() as $i) {
            $rows[] = $i + ['archived_at' => null, 'created_at' => $now, 'updated_at' => $now];
        }
        $this->db->table('clinic_inventory_items')->insertBatch($rows);

        // Read the ids back so the movements table can FK them.
        $result = $this->db->table('clinic_inventory_items')
            ->select('id, sku')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
        return array_map(static fn (array $r) => (int) $r['id'], $result);
    }

    /**
     * @param list<int> $itemIds
     */
    private function seedMovements(array $itemIds, int $userId): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $items = $this->items();

        $rows = [];
        foreach ($itemIds as $idx => $id) {
            $item = $items[$idx] ?? null;
            if ($item === null) {
                continue;
            }
            // 1. Initial bulk receive (14 days ago) — qty arrives at current_on_hand.
            $rows[] = [
                'item_id'         => $id,
                'qty_delta'       => $item['quantity_on_hand'],
                'reason_code'     => 'receive',
                'moved_by_user_id' => $userId,
                'note'            => sprintf('Initial stock count (%s).', $item['sku']),
                'created_at'      => $now->modify('-14 days')->format('Y-m-d H:i:s'),
            ];
            // 2. Recent dispense (today) — small qty, exercises history.
            $rows[] = [
                'item_id'         => $id,
                'qty_delta'       => 1,
                'reason_code'     => 'dispense',
                'moved_by_user_id' => $userId,
                'note'            => 'Dispensed to clinic floor.',
                'created_at'      => $now->format('Y-m-d H:i:s'),
            ];
        }
        $this->db->table('clinic_inventory_movements')->insertBatch($rows);
    }
}
