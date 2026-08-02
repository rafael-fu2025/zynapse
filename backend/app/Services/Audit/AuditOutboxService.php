<?php

declare(strict_types=1);

namespace App\Services\Audit;

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
