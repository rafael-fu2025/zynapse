<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\CurrentTenant;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/**
 * AuditOutboxService — transactional outbox writer.
 *
 * Call from inside a `transStart()/transComplete()` block. The same
 * transaction guarantees atomicity between the business write and
 * the audit row. A background CLI worker (Phase 2) drains the outbox
 * into the append-only `audit_events` table.
 *
 * NEVER log raw payload; redacted summary only.
 */
final class AuditOutboxService
{
    /**
     * Master allow-list for context keys. Callers may pass any subset
     * via `$contextWhitelist`; keys outside this list are silently
     * dropped. Add new keys here as new event families need them.
     */
    private const CONTEXT_KEYS = [
        'resource_code', 'previous_status', 'next_status', 'reason_code',
        'auth_method', 'outcome', 'family_id',
        // Which BMG alert fired and how loudly. The alert call sites
        // already pass these; they were being dropped, which left alert
        // audit rows without the code they reference.
        'alert_code', 'severity',
        // Network provenance for auth/session events (non-PII, standard
        // in security audit trails). Populated by AuthController.
        'ip_address', 'user_agent',
        // MIS-delegated login provenance (2026-09 integration): which
        // university identity namespace authenticated the user. The
        // identifier itself is never audited — only this discriminator.
        'mis_namespace',
    ];

    /**
     * Enqueue an event for asynchronous commit to `audit_events`.
     *
     * @param array<string, mixed> $contextWhitelist Only the keys listed here
     *        are persisted. NEVER pass raw request payloads or PII.
     */
    public function enqueue(
        string $actionCode,
        string $entityType,
        ?int $entityId,
        ?int $actorUserId,
        array $contextWhitelist = [],
    ): void {
        $context = [];
        foreach (self::CONTEXT_KEYS as $k) {
            if (array_key_exists($k, $contextWhitelist)) {
                $context[$k] = $contextWhitelist[$k];
            }
        }

        Services::database()->table(SYNAPSE_AUDIT_OUTBOX)->insert([
            // Tenant stamp so the drain can chain per tenant (v2). CLI
            // contexts resolve CurrentTenant via env/default, exactly like
            // the domain services do.
            'tenant_id'     => CurrentTenant::id(),
            'action_code'   => $actionCode,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'actor_user_id' => $actorUserId,
            'context_json'  => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'request_id'    => Services::requestId()->current(),
            'created_at'    => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'processed_at'  => null,
        ]);
    }
}
