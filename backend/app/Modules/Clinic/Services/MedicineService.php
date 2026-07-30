<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\DTOs\MedicineBatchDto;
use Modules\Clinic\DTOs\MedicineDto;
use Modules\Clinic\Policies\ClinicPolicy;

/**
 * MedicineService — batch-tracked medicine inventory (Phase 12,
 * recycled from synapse_ag medicines/medicine_batches/transactions).
 *
 * Rules ported from the legacy module:
 *   - Stock = SUM(quantity_remaining) of ACTIVE, unexpired batches.
 *   - Dispensing is FEFO: consume from the earliest-expiring batch
 *     first, under SELECT ... FOR UPDATE; batches reaching zero are
 *     marked `depleted` in the same transaction.
 *   - Every stock change appends a typed row to the transactions
 *     ledger (never updated or deleted) + an audit outbox row.
 */
final class MedicineService extends BaseService
{
    private const MED_COLS = 'id, generic_name, brand_name, category, dosage_form, dosage_strength, unit, reorder_threshold, description, archived_at, created_at, updated_at';
    private const BATCH_COLS = 'id, medicine_id, batch_number, quantity_received, quantity_remaining, expiration_date, received_date, supplier, status, created_at';

    public function __construct(
        private readonly ClinicPolicy $policy,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function listMedicines(?string $cursor, int $limit, ?string $q = null, bool $includeArchived = false): array
    {
        $this->policy->check('inventoryRead');

        $builder = $this->db->table('clinic_medicines')
            ->select(self::MED_COLS)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        if (! $includeArchived) {
            $builder->where('archived_at', null);
        }

        $qTrim = $q !== null ? trim($q) : '';
        if ($qTrim !== '') {
            $like  = '%' . $this->db->escapeLikeString($qTrim) . '%';
            $builder->groupStart()
                ->like('generic_name', $like)
                ->orLike('brand_name', $like)
                ->orLike('category', $like)
                ->groupEnd();
        }

        KeysetPaginator::apply($builder, $cursor, $limit);

        $rows  = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        $stock = $this->stockByMedicine(array_map(static fn (array $r): int => (int) $r['id'], $final['rows']));

        return [
            'data' => array_map(
                fn (array $r) => MedicineDto::fromRow(
                    $r,
                    $stock[(int) $r['id']]['on_hand'] ?? 0,
                    $stock[(int) $r['id']]['earliest_expiry'] ?? null,
                )->toArray(),
                $final['rows'],
            ),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    /** Detail — includes the batch list (newest expiry last = FEFO order). */
    public function getMedicine(int $id): MedicineDto
    {
        $this->policy->check('inventoryRead');

        $row = $this->db->table('clinic_medicines')->select(self::MED_COLS)->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Medicine #{$id} not found."],
            ]);
        }

        $batches = $this->db->table('clinic_medicine_batches')
            ->select(self::BATCH_COLS)
            ->where('medicine_id', $id)
            ->orderBy('expiration_date', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $stock = $this->stockByMedicine([$id]);

        return MedicineDto::fromRow(
            $row,
            $stock[$id]['on_hand'] ?? 0,
            $stock[$id]['earliest_expiry'] ?? null,
            $batches,
        );
    }

    /**
     * @param array<string, mixed> $input validated payload
     */
    public function createMedicine(array $input): MedicineDto
    {
        $this->policy->check('inventoryWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($input, $userId): MedicineDto {
            $now = $this->utcNow();

            $this->db->table('clinic_medicines')->insert([
                'generic_name'      => (string) $input['generic_name'],
                'brand_name'        => $this->strOrNull($input, 'brand_name'),
                'category'          => $this->strOrNull($input, 'category'),
                'dosage_form'       => $this->strOrNull($input, 'dosage_form'),
                'dosage_strength'   => $this->strOrNull($input, 'dosage_strength'),
                'unit'              => (string) ($input['unit'] ?? 'pc'),
                'reorder_threshold' => (int) ($input['reorder_threshold'] ?? 10),
                'description'       => $this->strOrNull($input, 'description'),
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'clinic.medicine_created',
                'clinic_medicines',
                $id,
                $userId,
                ['resource_code' => 'medicine#' . (string) $input['generic_name']],
            );

            $row = $this->db->table('clinic_medicines')->select(self::MED_COLS)->where('id', $id)->get()->getRowArray();
            return MedicineDto::fromRow($row, 0, null);
        });
    }

    /**
     * Receive a lot against the procurement workflow: the medicine must
     * have a reorder request in `received` status (delivery arrived).
     * The batch quantity is taken from that request — the operator only
     * supplies batch number, expiry and supplier. The reorder row is
     * marked `completed` in the SAME transaction, closing the loop
     * between the Reorders tab and the batch ledger.
     *
     * @param array<string, mixed> $input validated payload
     */
    public function addBatch(int $medicineId, array $input): MedicineDto
    {
        $this->policy->check('inventoryWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($medicineId, $input, $userId): MedicineDto {
            $med = $this->selectForUpdate('clinic_medicines', ['id' => $medicineId, 'archived_at' => null]);
            if ($med === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Medicine #{$medicineId} not found."],
                ]);
            }

            // Gate: stock can only be entered for a delivery the Reorders
            // tab has marked `received`. Locked so two clerks can't both
            // consume the same request.
            $reorder = $this->selectForUpdate('clinic_reorder_requests', [
                'medicine_id' => $medicineId,
                'item_type'   => 'medicine',
                'status'      => 'received',
            ]);
            if ($reorder === null) {
                throw new ApiException('statemachine.reorder.not_received', 409, [
                    ['code' => 'statemachine.reorder.not_received', 'message' => 'No received delivery for this medicine. Mark the reorder request as received first.'],
                ]);
            }

            $batchNumber = (string) $input['batch_number'];
            $dup = $this->db->table('clinic_medicine_batches')
                ->where(['medicine_id' => $medicineId, 'batch_number' => $batchNumber])
                ->get()->getRowArray();
            if ($dup !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "Batch '{$batchNumber}' already exists for this medicine.", 'field' => 'batch_number'],
                ]);
            }

            // Quantity comes from the fulfilled order, never the payload.
            $qty      = (int) $reorder['requested_quantity'];
            $expires  = (string) $input['expiration_date'];
            $received = (string) ($input['received_date'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'));
            if ($expires <= $received) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'expiration_date must be after received_date.', 'field' => 'expiration_date'],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('clinic_medicine_batches')->insert([
                'medicine_id'        => $medicineId,
                'batch_number'       => $batchNumber,
                'quantity_received'  => $qty,
                'quantity_remaining' => $qty,
                'expiration_date'    => $expires,
                'received_date'      => $received,
                'supplier'           => $this->strOrNull($input, 'supplier'),
                'status'             => 'active',
                'created_at'         => $now,
            ]);
            $batchId = (int) $this->db->insertID();

            $this->db->table('clinic_medicine_transactions')->insert([
                'medicine_id'          => $medicineId,
                'batch_id'             => $batchId,
                'type'                 => 'received',
                'quantity'             => $qty,
                'balance_after'        => $this->lastBalance($medicineId) + $qty,
                'performed_by_user_id' => $userId,
                'note'                 => $this->strOrNull($input, 'note'),
                'created_at'           => $now,
            ]);

            // Close the procurement loop.
            $this->db->table('clinic_reorder_requests')
                ->where('id', (int) $reorder['id'])
                ->update(['status' => 'completed', 'fulfilled_at' => $now, 'updated_at' => $now]);

            $this->audit->enqueue(
                'clinic.medicine_batch_received',
                'clinic_medicine_batches',
                $batchId,
                $userId,
                ['resource_code' => 'batch#' . $batchNumber],
            );
            $this->audit->enqueue(
                'clinic.reorder_completed',
                'clinic_reorder_requests',
                (int) $reorder['id'],
                $userId,
                ['resource_code' => 'medicine#' . (string) $medicineId, 'outcome' => 'completed'],
            );

            return $this->getMedicine($medicineId);
        });
    }

    /**
     * FEFO dispense: consume `quantity` units from the earliest-expiring
     * active, unexpired batches, all rows locked for the transaction.
     *
     * Panel revision (July 2026): every dispense is anchored to an OPEN
     * encounter — the actual clinic visit — so the ledger row records
     * WHO the stock went to (`reference_type = 'encounter'`).
     */
    public function dispense(int $medicineId, int $quantity, ?string $note, int $encounterId): MedicineDto
    {
        $this->policy->check('inventoryWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($medicineId, $quantity, $note, $encounterId, $userId): MedicineDto {
            $med = $this->selectForUpdate('clinic_medicines', ['id' => $medicineId, 'archived_at' => null]);
            if ($med === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Medicine #{$medicineId} not found."],
                ]);
            }

            // The anchoring encounter must exist and still be open.
            $enc = $this->selectForUpdate('clinic_encounters', ['id' => $encounterId, 'archived_at' => null]);
            if ($enc === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Encounter #{$encounterId} not found.", 'field' => 'encounter_id'],
                ]);
            }
            if ((string) $enc['status'] !== 'open') {
                throw new ApiException('statemachine.clinic.encounter_closed', 409, [
                    ['code' => 'statemachine.clinic.encounter_closed', 'message' => 'Medicines can only be dispensed against an open encounter.', 'field' => 'encounter_id'],
                ]);
            }

            $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

            // FEFO order, locked. Raw SQL is the sanctioned escape hatch
            // for row locking (see BaseService::selectForUpdate).
            $batches = $this->db->query(
                'SELECT ' . self::BATCH_COLS . ' FROM `clinic_medicine_batches`'
                . ' WHERE `medicine_id` = ? AND `status` = ? AND `quantity_remaining` > 0 AND `expiration_date` >= ?'
                . ' ORDER BY `expiration_date` ASC, `id` ASC FOR UPDATE',
                [$medicineId, 'active', $today],
            )->getResultArray();

            $available = array_sum(array_map(static fn (array $b): int => (int) $b['quantity_remaining'], $batches));
            if ($available < $quantity) {
                throw new ApiException('statemachine.inventory.insufficient_stock', 409, [
                    ['code' => 'statemachine.inventory.insufficient_stock', 'message' => "Only {$available} unexpired unit(s) on hand.", 'field' => 'quantity'],
                ]);
            }

            $now       = $this->utcNow();
            $remaining = $quantity;
            $balance   = $this->lastBalance($medicineId);

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }
                $take    = min($remaining, (int) $batch['quantity_remaining']);
                $newQty  = (int) $batch['quantity_remaining'] - $take;

                $this->db->table('clinic_medicine_batches')
                    ->where('id', (int) $batch['id'])
                    ->update([
                        'quantity_remaining' => $newQty,
                        'status'             => $newQty === 0 ? 'depleted' : 'active',
                    ]);

                $balance -= $take;
                $this->db->table('clinic_medicine_transactions')->insert([
                    'medicine_id'          => $medicineId,
                    'batch_id'             => (int) $batch['id'],
                    'type'                 => 'dispensed',
                    'quantity'             => $take,
                    'balance_after'        => $balance,
                    'reference_type'       => 'encounter',
                    'reference_id'         => $encounterId,
                    'performed_by_user_id' => $userId,
                    'note'                 => $note,
                    'created_at'           => $now,
                ]);

                $remaining -= $take;
            }

            $this->audit->enqueue(
                'clinic.medicine_dispensed',
                'clinic_medicines',
                $medicineId,
                $userId,
                ['resource_code' => 'qty#' . (string) $quantity, 'outcome' => 'encounter#' . $encounterId],
            );

            return $this->getMedicine($medicineId);
        });
    }

    /**
     * Medicines at or below their reorder threshold (legacy low-stock
     * report; feeds the Phase-13 procurement workflow).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLowStock(): array
    {
        $this->policy->check('inventoryRead');

        $rows = $this->db->table('clinic_medicines')
            ->select(self::MED_COLS)
            ->where('archived_at', null)
            ->orderBy('generic_name', 'ASC')
            ->get()->getResultArray();

        $stock = $this->stockByMedicine(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        $out = [];
        foreach ($rows as $r) {
            $onHand = $stock[(int) $r['id']]['on_hand'] ?? 0;
            if ($onHand <= (int) $r['reorder_threshold']) {
                $out[] = MedicineDto::fromRow($r, $onHand, $stock[(int) $r['id']]['earliest_expiry'] ?? null)->toArray();
            }
        }
        return $out;
    }

    /**
     * Batches with stock expiring within `$days` (legacy expiring report).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listExpiring(int $days = 30): array
    {
        $this->policy->check('inventoryRead');

        $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $until = $today->modify('+' . max(1, min($days, 365)) . ' days')->format('Y-m-d');

        $rows = $this->db->table('clinic_medicine_batches b')
            ->select('b.' . str_replace(', ', ', b.', self::BATCH_COLS) . ', m.generic_name, m.unit')
            ->join('clinic_medicines m', 'm.id = b.medicine_id')
            ->where('b.status', 'active')
            ->where('b.quantity_remaining >', 0)
            ->where('b.expiration_date <=', $until)
            ->orderBy('b.expiration_date', 'ASC')
            ->get()->getResultArray();

        return array_map(static function (array $r): array {
            $dto = MedicineBatchDto::fromRow($r)->toArray();
            $dto['generic_name'] = (string) $r['generic_name'];
            $dto['unit']         = (string) $r['unit'];
            return $dto;
        }, $rows);
    }

    // ------------------------------------------------------------ helpers

    /**
     * Ledger view: the medicine's typed transactions in chronological
     * order with the stored running balance (panel revision — in/out
     * debit-credit tracking). Capped at the most recent 200 rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTransactions(int $medicineId, int $limit = 200): array
    {
        $this->policy->check('inventoryRead');

        $med = $this->db->table('clinic_medicines')->select('id')->where('id', $medicineId)->get()->getRowArray();
        if ($med === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Medicine #{$medicineId} not found."],
            ]);
        }

        // Newest N rows, then flip so the ledger reads oldest → newest.
        $rows = $this->db->table('clinic_medicine_transactions')
            ->select('id, batch_id, type, quantity, balance_after, reference_type, reference_id, performed_by_user_id, note, created_at')
            ->where('medicine_id', $medicineId)
            ->orderBy('id', 'DESC')
            ->limit(max(1, min($limit, 500)))
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'             => (int) $r['id'],
            'batch_id'       => (int) $r['batch_id'],
            'type'           => (string) $r['type'],
            'qty_in'         => in_array((string) $r['type'], ['received', 'returned'], true) ? (int) $r['quantity'] : null,
            'qty_out'        => in_array((string) $r['type'], ['received', 'returned'], true) ? null : (int) $r['quantity'],
            'balance_after'  => $r['balance_after'] !== null ? (int) $r['balance_after'] : null,
            'reference_type' => $r['reference_type'] !== null ? (string) $r['reference_type'] : null,
            'reference_id'   => $r['reference_id'] !== null ? (int) $r['reference_id'] : null,
            'note'           => $r['note'] !== null ? (string) $r['note'] : null,
            'created_at'     => (string) $r['created_at'],
        ], array_reverse($rows));
    }

    /**
     * Last running-balance value on the medicine's ledger (row-locked;
     * call inside a transaction). Seeds the `balance_after` chain for
     * the rows about to be appended.
     */
    private function lastBalance(int $medicineId): int
    {
        $row = $this->db->query(
            'SELECT `balance_after` FROM `clinic_medicine_transactions`'
            . ' WHERE `medicine_id` = ? ORDER BY `id` DESC LIMIT 1 FOR UPDATE',
            [$medicineId],
        )->getRowArray();

        return $row !== null && $row['balance_after'] !== null ? (int) $row['balance_after'] : 0;
    }

    /**
     * Compute + persist a deterministic stock forecast for a medicine
     * (Phase P2b). "Latest wins" per (medicine_id, today).
     *
     * @return array<string, mixed>
     */
    public function computeForecast(int $medicineId): array
    {
        $this->policy->check('inventoryForecast');
        $userId = \App\Auth\CurrentUser::assert();

        $med = $this->db->table('clinic_medicines')
            ->select('id, category, reorder_threshold')
            ->where('id', $medicineId)->where('archived_at', null)
            ->get()->getRowArray();
        if ($med === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Medicine #{$medicineId} not found."],
            ]);
        }

        $currentStock = $this->stockByMedicine([$medicineId])[$medicineId]['on_hand'] ?? 0;

        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-30 days')->format('Y-m-d H:i:s');
        $disp  = $this->db->table('clinic_medicine_transactions')
            ->selectSum('quantity', 'total')
            ->where('medicine_id', $medicineId)
            ->where('type', 'dispensed')
            ->where('created_at >=', $since)
            ->get()->getRowArray();
        $totalDispensed30d = (int) ($disp['total'] ?? 0);

        $result = (new \App\Services\Analytics\InventoryForecaster())->forecast(
            $currentStock,
            (int) $med['reorder_threshold'],
            $totalDispensed30d,
            $med['category'] !== null ? (string) $med['category'] : null,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $now   = $this->utcNow();

        return $this->txn(function () use ($medicineId, $result, $today, $now, $userId): array {
            $this->db->table('clinic_medicine_forecasts')
                ->where('medicine_id', $medicineId)->where('forecast_date', $today)->delete();
            $this->db->table('clinic_medicine_forecasts')->insert([
                'medicine_id'               => $medicineId,
                'forecast_date'             => $today,
                'forecast_period_start'     => $today,
                'forecast_period_end'       => (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d'),
                'predicted_daily_usage'     => $result['predicted_daily_usage'],
                'predicted_stockout_date'   => $result['predicted_stockout_date'],
                'predicted_reorder_date'    => $result['predicted_reorder_date'],
                'current_stock'             => $result['current_stock'],
                'reorder_threshold'         => $result['reorder_threshold'],
                'model_type'                => $result['model_type'],
                'seasonality_factor'        => $result['seasonality_factor'],
                'confidence_interval_lower' => $result['confidence_interval_lower'],
                'confidence_interval_upper' => $result['confidence_interval_upper'],
                'accuracy_metrics'          => json_encode($result['accuracy_metrics'], JSON_THROW_ON_ERROR),
                'created_at'                => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('clinic.forecast_computed', 'clinic_medicine_forecasts', $id, $userId, [
                'resource_code' => 'medicine#' . $medicineId,
            ]);

            return ['medicine_id' => $medicineId, 'forecast_date' => $today] + $result;
        });
    }

    /**
     * Latest forecast for a medicine, or null if none computed yet.
     *
     * @return array<string, mixed>|null
     */
    public function getLatestForecast(int $medicineId): ?array
    {
        $this->policy->check('inventoryForecast');

        $row = $this->db->table('clinic_medicine_forecasts')
            ->where('medicine_id', $medicineId)
            ->orderBy('forecast_date', 'DESC')->orderBy('id', 'DESC')
            ->limit(1)->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return [
            'medicine_id'               => (int) $row['medicine_id'],
            'forecast_date'             => (string) $row['forecast_date'],
            'predicted_daily_usage'     => (float) $row['predicted_daily_usage'],
            'predicted_stockout_date'   => $row['predicted_stockout_date'] !== null ? (string) $row['predicted_stockout_date'] : null,
            'predicted_reorder_date'    => $row['predicted_reorder_date'] !== null ? (string) $row['predicted_reorder_date'] : null,
            'current_stock'             => (int) $row['current_stock'],
            'reorder_threshold'         => (int) $row['reorder_threshold'],
            'model_type'                => (string) $row['model_type'],
            'seasonality_factor'        => $row['seasonality_factor'] !== null ? (float) $row['seasonality_factor'] : null,
        ];
    }

    /**
     * Aggregate on-hand + earliest expiry for a set of medicines in ONE
     * query (active, unexpired batches only) — ports the legacy
     * `MedicineBatchModel::getTotalStock()`.
     *
     * @param array<int, int> $ids
     * @return array<int, array{on_hand: int, earliest_expiry: ?string}>
     */
    private function stockByMedicine(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

        $rows = $this->db->table('clinic_medicine_batches')
            ->select('medicine_id, SUM(quantity_remaining) AS on_hand, MIN(expiration_date) AS earliest_expiry')
            ->whereIn('medicine_id', $ids)
            ->where('status', 'active')
            ->where('quantity_remaining >', 0)
            ->where('expiration_date >=', $today)
            ->groupBy('medicine_id')
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['medicine_id']] = [
                'on_hand'         => (int) $r['on_hand'],
                'earliest_expiry' => $r['earliest_expiry'] !== null ? (string) $r['earliest_expiry'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function strOrNull(array $input, string $key): ?string
    {
        return isset($input[$key]) && $input[$key] !== '' ? (string) $input[$key] : null;
    }

    /**
     * Update a medicine's catalog row. Locked down to the reorder
     * threshold ONLY: the catalog identity (names, category, form,
     * strength, unit, description) is immutable after creation so the
     * batch ledger and forecasts always describe the same product.
     *
     * @param array<string, mixed> $input validated payload
     */
    public function updateMedicine(int $medicineId, array $input): MedicineDto
    {
        $this->policy->check('inventoryWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($medicineId, $input, $userId): MedicineDto {
            $med = $this->selectForUpdate('clinic_medicines', ['id' => $medicineId, 'archived_at' => null]);
            if ($med === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Medicine #{$medicineId} not found."],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('clinic_medicines')->where('id', $medicineId)->update([
                'reorder_threshold' => (int) ($input['reorder_threshold'] ?? $med['reorder_threshold']),
                'updated_at'        => $now,
            ]);

            $this->audit->enqueue(
                'clinic.medicine_updated',
                'clinic_medicines',
                $medicineId,
                $userId,
                ['resource_code' => 'medicine#' . (string) $med['generic_name']],
            );

            return $this->getMedicine($medicineId);
        });
    }

    /**
     * Soft-archive a medicine. Archived rows disappear from the
     * default list, but historical batches/forecasts stay intact
     * for the audit trail. Hard delete is intentionally NOT
     * supported — stock history is part of the clinical record.
     */
    public function archiveMedicine(int $medicineId): MedicineDto
    {
        $this->policy->check('inventoryDelete');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($medicineId, $userId): MedicineDto {
            $med = $this->selectForUpdate('clinic_medicines', ['id' => $medicineId]);
            if ($med === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Medicine #{$medicineId} not found."],
                ]);
            }
            if ($med['archived_at'] !== null) {
                // Idempotent: already archived.
                return $this->getMedicine($medicineId);
            }

            $now = $this->utcNow();
            $this->db->table('clinic_medicines')
                ->where('id', $medicineId)
                ->update(['archived_at' => $now, 'updated_at' => $now]);

            $this->audit->enqueue(
                'clinic.medicine_archived',
                'clinic_medicines',
                $medicineId,
                $userId,
                ['resource_code' => 'medicine#' . (string) $med['generic_name']],
            );

            return $this->getMedicine($medicineId);
        });
    }

    /**
     * Restore a soft-archived medicine: clears `archived_at` so the row
     * rejoins the default list. Batches, forecasts and the transaction
     * ledger were never touched by the archive, so stock resumes as-is.
     * Idempotent — restoring an active medicine returns the same row.
     */
    public function unarchiveMedicine(int $medicineId): MedicineDto
    {
        $this->policy->check('inventoryDelete');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($medicineId, $userId): MedicineDto {
            $med = $this->selectForUpdate('clinic_medicines', ['id' => $medicineId]);
            if ($med === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Medicine #{$medicineId} not found."],
                ]);
            }
            if ($med['archived_at'] === null) {
                // Idempotent: already active.
                return $this->getMedicine($medicineId);
            }

            $now = $this->utcNow();
            $this->db->table('clinic_medicines')
                ->where('id', $medicineId)
                ->update(['archived_at' => null, 'updated_at' => $now]);

            $this->audit->enqueue(
                'clinic.medicine_restored',
                'clinic_medicines',
                $medicineId,
                $userId,
                ['resource_code' => 'medicine#' . (string) $med['generic_name']],
            );

            return $this->getMedicine($medicineId);
        });
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
