<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\StateMachineException;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\DTOs\ReorderDto;
use Modules\Clinic\Policies\ClinicPolicy;

/**
 * ReorderService — procurement workflow (Phase 13, recycled from
 * synapse_ag ProcurementRouter + ReorderRequestModel).
 *
 * Rules ported from the legacy module:
 *   - ONE open request (pending/approved/ordered) per medicine.
 *   - Auto-check scans every non-archived medicine with a positive
 *     threshold; when unexpired on-hand <= threshold and no open
 *     request exists, a `pending` request is auto-created with
 *     quantity = max(threshold * 2 - on_hand, threshold).
 *   - Lifecycle: pending → approved → ordered → received;
 *     `cancelled` is reachable from any non-terminal state.
 */
final class ReorderService extends BaseService
{
    private const OPEN_STATUSES = ['pending', 'approved', 'ordered'];

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
    ) {
        parent::__construct();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function list(?string $cursor, int $limit, ?string $status): array
    {
        $this->policy->check('reordersRead');

        $builder = $this->db->table('clinic_reorder_requests r')
            ->select('r.*, m.generic_name, m.unit')
            ->join('clinic_medicines m', 'm.id = r.medicine_id')
            ->orderBy('r.created_at', 'DESC')
            ->orderBy('r.id', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('r.status', $status);
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

    /** Manual request (legacy ReorderController::store). */
    public function create(int $medicineId, int $quantity, string $urgency, ?string $note): ReorderDto
    {
        $this->policy->check('reordersManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($medicineId, $quantity, $urgency, $note, $userId): ReorderDto {
            $med = $this->selectForUpdate('clinic_medicines', ['id' => $medicineId, 'archived_at' => null]);
            if ($med === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Medicine #{$medicineId} not found."],
                ]);
            }

            $this->assertNoOpenRequest($medicineId);

            $id = $this->insertRequest($medicineId, $quantity, $this->onHand($medicineId), (int) $med['reorder_threshold'], $urgency, false, $userId, $note);
            return $this->getDto($id);
        });
    }

    /**
     * Ports ProcurementRouter::checkAll — scan all medicines, create
     * pending requests where stock has fallen to the threshold.
     *
     * @return array<int, array<string, mixed>> the created requests
     */
    public function autoCheck(): array
    {
        $this->policy->check('reordersManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($userId): array {
            $medicines = $this->db->table('clinic_medicines')
                ->select('id, reorder_threshold')
                ->where('archived_at', null)
                ->where('reorder_threshold >', 0)
                ->get()->getResultArray();

            $created = [];
            foreach ($medicines as $med) {
                $medicineId = (int) $med['id'];
                $threshold  = (int) $med['reorder_threshold'];

                $onHand = $this->onHand($medicineId);
                if ($onHand > $threshold || $this->hasOpenRequest($medicineId)) {
                    continue;
                }

                // Legacy heuristic: restock to twice the threshold.
                $qty = max($threshold * 2 - $onHand, $threshold);
                // Legacy urgency tiers by depletion ratio.
                $urgency = $onHand === 0 ? 'critical' : ($onHand <= (int) floor($threshold / 2) ? 'high' : 'medium');

                $id        = $this->insertRequest($medicineId, $qty, $onHand, $threshold, $urgency, true, $userId, 'Auto-triggered by low-stock check.');
                $created[] = $this->getDto($id)->toArray();
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

    private function insertRequest(int $medicineId, int $qty, int $onHand, int $threshold, string $urgency, bool $auto, int $userId, ?string $note): int
    {
        $now = $this->utcNow();
        $this->db->table('clinic_reorder_requests')->insert([
            'medicine_id'          => $medicineId,
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
            ['resource_code' => 'medicine#' . (string) $medicineId, 'outcome' => $auto ? 'auto' : 'manual'],
        );

        return $id;
    }

    private function hasOpenRequest(int $medicineId): bool
    {
        return $this->db->table('clinic_reorder_requests')
            ->where('medicine_id', $medicineId)
            ->whereIn('status', self::OPEN_STATUSES)
            ->get()->getRowArray() !== null;
    }

    private function assertNoOpenRequest(int $medicineId): void
    {
        if ($this->hasOpenRequest($medicineId)) {
            throw new ApiException('resource.conflict', 409, [
                ['code' => 'resource.conflict', 'message' => 'An open reorder request already exists for this medicine.', 'field' => 'medicine_id'],
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
            ->select('r.*, m.generic_name, m.unit')
            ->join('clinic_medicines m', 'm.id = r.medicine_id')
            ->where('r.id', $id)
            ->get()->getRowArray();
        return ReorderDto::fromRow($row);
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
