<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Config\Services;

/**
 * AuditChainVerifier — recomputes the `audit_events` hash chains and
 * reports the first divergence.
 *
 * Two chain families coexist after the AuditChainV2 migration:
 *
 *   v1 (frozen): ONE global chain. commit_hash = SHA-256(prev_hash || payload_json),
 *                prev_id points at the immediately preceding row id overall.
 *   v2: ONE chain PER TENANT. commit_hash = SHA-256(AuditHashV2 canonical JSON incl.
 *       tenant_id), prev_id points at the preceding row id of the SAME tenant.
 *
 * verify() walks both families and merges the reports; verifyTenant() walks a
 * single tenant's v2 chain (what the HTTP verify endpoint and the --tenant CLI
 * option expose); verifyTo() verifies through a specific event id by resolving
 * that row's family.
 *
 * Verification walks id-ascending in bounded batches so memory stays O(batch).
 * Rows are never mutated — the log is append-only.
 */
final class AuditChainVerifier
{
    private const BATCH_SIZE = 1000;

    /**
     * Verify every chain: the v1 global chain plus each tenant's v2 chain.
     *
     * @return array{
     *     ok: bool,
     *     checked: int,
     *     verified_up_to: ?int,
     *     first_divergence: ?array{id:int, reason:string, expected:string, actual:string},
     *     tenants: list<array{tenant_id:int, ok:bool, checked:int, verified_up_to:?int, first_divergence:?array{id:int, reason:string, expected:string, actual:string}}>,
     * }
     */
    public function verify(?int $toId = null): array
    {
        $db = Services::database();

        $v1 = $this->walkV1($toId);

        $tenants = [];
        $tenantRows = $db->table(SYNAPSE_AUDIT_EVENTS)
            ->select('tenant_id')
            ->where('hash_version', 2)
            ->groupBy('tenant_id')
            ->orderBy('tenant_id', 'ASC')
            ->get()->getResultArray();
        foreach ($tenantRows as $t) {
            $tenants[] = ['tenant_id' => (int) $t['tenant_id']] + $this->verifyTenant((int) $t['tenant_id'], $toId);
        }

        $divergences = array_merge(
            $v1['first_divergence'] !== null ? [$v1['first_divergence'] + ['family' => 'v1']] : [],
            ...array_map(
                static fn (array $t): array => $t['first_divergence'] !== null
                    ? [$t['first_divergence'] + ['family' => 'tenant']]
                    : [],
                $tenants,
            ),
        );
        usort($divergences, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $first = $divergences === [] ? null : $divergences[0];
        if ($first !== null) {
            unset($first['family']);
        }

        $ok = $v1['ok'];
        foreach ($tenants as $t) {
            $ok = $ok && $t['ok'];
        }

        $verifiedUpTo = $v1['verified_up_to'];
        foreach ($tenants as $t) {
            if ($t['verified_up_to'] !== null && ($verifiedUpTo === null || $t['verified_up_to'] > $verifiedUpTo)) {
                $verifiedUpTo = $t['verified_up_to'];
            }
        }

        return [
            'ok'               => $ok,
            'checked'          => $v1['checked'] + array_sum(array_column($tenants, 'checked')),
            'verified_up_to'   => $ok ? $verifiedUpTo : ($first !== null ? null : $verifiedUpTo),
            'first_divergence' => $first,
            'tenants'          => $tenants,
        ];
    }

    /**
     * Verify one tenant's v2 chain (up to $toId when given).
     *
     * @return array{ok:bool, checked:int, verified_up_to:?int, first_divergence:?array{id:int, reason:string, expected:string, actual:string}}
     */
    public function verifyTenant(int $tenantId, ?int $toId = null): array
    {
        $db = Services::database();

        $prevHash = AuditHashV2::GENESIS;
        $prevId   = null;
        $checked  = 0;
        $lastOkId = null;
        $afterId  = 0;

        while (true) {
            $builder = $db->table(SYNAPSE_AUDIT_EVENTS)
                ->select('id, prev_id, tenant_id, action_code, entity_type, entity_id, actor_user_id, request_id, occurred_at, payload_json, commit_hash')
                ->where('hash_version', 2)
                ->where('tenant_id', $tenantId)
                ->where('id >', $afterId)
                ->orderBy('id', 'ASC')
                ->limit(self::BATCH_SIZE);

            if ($toId !== null) {
                $builder->where('id <=', $toId);
            }

            $rows = $builder->get()->getResultArray();
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $id      = (int) $row['id'];
                $rowPrev = $row['prev_id'] !== null ? (int) $row['prev_id'] : null;

                if ($rowPrev !== $prevId) {
                    return $this->divergence($checked, $lastOkId, $id, 'prev_id_mismatch', (string) $prevId, (string) $rowPrev);
                }

                // The table carries no prev_hash column — the linkage value
                // is the previous row's commit_hash, tracked by this walk.
                try {
                    $expected = AuditHashV2::compute($row + ['prev_hash' => $prevHash]);
                } catch (\InvalidArgumentException $e) {
                    return $this->divergence($checked, $lastOkId, $id, 'malformed_v2_event', $e->getMessage(), '');
                }

                if (! hash_equals($expected, (string) $row['commit_hash'])) {
                    return $this->divergence($checked, $lastOkId, $id, 'hash_mismatch', $expected, (string) $row['commit_hash']);
                }

                $prevHash = (string) $row['commit_hash'];
                $prevId   = $id;
                $lastOkId = $id;
                $checked++;
            }

            $afterId = (int) end($rows)['id'];
        }

        return [
            'ok'               => true,
            'checked'          => $checked,
            'verified_up_to'   => $lastOkId,
            'first_divergence' => null,
        ];
    }

