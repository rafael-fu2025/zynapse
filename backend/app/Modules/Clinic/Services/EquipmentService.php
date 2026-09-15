<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\ManilaDay;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\Policies\ClinicPolicy;

/**
 * EquipmentService — durable-asset tracking (third inventory catalog).
 *
 * The catalog (clinic_equipment) holds what the clinic owns; each physical
 * unit is a clinic_equipment_units row carrying a status:
 *
 *   working → for_repair → for_replacement → retired
 *
 * ALL transitions are permitted in any direction — the append-only
 * clinic_equipment_status_log is the control. Every change records who,
 * when, from, to, and why, and the unit's `status_changed_at` mirrors the
 * latest change so reports can show how long an item has been flagged.
 *
 * Write methods follow the module's standard pattern: row locked with
 * selectForUpdate inside txn(), audit outbox row written in the SAME
 * transaction.
 */
final class EquipmentService extends BaseService
{
    public const STATUSES = ['working', 'for_repair', 'for_replacement', 'retired'];

    private const CATALOG_COLS = 'id, name, category, location, notes, archived_at, created_at, updated_at';

    public function __construct(
        private readonly ClinicPolicy $policy,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function listItems(?string $cursor, int $limit, ?string $q = null, bool $includeArchived = false): array
    {
        $this->policy->check('equipmentRead');

        $builder = $this->db->table('clinic_equipment')
            ->where('clinic_equipment.tenant_id', CurrentTenant::id())
            ->select(self::CATALOG_COLS)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        if (! $includeArchived) {
            $builder->where('archived_at', null);
        }

        $qTrim = $q !== null ? trim($q) : '';
        if ($qTrim !== '') {
            $like = '%' . $this->db->escapeLikeString($qTrim) . '%';
            $builder->groupStart()
                ->like('name', $like)
                ->orLike('category', $like)
                ->orLike('location', $like)
                ->groupEnd();
        }

        KeysetPaginator::apply($builder, $cursor, $limit);

        $rows  = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        $counts = $this->unitCountsByEquipment(array_map(static fn (array $r): int => (int) $r['id'], $final['rows']));

        return [
            'data'  => array_map(static fn (array $r): array => self::itemShape($r, $counts), $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    /**
     * Catalog row + its units (attention statuses first) + the equipment's
     * status log (newest first, capped) so the manage-units dialog renders
     * current state and history in one round trip.
     *
     * @return array<string, mixed>
     */
    public function getEquipment(int $equipmentId): array
    {
        $this->policy->check('equipmentRead');

        $row = $this->db->table('clinic_equipment')
            ->where('clinic_equipment.tenant_id', CurrentTenant::id())
            ->select(self::CATALOG_COLS)
            ->where('id', $equipmentId)
            ->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Equipment #{$equipmentId} not found."],
            ]);
        }

        $units = $this->db->table('clinic_equipment_units')
            ->where('clinic_equipment_units.tenant_id', CurrentTenant::id())
            ->where('equipment_id', $equipmentId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        // Attention first: for_replacement, for_repair, working, retired.
        $rank = ['for_replacement' => 0, 'for_repair' => 1, 'working' => 2, 'retired' => 3];
        usort($units, static function (array $a, array $b) use ($rank): int {
            $byRank = $rank[(string) $a['status']] <=> $rank[(string) $b['status']];
            return $byRank !== 0 ? $byRank : (int) $a['id'] <=> (int) $b['id'];
        });

        // Guarded: a freshly created catalog row has no units yet, and a
        // whereIn over an empty id list is invalid SQL.
        $log = [];
        if ($units !== []) {
            $log = $this->db->table('clinic_equipment_status_log l')
                ->where('l.tenant_id', CurrentTenant::id())
                ->select('l.id, l.unit_id, l.from_status, l.to_status, l.note, l.created_at, COALESCE(NULLIF(ai.secret, \'\'), u.username) AS user_email')
                ->join('users u', 'u.id = l.changed_by_user_id', 'left')
                ->join('auth_identities ai', "ai.user_id = u.id AND ai.type = 'email_password'", 'left')
                ->whereIn('l.unit_id', array_map(static fn (array $u): int => (int) $u['id'], $units))
                ->orderBy('l.id', 'DESC')
                ->limit(200)
                ->get()->getResultArray();
        }

        $counts = $this->unitCountsByEquipment([$equipmentId]);

        return self::itemShape($row, $counts) + [
            'units'      => array_map(static fn (array $u): array => [
                'id'                => (int) $u['id'],
                'status'            => (string) $u['status'],
                'condition_note'    => $u['condition_note'] !== null ? (string) $u['condition_note'] : null,
                'acquired_date'     => $u['acquired_date'] !== null ? (string) $u['acquired_date'] : null,
                'status_changed_at' => (string) $u['status_changed_at'],
                'created_at'        => (string) $u['created_at'],
            ], $units),
            'status_log' => array_map(static fn (array $l): array => [
                'id'          => (int) $l['id'],
                'unit_id'     => (int) $l['unit_id'],
                'from_status' => $l['from_status'] !== null ? (string) $l['from_status'] : null,
                'to_status'   => (string) $l['to_status'],
                'note'        => $l['note'] !== null ? (string) $l['note'] : null,
                'user_email'  => $l['user_email'] !== null ? (string) $l['user_email'] : null,
                'created_at'  => (string) $l['created_at'],
            ], $log),
        ];
    }

    /**
     * @param array<string, mixed> $input validated payload
     */
    public function createItem(array $input): array
    {
        $this->policy->check('equipmentWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($input, $userId): array {
            $now = $this->utcNow();

            $this->db->table('clinic_equipment')->insert([
                'tenant_id'  => CurrentTenant::id(),
                'name'       => (string) $input['name'],
                'category'   => $this->strOrNull($input, 'category'),
                'location'   => $this->strOrNull($input, 'location'),
                'notes'      => $this->strOrNull($input, 'notes'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'clinic.equipment_created',
                'clinic_equipment',
                $id,
                $userId,
                ['resource_code' => 'equipment#' . (string) $input['name']],
            );

            return $this->getEquipment($id);
        });
    }

    /**
     * Update the catalog row only — unit statuses go through
     * changeUnitStatus() so every change lands in the status log.
     *
     * @param array<string, mixed> $input validated payload
     */
    public function updateItem(int $equipmentId, array $input): array
    {
        $this->policy->check('equipmentWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($equipmentId, $input, $userId): array {
            $item = $this->selectForUpdate('clinic_equipment', ['tenant_id' => CurrentTenant::id(), 'id' => $equipmentId, 'archived_at' => null]);
            if ($item === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Equipment #{$equipmentId} not found."],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('clinic_equipment')
                ->where('clinic_equipment.tenant_id', CurrentTenant::id())
                ->where('id', $equipmentId)
                ->update([
                    'name'       => (string) $input['name'],
                    'category'   => $this->strOrNull($input, 'category'),
                    'location'   => $this->strOrNull($input, 'location'),
                    'notes'      => $this->strOrNull($input, 'notes'),
                    'updated_at' => $now,
                ]);

            $this->audit->enqueue(
                'clinic.equipment_updated',
                'clinic_equipment',
                $equipmentId,
                $userId,
                ['resource_code' => 'equipment#' . (string) $input['name']],
            );

            return $this->getEquipment($equipmentId);
        });
    }

    /**
     * Soft-archive the catalog row. Units and the status log are preserved
     * — the asset's history is part of the record. Idempotent.
     */
    public function archiveItem(int $equipmentId): array
    {
        $this->policy->check('equipmentDelete');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($equipmentId, $userId): array {
            $item = $this->selectForUpdate('clinic_equipment', ['tenant_id' => CurrentTenant::id(), 'id' => $equipmentId]);
            if ($item === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Equipment #{$equipmentId} not found."],
                ]);
            }
            if ($item['archived_at'] !== null) {
                return $this->getEquipment($equipmentId);
            }

            $now = $this->utcNow();
            $this->db->table('clinic_equipment')
                ->where('clinic_equipment.tenant_id', CurrentTenant::id())
                ->where('id', $equipmentId)
                ->update(['archived_at' => $now, 'updated_at' => $now]);

            $this->audit->enqueue(
                'clinic.equipment_archived',
                'clinic_equipment',
                $equipmentId,
                $userId,
                ['resource_code' => 'equipment#' . (string) $item['name']],
            );

            return $this->getEquipment($equipmentId);
        });
    }

