<?php

declare(strict_types=1);

namespace App\Services\Audit;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;

/**
 * AuditDrainService — moves rows from `audit_outbox` to `audit_events`.
 *
 * Single source of truth for the drain loop, shared by the
 * `synapse:audit-drain` CLI command and the opportunistic auto-drain
 * hook (Config\Events) so dev/demo logs appear without a cron worker.
 *
 * Guarantees:
 *   - Hash chaining (v2, per tenant — 2026-10 audit G-2): for each tenant,
 *     commit_hash = AuditHashV2 canonical SHA-256 (fixed-order JSON that
 *     includes tenant_id and actor/request provenance); genesis hashes
 *     against 64 zero chars; `prev_id` links each row to the previous event
 *     of the SAME tenant (verified by `synapse:audit-verify`). Pre-v2 rows
 *     (hash_version = 1) are frozen on the old global formula and remain
 *     verifiable by the frozen v1 walk in AuditChainVerifier.
 *   - The chain tail is fetched ONCE per batch and carried forward —
 *     no per-row tail lookup, no broken `prev_id`.
 *   - `SELECT ... FOR UPDATE SKIP LOCKED` — parallel workers never
 *     double-process a claimed row.
 *   - Poison rows: a failing batch is rolled back atomically, the
 *     offending row's `attempt_count`/`last_error` are bumped outside
 *     the transaction, and rows at MAX_ATTEMPTS are no longer selected.
 */
final class AuditDrainService
{
    public const MAX_ATTEMPTS = 5;

