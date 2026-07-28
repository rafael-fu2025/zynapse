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
    public function listMedicines(?string $cursor, int $limit): array
    {
        $this->policy->check('inventoryRead');

        $builder = $this->db->table('clinic_medicines')
            ->select(self::MED_COLS)
            ->where('archived_at', null)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

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
     * Receive a lot: insert the batch + a `received` ledger row.
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

            $batchNumber = (string) $input['batch_number'];
            $dup = $this->db->table('clinic_medicine_batches')
                ->where(['medicine_id' => $medicineId, 'batch_number' => $batchNumber])
                ->get()->getRowArray();
            if ($dup !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "Batch '{$batchNumber}' already exists for this medicine.", 'field' => 'batch_number'],
                ]);
            }

            $qty      = (int) $input['quantity_received'];
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
                'performed_by_user_id' => $userId,
                'note'                 => $this->strOrNull($input, 'note'),
                'created_at'           => $now,
            ]);

            $this->audit->enqueue(
                'clinic.medicine_batch_received',
                'clinic_medicine_batches',
                $batchId,
                $userId,
                ['resource_code' => 'batch#' . $batchNumber],
            );

            return $this->getMedicine($medicineId);
        });
    }

    /**
     * FEFO dispense: consume `quantity` units from the earliest-expiring
     * active, unexpired batches, all rows locked for the transaction.
     */
    public function dispense(int $medicineId, int $quantity, ?string $note): MedicineDto
    {
        $this->policy->check('inventoryWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($medicineId, $quantity, $note, $userId): MedicineDto {
            $med = $this->selectForUpdate('clinic_medicines', ['id' => $medicineId, 'archived_at' => null]);
            if ($med === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Medicine #{$medicineId} not found."],
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

                $this->db->table('clinic_medicine_transactions')->insert([
                    'medicine_id'          => $medicineId,
                    'batch_id'             => (int) $batch['id'],
                    'type'                 => 'dispensed',
                    'quantity'             => $take,
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
                ['resource_code' => 'qty#' . (string) $quantity],
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

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
