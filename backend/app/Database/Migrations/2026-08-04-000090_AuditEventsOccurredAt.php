<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * AuditEventsOccurredAt — record the true event time, not just commit time.
 *
 * The drain previously stamped `commited_at` with the worker's clock, so
 * every event in a batch looked like it happened at drain time. This adds
 * `occurred_at` (the original `audit_outbox.created_at`) and uses it as the
 * primary reader timestamp. `commited_at` is retained as the immutable
 * chain-commit time.
 */
final class AuditEventsOccurredAt extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn(SYNAPSE_AUDIT_EVENTS, [
            'occurred_at' => [
                'type'    => 'DATETIME(6)',
                'null'    => true,
                'default' => null,
                'after'   => 'request_id',
            ],
        ]);

        // Backfill: pre-existing rows keep their commit time (the original
        // event time is unrecoverable for already-drained rows).
        $this->db->query(
            'UPDATE ' . SYNAPSE_AUDIT_EVENTS . ' SET occurred_at = commited_at WHERE occurred_at IS NULL'
        );

        $this->forge->addKey(['occurred_at'], false, false, 'ix_events_occurred_at');
        $this->forge->processIndexes(SYNAPSE_AUDIT_EVENTS);
    }

    public function down(): void
    {
        $this->forge->dropKey(SYNAPSE_AUDIT_EVENTS, 'ix_events_occurred_at');
        $this->forge->dropColumn(SYNAPSE_AUDIT_EVENTS, 'occurred_at');
    }
}
