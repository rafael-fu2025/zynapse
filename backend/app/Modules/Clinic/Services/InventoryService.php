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
    public function listItems(?string $cursor, int $limit): array
    {
        $this->policy->check('inventoryRead');

        $builder = $this->db->table('clinic_inventory_items')
            ->select('id, sku, name, unit, quantity_on_hand, reorder_level, created_at')
            ->where('archived_at', null)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        KeysetPaginator::apply($builder, $cursor, $limit);

        $rows = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        return [
            'data'  => array_map(static fn (array $r) => InventoryItemDto::fromRow($r)->toArray(), $final['rows']),
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
     * Apply a signed stock movement. `receive` must be positive,
     * `dispense` negative; `adjustment` may be either but never below 0
     * on-hand.
     */
    public function moveStock(int $itemId, int $qtyDelta, string $reasonCode, ?string $note): InventoryItemDto
    {
        $this->policy->check('inventoryWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($itemId, $qtyDelta, $reasonCode, $note, $userId): InventoryItemDto {
            $item = $this->selectForUpdate('clinic_inventory_items', ['id' => $itemId, 'archived_at' => null]);

            if ($item === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Inventory item #{$itemId} not found."],
                ]);
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
}
