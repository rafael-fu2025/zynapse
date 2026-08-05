<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\StateMachineException;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use App\Services\Notify\NotificationOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\DTOs\ReorderDto;
use Modules\Clinic\Policies\ClinicPolicy;

/**
 * ReorderService — procurement workflow (Phase 13, recycled from
 * synapse_ag ProcurementRouter + ReorderRequestModel).
 *
 * Rules ported from the legacy module + the supply/completion rework:
 *   - ONE open request (pending/approved/ordered/received) per item.
 *     `received` counts as OPEN so auto-check cannot file a duplicate
 *     while the delivered stock still awaits entry.
 *   - Requests cover medicines (batch stock) AND supply items (ledger
 *     stock); exactly one of medicine_id / supply_item_id is set.
 *   - Auto-check scans every non-archived item with a positive
 *     threshold; when on-hand <= threshold and no open request
 *     exists, a `pending` request is auto-created with
 *     quantity = max(threshold * 2 - on_hand, threshold).
 *   - Lifecycle: pending → approved → ordered → received → completed;
 *     `cancelled` is reachable from pending/approved/ordered.
 *     `completed` is set by the stock-entry flows (medicine batch
 *     receive / supply receive), never by a direct transition.
 */
final class ReorderService extends BaseService
{
    private const OPEN_STATUSES = ['pending', 'approved', 'ordered', 'received'];

    /** @var array<string, array<int, string>> action => allowed current statuses */
    private const TRANSITIONS = [
        'approve' => ['pending'],
        'order'   => ['approved'],
        'receive' => ['ordered'],
        'cancel'  => ['pending', 'approved', 'ordered'],
    ];

    /** @var array<string, string> action => resulting status */
    private const RESULT = [
        'approve' => 'approved',
        'order'   => 'ordered',
        'receive' => 'received',
        'cancel'  => 'cancelled',
    ];

