<?php

declare(strict_types=1);

namespace App\Services\Notify;

use Config\Services;
use DateTimeImmutable;
use DateTimeZone;

/**
 * NotificationOutboxService — same-transaction notification writer.
 *
 * Callers enqueue INSIDE their business transaction (directive:
 * Transactional Outbox Pattern for Audit and Notifications). A CLI
 * worker (`synapse:notify-drain`) delivers rows to the `notifications`
 * in-app landing zone.
 *
 * Context is whitelisted exactly like the audit outbox — resource ids
 * and status codes only. NEVER patient identifiers, emails, or notes.
 */
final class NotificationOutboxService
{
    /**
     * Master allow-list for context keys.
     */
    private const CONTEXT_KEYS = [
        'resource_code', 'next_status', 'scheduled_at',
    ];

    /**
     * @param array<string, mixed> $contextWhitelist
     */
    public function enqueue(
        int $recipientUserId,
        string $templateCode,
        array $contextWhitelist = [],
        string $channel = 'inapp',
    ): void {
        $context = [];
        foreach (self::CONTEXT_KEYS as $k) {
            if (array_key_exists($k, $contextWhitelist)) {
                $context[$k] = $contextWhitelist[$k];
            }
        }

        Services::database()->table('notification_outbox')->insert([
            'channel'           => $channel,
            'recipient_user_id' => $recipientUserId,
            'template_code'     => $templateCode,
            'context_json'      => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at'        => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'processed_at'      => null,
        ]);
    }
}
