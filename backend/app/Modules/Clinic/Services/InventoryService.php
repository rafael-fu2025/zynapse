<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\DTOs\InventoryItemDto;
use Modules\Clinic\Policies\ClinicPolicy;

/**
 * InventoryService — clinic supply stock (Phase 8).
 *
 * Stock changes go through `moveStock()` ONLY: item row is locked with
 * `selectForUpdate`, the non-negative invariant is asserted, the signed
 * movement is appended to the ledger, and the audit outbox row is
 * written in the SAME transaction.
 */
final class InventoryService extends BaseService
{
    public function __construct(
        private readonly ClinicPolicy $policy,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function listItems(?string $cursor, int $limit, ?string $q = null, bool $includeArchived = false, bool $lowStockOnly = false): array
    {
        $this->policy->check('inventoryRead');

        $builder = $this->db->table('clinic_inventory_items')
            ->select('id, sku, name, unit, quantity_on_hand, reorder_level, archived_at, created_at')
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        if (! $includeArchived) {
            $builder->where('archived_at', null);
        }

        // Frontend "Low stock only" chip — mirrors the DTO's `low_stock`
        // boolean (`quantity_on_hand <= reorder_level`). Both columns are
        // NOT NULL INT, so the comparison is safe.
        if ($lowStockOnly) {
            $builder->where('quantity_on_hand <= reorder_level', null, false);
        }

        $qTrim = $q !== null ? trim($q) : '';
        if ($qTrim !== '') {
            $like  = '%' . $this->db->escapeLikeString($qTrim) . '%';
            $builder->groupStart()
                ->like('sku', $like)
                ->orLike('name', $like)
                ->groupEnd();
        }

        KeysetPaginator::apply($builder, $cursor, $limit);

        $rows = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        $movement = $this->lastMovementByItem(array_map(static fn (array $r): int => (int) $r['id'], $final['rows']));

        return [
            'data'  => array_map(
                static fn (array $r) => InventoryItemDto::fromRow($r, $movement[(int) $r['id']] ?? null)->toArray(),
                $final['rows'],
            ),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    public function createItem(string $sku, string $name, string $unit, int $reorderLevel): InventoryItemDto
    {
        $this->policy->check('inventoryWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($sku, $name, $unit, $reorderLevel, $userId): InventoryItemDto {
            $existing = $this->db->table('clinic_inventory_items')
                ->where('sku', $sku)
                ->get()->getRowArray();
            if ($existing !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "SKU '{$sku}' already exists.", 'field' => 'sku'],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('clinic_inventory_items')->insert([
                'sku'              => $sku,
                'name'             => $name,
                'unit'             => $unit,
                'quantity_on_hand' => 0,
                'reorder_level'    => $reorderLevel,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'clinic.inventory_item_created',
                'clinic_inventory_items',
                $id,
                $userId,
                ['resource_code' => 'sku#' . $sku],
            );

            $row = $this->db->table('clinic_inventory_items')->where('id', $id)->get()->getRowArray();
            return InventoryItemDto::fromRow($row);
        });
    }

    /**
     * Update a supply item's catalog row (name, unit, reorder_level).
     * Stock is NOT touched here — `moveStock()` is the only path that
     * changes `quantity_on_hand`. SKU is intentionally immutable: the
     * SKU is the foreign key used by the movement ledger.
     */
    public function updateItem(int $itemId, string $name, string $unit, int $reorderLevel): InventoryItemDto
    {
        $this->policy->check('inventoryWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($itemId, $name, $unit, $reorderLevel, $userId): InventoryItemDto {
            $item = $this->selectForUpdate('clinic_inventory_items', ['id' => $itemId, 'archived_at' => null]);
            if ($item === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Inventory item #{$itemId} not found."],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('clinic_inventory_items')->where('id', $itemId)->update([
                'name'          => $name,
                'unit'          => $unit,
                'reorder_level' => $reorderLevel,
                'updated_at'    => $now,
            ]);

            $this->audit->enqueue(
                'clinic.inventory_item_updated',
                'clinic_inventory_items',
                $itemId,
                $userId,
                ['resource_code' => 'sku#' . (string) $item['sku']],
            );

            $row = $this->db->table('clinic_inventory_items')->where('id', $itemId)->get()->getRowArray();
            return InventoryItemDto::fromRow($row);
        });
    }

    /**
     * Soft-archive a supply item. Archived items drop out of the
     * default list, but every `clinic_inventory_movements` row stays
     * intact for the audit trail. Hard delete is intentionally NOT
     * supported — the movement ledger IS the clinical record.
     */
    public function archiveItem(int $itemId): InventoryItemDto
    {
        $this->policy->check('inventoryDelete');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($itemId, $userId): InventoryItemDto {
            $item = $this->selectForUpdate('clinic_inventory_items', ['id' => $itemId]);
            if ($item === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Inventory item #{$itemId} not found."],
                ]);
            }
            if ($item['archived_at'] !== null) {
                // Idempotent: already archived — just return the row.
                return InventoryItemDto::fromRow($item);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('clinic_inventory_items')
                ->where('id', $itemId)
                ->update(['archived_at' => $now, 'updated_at' => $now]);

            $this->audit->enqueue(
                'clinic.inventory_item_archived',
                'clinic_inventory_items',
                $itemId,
                $userId,
                ['resource_code' => 'sku#' . (string) $item['sku']],
            );

            $row = $this->db->table('clinic_inventory_items')->where('id', $itemId)->get()->getRowArray();
            return InventoryItemDto::fromRow($row);
        });
    }

    /**
     * Restore a soft-archived supply item: clears `archived_at` so the
     * row rejoins the default list. Idempotent — restoring an active
     * item just returns the row. Mirrors `archiveItem()` (same
     * permission, same-transaction audit row).
     */
    public function unarchiveItem(int $itemId): InventoryItemDto
    {
        $this->policy->check('inventoryDelete');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($itemId, $userId): InventoryItemDto {
            $item = $this->selectForUpdate('clinic_inventory_items', ['id' => $itemId]);
            if ($item === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Inventory item #{$itemId} not found."],
                ]);
            }
            if ($item['archived_at'] === null) {
                // Idempotent: not archived — just return the row.
                return InventoryItemDto::fromRow($item);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('clinic_inventory_items')
                ->where('id', $itemId)
                ->update(['archived_at' => null, 'updated_at' => $now]);

            $this->audit->enqueue(
                'clinic.inventory_item_restored',
                'clinic_inventory_items',
                $itemId,
                $userId,
                ['resource_code' => 'sku#' . (string) $item['sku']],
            );

            $row = $this->db->table('clinic_inventory_items')->where('id', $itemId)->get()->getRowArray();
            return InventoryItemDto::fromRow($row);
        });
    }

    /**
     * Apply a signed stock movement. `dispense` must be negative AND is
     * encounter-anchored (an open clinic visit is required, matching the
     * medicine dispense rule — inventory audit fix); `adjustment` may be
     * either but never below 0 on-hand. `receive` is rejected here —
     * received stock enters via `receiveOrdered()` so every receipt
     * traces back to a completed reorder request.
     */
    public function moveStock(int $itemId, int $qtyDelta, string $reasonCode, ?string $note, ?int $encounterId = null): InventoryItemDto
    {
        $this->policy->check('inventoryWrite');
        $userId = \App\Auth\CurrentUser::assert();

        if ($reasonCode === 'receive') {
            throw new ApiException('statemachine.reorder.not_received', 409, [
                ['code' => 'statemachine.reorder.not_received', 'message' => 'Receipts go through the procurement workflow. Order the item, mark the reorder received, then use Receive.', 'field' => 'reason_code'],
            ]);
        }

        return $this->txn(function () use ($itemId, $qtyDelta, $reasonCode, $note, $encounterId, $userId): InventoryItemDto {
            $item = $this->selectForUpdate('clinic_inventory_items', ['id' => $itemId, 'archived_at' => null]);

            if ($item === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Inventory item #{$itemId} not found."],
                ]);
            }

            $referenceType = null;
            $referenceId   = null;

            // Supplies are dispensed against an OPEN encounter so the
            // ledger records which visit consumed the stock (mirrors
            // MedicineService::dispense).
            if ($reasonCode === 'dispense') {
                if ($encounterId === null || $encounterId < 1) {
                    throw new ApiException('validation.field', 422, [
                        ['code' => 'validation.field', 'message' => 'A dispense must be tied to an open encounter.', 'field' => 'encounter_id'],
                    ]);
                }
                $enc = $this->selectForUpdate('clinic_encounters', ['id' => $encounterId, 'archived_at' => null]);
                if ($enc === null) {
                    throw new ApiException('resource.not_found', 404, [
                        ['code' => 'resource.not_found', 'message' => "Encounter #{$encounterId} not found.", 'field' => 'encounter_id'],
                    ]);
                }
                if ((string) $enc['status'] !== 'open') {
                    throw new ApiException('statemachine.clinic.encounter_closed', 409, [
                        ['code' => 'statemachine.clinic.encounter_closed', 'message' => 'Supplies can only be dispensed against an open encounter.', 'field' => 'encounter_id'],
                    ]);
                }
                $referenceType = 'encounter';
                $referenceId   = $encounterId;
            }

            $newQty = (int) $item['quantity_on_hand'] + $qtyDelta;
            if ($newQty < 0) {
                throw new ApiException('statemachine.inventory.negative_stock', 409, [
                    ['code' => 'statemachine.inventory.negative_stock', 'message' => 'Movement would drive stock below zero.', 'field' => 'qty_delta'],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('clinic_inventory_items')
                ->where('id', $itemId)
                ->update(['quantity_on_hand' => $newQty, 'updated_at' => $now]);

            $this->db->table('clinic_inventory_movements')->insert([
                'item_id'          => $itemId,
                'qty_delta'        => $qtyDelta,
                'reason_code'      => $reasonCode,
                'reference_type'   => $referenceType,
                'reference_id'     => $referenceId,
                'balance_after'    => $newQty,
                'moved_by_user_id' => $userId,
                'note'             => $note,
                'created_at'       => $now,
            ]);

            $this->audit->enqueue(
                'clinic.inventory_moved',
                'clinic_inventory_items',
                $itemId,
                $userId,
                ['reason_code' => $reasonCode],
            );

            $row = $this->db->table('clinic_inventory_items')->where('id', $itemId)->get()->getRowArray();
            return InventoryItemDto::fromRow($row);
        });
    }

    /**
     * Receive delivered stock against the procurement workflow: the
     * item must have a reorder request in `received` status. The
     * movement quantity is taken from that request; the reorder row is
     * marked `completed` in the SAME transaction (mirrors
     * MedicineService::addBatch for the batchless supply ledger).
     *
     * Partial deliveries are supported (inventory audit fix): a lower
     * `quantity` keeps the reorder in `received` so the operator can
     * chase the supplier, and the shortage is surfaced in the ledger +
     * an audit row — mirroring the medicine `addBatch` flow.
     */
    public function receiveOrdered(int $itemId, ?string $note, ?int $quantity = null, ?string $shortageNote = null): InventoryItemDto
    {
        $this->policy->check('inventoryWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($itemId, $note, $quantity, $shortageNote, $userId): InventoryItemDto {
            $item = $this->selectForUpdate('clinic_inventory_items', ['id' => $itemId, 'archived_at' => null]);
            if ($item === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Inventory item #{$itemId} not found."],
                ]);
            }

            // Gate: a delivery must have been marked `received` on the
            // Reorders tab. Locked so the request is consumed exactly once.
            $reorder = $this->selectForUpdate('clinic_reorder_requests', [
                'supply_item_id' => $itemId,
                'item_type'      => 'supply',
                'status'         => 'received',
            ]);
            if ($reorder === null) {
                throw new ApiException('statemachine.reorder.not_received', 409, [
                    ['code' => 'statemachine.reorder.not_received', 'message' => 'No received delivery for this item. Mark the reorder request as received first.'],
                ]);
            }

            $ordered = (int) $reorder['requested_quantity'];
            $qty     = $quantity !== null && $quantity > 0 ? $quantity : $ordered;
            if ($qty > $ordered) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'quantity cannot exceed the ordered amount.', 'field' => 'quantity'],
                ]);
            }

            $isPartial = $qty < $ordered;
            $newQty    = (int) $item['quantity_on_hand'] + $qty;
            $now       = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('clinic_inventory_items')
                ->where('id', $itemId)
                ->update(['quantity_on_hand' => $newQty, 'updated_at' => $now]);

            // Ledger note carries the shortage so the discrepancy is
            // visible without joining tables (mirrors addBatch).
            $shortageSuffix = '';
            if ($isPartial) {
                $shortage = $ordered - $qty;
                $reason   = $shortageNote !== null && $shortageNote !== '' ? ' — ' . $shortageNote : '';
                $shortageSuffix = sprintf(' (short by %d%s)', $shortage, $reason);
            }
            $ledgerNote = ($note ?? '') . $shortageSuffix;

            $this->db->table('clinic_inventory_movements')->insert([
                'item_id'          => $itemId,
                'qty_delta'        => $qty,
                'reason_code'      => 'receive',
                'reference_type'   => 'reorder',
                'reference_id'     => (int) $reorder['id'],
                'balance_after'    => $newQty,
                'moved_by_user_id' => $userId,
                'note'             => $ledgerNote !== '' ? $ledgerNote : null,
                'created_at'       => $now,
            ]);

            // Close the procurement loop only on a full delivery; a
            // partial keeps the reorder in `received` so the operator
            // can chase the supplier or raise a follow-up.
            if ($isPartial) {
                $this->db->table('clinic_reorder_requests')
                    ->where('id', (int) $reorder['id'])
                    ->update([
                        'status'               => 'received',
                        'actual_delivery_date' => substr($now, 0, 10),
                        'updated_at'           => $now,
                    ]);
                $this->audit->enqueue(
                    'clinic.reorder_partial',
                    'clinic_reorder_requests',
                    (int) $reorder['id'],
                    $userId,
                    [
                        'resource_code' => 'supply#' . (string) $itemId,
                        'outcome'       => 'partial',
                        'ordered'       => $ordered,
                        'received'      => $qty,
                    ],
                );
            } else {
                $this->db->table('clinic_reorder_requests')
                    ->where('id', (int) $reorder['id'])
                    ->update(['status' => 'completed', 'fulfilled_at' => $now, 'updated_at' => $now]);
                $this->audit->enqueue(
                    'clinic.reorder_completed',
                    'clinic_reorder_requests',
                    (int) $reorder['id'],
                    $userId,
                    ['resource_code' => 'supply#' . (string) $itemId, 'outcome' => 'completed'],
                );
            }

            $this->audit->enqueue(
                'clinic.inventory_moved',
                'clinic_inventory_items',
                $itemId,
                $userId,
                ['reason_code' => 'receive'],
            );

            $row = $this->db->table('clinic_inventory_items')->where('id', $itemId)->get()->getRowArray();
            return InventoryItemDto::fromRow($row);
        });
    }

    /**
     * Ledger view: the item's signed movements in chronological order
     * with the stored running balance (panel revision — in/out
     * debit-credit tracking). Capped at the most recent 200 rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMovements(int $itemId, int $limit = 200): array
    {
        $this->policy->check('inventoryRead');

        $item = $this->db->table('clinic_inventory_items')->select('id')->where('id', $itemId)->get()->getRowArray();
        if ($item === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Inventory item #{$itemId} not found."],
            ]);
        }

        // Newest N rows, then flip so the ledger reads oldest → newest.
        // The actor's email/username is joined so the ledger shows WHO
        // moved the stock (inventory audit gap); reference_* records
        // which encounter / reorder the movement belonged to.
        $rows = $this->db->table('clinic_inventory_movements m')
            ->select('m.id, m.qty_delta, m.reason_code, m.reference_type, m.reference_id, m.balance_after, m.moved_by_user_id, m.note, m.created_at, COALESCE(NULLIF(ai.secret, \'\'), u.username) AS user_email')
            ->join('users u', 'u.id = m.moved_by_user_id', 'left')
            ->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'left')
            ->where('m.item_id', $itemId)
            ->orderBy('m.id', 'DESC')
            ->limit(max(1, min($limit, 500)))
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'             => (int) $r['id'],
            'reason_code'    => (string) $r['reason_code'],
            'qty_in'         => (int) $r['qty_delta'] > 0 ? (int) $r['qty_delta'] : null,
            'qty_out'        => (int) $r['qty_delta'] < 0 ? abs((int) $r['qty_delta']) : null,
            'balance_after'  => $r['balance_after'] !== null ? (int) $r['balance_after'] : null,
            'reference_type' => $r['reference_type'] !== null ? (string) $r['reference_type'] : null,
            'reference_id'   => $r['reference_id'] !== null ? (int) $r['reference_id'] : null,
            'user_email'     => $r['user_email'] !== null ? (string) $r['user_email'] : null,
            'note'           => $r['note'] !== null ? (string) $r['note'] : null,
            'created_at'     => (string) $r['created_at'],
        ], array_reverse($rows));
    }

    /**
     * Latest movement per item in ONE query — powers the row-level
     * "last move" hint on the Supplies tab (mirrors the medicine
     * `lastMovementByMedicine` helper).
     *
     * @param array<int, int> $ids
     * @return array<int, array{reason_code: string, qty_delta: int, created_at: string, user_email: ?string}>
     */
    private function lastMovementByItem(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $idList = implode(',', array_map(static fn ($id) => (string) (int) $id, $ids));

        $rows = $this->db->query(
            "SELECT m.item_id, m.reason_code, m.qty_delta, m.created_at,"
            . " COALESCE(NULLIF(ai.secret, ''), u.username) AS user_email"
            . " FROM `clinic_inventory_movements` m"
            . " INNER JOIN ("
            . "   SELECT item_id, MAX(id) AS max_id"
            . "   FROM `clinic_inventory_movements`"
            . "   WHERE item_id IN ($idList)"
            . "   GROUP BY item_id"
            . " ) latest ON latest.max_id = m.id"
            . ' LEFT JOIN `users` u ON u.id = m.moved_by_user_id'
            . " LEFT JOIN `auth_identities` ai"
            . "   ON ai.user_id = u.id AND ai.type = 'email_password'"
        )->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['item_id']] = [
                'reason_code' => (string) $r['reason_code'],
                'qty_delta'   => (int)    $r['qty_delta'],
                'created_at'  => (string) $r['created_at'],
                'user_email'  => $r['user_email'] !== null ? (string) $r['user_email'] : null,
            ];
        }
        return $out;
    }
}
