<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * CounsellingPurge — permanently delete soft-archived counselling sessions
 * past the retention window (counselling audit 2026-09-03, F15 decision).
 *
 *   php spark synapse:counselling-purge [--yes] [--dry-run]
 *
 * DESTRUCTIVE: rows in `counselling_sessions` with `archived_at <= (now -
 * RETENTION)` are permanently removed. `counselling_notes` cascades via
 * the existing FK (`ON DELETE CASCADE`, migration CounsellingSessions:52).
 *
 * STATUTORY COMPLIANCE (RA 10173 / Data Privacy Act): the retention period
 * is a legal policy decision owned by the university records officer, not
 * an engineering choice — mental-health records carry longer statutory
 * minimums than routine student data. This command REFUSES TO RUN until
 * `COUNSELLING_RETENTION_DAYS` is explicitly configured.
 *
 * Caveat: CI4 parses a bare `--yes` with a NULL value (CLI::getOptions), so
 * `isset()` is false — use `array_key_exists()` per AuditClear.
 */
final class CounsellingPurge extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:counselling-purge';
    protected $description = 'Permanently purge soft-archived counselling records past the retention period. DESTRUCTIVE.';
    protected $usage       = 'synapse:counselling-purge [--yes] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--yes'     => 'Skip the confirmation prompt',
        '--dry-run' => 'Count eligible records without deleting',
    ];

    public function run(array $params): int
    {
        $raw = getenv('COUNSELLING_RETENTION_DAYS');
        if ($raw === false || $raw === '' || ! ctype_digit((string) $raw) || (int) $raw <= 0) {
            CLI::error('Aborting: COUNSELLING_RETENTION_DAYS is unset or invalid.');
            CLI::write('The retention period is a compliance decision owned by the university records officer.');
            CLI::write('Configure COUNSELLING_RETENTION_DAYS=<positive-integer> before running this command.');
            return 1;
        }
        $days = (int) $raw;

        $dryRun = array_key_exists('dry-run', $params);
        if (! $dryRun && ! array_key_exists('yes', $params)) {
            CLI::error('Aborting: pass --yes to permanently delete archived counselling records.');
            CLI::write('Example: php spark synapse:counselling-purge --yes');
            return 1;
        }

        $cutoff = (new DateTimeImmutable("-{$days} days", new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $db = Database::connect();
        $builder = $db->table('counselling_sessions')
            ->where('archived_at IS NOT NULL', null, false)
            ->where('archived_at <=', $cutoff);

        $eligible = $builder->countAllResults(false);
        CLI::write(sprintf(
            'Found %d archived session(s) older than %d day(s) (cutoff: %s UTC).',
            $eligible,
            $days,
            $cutoff,
        ));

        if ($dryRun) {
            CLI::write('[dry-run] No rows deleted.');
            return 0;
        }

        if ($eligible === 0) {
            CLI::write('Nothing to purge.');
            return 0;
        }

        $db->transStart();

        // Audit the purge before the cascade deletes the target rows.
        // Records the cutoff, the statutory days window, and count.
        \Config\Services::auditOutbox()->enqueue(
            'counselling.records_purged',
            'counselling_sessions',
            null,
            null,
            ['resource_code' => sprintf('purge:days=%d:count=%d', $days, $eligible)],
        );

        $builder->delete();
        $db->transComplete();

        if ($db->transStatus() === false) {
            CLI::error('Purge transaction failed.');
            return 1;
        }

        CLI::write("Purged {$eligible} session(s) and their cascading encrypted notes.");
        return 0;
    }
}
