<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * SandboxTenantSeeder — DEV/TEST ONLY synthetic data for the sandbox
 * tenant (the tenant all `syn_test_…` keys are pinned to).
 *
 * Follows the repo-wide demo-seeder gating: refuses to run in
 * production, so a production sandbox tenant starts EMPTY until the
 * university deliberately seeds it. Run AFTER migrations:
 *
 *   php spark db:seed App\Database\Seeds\SandboxTenantSeeder
 *
 * Data is aggregate-flavoured (encounters/checkins/referrals spread
 * over the last 14 days) so the external aggregate endpoints and the
 * sandbox explorer have something to show. No real personal data —
 * school ids are `SBX-…` placeholders and names are absent (guest-
 * style rows).
 */
final class SandboxTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('SandboxTenantSeeder must never run in production.');
        }

        $tenant = $this->db->table('tenants')->where('slug', 'sandbox')->get()->getRowArray();
        if ($tenant === null) {
            fwrite(STDERR, "SandboxTenantSeeder: 'sandbox' tenant missing — run migrations first.\n");
            return;
        }
        $tenantId = (int) $tenant['id'];

        if ((int) $this->db->table('users')->where('tenant_id', $tenantId)->countAllResults() > 0) {
            fwrite(STDOUT, "SandboxTenantSeeder: sandbox tenant already has users — skipping.\n");
            return;
        }

        $now = date('Y-m-d H:i:s');

        // A synthetic attending/recorded-by staff account (machine-flavoured).
        $this->db->table('users')->insert([
            'tenant_id'  => $tenantId,
            'username'   => 'sandbox-staff',
            'status'     => 'active',
            'active'     => 1,
            'first_name' => 'Sandbox',
            'last_name'  => 'Staff',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $staffId = (int) $this->db->insertID();

        $encounters = 0;
        $checkins   = 0;
        $referrals  = 0;
        for ($day = 13; $day >= 0; $day--) {
            $count = random_int(1, 3);
            for ($i = 0; $i < $count; $i++) {
                $when = date('Y-m-d H:i:s', strtotime("-{$day} days -" . random_int(0, 9) . ' hours'));
                $this->db->table('clinic_encounters')->insert([
                    'tenant_id'         => $tenantId,
                    'patient_school_id' => 'SBX-' . strtoupper(bin2hex(random_bytes(4))),
                    'guest_name'        => 'Sandbox Visitor ' . random_int(1, 99),
                    'chief_complaint'   => 'Synthetic sandbox complaint',
                    'status'            => 'closed',
                    'attending_user_id' => $staffId,
                    'started_at'        => $when,
                    'closed_at'         => $when,
                    'outcome'           => 'closed',
                    'created_at'        => $when,
                    'updated_at'        => $when,
                ]);
                $encounterId = (int) $this->db->insertID();
                $encounters++;

                $this->db->table('clinic_checkins')->insert([
                    'tenant_id'            => $tenantId,
                    'patient_school_id'    => 'SBX-' . strtoupper(bin2hex(random_bytes(4))),
                    'method'               => 'manual',
                    'station_id'           => 'sandbox-kiosk',
                    'destination'          => 'clinic',
                    'outcome'              => 'clinic_queued',
                    'encounter_id'         => $encounterId,
                    'recorded_by_user_id'  => $staffId,
                    'scanned_at'           => $when,
                    'created_at'           => $when,
                ]);
                $checkins++;
            }

            if ($day % 4 === 0) {
                $when = date('Y-m-d H:i:s', strtotime("-{$day} days"));
                $this->db->table('referral_referrals')->insert([
                    'tenant_id'         => $tenantId,
                    'patient_school_id' => 'SBX-' . strtoupper(bin2hex(random_bytes(4))),
                    'source_module'     => 'clinic',
                    'target_module'     => 'counselling',
                    'artifact_type'     => 'referral',
                    'issuer_user_id'    => $staffId,
                    'status'            => ['submitted', 'acknowledged', 'closed'][random_int(0, 2)],
                    'created_at'        => $when,
                    'updated_at'        => $when,
                ]);
                $referrals++;
            }
        }

        fwrite(STDOUT, "SandboxTenantSeeder: {$encounters} encounters, {$checkins} checkins, {$referrals} referrals seeded into the sandbox tenant.\n");
    }
}