    public function __construct(
        private readonly ClinicPolicy $policy,
        private readonly AuditOutboxService $audit,
        private readonly NotificationOutboxService $notify,
    ) {
        parent::__construct();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function list(?string $cursor, int $limit, ?string $status, ?string $q = null, ?int $medicineId = null, ?int $supplyItemId = null): array
    {
        $this->policy->check('reordersRead');

        $builder = $this->db->table('clinic_reorder_requests r')
            ->select('r.*, m.generic_name, COALESCE(m.generic_name, i.name) AS item_name, COALESCE(m.unit, i.unit) AS unit')
            ->join('clinic_medicines m', 'm.id = r.medicine_id', 'left')
            ->join('clinic_inventory_items i', 'i.id = r.supply_item_id', 'left')
            ->orderBy('r.created_at', 'DESC')
            ->orderBy('r.id', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('r.status', $status);
        }
        if ($medicineId !== null) {
            $builder->where('r.medicine_id', $medicineId);
        }
        if ($supplyItemId !== null) {
            $builder->where('r.supply_item_id', $supplyItemId);
        }

        $qTrim = $q !== null ? trim($q) : '';
        if ($qTrim !== '') {
            $like  = '%' . $this->db->escapeLikeString($qTrim) . '%';
            $builder->groupStart()
                ->like('m.generic_name', $like)
                ->orLike('i.name', $like)
                ->orLike('r.procurement_note', $like)
                ->groupEnd();
        }

        KeysetPaginator::apply($builder, $cursor, $limit, 'r.created_at', 'r.id');

        $rows  = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        return [
            'data'  => array_map(static fn (array $r) => ReorderDto::fromRow($r)->toArray(), $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    /** Manual request (legacy ReorderController::store). Supports both item types. */
    public function create(string $itemType, int $itemId, int $quantity, string $urgency, ?string $note): ReorderDto
    {
        $this->policy->check('reordersManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($itemType, $itemId, $quantity, $urgency, $note, $userId): ReorderDto {
            if ($itemType === 'supply') {
                $item = $this->selectForUpdate('clinic_inventory_items', ['id' => $itemId, 'archived_at' => null]);
                if ($item === null) {
                    throw new ApiException('resource.not_found', 404, [
                        ['code' => 'resource.not_found', 'message' => "Inventory item #{$itemId} not found."],
                    ]);
                }
                $this->assertNoOpenRequest('supply', $itemId);
                $id = $this->insertRequest('supply', $itemId, $quantity, (int) $item['quantity_on_hand'], (int) $item['reorder_level'], $urgency, false, $userId, $note);

                return $this->getDto($id);
            }

            $med = $this->selectForUpdate('clinic_medicines', ['id' => $itemId, 'archived_at' => null]);
            if ($med === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Medicine #{$itemId} not found."],
                ]);
            }

            $this->assertNoOpenRequest('medicine', $itemId);

            $id = $this->insertRequest('medicine', $itemId, $quantity, $this->onHand($itemId), (int) $med['reorder_threshold'], $urgency, false, $userId, $note);
            return $this->getDto($id);
        });
    }

    /**
     * Ports ProcurementRouter::checkAll — scan all medicines AND supply
     * items, create pending requests where stock has fallen to the
     * threshold. `hasOpenRequest` (which now includes `received`)
     * guarantees one in-flight request per item, so re-running the
     * check never duplicates an entry.
     *
     * @return array<int, array<string, mixed>> the created requests
     */
    public function autoCheck(): array
    {
        $this->policy->check('reordersManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($userId): array {
            $created = [];

            $medicines = $this->db->table('clinic_medicines')
                ->select('id, reorder_threshold')
                ->where('archived_at', null)
                ->where('reorder_threshold >', 0)
                ->get()->getResultArray();

            foreach ($medicines as $med) {
                $medicineId = (int) $med['id'];
                $threshold  = (int) $med['reorder_threshold'];

                // Lock the item row so two concurrent auto-checks cannot
                // both pass the open-request probe and file duplicates.
                $this->selectForUpdate('clinic_medicines', ['id' => $medicineId]);

                $onHand = $this->onHand($medicineId);
                if ($onHand > $threshold || $this->hasOpenRequest('medicine', $medicineId)) {
                    continue;
                }

                // Legacy heuristic: restock to twice the threshold — this IS
                // the quantity that will be ordered and later received.
                $qty = max($threshold * 2 - $onHand, $threshold);
                // Legacy urgency tiers by depletion ratio.
                $urgency = $onHand === 0 ? 'critical' : ($onHand <= (int) floor($threshold / 2) ? 'high' : 'medium');

                $id        = $this->insertRequest('medicine', $medicineId, $qty, $onHand, $threshold, $urgency, true, $userId, 'Auto-triggered by low-stock check.');
                $created[] = $this->getDto($id)->toArray();
                $this->notifyReorderCreated($id, $urgency);
            }

            // Supply items: same heuristic against the ledger counter.
            $items = $this->db->table('clinic_inventory_items')
                ->select('id, quantity_on_hand, reorder_level')
                ->where('archived_at', null)
                ->where('reorder_level >', 0)
                ->get()->getResultArray();

            foreach ($items as $item) {
                $itemId    = (int) $item['id'];
                $threshold = (int) $item['reorder_level'];

                // Lock the item row (see medicine loop above).
                $this->selectForUpdate('clinic_inventory_items', ['id' => $itemId]);
                $onHand = (int) $item['quantity_on_hand'];

                if ($onHand > $threshold || $this->hasOpenRequest('supply', $itemId)) {
                    continue;
                }

                $qty     = max($threshold * 2 - $onHand, $threshold);
                $urgency = $onHand === 0 ? 'critical' : ($onHand <= (int) floor($threshold / 2) ? 'high' : 'medium');

                $id        = $this->insertRequest('supply', $itemId, $qty, $onHand, $threshold, $urgency, true, $userId, 'Auto-triggered by low-stock check.');
                $created[] = $this->getDto($id)->toArray();
                $this->notifyReorderCreated($id, $urgency);
            }

            return $created;
        });
    }

