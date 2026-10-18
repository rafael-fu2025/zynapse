<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\ManilaDay;
use App\Services\CurrentTenant;

/** Policy-free stock allocation inside the caller's transaction (never starts one). */
final class MedicineFefoAllocator extends BaseService
{
    /** Returns the representative first batch; the ledger records every split. */
    public function consume(int $medicineId, int $quantity, int $userId, int $encounterId, string $now, ?string $note): int
    {
        if ($quantity < 1) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'quantity must be at least 1.', 'field' => 'quantity'],
            ]);
        }
        $med = $this->selectForUpdate('clinic_medicines', ['tenant_id' => CurrentTenant::id(), 'id' => $medicineId, 'archived_at' => null]);
        if ($med === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Medicine #{$medicineId} not found."],
            ]);
        }
        // Expiry day itself remains usable, matching the established inventory policy.
        $batches = $this->db->query(
            'SELECT `id`, `quantity_remaining` FROM `clinic_medicine_batches`'
            . ' WHERE `tenant_id` = ? AND `medicine_id` = ? AND `status` = ? AND `quantity_remaining` > 0 AND `expiration_date` >= ?'
            . ' ORDER BY `expiration_date` ASC, `id` ASC FOR UPDATE',
            [CurrentTenant::id(), $medicineId, 'active', ManilaDay::fromUtcSql($now)],
        )->getResultArray();
        $available = array_sum(array_map(static fn (array $b): int => (int) $b['quantity_remaining'], $batches));
        if ($available < $quantity) {
            throw new ApiException('statemachine.inventory.insufficient_stock', 409, [
                ['code' => 'statemachine.inventory.insufficient_stock', 'message' => "Only {$available} unexpired unit(s) on hand.", 'field' => 'quantity'],
            ]);
        }
        $row = $this->db->query(
            'SELECT `balance_after` FROM `clinic_medicine_transactions`'
            . ' WHERE `tenant_id` = ? AND `medicine_id` = ? ORDER BY `id` DESC LIMIT 1 FOR UPDATE',
            [CurrentTenant::id(), $medicineId],
        )->getRowArray();
        $balance = $row !== null && $row['balance_after'] !== null ? (int) $row['balance_after'] : 0;
        $remaining = $quantity;
        $firstBatchId = 0;
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $bid = (int) $batch['id'];
            $take = min($remaining, (int) $batch['quantity_remaining']);
            $newQty = (int) $batch['quantity_remaining'] - $take;
            if ($firstBatchId === 0) {
                $firstBatchId = $bid;
            }
            $this->db->table('clinic_medicine_batches')->where('tenant_id', CurrentTenant::id())->where('id', $bid)->update([
                'quantity_remaining' => $newQty,
                'status' => $newQty === 0 ? 'depleted' : 'active',
            ]);
            $balance -= $take;
            $this->db->table('clinic_medicine_transactions')->insert([
                'tenant_id' => CurrentTenant::id(),
                'medicine_id' => $medicineId,
                'batch_id' => $bid,
                'type' => 'dispensed',
                'quantity' => $take,
                'balance_after' => $balance,
                'reference_type' => 'encounter',
                'reference_id' => $encounterId,
                'performed_by_user_id' => $userId,
                'note' => $note,
                'created_at' => $now,
            ]);
            $remaining -= $take;
        }
        return $firstBatchId;
    }
}
