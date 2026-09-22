<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * PurgeDemo — removes the legacy demo dataset from a dev database.
 *
 * The demo seeders (PatientRegistrySeeder, SeedDemoUsersSeeder, and the
 * activity seeders that depended on them) were removed 2026-09-16. Dev
 * databases seeded before that carry ~48 demo accounts (every account
 * with an `email_password` identity ending @foundationu.edu.ph) plus the
 * clinical/counselling/referral/facilities activity anchored to them.
 *
 *   php spark synapse:purge-demo             # dry run: report only
 *   php spark synapse:purge-demo --execute   # perform the purge
 *
 * Preserved untouched: MIS-provisioned users (they have NO identity row),
 * the @synapse.dev dev machine accounts, inventory/equipment catalogs,
 * waste categories/drums, and the append-only audit_events
 * trail. Never runs in production.
 */
final class PurgeDemo extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:purge-demo';
    protected $description = 'Dry-run/delete the legacy demo accounts (@foundationu.edu.ph identities) and their activity rows. Preserves MIS users and dev machine accounts.';
    protected $usage       = 'synapse:purge-demo [options]';
    protected $options     = [
        '--execute' => 'Actually delete (default is a dry run that only reports counts).',
    ];

    public function run(array $params): int
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            CLI::error('Refusing to run in production — demo data was never seeded there.');
            return 1;
        }

        $execute = array_key_exists('execute', $params);
        $db = Services::database();

        // The demo population: every user with a local email_password
        // identity on the demo domain. MIS-provisioned users carry no
        // identity row; DevUserSeeder accounts are @synapse.dev.
        $demoRows = $db->table('users u')
            ->select('u.id, i.secret AS email')
            ->join('auth_identities i', "i.user_id = u.id AND i.type = 'email_password'")
            ->like('i.secret', '@foundationu.edu.ph', 'before')
            ->orderBy('u.id', 'ASC')
            ->get()->getResultArray();

        if ($demoRows === []) {
            CLI::write('No demo accounts found (@foundationu.edu.ph identities) — nothing to do.');
            return 0;
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $demoRows);
        $idList = implode(',', $ids);
        $demoEncounters = 'SELECT id FROM clinic_encounters WHERE patient_user_id IN (' . $idList . ')';
        $demoReferrals  = 'SELECT id FROM referral_referrals WHERE patient_user_id IN (' . $idList . ')'
            . ' OR provider_user_id IN (' . $idList . ') OR issuer_user_id IN (' . $idList . ')';
        $demoSessions   = 'SELECT id FROM counselling_sessions WHERE patient_user_id IN (' . $idList . ')'
            . ' OR counsellor_user_id IN (' . $idList . ')';
        $demoBatches    = 'SELECT id FROM facilities_bmg_batches WHERE started_by_user_id IN (' . $idList . ')'
            . ' OR finished_by_user_id IN (' . $idList . ') OR released_by_user_id IN (' . $idList . ')';

        $in = static fn (string ...$cols): string => implode(' OR ', array_map(
            static fn (string $c): string => $c . ' IN (' . $idList . ')',
            $cols,
        ));

        // Deletion plan. Steps are ordered so subqueries still see the
        // parent rows they reference; FOREIGN_KEY_CHECKS is disabled for
        // the pass (same posture as the removed demo seeders' wipes).
        // Each step: [label, table, where].
        $steps = [
            ['in-app notifications', 'notifications', $in('recipient_user_id')],
            ['notification outbox', 'notification_outbox', $in('recipient_user_id')],
            ['clinic queue entries', 'clinic_queue_entries',
                $in('called_by_user_id') . " OR encounter_id IN ($demoEncounters) OR referral_id IN ($demoReferrals)"],
            ['clinic check-ins', 'clinic_checkins',
                $in('patient_user_id', 'recorded_by_user_id') . " OR encounter_id IN ($demoEncounters)"],
            ['clinic vitals', 'clinic_vitals', $in('recorded_by_user_id') . " OR encounter_id IN ($demoEncounters)"],
            ['clinic triage', 'clinic_triage_predictions', $in('decided_by_user_id') . " OR encounter_id IN ($demoEncounters)"],
            ['clinic treatments', 'clinic_treatments', $in('administered_by_user_id') . " OR encounter_id IN ($demoEncounters)"],
            ['clinic encounters', 'clinic_encounters', $in('patient_user_id', 'attending_user_id')],
            ['clinic appointments', 'clinic_appointments', $in('patient_user_id', 'provider_user_id')],
            ['counselling notes', 'counselling_notes', $in('created_by_user_id') . " OR session_id IN ($demoSessions)"],
            ['counselling appointments', 'counselling_appointments', $in('patient_user_id', 'counsellor_user_id', 'created_by_user_id')],
            ['counselling availability', 'counselling_availability', $in('counsellor_user_id')],
            ['counselling queue entries', 'counselling_queue_entries', $in('called_by_user_id', 'assigned_counsellor_user_id', 'patient_user_id')],
            ['counselling analytics', 'counselling_scheduling_analytics', $in('counsellor_user_id')],
            ['counselling sessions', 'counselling_sessions', $in('patient_user_id', 'counsellor_user_id')],
            ['guidance follow-ups', 'guidance_followups', $in('student_user_id', 'assigned_counsellor_user_id')],
            ['referrals', 'referral_referrals', $in('patient_user_id', 'provider_user_id', 'issuer_user_id')],
            ['survey responses', 'survey_responses', $in('student_user_id')],
            ['patient allergies', 'patient_allergies', $in('user_id', 'noted_by_user_id')],
            ['patient contacts', 'patient_contacts', $in('user_id')],
            ['equipment status log', 'clinic_equipment_status_log', $in('changed_by_user_id')],
            ['inventory movements', 'clinic_inventory_movements', $in('moved_by_user_id')],
            ['medicine transactions', 'clinic_medicine_transactions', $in('performed_by_user_id')],
            ['reorder requests', 'clinic_reorder_requests', $in('requested_by_user_id', 'approved_by_user_id')],
            ['staff schedules', 'clinic_staff_schedules', $in('user_id')],
            ['BMG batch updates', 'facilities_bmg_batch_updates', $in('recorded_by_user_id') . " OR batch_id IN ($demoBatches)"],
            ['BMG inputs', 'facilities_bmg_inputs', $in('recorded_by_user_id') . " OR batch_id IN ($demoBatches)"],
            ['BMG losses', 'facilities_bmg_losses', $in('recorded_by_user_id') . " OR batch_id IN ($demoBatches)"],
            ['BMG outputs', 'facilities_bmg_outputs', $in('recorded_by_user_id') . " OR batch_id IN ($demoBatches)"],
            ['BMG process logs', 'facilities_bmg_process_logs', $in('recorded_by_user_id') . " OR batch_id IN ($demoBatches)"],
            ['BMG alerts', 'facilities_bmg_alerts', $in('acknowledged_by_user_id')],
            ['BMG batches', 'facilities_bmg_batches', $in('started_by_user_id', 'finished_by_user_id', 'released_by_user_id')],
            ['SOP documents', 'facilities_sop_documents', $in('owner_user_id')],
            ['generated reports', 'generated_reports', $in('generated_by_user_id')],
            ['report summaries', 'report_summaries', $in('generated_by_user_id')],
            ['report configurations', 'report_configurations', $in('created_by_user_id')],
            ['kiosk media assets', 'kiosk_media_assets', $in('uploaded_by_user_id')],
            ['audit outbox (demo actors)', 'audit_outbox', $in('actor_user_id')],
            ['auth logins', 'auth_logins', $in('user_id')],
            ['auth refresh tokens', 'auth_refresh_tokens', $in('user_id')],
            ['auth token logins', 'auth_token_logins', $in('user_id')],
            ['auth identities', 'auth_identities', $in('user_id')],
            ['auth group memberships', 'auth_groups_users', $in('user_id')],
            ['user permissions', 'user_permissions', $in('user_id')],
        ];

        CLI::write(count($demoRows) . ' demo account(s) found:', 'yellow');
        CLI::write('  ' . implode(', ', array_map(
            static fn (array $r): string => (string) $r['email'],
            array_slice($demoRows, 0, 8),
        )) . (count($demoRows) > 8 ? ' … (+' . (count($demoRows) - 8) . ' more)' : ''));
        CLI::newLine();

        if (! $execute) {
            $this->report($db, $steps, $ids);
            CLI::newLine();
            CLI::write('Dry run — nothing was deleted. Re-run with --execute to purge.', 'yellow');
            return 0;
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->transBegin();
        try {
            $this->reassignKioskSettingsActor($db, $idList);
            foreach ($steps as [$label, $table, $where]) {
                $db->query("DELETE FROM {$table} WHERE {$where}");
                $affected = $db->affectedRows();
                if ($affected > 0) {
                    CLI::write("  deleted {$affected} row(s): {$label}");
                }
            }
            $db->query('DELETE FROM users WHERE id IN (' . $idList . ')');
            CLI::write('  deleted ' . count($ids) . ' row(s): users', 'light_yellow');

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        CLI::newLine();
        CLI::write('Demo dataset purged. MIS-provisioned users and dev machine accounts were preserved.', 'green');
        return 0;
    }

    /**
     * Dry-run report: per-table row counts that WOULD be deleted, plus a
     * kept/population summary.
     *
     * @param list<array{string, string, string}> $steps
     * @param list<int> $ids
     */
    private function report(\CodeIgniter\Database\BaseConnection $db, array $steps, array $ids): void
    {
        $rows = [];
        foreach ($steps as [$label, $table, $where]) {
            $count = $db->query("SELECT COUNT(*) AS n FROM {$table} WHERE {$where}")->getRowArray()['n'] ?? 0;
            if ((int) $count > 0) {
                $rows[] = [$label, (string) $count];
            }
        }
        if ($rows !== []) {
            CLI::table($rows, ['Rows that would be deleted', 'Count']);
        } else {
            CLI::write('No activity rows reference the demo accounts.');
        }

        $kept = $db->table('users u')
            ->select('COUNT(*) AS n', false)
            ->whereNotIn('u.id', $ids)
            ->get()->getRowArray()['n'] ?? 0;
        CLI::write("Users kept: {$kept} (MIS-provisioned + @synapse.dev machine accounts).");
    }

    /**
     * kiosk_settings.updated_by_user_id is NOT NULL and FK'd to users —
     * reassign the actor to the superadmin rather than deleting settings.
     */
    private function reassignKioskSettingsActor(\CodeIgniter\Database\BaseConnection $db, string $idList): void
    {
        $superadmin = $db->table('auth_groups_users agu')
            ->select('agu.user_id')
            ->join('auth_groups ag', 'ag.id = agu.group_id')
            ->where('ag.name', 'superadmin')
            ->orderBy('agu.user_id', 'ASC')
            ->limit(1)
            ->get()->getRowArray();
        if ($superadmin === null) {
            return; // No superadmin to hand the settings to; the FK is then moot in dev.
        }
        $db->query(
            'UPDATE kiosk_settings SET updated_by_user_id = ' . (int) $superadmin['user_id']
            . ' WHERE updated_by_user_id IN (' . $idList . ')',
        );
    }
}
