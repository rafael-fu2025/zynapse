<?php

declare(strict_types=1);

namespace Modules\Facilities\Services\Bmg;

use App\Modules\Shared\BaseService;
use App\Pagination\KeysetPaginator;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Modules\Facilities\Policies\BmgPolicy;

/**
 * HistoryService — batch history listing for the BMG module.
 *
 * Extracted verbatim from BmgService: the keyset-paginated terminal /
 * historical batch feed that serves the "batch history" audit surface.
 * Read-only (no txn, no audit); policy and pagination behavior are
 * unchanged from the pre-extraction BmgService.
 */
final class HistoryService extends BaseService
{
    public function __construct(
        ?BaseConnection $db,
        private readonly BmgPolicy $policy,
    ) {
        parent::__construct($db);
    }

    /**
     * Batch history listing — terminal + historical batches across all
     * units (or one unit). Keyset-paginated like the unit list. Serves
     * the "batch history" audit surface.
     *
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function listBatches(?int $unitId, ?string $status, ?string $cursor, int $limit): array
    {
        $this->policy->check('list');

        $builder = $this->db->table('facilities_bmg_batches AS b')
            ->select(
                'b.id, b.reference_code, b.status, b.total_input_weight_kg, b.output_weight_kg,'
                . ' b.total_loss_kg, b.quality_grade, b.maturity_level, b.started_at, b.finished_at,'
                . ' b.released_at, b.cancelled_at, b.created_at,'
                . ' u.id AS unit_id, u.code AS unit_code, u.display_name AS unit_name,'
                . ' c.name AS category_name'
            )
            ->join('facilities_bmg_units AS u', 'u.id = b.unit_id', 'left')
            ->join('facilities_waste_categories AS c', 'c.id = b.category_id', 'left')
            ->where('b.archived_at', null)
            ->where('b.tenant_id', CurrentTenant::id())
            ->orderBy('b.created_at', 'DESC')
            ->orderBy('b.id', 'DESC');

        if ($unitId !== null) {
            $builder->where('b.unit_id', $unitId);
        }
        if ($status !== null) {
            $builder->where('b.status', $status);
        }

        KeysetPaginator::apply($builder, $cursor, $limit, 'b.created_at', 'b.id');

        $rows  = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit, 'created_at');

        $data = array_map(static fn (array $r): array => [
            'id'                  => (int) $r['id'],
            'reference_code'      => (string) $r['reference_code'],
            'status'              => (string) $r['status'],
            'unit_id'             => (int) $r['unit_id'],
            'unit_code'           => (string) $r['unit_code'],
            'unit_name'           => (string) $r['unit_name'],
            'category_name'       => $r['category_name'] !== null ? (string) $r['category_name'] : null,
            'total_input_weight_kg' => (float) $r['total_input_weight_kg'],
            'output_weight_kg'    => $r['output_weight_kg'] !== null ? (float) $r['output_weight_kg'] : null,
            'total_loss_kg'       => $r['total_loss_kg'] !== null ? (float) $r['total_loss_kg'] : null,
            'quality_grade'       => $r['quality_grade'] !== null ? (string) $r['quality_grade'] : null,
            'maturity_level'      => $r['maturity_level'] !== null ? (string) $r['maturity_level'] : null,
            'started_at'          => (string) $r['started_at'],
            'finished_at'         => $r['finished_at'] !== null ? (string) $r['finished_at'] : null,
            'released_at'         => $r['released_at'] !== null ? (string) $r['released_at'] : null,
            'cancelled_at'        => $r['cancelled_at'] !== null ? (string) $r['cancelled_at'] : null,
        ], $final['rows']);

        return ['data' => $data, 'next' => $final['nextCursor'], 'count' => $limit];
    }
}
