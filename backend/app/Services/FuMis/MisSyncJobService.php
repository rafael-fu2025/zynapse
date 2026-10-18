<?php

declare(strict_types=1);

namespace App\Services\FuMis;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\CurrentTenant;
use Config\Services;

/** DB-only admission; directory IO belongs exclusively to a claimed worker. */
final class MisSyncJobService extends BaseService
{
    public const LEASE_SECONDS = 900;

    public static function validate(string $kind, int $pageSize, int $maxPages): void
    {
        if (! in_array($kind, ['all', 'student', 'employee'], true) || $pageSize < 1 || $pageSize > 100
            || $maxPages < 1 || $maxPages > 10000) {
            throw ApiException::validationFailure([['code' => 'validation.field', 'message' => 'Use kind all/student/employee, page_size 1-100 and max_pages 1-10000.', 'field' => 'kind']]);
        }
    }

    public function enqueue(string $kind = 'all', bool $dryRun = false, int $pageSize = 100, int $maxPages = 1000, string $source = 'admin', ?int $cooldown = null, ?int $actorId = null): ?array
    {
        self::validate($kind, $pageSize, $maxPages);
        if (! in_array($source, ['admin', 'cli', 'auto'], true)) {
            throw new \InvalidArgumentException('Invalid queue source.');
        }
        $tenant = CurrentTenant::id();
        return $this->txn(function () use ($tenant, $kind, $dryRun, $pageSize, $maxPages, $source, $cooldown, $actorId): ?array {
            if ($this->selectForUpdate('tenants', ['id' => $tenant]) === null) {
                throw ApiException::notFound();
            }
            $active = $this->selectForUpdate('mis_sync_jobs', ['tenant_id' => $tenant, 'active_guard' => 1]);
            if ($active !== null && $active['status'] === 'running' && $this->stale($active)) {
                $this->interrupt($active);
                $active = null;
            }
            if ($active !== null) {
                if ($source === 'auto') {
                    return null;
                }
                if ($active['kind'] === $kind && (bool) $active['dry_run'] === $dryRun
                    && (int) $active['page_size'] === $pageSize && (int) $active['max_pages'] === $maxPages) {
                    return self::dto($active);
                }
                throw ApiException::conflict('mis.sync_active', 'A different directory sync is already active for this tenant (job ' . $active['id'] . ').');
            }
            if ($source === 'auto') {
                $latest = $this->db->table('mis_sync_jobs')->where('tenant_id', $tenant)->orderBy('id', 'DESC')->get(1)->getRowArray();
                // A failed/interrupted sweep always requires an explicit new submission.
                if ($latest !== null && in_array($latest['status'], ['failed', 'interrupted'], true)) {
                    return null;
                }
                $lastAuto = $this->db->table('mis_sync_jobs')->where('tenant_id', $tenant)->where('source', 'auto')->orderBy('id', 'DESC')->get(1)->getRowArray();
                if ($lastAuto !== null && strtotime($lastAuto['created_at'] . ' UTC') > time() - ($cooldown ?? 86400)) {
                    return null;
                }
            }
            $this->db->table('mis_sync_jobs')->insert([
                'tenant_id' => $tenant, 'active_guard' => 1, 'kind' => $kind, 'dry_run' => (int) $dryRun,
                'page_size' => $pageSize, 'max_pages' => $maxPages, 'source' => $source, 'actor_user_id' => $actorId,
                'status' => 'queued', 'kinds_json' => '{}', 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->db->insertID();
            Services::auditOutbox()->enqueue('mis.directory_sync_queued', 'mis_sync_jobs', $id, $actorId, [
                'resource_code' => $kind, 'reason_code' => 'mis.sync_queue', 'outcome' => 'queued',
            ]);
            return $this->get($id);
        });
    }

    public function latest(): ?array
    {
        $this->expireStale();
        $row = $this->db->table('mis_sync_jobs')->where('tenant_id', CurrentTenant::id())->orderBy('id', 'DESC')->get(1)->getRowArray();
        return $row === null ? null : self::dto($row);
    }

    public function get(int $id): array
    {
        $this->expireStale();
        $row = $this->db->table('mis_sync_jobs')->where('tenant_id', CurrentTenant::id())->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            throw ApiException::notFound();
        }
        return self::dto($row);
    }

    public function expireStale(): void
    {
        $this->txn(function (): void {
            $row = $this->selectForUpdate('mis_sync_jobs', ['tenant_id' => CurrentTenant::id(), 'active_guard' => 1]);
            if ($row !== null && $row['status'] === 'running' && $this->stale($row)) {
                $this->interrupt($row);
            }
        });
    }

    private function stale(array $row): bool
    {
        // Token acquisition/recovery plus one directory retry can make several upstream calls.
        $lease = max(self::LEASE_SECONDS, (new \Config\FuMis())->timeoutSeconds * 8 + 300);
        return strtotime($row['heartbeat_at'] . ' UTC') <= time() - $lease;
    }

    private function interrupt(array $row): void
    {
        $this->db->table('mis_sync_jobs')->where('tenant_id', CurrentTenant::id())->where('id', $row['id'])->update([
            'status' => 'interrupted', 'active_guard' => null, 'owner_token' => null,
            'finished_at' => gmdate('Y-m-d H:i:s'), 'error_code' => 'mis.sync_interrupted',
            'error_message' => 'Worker lease expired. Committed pages remain. Submit a new sync to start a fresh sweep.',
        ]);
        Services::auditOutbox()->enqueue('mis.directory_sync_interrupted', 'mis_sync_jobs', (int) $row['id'], isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : null, [
            'resource_code' => $row['kind'], 'reason_code' => 'mis.sync_' . $row['source'], 'outcome' => 'interrupted',
        ]);
    }

    public function claim(?int $id = null): ?array
    {
        $this->expireStale();
        return $this->txn(function () use ($id): ?array {
            $where = ['tenant_id' => CurrentTenant::id(), 'active_guard' => 1, 'status' => 'queued'];
            if ($id !== null) {
                $where['id'] = $id;
            }
            $row = $this->selectForUpdate('mis_sync_jobs', $where);
            if ($row === null) {
                return null;
            }
            $row['owner_token'] = bin2hex(random_bytes(32));
            $row['status'] = 'running';
            $row['started_at'] = $row['heartbeat_at'] = gmdate('Y-m-d H:i:s');
            $this->db->table('mis_sync_jobs')->where('tenant_id', CurrentTenant::id())->where('id', $row['id'])->update([
                'owner_token' => $row['owner_token'], 'status' => 'running',
                'started_at' => $row['started_at'], 'heartbeat_at' => $row['heartbeat_at'],
            ]);
            return $row;
        });
    }

    /** Must run inside the same transaction as page identity changes. */
    public function fence(array $claim): void
    {
        $row = $this->selectForUpdate('mis_sync_jobs', ['tenant_id' => CurrentTenant::id(), 'id' => $claim['id']]);
        if ($row === null || $row['status'] !== 'running' || $row['owner_token'] !== $claim['owner_token'] || $this->stale($row)) {
            throw ApiException::conflict('mis.sync_fenced', 'Worker no longer owns this sync.');
        }
    }

    public function progress(array $claim, string $kind, int $page, int $total, array $kinds): void
    {
        $this->db->table('mis_sync_jobs')->where('tenant_id', CurrentTenant::id())->where('id', $claim['id'])
            ->where('owner_token', $claim['owner_token'])->where('status', 'running')->update([
                'current_kind' => $kind, 'current_page' => $page, 'total_pages' => $total,
                'kinds_json' => json_encode($kinds, JSON_THROW_ON_ERROR), 'heartbeat_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    public function execute(array $claim, ?FuMisDirectorySyncService $runner = null): array
    {
        $previous = CurrentTenant::id();
        CurrentTenant::set((int) $claim['tenant_id']);
        try {
            try {
                $this->txn(fn () => $this->fence($claim));
                $runner ??= new FuMisDirectorySyncService(null, $this->db);
                $summary = $runner->run($claim['kind'], (bool) $claim['dry_run'], (int) $claim['page_size'], (int) $claim['max_pages'],
                fn () => $this->fence($claim),
                fn (string $kind, int $page, int $total, array $kinds) => $this->progress($claim, $kind, $page, $total, $kinds), isset($claim['actor_user_id']) ? (int) $claim['actor_user_id'] : null);
            } catch (\Throwable) {
                $summary = ['success' => false, 'error' => ['code' => 'mis.sync_failed', 'message' => 'Sync could not execute. Check database/configuration, then submit a fresh sync.']];
            }
            $this->txn(function () use ($claim, $summary): void {
                $this->fence($claim);
                $this->db->table('mis_sync_jobs')->where('tenant_id', CurrentTenant::id())->where('id', $claim['id'])
                    ->where('owner_token', $claim['owner_token'])->update([
                        'status' => $summary['success'] ? 'completed' : 'failed', 'active_guard' => null,
                        'owner_token' => null, 'finished_at' => gmdate('Y-m-d H:i:s'),
                        'error_code' => $summary['error']['code'] ?? null, 'error_message' => $summary['error']['message'] ?? null,
                    ]);
                Services::auditOutbox()->enqueue('mis.directory_sync_finished', 'mis_sync_jobs', (int) $claim['id'], isset($claim['actor_user_id']) ? (int) $claim['actor_user_id'] : null, [
                    'resource_code' => $claim['kind'], 'reason_code' => 'mis.sync_' . $claim['source'],
                    'outcome' => $summary['success'] ? 'completed' : 'failed',
                ]);
            });
            return $this->get((int) $claim['id']);
        } catch (ApiException $e) {
            if ($e->errorCode !== 'mis.sync_fenced') {
                throw $e;
            }
            $this->expireStale();
            return $this->get((int) $claim['id']);
        } finally {
            CurrentTenant::set($previous);
        }
    }

    public function drain(int $limit = 1, ?int $tenant = null): array
    {
        $previous = CurrentTenant::id();
        $results = [];
        try {
            // Global discovery is intentional; every claim/write is explicitly tenant scoped.
            $query = $this->db->table('mis_sync_jobs')->select('tenant_id')->where('active_guard', 1)->orderBy('id');
            if ($tenant !== null) {
                $query->where('tenant_id', $tenant);
            }
            foreach ($query->get()->getResultArray() as $row) {
                CurrentTenant::set((int) $row['tenant_id']);
                $claim = $this->claim();
                if ($claim !== null) {
                    $results[] = $this->execute($claim);
                    if (count($results) >= $limit) {
                        break;
                    }
                }
            }
            return $results;
        } finally {
            CurrentTenant::set($previous);
        }
    }

    public static function dto(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'tenant_id' => (int) $row['tenant_id'], 'kind' => $row['kind'],
            'dry_run' => (bool) $row['dry_run'], 'status' => $row['status'],
            'kinds' => (object) json_decode($row['kinds_json'], true, 512, JSON_THROW_ON_ERROR),
            'current_kind' => $row['current_kind'] ?? null, 'current_page' => (int) ($row['current_page'] ?? 0),
            'total_pages' => isset($row['total_pages']) ? (int) $row['total_pages'] : null,
            'created_at' => $row['created_at'], 'started_at' => $row['started_at'] ?? null, 'finished_at' => $row['finished_at'] ?? null,
            'error' => empty($row['error_code']) ? null : ['code' => $row['error_code'], 'message' => $row['error_message']],
        ];
    }
}
