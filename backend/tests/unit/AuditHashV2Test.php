<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Audit\AuditHashV2;
use PHPUnit\Framework\TestCase;

/**
 * AuditHashV2 — canonical, per-tenant audit digest (unit suite: pure PHP,
 * no framework, no database).
 *
 * Pins three properties the chain depends on:
 *   1. CANONICAL SHAPE — the digest is taken over a fixed-order JSON
 *      document, verified here against a hand-built literal so a refactor
 *      that reorders fields or changes casting cannot pass silently.
 *   2. TENANT BINDING — moving an event between tenants changes the hash.
 *   3. FAIL-CLOSED INPUTS — malformed ids/hashes throw instead of hashing
 *      something ambiguous.
 */
final class AuditHashV2Test extends TestCase
{
    /** @return array<string, mixed> */
    private static function event(int $tenantId = 1): array
    {
        return [
            'tenant_id'     => $tenantId,
            'prev_hash'     => AuditHashV2::GENESIS,
            'action_code'   => 'clinic.encounter_created',
            'entity_type'   => 'clinic_encounters',
            'entity_id'     => 42,
            'actor_user_id' => 7,
            'request_id'    => 'abc123abc123abc123abc123abc123ab',
            'occurred_at'   => '2026-10-24 08:00:00',
            'payload_json'  => '{"action_code":"clinic.encounter_created","context":{"outcome":"success"}}',
        ];
    }

    public function testDigestIsOverTheCanonicalFixedOrderJson(): void
    {
        // Built BY HAND — field order, stringification and JSON flags are
        // all part of the contract.
        $canonical = '{"format":"synapse.audit.v2","hash_version":2,"tenant_id":1,'
            . '"prev_hash":"' . AuditHashV2::GENESIS . '",'
            . '"action_code":"clinic.encounter_created","entity_type":"clinic_encounters",'
            . '"entity_id":"42","actor_user_id":"7",'
            . '"request_id":"abc123abc123abc123abc123abc123ab",'
            . '"occurred_at":"2026-10-24 08:00:00",'
            . '"payload_json":"{\"action_code\":\"clinic.encounter_created\",\"context\":{\"outcome\":\"success\"}}"}';

        $this->assertSame(
            hash('sha256', $canonical),
            AuditHashV2::compute(self::event()),
            'The v2 digest must be SHA-256 over the canonical fixed-order JSON document.',
        );
    }

    public function testInputFieldOrderDoesNotAffectTheDigest(): void
    {
        $event = self::event();
        $shuffled = array_reverse($event, true);

        $this->assertSame(AuditHashV2::compute($event), AuditHashV2::compute($shuffled));
    }

    public function testTenantIdIsInsideTheDigest(): void
    {
        $this->assertNotSame(
            AuditHashV2::compute(self::event(1)),
            AuditHashV2::compute(self::event(2)),
            'Relabelling an event as another tenant must break its hash.',
        );
    }

    public function testNullEntityAndActorSurviveTheRoundTrip(): void
    {
        $hash = AuditHashV2::compute(array_merge(self::event(), ['entity_id' => null, 'actor_user_id' => null, 'request_id' => null]));

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
    }

    public function testRejectsMalformedPrevHash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AuditHashV2::compute(array_merge(self::event(), ['prev_hash' => 'not-hex']));
    }

    public function testRejectsNonPositiveTenant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AuditHashV2::compute(self::event(0));
    }

    public function testRejectsNonDecimalEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AuditHashV2::compute(array_merge(self::event(), ['entity_id' => '12;7']));
    }
}
