<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * AuditClear — wipe the audit trail and reseed a genesis marker.
 *
 *   php spark synapse:audit-clear [--yes]
 *
 * DESTRUCTIVE: the append-only, hash-chained log is truncated (both
 * `audit_outbox` and `audit_events`), then a single genesis event is
 * planted so the new chain records the wipe itself and starts from a
 * clean, verifiable state. Prompts for confirmation unless `--yes`.
 */
final class AuditClear extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:audit-clear';
    protected $description = 'Wipe audit_outbox and audit_events, then plant a genesis marker. DESTRUCTIVE.';
    protected $usage       = 'synapse:audit-clear [--yes]';
    protected $arguments   = [];
    protected $options     = [
        '--yes' => 'Skip the confirmation prompt',
    ];

    public function run(array $params): int
    {
        // CI4 parses a bare `--yes` flag with a NULL value (see
        // CLI::getOptions), so isset() is wrong here — use array_key_exists.
        if (! array_key_exists('yes', $params)) {
            CLI::error('Aborting: pass --yes to permanently wipe the audit log.');
            CLI::error('Example: php spark synapse:audit-clear --yes');
            return 1;
        }

        $db = Database::connect();

        $events = (int) $db->table(SYNAPSE_AUDIT_EVENTS)->countAllResults();
        $outbox = (int) $db->table(SYNAPSE_AUDIT_OUTBOX)->countAllResults();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table(SYNAPSE_AUDIT_OUTBOX)->truncate();
        $db->table(SYNAPSE_AUDIT_EVENTS)->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        // Genesis marker so the new chain records the wipe itself and the
        // verifier has a clean, self-documenting starting point.
        $payload = json_encode([
            'action_code'   => 'audit.log_cleared',
            'entity_type'   => 'audit_events',
            'entity_id'     => null,
            'actor_user_id' => null,
            'context'       => ['reason' => 'manual clear via synapse:audit-clear'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $db->table(SYNAPSE_AUDIT_EVENTS)->insert([
            'prev_id'       => null,
            'action_code'   => 'audit.log_cleared',
            'entity_type'   => 'audit_events',
            'entity_id'     => null,
            'actor_user_id' => null,
            'payload_json'  => $payload,
            'request_id'    => null,
            'occurred_at'   => $now,
            'commited_at'   => $now,
            'commit_hash'   => hash('sha256', str_repeat('0', 64) . $payload),
        ]);

        CLI::write(
            "Cleared {$outbox} outbox row(s) and {$events} event(s); planted genesis marker.",
            'green',
        );
        return 0;
    }
}
