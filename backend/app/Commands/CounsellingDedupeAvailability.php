<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * CounsellingDedupeAvailability — collapse duplicate availability windows
 * (2026-09-24).
 *
 *   php spark synapse:counselling-dedupe-availability             # dry run: report only
 *   php spark synapse:counselling-dedupe-availability --execute   # remove duplicates
 *
 * The desk had no way to edit a window, so the only way to "change" one was
 * to add another — which inserted a second row and left the first active.
 * `counselling_availability` has never carried a unique constraint, so
 * nothing stopped it. The update path and an add-time duplicate guard now
 * prevent new ones; this command clears the ones already on disk.
 *
 * "Duplicate" means **identical**: same tenant, counsellor, weekday and time
 * range. The lowest id survives, because it is the row the desk has been
 * looking at and the one an appointment's time was first matched against.
 *
 * Overlapping-but-different windows are REPORTED, never deleted. Once
 * capacity is gone a bookable place is per counsellor covering the time, so
 * an overlap grants nothing extra — but it is also not the same cover
 * written twice, and deleting it would silently remove real availability.
 *
 * Every statement goes through the query builder, so the only SQL text in
 * this file is static and un-parameterised fragments (an aggregate select
 * list and a join predicate) — no value is ever interpolated.
 *
 * Safe to run repeatedly: it only ever targets rows that duplicate a
 * survivor, and CI4 parses a bare flag with a NULL value, so flags are read
 * with `array_key_exists()` rather than `isset()` (per AuditClear).
 */
final class CounsellingDedupeAvailability extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:counselling-dedupe-availability';
    protected $description = 'Report (and with --execute, remove) duplicate counselling availability windows.';
    protected $usage       = 'synapse:counselling-dedupe-availability [--execute]';
    protected $arguments   = [];
    protected $options     = [
        '--execute' => 'Delete the duplicate rows (default is a dry run that only reports them).',
    ];

    /**
     * Two windows of one counsellor on one weekday whose times genuinely
     * intersect, excluding the identical pairs handled as duplicates.
     * Static predicate — the column names are literals, not values.
     */
    private const OVERLAP_PREDICATE = 'b.tenant_id = a.tenant_id AND b.counsellor_user_id = a.counsellor_user_id AND b.day_of_week = a.day_of_week AND b.is_active = 1 AND b.id > a.id AND a.start_time < b.end_time AND b.start_time < a.end_time AND NOT (a.start_time = b.start_time AND a.end_time = b.end_time)';

    /** Identical-window groups: the surviving id plus every copy of it. */
    private const GROUP_SELECT = 'tenant_id, counsellor_user_id, day_of_week, start_time, end_time, COUNT(*) AS n, MIN(id) AS keep_id, GROUP_CONCAT(id ORDER BY id) AS ids';

    public function run(array $params): int
    {
        $db = Database::connect();

        if (! $db->tableExists('counselling_availability')) {
            CLI::error('Table counselling_availability does not exist.');
            return 1;
        }

        // Active rows only: a soft-removed window is not cover, so it cannot
        // duplicate one. Grouping includes tenant_id — two tenants may hold
        // identically-timed windows for their own counsellors.
        $groups = $db->table('counselling_availability')
            ->select(self::GROUP_SELECT)
            ->where('is_active', 1)
            ->groupBy('tenant_id, counsellor_user_id, day_of_week, start_time, end_time')
            ->having('COUNT(*) >', 1)
            ->orderBy('tenant_id', 'ASC')
            ->orderBy('counsellor_user_id', 'ASC')
            ->orderBy('day_of_week', 'ASC')
            ->orderBy('start_time', 'ASC')
            ->get()->getResultArray();

        $doomed = [];
        foreach ($groups as $g) {
            $ids = array_map('intval', explode(',', (string) $g['ids']));
            $keep = (int) $g['keep_id'];
            foreach ($ids as $id) {
                if ($id !== $keep) {
                    $doomed[$id] = $keep;
                }
            }
        }

        $overlaps = $db->table('counselling_availability a')
            ->select('a.id, a.counsellor_user_id, a.day_of_week, a.start_time, a.end_time, b.id AS other_id')
            ->join('counselling_availability b', self::OVERLAP_PREDICATE, 'inner')
            ->where('a.is_active', 1)
            ->orderBy('a.counsellor_user_id', 'ASC')
            ->orderBy('a.day_of_week', 'ASC')
            ->orderBy('a.start_time', 'ASC')
            ->get()->getResultArray();

        if ($groups !== []) {
            CLI::write(sprintf('%d duplicate group(s) found:', count($groups)), 'yellow');
            CLI::table(array_map(static fn (array $g): array => [
                (string) $g['tenant_id'],
                (string) $g['counsellor_user_id'],
                (string) $g['day_of_week'],
                substr((string) $g['start_time'], 0, 5) . '–' . substr((string) $g['end_time'], 0, 5),
                (string) $g['n'],
                (string) $g['keep_id'],
            ], $groups), ['Tenant', 'Counsellor', 'Day', 'Window', 'Copies', 'Keep id']);
        } else {
            CLI::write('No duplicate windows found.', 'green');
        }

        if ($overlaps !== []) {
            CLI::newLine();
            CLI::write(sprintf(
                '%d overlapping pair(s) left alone (different time ranges — not duplicates):',
                count($overlaps),
            ), 'light_yellow');
            CLI::table(array_map(static fn (array $o): array => [
                (string) $o['counsellor_user_id'],
                (string) $o['day_of_week'],
                substr((string) $o['start_time'], 0, 5) . '–' . substr((string) $o['end_time'], 0, 5),
                '#' . (string) $o['id'],
                '#' . (string) $o['other_id'],
            ], $overlaps), ['Counsellor', 'Day', 'Window', 'Id', 'Overlaps']);
        }

        if ($doomed === []) {
            CLI::newLine();
            CLI::write('Nothing to delete.');
            return 0;
        }

        if (! array_key_exists('execute', $params)) {
            CLI::newLine();
            CLI::write(
                sprintf('%d row(s) would be deleted. Dry run — nothing was deleted.', count($doomed)),
                'yellow',
            );
            CLI::write('Re-run with --execute to remove them.');
            return 0;
        }

        $audit = \Config\Services::auditOutbox();

        $db->transStart();
        foreach ($doomed as $id => $keepId) {
            $db->table('counselling_availability')->where('id', $id)->delete();
            $audit->enqueue(
                'counselling.availability_deduped',
                'counselling_availability',
                $id,
                null,
                ['resource_code' => 'dedupe:kept=' . $keepId],
            );
        }
        $db->transComplete();

        if ($db->transStatus() === false) {
            CLI::error('Dedupe transaction failed — no rows were deleted.');
            return 1;
        }

        CLI::write(sprintf('Deleted %d duplicate window(s).', count($doomed)), 'green');
        return 0;
    }
}