    /** Lifecycle transition (approve / order / receive / cancel). */
    public function transition(int $id, string $action, ?string $expectedDelivery, ?string $note): ReorderDto
    {
        $this->policy->check('reordersManage');
        $userId = \App\Auth\CurrentUser::assert();

        if (! isset(self::TRANSITIONS[$action])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => "Unknown action '{$action}'.", 'field' => 'action'],
            ]);
        }

        return $this->txn(function () use ($id, $action, $expectedDelivery, $note, $userId): ReorderDto {
            $row = $this->selectForUpdate('clinic_reorder_requests', ['id' => $id]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Reorder request #{$id} not found."],
                ]);
            }

            $current = (string) $row['status'];
            if (! in_array($current, self::TRANSITIONS[$action], true)) {
                throw StateMachineException::invalidTransition($current, self::RESULT[$action], 'reorder');
            }

            $now    = $this->utcNow();
            $today  = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
            $update = ['status' => self::RESULT[$action], 'updated_at' => $now];

            if ($action === 'approve') {
                $update['approved_by_user_id'] = $userId;
            }
            if ($action === 'order') {
                $update['order_date'] = $today;
                if ($expectedDelivery !== null && $expectedDelivery !== '') {
                    $update['expected_delivery_date'] = $expectedDelivery;
                }
            }
            if ($action === 'receive') {
                $update['actual_delivery_date'] = $today;
            }
            if ($note !== null && $note !== '') {
                $update['procurement_note'] = $note;
            }

            $this->db->table('clinic_reorder_requests')->where('id', $id)->update($update);

            $this->audit->enqueue(
                'clinic.reorder_' . self::RESULT[$action],
                'clinic_reorder_requests',
                $id,
                $userId,
                ['outcome' => self::RESULT[$action]],
            );

            return $this->getDto($id);
        });
    }

    // ------------------------------------------------------------ helpers

    private function notifyReorderCreated(int $reorderId, string $urgency): void
    {
        $this->notify->enqueueToPermissions(
            ['clinic.reorders.read'],
            'reorder.created',
            [
                'resource_code' => 'reorder#' . $reorderId,
                'next_status'   => 'pending',
                'urgency'       => $urgency,
            ],
        );
    }

    private function insertRequest(string $itemType, int $itemId, int $qty, int $onHand, int $threshold, string $urgency, bool $auto, int $userId, ?string $note): int
    {
        $now = $this->utcNow();
        $this->db->table('clinic_reorder_requests')->insert([
            'item_type'            => $itemType,
            'medicine_id'          => $itemType === 'medicine' ? $itemId : null,
            'supply_item_id'       => $itemType === 'supply' ? $itemId : null,
            'requested_quantity'   => $qty,
            'current_stock'        => $onHand,
            'reorder_level'        => $threshold,
            'urgency'              => $urgency,
            'status'               => 'pending',
            'auto_triggered'       => $auto ? 1 : 0,
            'requested_by_user_id' => $userId,
            'procurement_note'     => $note,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
        $id = (int) $this->db->insertID();

        $this->audit->enqueue(
            'clinic.reorder_requested',
            'clinic_reorder_requests',
            $id,
            $userId,
            ['resource_code' => $itemType . '#' . (string) $itemId, 'outcome' => $auto ? 'auto' : 'manual'],
        );

        return $id;
    }

    private function hasOpenRequest(string $itemType, int $itemId): bool
    {
        return $this->db->table('clinic_reorder_requests')
            ->where($itemType === 'supply' ? 'supply_item_id' : 'medicine_id', $itemId)
            ->whereIn('status', self::OPEN_STATUSES)
            ->get()->getRowArray() !== null;
    }

    private function assertNoOpenRequest(string $itemType, int $itemId): void
    {
        if ($this->hasOpenRequest($itemType, $itemId)) {
            throw new ApiException('resource.conflict', 409, [
                ['code' => 'resource.conflict', 'message' => 'An open reorder request already exists for this item.', 'field' => $itemType === 'supply' ? 'supply_item_id' : 'medicine_id'],
            ]);
        }
    }

    /** Unexpired active stock (same definition as MedicineService). */
    private function onHand(int $medicineId): int
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

        $row = $this->db->table('clinic_medicine_batches')
            ->select('SUM(quantity_remaining) AS on_hand')
            ->where('medicine_id', $medicineId)
            ->where('status', 'active')
            ->where('quantity_remaining >', 0)
            ->where('expiration_date >=', $today)
            ->get()->getRowArray();

        return (int) ($row['on_hand'] ?? 0);
    }

    private function getDto(int $id): ReorderDto
    {
        $row = $this->db->table('clinic_reorder_requests r')
            ->select('r.*, m.generic_name, COALESCE(m.generic_name, i.name) AS item_name, COALESCE(m.unit, i.unit) AS unit')
            ->join('clinic_medicines m', 'm.id = r.medicine_id', 'left')
            ->join('clinic_inventory_items i', 'i.id = r.supply_item_id', 'left')
            ->where('r.id', $id)
            ->get()->getRowArray();
        return ReorderDto::fromRow($row);
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
