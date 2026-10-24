<?php

declare(strict_types=1);

namespace App\Services\Audit;

use InvalidArgumentException;
use JsonException;

/**
 * AuditHashV2 — canonical, per-tenant audit hash (2026-10 security audit G-2).
 *
 * v1 hashed a bare `prev_hash . payload_json` concatenation on ONE global
 * chain. v2 hashes a fixed-order JSON document on a PER-TENANT chain, so:
 *   - field boundaries and nulls are unambiguous (no delimiter ambiguity),
 *   - tenant_id is inside the digest — a row moved between tenants or
 *     relabelled breaks verification,
 *   - actor and request provenance are tamper-evident.
 *
 * v1 rows are frozen forever at their formula (see AuditChainVerifier); the
 * drain only ever writes v2 rows after the AuditChainV2 migration.
 *
 * Like humus's AuditHash, this is tamper-EVIDENCE within the database, not
 * an external trust anchor — pair with an off-box record of the chain tail
 * for full guarantees.
 */
final class AuditHashV2
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param array<string, mixed> $event Row-shaped values; every field is
     *        cast explicitly so the digest never depends on DB driver types.
     */
    public static function compute(array $event): string
    {
        $data = [
            'format'         => 'synapse.audit.v2',
            'hash_version'   => 2,
            'tenant_id'      => self::unsignedInt($event['tenant_id'] ?? null),
            'prev_hash'      => self::hash64($event['prev_hash'] ?? null),
            'action_code'    => (string) ($event['action_code'] ?? ''),
            'entity_type'    => (string) ($event['entity_type'] ?? ''),
            'entity_id'      => $event['entity_id'] === null ? null : self::unsignedDecimal($event['entity_id']),
            'actor_user_id'  => $event['actor_user_id'] === null ? null : self::unsignedDecimal($event['actor_user_id']),
            'request_id'     => $event['request_id'] === null ? null : (string) $event['request_id'],
            'occurred_at'    => (string) ($event['occurred_at'] ?? ''),
            'payload_json'   => (string) ($event['payload_json'] ?? ''),
        ];

        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Audit v2 payload is not encodable: ' . $e->getMessage());
        }

        return hash('sha256', $json);
    }

    private static function hash64(mixed $value): string
    {
        $str = (string) $value;
        if (preg_match('/\A[0-9a-f]{64}\z/', $str) !== 1) {
            throw new InvalidArgumentException('Audit v2 prev_hash must be 64 lowercase hex chars.');
        }
        return $str;
    }

    private static function unsignedInt(mixed $value): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException('Audit v2 tenant_id must be an unsigned integer.');
        }
        $int = (int) $value;
        if ($int < 1) {
            throw new InvalidArgumentException('Audit v2 tenant_id must be positive.');
        }
        return $int;
    }

    /**
     * Canonical decimal representation without a lossy float conversion —
     * BIGINT ids stay exact on 32-bit PHP too.
     */
    private static function unsignedDecimal(mixed $value): string
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A[0-9]+\z/', (string) $value)) {
            throw new InvalidArgumentException('Audit v2 id fields must be unsigned decimal integers.');
        }
        $decimal = ltrim((string) $value, '0');

        return $decimal === '' ? '0' : $decimal;
    }
}