    /**
     * Drain up to `$maxBatches` batches of `$batch` rows each.
     *
     * @return array{drained:int, failed:int}
     */
    public function drain(int $batch = 500, int $maxBatches = 10): array
    {
        $batch      = max(1, $batch);
        $maxBatches = max(1, $maxBatches);

        $db = Database::connect();
        $skipLocked = self::skipLockedSupported($db);
        if (! $skipLocked) {
            Services::logger()->warning(
                'AuditDrainService: SKIP LOCKED not supported on this DB version — '
                . 'falling back to plain FOR UPDATE. Parallel workers will serialize. '
                . 'Upgrade to MariaDB 10.6+ or MySQL 8+ for concurrent drain.'
            );
        }
        $drained = 0;
        $failed  = 0;

        for ($i = 0; $i < $maxBatches; $i++) {
            $failingRowId = null;
            $db->transBegin();

            try {
                $rows = $db->query(
                    'SELECT * FROM ' . SYNAPSE_AUDIT_OUTBOX
                    . ' WHERE processed_at IS NULL AND attempt_count < ?'
                    . ' ORDER BY id ASC'
                    . ' LIMIT ' . $batch
                    . ' FOR UPDATE' . ($skipLocked ? ' SKIP LOCKED' : ''),
                    [self::MAX_ATTEMPTS],
                )->getResultArray();

                if ($rows === []) {
                    $db->transCommit();
                    break;
                }

                /*
                 * v2 chains: per-tenant. The tails of every tenant present
                 * in this batch are fetched up front (two small queries),
                 * then carried forward row-by-row exactly like the v1
                 * single-chain tail was.
                 */
                $tenantIds = [];
                foreach ($rows as $r) {
                    $tenantIds[(int) $r['tenant_id']] = true;
                }
                $tails = $db->table(SYNAPSE_AUDIT_EVENTS)
                    ->select('id, tenant_id, commit_hash')
                    ->where('hash_version', 2)
                    ->whereIn('tenant_id', array_keys($tenantIds))
                    ->orderBy('id', 'DESC')
                    ->get()
                    ->getResultArray();
                /** @var array<int, array{id:?int, hash:string}> $prevByTenant */
                $prevByTenant = [];
                foreach (array_keys($tenantIds) as $tid) {
                    $prevByTenant[(int) $tid] = ['id' => null, 'hash' => AuditHashV2::GENESIS];
                }
                foreach ($tails as $tail) {
                    $tid = (int) $tail['tenant_id'];
                    if ($prevByTenant[$tid]['id'] === null || (int) $tail['id'] > $prevByTenant[$tid]['id']) {
                        $prevByTenant[$tid] = ['id' => (int) $tail['id'], 'hash' => (string) $tail['commit_hash']];
                    }
                }

                $batchDrained = 0;

                foreach ($rows as $r) {
                    $failingRowId = (int) $r['id'];

                    $payload = json_encode([
                        'action_code'   => $r['action_code'],
                        'entity_type'   => $r['entity_type'],
                        'entity_id'     => $r['entity_id'],
                        'actor_user_id' => $r['actor_user_id'],
                        'context'       => json_decode((string) $r['context_json'], true),
                    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

                    $tenantId = (int) $r['tenant_id'];
                    $prev     = $prevByTenant[$tenantId];
                    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

                    $commitHash = AuditHashV2::compute([
                        'tenant_id'     => $tenantId,
                        'prev_hash'     => $prev['hash'],
                        'action_code'   => $r['action_code'],
                        'entity_type'   => $r['entity_type'],
                        'entity_id'     => $r['entity_id'],
                        'actor_user_id' => $r['actor_user_id'],
                        'request_id'    => $r['request_id'],
                        'occurred_at'   => (string) $r['created_at'],
                        'payload_json'  => $payload,
                    ]);

                    $db->table(SYNAPSE_AUDIT_EVENTS)->insert([
                        'hash_version'  => 2,
                        'tenant_id'     => $tenantId,
                        'prev_id'       => $prev['id'],
                        'action_code'   => $r['action_code'],
                        'entity_type'   => $r['entity_type'],
                        'entity_id'     => $r['entity_id'],
                        'actor_user_id' => $r['actor_user_id'],
                        'payload_json'  => $payload,
                        'request_id'    => $r['request_id'],
                        'occurred_at'   => $r['created_at'],
                        'commited_at'   => $now,
                        'commit_hash'   => $commitHash,
                    ]);

                    $prevByTenant[$tenantId] = ['id' => (int) $db->insertID(), 'hash' => $commitHash];

                    $db->table(SYNAPSE_AUDIT_OUTBOX)
                        ->where('id', $r['id'])
                        ->update([
                            'processed_at'  => $now,
                            'attempt_count' => (int) $r['attempt_count'] + 1,
                            'last_error'    => null,
                        ]);

                    $batchDrained++;
                }

                $db->transCommit();
                $drained += $batchDrained;
            } catch (\Throwable $t) {
                $db->transRollback();
                $failed++;

                if ($failingRowId !== null) {
                    // Outside the rolled-back transaction: eject the poison
                    // row after MAX_ATTEMPTS. Only the exception CLASS and a
                    // truncated, whitespace-collapsed message are stored —
                    // never payloads.
                    $reason = $t::class . ': ' . substr(
                        (string) preg_replace('/\s+/', ' ', $t->getMessage()),
                        0,
                        160,
                    );
                    $db->table(SYNAPSE_AUDIT_OUTBOX)
                        ->where('id', $failingRowId)
                        ->set('attempt_count', 'attempt_count + 1', false)
                        ->set('last_error', $reason)
                        ->update();

                    continue;
                }

                break;
            }
        }

        return ['drained' => $drained, 'failed' => $failed];
    }

    /**
     * `SKIP LOCKED` requires MySQL 8+ / MariaDB 10.6+. Older servers
     * (e.g. XAMPP's MariaDB 10.4 in dev) fall back to plain FOR UPDATE
     * — correctness holds; parallel workers just serialize.
     */
    private static function skipLockedSupported(BaseConnection $db): bool
    {
        $version = strtolower($db->getVersion());
        if (str_contains($version, 'mariadb')) {
            preg_match('/(\d+)\.(\d+)/', $version, $m);
            return isset($m[1], $m[2]) && ((int) $m[1] > 10 || ((int) $m[1] === 10 && (int) $m[2] >= 6));
        }
        return version_compare($version, '8.0', '>=');
    }
}
