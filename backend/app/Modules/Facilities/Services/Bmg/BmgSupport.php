<?php

declare(strict_types=1);

namespace Modules\Facilities\Services\Bmg;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Analytics\BmgAnalytics;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * BmgSupport — shared helper family for the BMG service cluster.
 *
 * Extracted verbatim from BmgService so every collaborator (Batch IO,
 * analytics reader, alerts, history, waste categories, and the
 * BmgService state machine itself) can compose the same composition /
 * duration / slug helpers without duplication. Queries here keep the
 * tenant scoping (`tenant_id` / {@see CurrentTenant}) they had in
 * BmgService.
 */
final class BmgSupport extends BaseService
{
    public function __construct(
        ?BaseConnection $db,
        private readonly BmgAnalytics $analytics = new BmgAnalytics(),
    ) {
        parent::__construct($db);
    }

    public function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * Weighted expected duration (days) for a batch's composition.
     *
     * Single-category: that category's `reference_duration_days`. Mixed:
     * weight-weighted average across the composition rows. Falls back to
     * the category default. Returns null when no duration is on record.
     *
     * @param array<int, array{category_id:int, weight_kg:float}> $composition
     */
    public function expectedDurationDays(array $composition, ?int $categoryId): ?int
    {
        if ($composition === []) {
            if ($categoryId === null) {
                return null;
            }
            $row = $this->db->table('facilities_waste_categories')
                ->select('reference_duration_days')
                ->where('tenant_id', CurrentTenant::id())
                ->where('id', $categoryId)
                ->get()->getRowArray();

            return $row !== null && $row['reference_duration_days'] !== null
                ? (int) $row['reference_duration_days']
                : null;
        }

        $totalW = 0.0;
        $weighted = 0.0;
        foreach ($composition as $c) {
            $w = (float) $c['weight_kg'];
            $totalW += $w;
            $cat = $this->db->table('facilities_waste_categories')
                ->select('reference_duration_days')
                ->where('tenant_id', CurrentTenant::id())
                ->where('id', (int) $c['category_id'])
                ->get()->getRowArray();
            $days = $cat !== null && $cat['reference_duration_days'] !== null
                ? (int) $cat['reference_duration_days']
                : 30; // sensible fallback when the category has no duration
            $weighted += $days * $w;
        }

        return $totalW > 0 ? (int) round($weighted / $totalW) : null;
    }

    /**
     * Recompute the stored `expected_completion_date` for every ACTIVE
     * batch (processing / awaiting_output / curing) that references the
     * given waste category — either as its single `category_id` or as a
     * component of its structured composition. Called when the category's
     * assigned `reference_duration_days` changes, so running drums reflect
     * the newly assigned expected duration immediately (web + mobile read
     * the stored date for the drum card / "N% toward expected completion").
     */
    public function refreshActiveBatchExpectedDates(int $categoryId): void
    {
        $active = [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT, BMG_STATE_CURING];

        $direct = $this->db->table('facilities_bmg_batches')
            ->select('id, category_id, started_at')
            ->where('category_id', $categoryId)
            ->where('archived_at', null)
            ->where('tenant_id', CurrentTenant::id())
            ->whereIn('status', $active)
            ->get()->getResultArray();

        $viaComposition = $this->db->table('facilities_bmg_batches AS b')
            ->select('b.id, b.category_id, b.started_at')
            ->join('facilities_bmg_composition AS bc', 'bc.batch_id = b.id')
            ->where('bc.category_id', $categoryId)
            ->where('b.archived_at', null)
            ->where('b.tenant_id', CurrentTenant::id())
            ->whereIn('b.status', $active)
            ->get()->getResultArray();

        $byId = [];
        foreach (array_merge($direct, $viaComposition) as $r) {
            $byId[(int) $r['id']] = $r;
        }
        if ($byId === []) {
            return;
        }

        $utc = new DateTimeZone('UTC');
        foreach ($byId as $id => $row) {
            $comps = $this->batchCompositions([$id])[$id] ?? [];
            $days  = $this->expectedDurationDays($comps, $row['category_id'] !== null ? (int) $row['category_id'] : null);
            $expectedAt = null;
            if ($days !== null) {
                $expectedAt = (new DateTimeImmutable((string) $row['started_at'], $utc))
                    ->modify("+{$days} days")
                    ->format('Y-m-d H:i:s');
            }
            $this->db->table('facilities_bmg_batches')
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                ->where('id', $id)
                ->update(['expected_completion_date' => $expectedAt, 'updated_at' => $this->utcNow()]);
        }
    }

