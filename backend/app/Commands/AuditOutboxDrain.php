<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Audit\AuditDrainService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * AuditOutboxDrain — moves rows from `audit_outbox` to `audit_events`.
 *
 *   php spark synapse:audit-drain [--batch=500] [--max-batches=10]
 *
 * Guarantees (Phase 6):
 *   - Hash chaining: commit_hash = SHA-256(prev_hash || payload_json),
 *     genesis hashes against 64 zero chars; `prev_id` links each row to
 *     the previous event (verified by `synapse:audit-verify`).
 *   - The chain tail is fetched ONCE per batch and carried forward —
 *     no per-row tail lookup (the old N+1), no broken `prev_id`.
 * Thin CLI wrapper around AuditDrainService (the same logic the
 * automatic in-request drain uses), so the manual and automatic paths
 * never drift.
 */
final class AuditOutboxDrain extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:audit-drain';
    protected $description = 'Drain audit_outbox into hash-chained audit_events.';
    protected $usage       = 'synapse:audit-drain [--batch=500] [--max-batches=10]';
    protected $arguments   = [];
    protected $options     = [
        '--batch'      => 'Rows per batch',
        '--max-batches'=> 'Max batches per run',
    ];

    public function run(array $params): int
    {
        $batch      = max(1, (int) ($params['batch'] ?? 500));
        $maxBatches = max(1, (int) ($params['max-batches'] ?? 10));

        $result = (new AuditDrainService())->drain($batch, $maxBatches);

        $color = $result['failed'] > 0 ? 'yellow' : 'green';
        CLI::write(
            "Drained {$result['drained']} audit rows into " . SYNAPSE_AUDIT_EVENTS
            . " ({$result['failed']} failed batch(es)).",
            $color,
        );
        return $result['failed'] > 0 && $result['drained'] === 0 ? 1 : 0;
    }

}
