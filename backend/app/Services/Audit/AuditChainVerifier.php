<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Config\Services;

/**
 * AuditChainVerifier — recomputes the `audit_events` hash chain and
 * reports the first divergence (Phase 6).
 *
 * Chain rule (must mirror `AuditOutboxDrain`):
 *   commit_hash = SHA-256( prev_commit_hash || payload_json )
 *   The genesis row hashes against 64 zero chars.
 *   prev_id must point at the immediately preceding row id.
 *
 * Verification walks id-ascending in bounded batches so memory stays
 * O(batch). Rows are never mutated — the log is append-only.
 */
final class AuditChainVerifier
{
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';
    private const BATCH_SIZE   = 1000;

    /**
     * Verify the chain from the first event up to `$toId` (inclusive),
     * or the whole table when `$toId` is null.
     *
     * @return array{
     *     ok: bool,
     *     checked: int,
     *     verified_up_to: ?int,
     *     first_divergence: ?array{id:int, reason:string, expected:string, actual:string},
     * }
     */
    public function verify(?int $toId = null): array
    {
        $db = Services::database();

        $prevHash = self::GENESIS_HASH;
        $prevId   = null;
        $checked  = 0;
        $lastOkId = null;
        $afterId  = 0;

        while (true) {
            $builder = $db->table(SYNAPSE_AUDIT_EVENTS)
                ->select('id, prev_id, payload_json, commit_hash')
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