    /**
     * Verify through a specific event id (HTTP endpoint semantics): resolve
     * the row's family and verify that family up to the id.
     *
     * @return array{ok:bool, checked:int, verified_up_to:?int, first_divergence:?array{id:int, reason:string, expected:string, actual:string}}
     */
    public function verifyTo(int $id): array
    {
        $db = Services::database();
        $row = $db->table(SYNAPSE_AUDIT_EVENTS)
            ->select('id, tenant_id, hash_version')
            ->where('id', $id)
            ->get()->getRowArray();

        if ($row === null) {
            return ['ok' => false, 'checked' => 0, 'verified_up_to' => null, 'first_divergence' => null];
        }

        if ((int) $row['hash_version'] === 2) {
            return $this->verifyTenant((int) $row['tenant_id'], $id);
        }

        return $this->walkV1($id);
    }

    /**
     * The frozen v1 walk: one global chain over hash_version = 1 rows.
     *
     * @return array{ok:bool, checked:int, verified_up_to:?int, first_divergence:?array{id:int, reason:string, expected:string, actual:string}}
     */
    private function walkV1(?int $toId): array
    {
        $db = Services::database();

        $prevHash = str_repeat('0', 64);
        $prevId   = null;
        $checked  = 0;
        $lastOkId = null;
        $afterId  = 0;

        while (true) {
            $builder = $db->table(SYNAPSE_AUDIT_EVENTS)
                ->select('id, prev_id, payload_json, commit_hash')
                ->where('hash_version', 1)
                ->where('id >', $afterId)
                ->orderBy('id', 'ASC')
                ->limit(self::BATCH_SIZE);

            if ($toId !== null) {
                $builder->where('id <=', $toId);
            }

            $rows = $builder->get()->getResultArray();
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $id       = (int) $row['id'];
                $rowPrev  = $row['prev_id'] !== null ? (int) $row['prev_id'] : null;
                $expected = hash('sha256', $prevHash . (string) $row['payload_json']);

                if ($rowPrev !== $prevId) {
                    return $this->divergence($checked, $lastOkId, $id, 'prev_id_mismatch', (string) $prevId, (string) $rowPrev);
                }
                if (! hash_equals($expected, (string) $row['commit_hash'])) {
                    return $this->divergence($checked, $lastOkId, $id, 'hash_mismatch', $expected, (string) $row['commit_hash']);
                }

                $prevHash = (string) $row['commit_hash'];
                $prevId   = $id;
                $lastOkId = $id;
                $checked++;
            }

            $afterId = (int) end($rows)['id'];
        }

        return [
            'ok'               => true,
            'checked'          => $checked,
            'verified_up_to'   => $lastOkId,
            'first_divergence' => null,
        ];
    }

    /**
     * @return array{ok:bool, checked:int, verified_up_to:?int, first_divergence:array{id:int, reason:string, expected:string, actual:string}}
     */
    private function divergence(int $checked, ?int $lastOkId, int $id, string $reason, string $expected, string $actual): array
    {
        return [
            'ok'               => false,
            'checked'          => $checked,
            'verified_up_to'   => $lastOkId,
            'first_divergence' => [
                'id'       => $id,
                'reason'   => $reason,
                'expected' => $expected,
                'actual'   => $actual,
            ],
        ];
    }
}