    /**
     * Restore a soft-archived catalog row. Idempotent.
     */
    public function unarchiveItem(int $equipmentId): array
    {
        $this->policy->check('equipmentDelete');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($equipmentId, $userId): array {
            $item = $this->selectForUpdate('clinic_equipment', ['tenant_id' => CurrentTenant::id(), 'id' => $equipmentId]);
            if ($item === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Equipment #{$equipmentId} not found."],
                ]);
            }
            if ($item['archived_at'] === null) {
                return $this->getEquipment($equipmentId);
            }

            $now = $this->utcNow();
            $this->db->table('clinic_equipment')
                ->where('clinic_equipment.tenant_id', CurrentTenant::id())
                ->where('id', $equipmentId)
                ->update(['archived_at' => null, 'updated_at' => $now]);

            $this->audit->enqueue(
                'clinic.equipment_restored',
                'clinic_equipment',
                $equipmentId,
                $userId,
                ['resource_code' => 'equipment#' . (string) $item['name']],
            );

            return $this->getEquipment($equipmentId);
        });
    }

    /**
     * Add physical units to a catalog item. New units start `working` and
     * the addition is logged (from_status NULL → working) with the note.
     */
    public function addUnits(int $equipmentId, int $quantity, ?string $acquiredDate, ?string $note): array
    {
        $this->policy->check('equipmentWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($equipmentId, $quantity, $acquiredDate, $note, $userId): array {
            if ($quantity < 1) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'quantity must be at least 1.', 'field' => 'quantity'],
                ]);
            }

            $item = $this->selectForUpdate('clinic_equipment', ['tenant_id' => CurrentTenant::id(), 'id' => $equipmentId, 'archived_at' => null]);
            if ($item === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Equipment #{$equipmentId} not found."],
                ]);
            }

            $now          = $this->utcNow();
            $acquiredDate = $acquiredDate !== null && $acquiredDate !== '' ? $acquiredDate : ManilaDay::today();

            // Rows are built inline (tenant_id visible in the statement)
            // so the tenancy fitness scan can vouch for the batch.
            $this->db->table('clinic_equipment_units')->insertBatch(array_map(
                static fn (int $i): array => [
                    'tenant_id'         => CurrentTenant::id(),
                    'equipment_id'      => $equipmentId,
                    'status'            => 'working',
                    'acquired_date'     => $acquiredDate,
                    'status_changed_at' => $now,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ],
                range(0, $quantity - 1),
            ));

            // The initial working state is part of the unit's history.
            $firstId = (int) $this->db->insertID();
            $this->db->table('clinic_equipment_status_log')->insertBatch(array_map(
                static fn (int $unitId): array => [
                    'tenant_id'          => CurrentTenant::id(),
                    'unit_id'            => $unitId,
                    'from_status'        => null,
                    'to_status'          => 'working',
                    'note'               => $note,
                    'changed_by_user_id' => $userId,
                    'created_at'         => $now,
                ],
                range($firstId, $firstId + $quantity - 1),
            ));

            $this->audit->enqueue(
                'clinic.equipment_units_added',
                'clinic_equipment',
                $equipmentId,
                $userId,
                ['resource_code' => 'equipment#' . (string) $item['name'], 'qty' => $quantity],
            );

            return $this->getEquipment($equipmentId);
        });
    }

    /**
     * Edit a unit's descriptive fields. Status changes must go through
     * changeUnitStatus() so they are logged.
     */
    public function updateUnit(int $unitId, ?string $conditionNote, ?string $acquiredDate): array
    {
        $this->policy->check('equipmentWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($unitId, $conditionNote, $acquiredDate, $userId): array {
            $unit = $this->lockUnitWithEquipment($unitId);

            $now = $this->utcNow();
            $this->db->table('clinic_equipment_units')
                ->where('clinic_equipment_units.tenant_id', CurrentTenant::id())
                ->where('id', $unitId)
                ->update([
                    'condition_note' => $conditionNote,
                    'acquired_date'  => $acquiredDate,
                    'updated_at'     => $now,
                ]);

            $this->audit->enqueue(
                'clinic.equipment_unit_updated',
                'clinic_equipment',
                (int) $unit['equipment_id'],
                $userId,
                ['resource_code' => 'unit#' . $unitId],
            );

            return $this->getEquipment((int) $unit['equipment_id']);
        });
    }

    /**
     * One-click status change — the load-bearing action. Locks the unit,
     * appends the from→to log row (with the optional note), mirrors the
     * time onto the unit, and audits. No-op when the status is unchanged.
     *
     * @return array<string, mixed> the refreshed equipment detail
     */
    public function changeUnitStatus(int $unitId, string $status, ?string $note): array
    {
        $this->policy->check('equipmentWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($unitId, $status, $note, $userId): array {
            $unit = $this->lockUnitWithEquipment($unitId);

            $from = (string) $unit['status'];
            if ($from !== $status) {
                $now = $this->utcNow();

                $this->db->table('clinic_equipment_units')
                    ->where('clinic_equipment_units.tenant_id', CurrentTenant::id())
                    ->where('id', $unitId)
                    ->update([
                        'status'            => $status,
                        'status_changed_at' => $now,
                        'updated_at'        => $now,
                    ]);

                $this->db->table('clinic_equipment_status_log')->insert([
                    'tenant_id'          => CurrentTenant::id(),
                    'unit_id'            => $unitId,
                    'from_status'        => $from,
                    'to_status'          => $status,
                    'note'               => $note,
                    'changed_by_user_id' => $userId,
                    'created_at'         => $now,
                ]);

                $this->audit->enqueue(
                    'clinic.equipment_unit_status_changed',
                    'clinic_equipment',
                    (int) $unit['equipment_id'],
                    $userId,
                    ['resource_code' => 'unit#' . $unitId, 'from' => $from, 'to' => $status],
                );
            }

            return $this->getEquipment((int) $unit['equipment_id']);
        });
    }

    // ------------------------------------------------------------ helpers

    /**
     * Lock a unit row and verify its parent equipment is live (not
     * archived) — archived equipment is read-only, matching the supplies
     * ledger's behavior for archived items.
     *
     * @return array<string, mixed> the locked unit row
     */
    private function lockUnitWithEquipment(int $unitId): array
    {
        $unit = $this->selectForUpdate('clinic_equipment_units', ['tenant_id' => CurrentTenant::id(), 'id' => $unitId]);
        if ($unit === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Equipment unit #{$unitId} not found."],
            ]);
        }

        $equipment = $this->db->table('clinic_equipment')
            ->where('clinic_equipment.tenant_id', CurrentTenant::id())
            ->select('id, name, archived_at')
            ->where('id', (int) $unit['equipment_id'])
            ->get()->getRowArray();
        if ($equipment === null || $equipment['archived_at'] !== null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Equipment unit #{$unitId} not found."],
            ]);
        }

        return $unit;
    }

    /**
     * Per-status unit counts for a set of equipment ids in ONE query —
     * powers the row-level status chips (mirrors lastMovementByItem).
     *
     * @param array<int, int> $ids
     * @return array<int, array{working: int, for_repair: int, for_replacement: int, retired: int}>
     */
    private function unitCountsByEquipment(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('clinic_equipment_units')
            ->where('clinic_equipment_units.tenant_id', CurrentTenant::id())
            ->select("equipment_id,
                SUM(status = 'working') AS working,
                SUM(status = 'for_repair') AS for_repair,
                SUM(status = 'for_replacement') AS for_replacement,
                SUM(status = 'retired') AS retired", false)
            ->whereIn('equipment_id', $ids)
            ->groupBy('equipment_id')
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['equipment_id']] = [
                'working'        => (int) $r['working'],
                'for_repair'     => (int) $r['for_repair'],
                'for_replacement' => (int) $r['for_replacement'],
                'retired'        => (int) $r['retired'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array{working: int, for_repair: int, for_replacement: int, retired: int}> $counts
     * @return array<string, mixed>
     */
    private static function itemShape(array $row, array $counts): array
    {
        $c = $counts[(int) $row['id']] ?? ['working' => 0, 'for_repair' => 0, 'for_replacement' => 0, 'retired' => 0];

        return [
            'id'              => (int) $row['id'],
            'name'            => (string) $row['name'],
            'category'        => $row['category'] !== null ? (string) $row['category'] : null,
            'location'        => $row['location'] !== null ? (string) $row['location'] : null,
            'notes'           => $row['notes'] !== null ? (string) $row['notes'] : null,
            'archived'        => ($row['archived_at'] ?? null) !== null,
            'working'         => $c['working'],
            'for_repair'      => $c['for_repair'],
            'for_replacement' => $c['for_replacement'],
            'retired'         => $c['retired'],
            'total_units'     => $c['working'] + $c['for_repair'] + $c['for_replacement'] + $c['retired'],
            'created_at'      => (string) $row['created_at'],
        ];
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
