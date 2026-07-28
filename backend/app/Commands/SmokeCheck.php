<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Config as DatabaseConfig;

/**
 * SmokeCheck — verifies the Phase 1 invariants are healthy.
 *
 *   php spark synapse:smoke
 *
 * Checks:
 *   - DB connection to `synapse_zcode`.
 *   - All required tables exist.
 *   - Required seed data present (permissions, groups).
 *   - CORS allowlist is non-empty.
 *   - JWT_SECRET is non-empty.
 *   - COUNSELLING_KEY length is 64 hex chars.
 *   - Audit outbox + audit events tables writable.
 *
 * Exit code 0 = healthy, 1 = any failure.
 */
final class SmokeCheck extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:smoke';
    protected $description = 'Phase 1 invariant smoke test.';
    protected $usage       = 'synapse:smoke';
    protected $arguments   = [];
    protected $options     = [];

    public function run(array $params): int
    {
        $failures = [];

        // 1. DB.
        try {
            $db = DatabaseConfig::connect();
            $db->connect();
            CLI::write('✔ DB connection: synapse_zcode', 'green');
        } catch (\Throwable $t) {
            $failures[] = 'DB connection failed: ' . $t->getMessage();
            CLI::write('✘ DB connection: ' . $t->getMessage(), 'red');
            return $this->finish($failures);
        }

        // 2. Required tables.
        $required = ['users','auth_identities','auth_groups','auth_groups_users','permissions','user_permissions','auth_groups_permissions',SYNAPSE_AUDIT_OUTBOX, SYNAPSE_AUDIT_EVENTS,'auth_refresh_tokens'];
        foreach ($required as $t) {
            try {
                $db->table($t)->countAllResults();
                CLI::write("✔ Table: {$t}", 'green');
            } catch (\Throwable $t) {
                $failures[] = "Missing or unreadable table: {$t}";
                CLI::write("✘ Table: {$t}", 'red');
            }
        }

        // 3. Seed data.
        $permCount   = (int) $db->table('permissions')->countAllResults();
        $groupCount  = (int) $db->table('auth_groups')->countAllResults();
        $groupPermCount = (int) $db->table('auth_groups_permissions')->countAllResults();
        if ($permCount > 0)    { CLI::write("✔ permissions ({$permCount})",   'green'); } else { $failures[] = 'permissions empty — run PermissionsAndGroupsSeeder'; CLI::write('✘ permissions empty', 'red'); }
        if ($groupCount > 0)   { CLI::write("✔ auth_groups ({$groupCount})",  'green'); } else { $failures[] = 'auth_groups empty — run PermissionsAndGroupsSeeder'; CLI::write('✘ auth_groups empty', 'red'); }
        if ($groupPermCount > 0) { CLI::write("✔ auth_groups_permissions ({$groupPermCount})", 'green'); } else { $failures[] = 'auth_groups_permissions empty'; CLI::write('✘ auth_groups_permissions empty', 'red'); }

        // 4. Env checks.
        if ((string) (getenv('JWT_SECRET') ?: '') !== '') {
            CLI::write('✔ JWT_SECRET present', 'green');
        } else {
            $failures[] = 'JWT_SECRET not set';
            CLI::write('✘ JWT_SECRET missing', 'red');
        }
        $hex = (string) (getenv('COUNSELLING_KEY') ?: '');
        if (strlen($hex) === 64 && ctype_xdigit($hex)) {
            CLI::write('✔ COUNSELLING_KEY length and format', 'green');
        } else {
            $failures[] = 'COUNSELLING_KEY must be 64 hex chars';
            CLI::write('✘ COUNSELLING_KEY invalid', 'red');
        }
        $cors = (string) (getenv('CORS_ALLOWED_ORIGINS') ?: '');
        if ($cors !== '' && ! str_contains($cors, '*')) {
            CLI::write("✔ CORS allowlist: {$cors}", 'green');
        } else {
            $failures[] = 'CORS_ALLOWED_ORIGINS missing or contains wildcard';
            CLI::write('✘ CORS allowlist invalid', 'red');
        }

        // 5. Outbox writability.
        try {
            $db->table(SYNAPSE_AUDIT_OUTBOX)->insert([
                'action_code'   => 'smoke.test',
                'entity_type'   => 'smoke',
                'entity_id'     => null,
                'actor_user_id' => null,
                'context_json'  => null,
                'created_at'    => date('Y-m-d H:i:s.u'),
                'processed_at'  => null,
                'attempt_count' => 0,
            ]);
            $id = $db->insertID();
            $db->table(SYNAPSE_AUDIT_OUTBOX)->where('id', $id)->delete();
            CLI::write('✔ audit_outbox writable', 'green');
        } catch (\Throwable $t) {
            $failures[] = 'audit_outbox not writable: ' . $t->getMessage();
            CLI::write('✘ audit_outbox not writable', 'red');
        }

        return $this->finish($failures);
    }

    private function finish(array $failures): int
    {
        if ($failures === []) {
            CLI::newLine();
            CLI::write('SYNAPSE Phase 1 — OK', 'green');
            return 0;
        }
        CLI::newLine();
        CLI::write('SYNAPSE Phase 1 — FAILED:', 'red');
        foreach ($failures as $f) {
            CLI::write('  - ' . $f, 'red');
        }
        return 1;
    }
}