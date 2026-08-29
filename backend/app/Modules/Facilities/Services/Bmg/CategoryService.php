<?php

declare(strict_types=1);

namespace Modules\Facilities\Services\Bmg;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Modules\Facilities\Policies\BmgPolicy;

/**
 * CategoryService — waste-categories CRUD cluster for the BMG module.
 *
 * Extracted verbatim from BmgService: list / create / update /
 * archive / unarchive / hard-delete of `facilities_waste_categories`,
 * plus the decorated {@see wasteCategoryDto}. Duration changes
 * propagate to running drums through
 * {@see BmgSupport::refreshActiveBatchExpectedDates()} (which lives in
 * BmgSupport because it writes `facilities_bmg_batches` rows across
 * clusters) — called inside this class's transaction exactly as it
 * was inside BmgService's. Transaction boundaries, audit rows, slug
 * contract and policy action strings are unchanged from the
 * pre-extraction BmgService.
 */
final class CategoryService extends BaseService
{
    public function __construct(
        ?BaseConnection $db,
        private readonly BmgPolicy $policy,
        private readonly AuditOutboxService $audit,
        private readonly BmgSupport $support,
    ) {
        parent::__construct($db);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listWasteCategories(bool $activeOnly = false): array
    {
        $this->policy->check('list');
        $builder = $this->db->table('facilities_waste_categories')
            ->select('id, code, name, description, expected_yield_pct, reference_duration_days, is_active')
            ->where('tenant_id', CurrentTenant::id())
            ->orderBy('name', 'ASC');
        if ($activeOnly) {
            $builder->where('is_active', 1);
        }
        $rows  = $builder->get()->getResultArray();
        // Expected days = the ASSIGNED reference duration (what the operator
        // sets per category — it drives batch ETAs). Historical trial stats
        // are still surfaced as `historical_avg_days` / `sample_count` for
        // context, but never override the assigned value.
        $stats = $this->support->categoryDurationStats(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return array_map(static function (array $r) use ($stats): array {
            $id   = (int) $r['id'];
            $hist = $stats[$id] ?? null;
            $ref  = $r['reference_duration_days'] !== null ? (int) $r['reference_duration_days'] : null;
            return [
                'id'                      => $id,
                'code'                    => (string) $r['code'],
                'name'                    => (string) $r['name'],
                'description'             => $r['description'] !== null ? (string) $r['description'] : null,
                'expected_yield_pct'      => $r['expected_yield_pct'] !== null ? (float) $r['expected_yield_pct'] : null,
                'reference_duration_days' => $ref,
                'historical_avg_days'     => $hist !== null ? round($hist['avg_days'], 1) : null,
                'sample_count'            => $hist !== null ? $hist['samples'] : 0,
                'expected_days'           => $ref,
                'is_active'               => (bool) $r['is_active'],
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createWasteCategory(array $input): array
    {
        $this->policy->check('categories_manage');
        $userId = \App\Auth\CurrentUser::assert();

        // Waste category codes follow the same slug contract as drum
        // codes (panel revision): lowercase, hyphen-separated.
        $input['code'] = $this->support->assertSlug((string) $input['code'], 'code');

        return $this->txn(function () use ($input, $userId): array {
            $dup = $this->db->table('facilities_waste_categories')->where('code', (string) $input['code'])->where('tenant_id', CurrentTenant::id())->get()->getRowArray();
            if ($dup !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => 'A waste category with this code already exists.', 'field' => 'code'],
                ]);
            }
            $now = $this->support->utcNow();
            $this->db->table('facilities_waste_categories')->insert([
                'code'                    => (string) $input['code'],
                'tenant_id'               => CurrentTenant::id(),
                'name'                    => (string) $input['name'],
                'description'             => isset($input['description']) && $input['description'] !== '' ? (string) $input['description'] : null,
                'expected_yield_pct'      => isset($input['expected_yield_pct']) && $input['expected_yield_pct'] !== '' ? (float) $input['expected_yield_pct'] : null,
                'reference_duration_days' => isset($input['reference_duration_days']) && $input['reference_duration_days'] !== '' ? (int) $input['reference_duration_days'] : null,
                'is_active'               => 1,
                'created_at'              => $now,
                'updated_at'              => $now,
            ]);
            $id = (int) $this->db->insertID();
            $this->audit->enqueue('bmg.waste_category_created', 'facilities_waste_categories', $id, $userId, [
                'resource_code' => (string) $input['code'],
            ]);
            return ['id' => $id, 'code' => (string) $input['code'], 'name' => (string) $input['name'], 'is_active' => true];
        });
    }

    /**
     * Full decorated DTO for a single waste category — the SAME shape
     * `listWasteCategories` returns (including the derived historical
     * trial stats `historical_avg_days` / `sample_count` / `expected_days`).
     *
     * Every mutating endpoint (update/archive/unarchive) returns this so
     * the frontend `wasteCategorySchema` parse succeeds. Previously these
     * returned a PARTIAL row, which made the edit/archive form surface a
     * spurious `Required` ZodError (toast "Required", form never closed)
     * even though the write itself had succeeded.
     *
     * @return array<string, mixed>
     */
    private function wasteCategoryDto(int $categoryId): array
    {
        $row = $this->db->table('facilities_waste_categories')
            ->where('id', $categoryId)
            ->where('tenant_id', CurrentTenant::id())
            ->get()
            ->getRowArray();

        $stats = $this->support->categoryDurationStats([$categoryId]);
        $hist  = $stats[$categoryId] ?? null;
        $ref   = $row !== null && $row['reference_duration_days'] !== null ? (int) $row['reference_duration_days'] : null;

        return [
            'id'                      => (int) $row['id'],
            'code'                    => (string) $row['code'],
            'name'                    => (string) $row['name'],
            'description'             => $row['description'] !== null ? (string) $row['description'] : null,
            'expected_yield_pct'      => $row['expected_yield_pct'] !== null ? (float) $row['expected_yield_pct'] : null,
            'reference_duration_days' => $ref,
            'historical_avg_days'     => $hist !== null ? round($hist['avg_days'], 1) : null,
            'sample_count'            => $hist !== null ? $hist['samples'] : 0,
            'expected_days'           => $ref,
            'is_active'               => (bool) $row['is_active'],
        ];
    }

    /**
     * Update a waste category. `code` is immutable (matches the legacy
     * rule and the `UNIQUE` index that backs it). All other fields are
     * optional so the form can PATCH only the changed values. Soft
     * activation/deactivation happens here too.
     *
     * @param array{name?:string, description?:?string, expected_yield_pct?:?float, reference_duration_days?:?int, is_active?:bool} $input
     * @return array<string, mixed>
     */
    public function updateWasteCategory(int $categoryId, array $input): array
    {
        $this->policy->check('categories_manage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($categoryId, $input, $userId): array {
            $cat = $this->selectForUpdate('facilities_waste_categories', ['id' => $categoryId, 'tenant_id' => CurrentTenant::id()]);
            if ($cat === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Waste category #{$categoryId} not found."],
                ]);
            }

            $update = ['updated_at' => $this->support->utcNow()];
            if (array_key_exists('name', $input) && $input['name'] !== null) {
                $n = trim((string) $input['name']);
                if ($n === '') {
                    throw new ApiException('validation.invalid', 422, [
                        ['code' => 'validation.invalid', 'message' => 'name cannot be empty.', 'field' => 'name'],
                    ]);
                }
                $update['name'] = $n;
            }
            if (array_key_exists('description', $input)) {
                $update['description'] = $input['description'] !== null && $input['description'] !== ''
                    ? (string) $input['description'] : null;
            }
            if (array_key_exists('expected_yield_pct', $input)) {
                $update['expected_yield_pct'] = $input['expected_yield_pct'] !== null && $input['expected_yield_pct'] !== ''
                    ? (float) $input['expected_yield_pct'] : null;
            }
            if (array_key_exists('reference_duration_days', $input)) {
                $update['reference_duration_days'] = $input['reference_duration_days'] !== null && $input['reference_duration_days'] !== ''
                    ? (int) $input['reference_duration_days'] : null;
            }
            if (array_key_exists('is_active', $input) && $input['is_active'] !== null) {
                $update['is_active'] = $input['is_active'] ? 1 : 0;
            }

            $this->db->table('facilities_waste_categories')
                ->where('facilities_waste_categories.tenant_id', CurrentTenant::id())
                ->where('id', $categoryId)->update($update);

            // A change to the assigned expected days must propagate to the
            // stored expected completion of any drum currently running this
            // category, so the ETA reflects the newly assigned duration.
            if (array_key_exists('reference_duration_days', $input)) {
                $this->support->refreshActiveBatchExpectedDates($categoryId);
            }

            $this->audit->enqueue('bmg.waste_category_updated', 'facilities_waste_categories', $categoryId, $userId, [
                'resource_code' => (string) $cat['code'],
            ]);

            return $this->wasteCategoryDto($categoryId);
        });
    }