    // -------------------------------------------- panel-revision helpers

    /**
     * Validate + normalize a batch composition payload: positive weights,
     * no duplicate categories, and the component sum must equal the
     * declared total (±0.01 kg tolerance).
     *
     * @param array<int, mixed> $composition
     * @return array<int, array{category_id:int, weight_kg:float}>
     */
    public function normalizeComposition(array $composition, float $totalInputKg): array
    {
        if ($composition === []) {
            return [];
        }

        $out  = [];
        $sum  = 0.0;
        $seen = [];
        foreach ($composition as $c) {
            $cid = isset($c['category_id']) ? (int) $c['category_id'] : 0;
            $w   = isset($c['weight_kg']) ? (float) $c['weight_kg'] : 0.0;
            if ($cid <= 0) {
                throw new ApiException('validation.invalid', 422, [
                    ['code' => 'validation.invalid', 'message' => 'Each composition row needs a category_id.', 'field' => 'composition'],
                ]);
            }
            if ($w <= 0) {
                throw new ApiException('validation.invalid', 422, [
                    ['code' => 'validation.invalid', 'message' => 'Each composition row needs a weight_kg > 0.', 'field' => 'composition'],
                ]);
            }
            if (isset($seen[$cid])) {
                throw new ApiException('validation.invalid', 422, [
                    ['code' => 'validation.invalid', 'message' => 'Duplicate waste category in composition.', 'field' => 'composition'],
                ]);
            }
            $seen[$cid] = true;
            $sum += $w;
            $out[] = ['category_id' => $cid, 'weight_kg' => round($w, 2)];
        }

        if (abs($sum - $totalInputKg) > 0.01) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => sprintf('Composition weights (%.2f kg) must add up to total_input_weight_kg (%.2f kg).', $sum, $totalInputKg), 'field' => 'composition'],
            ]);
        }

        return $out;
    }

    /**
     * Normalize + assert the slug contract for `code` fields: lowercase
     * `a-z0-9` groups separated by single hyphens. Whitespace and
     * uppercase input are normalized rather than rejected. Delegates the
     * pure normalization/validation to {@see BmgAnalytics} so the rule
     * is unit-tested without booting the DB.
     */
    public function assertSlug(string $raw, string $field): string
    {
        $slug = $this->analytics->normalizeSlug($raw);
        if (! $this->analytics->isValidSlug($slug)) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'Must be a slug: lowercase letters/digits separated by single hyphens (e.g. drum-01).', 'field' => $field],
            ]);
        }
        return $slug;
    }

    /**
     * Historical duration per category, averaged over FINISHED batches
     * (multi-trial validated data — panel revision). A batch counts for
     * a category when the category is in its structured composition;
     * legacy batches without composition rows count via their single
     * `category_id` tag.
     *
     * @param array<int, int> $categoryIds
     * @return array<int, array{avg_days: float, samples: int}>
     */
    public function categoryDurationStats(array $categoryIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);

        $rows = $this->db->query(
            'SELECT t.cat_id, AVG(t.days) AS avg_days, COUNT(*) AS samples FROM ('
            . ' SELECT c.category_id AS cat_id, DATEDIFF(b.finished_at, b.started_at) AS days'
            . ' FROM facilities_bmg_composition c'
            . ' JOIN facilities_bmg_batches b ON b.id = c.batch_id'
            . ' WHERE b.tenant_id = ? AND b.finished_at IS NOT NULL AND b.archived_at IS NULL AND c.category_id IN (' . $in . ')'
            . ' UNION ALL'
            . ' SELECT b.category_id, DATEDIFF(b.finished_at, b.started_at)'
            . ' FROM facilities_bmg_batches b'
            . ' LEFT JOIN facilities_bmg_composition c2 ON c2.batch_id = b.id'
            . ' WHERE c2.id IS NULL AND b.tenant_id = ? AND b.category_id IS NOT NULL AND b.finished_at IS NOT NULL'
            . ' AND b.archived_at IS NULL AND b.category_id IN (' . $in . ')'
            . ') t GROUP BY t.cat_id',
            [CurrentTenant::id(), CurrentTenant::id()],
        )->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['cat_id']] = [
                'avg_days' => (float) $r['avg_days'],
                'samples'  => (int) $r['samples'],
            ];
        }
        return $out;
    }

    /**
     * Per-category expected days: the ASSIGNED `reference_duration_days`
     * (the operator's target — drives batch ETAs). `sample_count`
     * (finished trials) is kept so callers can show historical context;
     * the rounded historical average is exposed separately on the category
     * list DTO as `historical_avg_days`.
     *
     * @param array<int, int> $categoryIds
     * @return array<int, array{expected_days: ?int, sample_count: int}>
     */
    public function expectedDaysByCategory(array $categoryIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($ids === []) {
            return [];
        }

        $refs  = $this->db->table('facilities_waste_categories')
            ->where('facilities_waste_categories.tenant_id', CurrentTenant::id())
            ->select('id, reference_duration_days')
            ->whereIn('id', $ids)
            ->get()->getResultArray();

        $out = [];
        foreach ($refs as $r) {
            $id   = (int) $r['id'];
            $ref  = $r['reference_duration_days'] !== null ? (int) $r['reference_duration_days'] : null;
            $out[$id] = [
                'expected_days' => $ref,
                'sample_count'  => $this->categorySamples($id),
            ];
        }
        return $out;
    }

    /**
     * Number of finished trials for a category (composition rows + the
     * single-category fallback), used only for historical context.
     */
    public function categorySamples(int $categoryId): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS n FROM ('
            . ' SELECT c.category_id AS cat_id FROM facilities_bmg_composition c'
            . ' JOIN facilities_bmg_batches b ON b.id = c.batch_id'
            . ' WHERE b.tenant_id = ? AND b.finished_at IS NOT NULL AND b.archived_at IS NULL AND c.category_id = ' . (int) $categoryId
            . ' UNION ALL'
            . ' SELECT b.category_id FROM facilities_bmg_batches b'
            . ' LEFT JOIN facilities_bmg_composition c2 ON c2.batch_id = b.id'
            . ' WHERE c2.id IS NULL AND b.tenant_id = ? AND b.category_id IS NOT NULL AND b.finished_at IS NOT NULL'
            . ' AND b.archived_at IS NULL AND b.category_id = ' . (int) $categoryId
            . ') t',
            [CurrentTenant::id(), CurrentTenant::id()],
        )->getRowArray();
        return $row !== null ? (int) $row['n'] : 0;
    }

    /**
     * Structured composition rows (with category names) for a set of
     * batches, keyed by batch id.
     *
     * @param array<int, int> $batchIds
     * @return array<int, array<int, array{category_id:int, category_name:string, weight_kg:float}>>
     */
    public function batchCompositions(array $batchIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $batchIds)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('facilities_bmg_composition AS bc')
            ->select('bc.batch_id, bc.category_id, bc.weight_kg, c.name AS category_name')
            ->join('facilities_waste_categories AS c', 'c.id = bc.category_id')
            ->whereIn('bc.batch_id', $ids)
            ->orderBy('bc.weight_kg', 'DESC')
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['batch_id']][] = [
                'category_id'   => (int) $r['category_id'],
                'category_name' => (string) $r['category_name'],
                'weight_kg'     => (float) $r['weight_kg'],
            ];
        }
        return $out;
    }

    /**
     * Weight-ratio-weighted expected duration for a drum's specific mix.
     * Delegates the pure math to {@see BmgAnalytics::weightedExpectedDays}
     * (unit-tested); returns null when no component carries data.
     *
     * @param array<int, array{category_id:int, weight_kg:float}> $components
     * @param array<int, array{expected_days: ?int, sample_count: int}> $expectedByCat
     */
    public function weightedExpectedDays(array $components, array $expectedByCat): ?int
    {
        return $this->analytics->weightedExpectedDays($components, $expectedByCat);
    }
}
