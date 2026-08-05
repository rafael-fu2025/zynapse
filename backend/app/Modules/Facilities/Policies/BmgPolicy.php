<?php

declare(strict_types=1);

namespace Modules\Facilities\Policies;

use App\Models\BmgBatchModel;
use App\Modules\Shared\BasePolicy;
use CodeIgniter\Database\BaseConnection;

/**
 * BmgPolicy — gates the BMG state machine.
 *
 * Module-level permissions:
 *   - facilities.units.read       → list
 *   - facilities.bmg.transition   → start / finish / cancel
 *   - facilities.bmg.record_output → record output
 *
 * Record-level ownership (Tier 3.2): for batch-scoped actions the
 * starter is the owner. Once `BMG_ENFORCE_OWNERSHIP=true` (default)
 * other operators cannot finish / cancel / record output / log on
 * a batch they did not start. The flag exists so deployments with
 * single-operator workflows can opt out without code changes.
 */
final class BmgPolicy extends BasePolicy
{
    /**
     * Actions that target an EXISTING batch and therefore carry a
     * `started_by_user_id` for ownership comparison. `start` itself
     * is not in this set because no prior batch exists to own.
     */
    private const OWNED_BATCH_ACTIONS = [
        'record_output',
        'finish',
        'cancel',
        'move_to_curing',
        'release',
        'logs_record',
        'logs_read',
        'io_record',
        'losses_record',
        'analytics',
        'alerts_read',
        'alerts_ack',
    ];

    /**
     * Read the toggle. Defaults to true so multi-tenant deployments
     * cannot accidentally open the gate. To disable, set
     * `BMG_ENFORCE_OWNERSHIP=false` in `.env`.
     */
    private function ownershipEnforced(): bool
    {
        $env = (string) (getenv('BMG_ENFORCE_OWNERSHIP') ?? '');
        if ($env === '') {
            return true;
        }
        return ! in_array(strtolower($env), ['0', 'false', 'no', 'off'], true);
    }

    public function check(string $action, mixed $record = null): void
    {
        $code = match ($action) {
            'list'             => 'facilities.units.read',
            'manage_units'     => 'facilities.units.manage',
            'start'            => 'facilities.bmg.transition',
            'record_output'    => 'facilities.bmg.record_output',
            'finish'           => 'facilities.bmg.transition',
            'cancel'           => 'facilities.bmg.transition',
            'move_to_curing'   => 'facilities.bmg.transition',
            'release'          => 'facilities.bmg.transition',
            'maintenance'      => 'facilities.bmg.transition',
            'logs_read'        => 'facilities.bmg.logs.read',
            'logs_record'      => 'facilities.bmg.logs.record',
            'categories_manage' => 'facilities.categories.manage',
            'io_record'        => 'facilities.bmg.io.record',
            'losses_record'    => 'facilities.bmg.io.record',
            'alerts_read'      => 'facilities.bmg.logs.read',
            'alerts_ack'       => 'facilities.bmg.logs.record',
            'analytics'        => 'facilities.units.read',
            default            => null,
        };
        if ($code === null) {
            $this->deny('rbac.facilities.forbidden');
        }
        $this->enforce($code, $action, $record);
    }

    /**
     * Override: batch-scoped actions require the caller to be the
     * user who started the batch. Module-level ops (`manage_units`,
     * `categories_manage`) and `start` (creates the row) are not
     * gated here. Skipped entirely when the env flag is off.
     */
    protected function canOnRecord(int $userId, mixed $record, string $action): bool
    {
        if (! $this->ownershipEnforced()) {
            return true;
        }
        if (! in_array($action, self::OWNED_BATCH_ACTIONS, true)) {
            return true;
        }
        // The record may be passed directly (an array from a JOIN, or a
        // batch DTO). If it carries `started_by_user_id` we trust it.
        if (is_array($record) && isset($record['started_by_user_id'])) {
            return (int) $record['started_by_user_id'] === $userId;
        }
        if (is_object($record) && isset($record->started_by_user_id)) {
            return (int) $record->started_by_user_id === $userId;
        }
        // Caller didn't supply the batch row — refuse closed rather than
        // open. Service code must load the row before invoking an
        // owned action.
        return false;
    }

    /**
     * Convenience: resolve a batch row by id so service call sites
     * don't each have to import BmgBatchModel. Returns null when the
     * batch does not exist (caller should then 404).
     *
     * @return array<string, mixed>|null
     */
    public function loadBatchForOwnership(int $batchId): ?array
    {
        /** @var BaseConnection $db */
        $db = db_connect();
        $row = $db->table('facilities_bmg_batches')
            ->select('id, unit_id, started_by_user_id, status, tenant_id, archived_at')
            ->where('id', $batchId)
            ->get()
            ->getRowArray();
        return $row === null ? null : $row;
    }
}