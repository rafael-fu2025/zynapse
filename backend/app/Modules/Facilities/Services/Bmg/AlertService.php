<?php

declare(strict_types=1);

namespace Modules\Facilities\Services\Bmg;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Facilities\DTOs\BmgAlertDto;
use Modules\Facilities\Policies\BmgPolicy;

/**
 * AlertService — read/acknowledge cluster for BMG alerts.
 *
 * Extracted verbatim from BmgService: listing a batch's alerts,
 * acknowledging an alert, and the global open-alert feed. Alerts are
 * CREATED in addProcessLog (which stays in BmgService together with
 * the alertEngine/notify dependencies) — this collaborator only reads
 * and acknowledges. Transaction boundaries, audit rows and policy
 * action strings are unchanged from the pre-extraction BmgService.
 */
final class AlertService extends BaseService
{
    public function __construct(
        ?BaseConnection $db,
        private readonly BmgPolicy $policy,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct($db);
    }

    /**
     * List alerts for a single batch. Read-only; ordered most-recent
     * first, unacknowledged alerts come before acknowledged ones so
     * the UI can render a banner without re-sorting.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAlerts(int $batchId): array
    {
        // Tier 3.2 — `alerts_read` is owned; the tenant-guarded batch
        // lookup doubles as the ownership-row source.
        $batch = $this->db->table('facilities_bmg_batches')
            ->select('id, started_by_user_id, tenant_id, archived_at')
            ->where('id', $batchId)
            ->where('tenant_id', CurrentTenant::id())
            ->where('archived_at', null)
            ->get()
            ->getRowArray();
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('alerts_read', $batch);

        $rows = $this->db->table('facilities_bmg_alerts')
            ->where('batch_id', $batchId)
            ->where('tenant_id', CurrentTenant::id())
            ->orderBy('acknowledged_at', 'ASC', false)
            ->orderBy('triggered_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $r) => BmgAlertDto::fromRow($r)->toArray(), $rows);
    }

    /**
     * Acknowledge an alert. Records the user and timestamp; subsequent
     * UI fetches will rank the alert below unacknowledged ones.
     */
    public function acknowledgeAlert(int $alertId): array
    {
        // Tier 3.2 — `alerts_ack` is owned. Load the alert first to
        // discover its batch_id, then load the batch row for the
        // ownership check. Both reads are tenant-scoped.
        $alert = $this->db->table('facilities_bmg_alerts')
            ->select('id, batch_id, tenant_id')
            ->where('id', $alertId)
            ->where('tenant_id', CurrentTenant::id())
            ->get()->getRowArray();
        if ($alert === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Alert #{$alertId} not found."],
            ]);
        }
        $batch = $this->policy->loadBatchForOwnership((int) $alert['batch_id']);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$alert['batch_id']} not found."],
            ]);
        }
        $this->policy->check('alerts_ack', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($alertId, $userId): array {
            $row = $this->selectForUpdate('facilities_bmg_alerts', [
                'id'        => $alertId,
                'tenant_id' => CurrentTenant::id(),
            ]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Alert #{$alertId} not found."],
                ]);
            }
            if ($row['acknowledged_at'] !== null) {
                // Idempotent — re-acking is allowed and returns the
                // current row, but we don't write a second audit event.
                return BmgAlertDto::fromRow($row)->toArray();
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('facilities_bmg_alerts')
                ->where('id', $alertId)
                ->where('tenant_id', CurrentTenant::id())
                ->update([
                    'acknowledged_at'         => $now,
                    'acknowledged_by_user_id' => $userId,
                    'updated_at'              => $now,
                ]);

            $this->audit->enqueue(
                'bmg.alert_acknowledged',
                'facilities_bmg_alerts',
                $alertId,
                $userId,
                [
                    'batch_id' => (int) $row['batch_id'],
                    'code'     => (string) $row['code'],
                ],
            );

            $row['acknowledged_at'] = $now;
            $row['acknowledged_by_user_id'] = $userId;
            return BmgAlertDto::fromRow($row)->toArray();
        });
    }

    // -------------------------------------------------- open alerts

    /**
     * Global open-alert feed — every unacknowledged alert across ALL
     * batches (dashboard "at-risk" widget + facilities banner). Joined
     * with the batch + unit so staff can act without per-batch hops.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listOpenAlerts(): array
    {
        $this->policy->check('list');

        $rows = $this->db->table('facilities_bmg_alerts AS a')
            ->select(
                'a.id AS alert_id, a.code, a.severity, a.message, a.triggered_at, a.acknowledged_at,'
                . ' b.id AS batch_id, b.reference_code, b.status AS batch_status,'
                . ' u.id AS unit_id, u.code AS unit_code, u.display_name AS unit_name'
            )
            ->join('facilities_bmg_batches AS b', 'b.id = a.batch_id')
            ->join('facilities_bmg_units AS u', 'u.id = b.unit_id', 'left')
            ->where('a.tenant_id', CurrentTenant::id())
            ->where('a.acknowledged_at', null)
            ->whereIn('b.status', [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT, BMG_STATE_CURING])
            ->orderBy('a.triggered_at', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'alert_id'       => (int) $r['alert_id'],
            'code'           => (string) $r['code'],
            'severity'       => (string) $r['severity'],
            'message'        => (string) $r['message'],
            'triggered_at'   => (string) $r['triggered_at'],
            'acknowledged_at'=> $r['acknowledged_at'] !== null ? (string) $r['acknowledged_at'] : null,
            'batch_id'       => (int) $r['batch_id'],
            'reference_code' => (string) $r['reference_code'],
            'batch_status'   => (string) $r['batch_status'],
            'unit_id'        => $r['unit_id'] !== null ? (int) $r['unit_id'] : null,
            'unit_code'      => $r['unit_code'] !== null ? (string) $r['unit_code'] : null,
            'unit_name'      => $r['unit_name'] !== null ? (string) $r['unit_name'] : null,
        ], $rows);
    }
}