    /**
     * Soft-archive a waste category by setting `is_active = 0`. The
     * FK `default_category_id` on `facilities_bmg_units` is `SET NULL`
     * on parent change — but the operator may have set a drum's default
     * to this category, so we refuse the archive if any active unit
     * still references it. The operator must clear the unit's
     * `default_category_id` first.
     */
    public function archiveWasteCategory(int $categoryId): array
    {
        $this->policy->check('categories_manage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($categoryId, $userId): array {
            $cat = $this->selectForUpdate('facilities_waste_categories', ['id' => $categoryId, 'tenant_id' => CurrentTenant::id()]);
            if ($cat === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Waste category #{$categoryId} not found."],
                ]);
            }
            if ((int) $cat['is_active'] === 0) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => 'Category is already archived.', 'field' => 'is_active'],
                ]);
            }

            // Guard: refuse to archive while any unit still uses this as its default.
            $refs = $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('default_category_id', $categoryId)
                ->where('archived_at', null)
                ->countAllResults();
            if ($refs > 0) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "Cannot archive: {$refs} unit(s) still use this category as their default. Clear those first."],
                ]);
            }

            $now = $this->support->utcNow();
            $this->db->table('facilities_waste_categories')
                ->where('facilities_waste_categories.tenant_id', CurrentTenant::id())
                ->where('id', $categoryId)->update([
                    'is_active'  => 0,
                    'updated_at' => $now,
                ]);

            $this->audit->enqueue('bmg.waste_category_archived', 'facilities_waste_categories', $categoryId, $userId, [
                'resource_code' => (string) $cat['code'],
            ]);

            return $this->wasteCategoryDto($categoryId);
        });
    }

    /**
     * Restore an archived waste category (`is_active = 1`) so it
     * reappears in pickers. Mirrors `archiveWasteCategory` — 409 when
     * the category is already active, same-transaction audit row.
     */
    public function unarchiveWasteCategory(int $categoryId): array
    {
        $this->policy->check('categories_manage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($categoryId, $userId): array {
            $cat = $this->selectForUpdate('facilities_waste_categories', ['id' => $categoryId, 'tenant_id' => CurrentTenant::id()]);
            if ($cat === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Waste category #{$categoryId} not found."],
                ]);
            }
            if ((int) $cat['is_active'] === 1) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => 'Category is already active.', 'field' => 'is_active'],
                ]);
            }

            $now = $this->support->utcNow();
            $this->db->table('facilities_waste_categories')
                ->where('facilities_waste_categories.tenant_id', CurrentTenant::id())
                ->where('id', $categoryId)->update([
                    'is_active'  => 1,
                    'updated_at' => $now,
                ]);

            $this->audit->enqueue('bmg.waste_category_restored', 'facilities_waste_categories', $categoryId, $userId, [
                'resource_code' => (string) $cat['code'],
            ]);

            return $this->wasteCategoryDto($categoryId);
        });
    }

    /**
     * Hard-delete a waste category. Refuses if any batch or unit still
     * references it — the operator should archive instead, which keeps
     * the category out of pickers but preserves history. Mirrors the
     * legacy `WasteCategoryController::delete()` guard.
     */
    public function deleteWasteCategory(int $categoryId): void
    {
        $this->policy->check('categories_manage');
        $userId = \App\Auth\CurrentUser::assert();

        $this->txn(function () use ($categoryId, $userId): void {
            $cat = $this->selectForUpdate('facilities_waste_categories', ['id' => $categoryId, 'tenant_id' => CurrentTenant::id()]);
            if ($cat === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Waste category #{$categoryId} not found."],
                ]);
            }

            $batchRefs = $this->db->table('facilities_bmg_batches')
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                ->where('category_id', $categoryId)
                ->countAllResults();
            if ($batchRefs > 0) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "Cannot delete: {$batchRefs} batch(es) reference this category. Archive it instead."],
                ]);
            }
            $unitRefs = $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('default_category_id', $categoryId)
                ->countAllResults();
            if ($unitRefs > 0) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "Cannot delete: {$unitRefs} unit(s) reference this category as their default. Clear those first."],
                ]);
            }

            $this->db->table('facilities_waste_categories')
                ->where('facilities_waste_categories.tenant_id', CurrentTenant::id())
                ->where('id', $categoryId)->delete();

            $this->audit->enqueue('bmg.waste_category_deleted', 'facilities_waste_categories', $categoryId, $userId, [
                'resource_code' => (string) $cat['code'],
            ]);
        });
    }
}
