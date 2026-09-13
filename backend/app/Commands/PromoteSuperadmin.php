<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * PromoteSuperadmin — the ONLY sanctioned way to mint the first
 * superadmin (Platform Owner). Never exposed via UI: privileged role
 * grants through the API require an existing `rbac.privileged.manage`
 * holder, so bootstrap must be a shell command.
 *
 *   php spark synapse:promote-superadmin owner@foundationu.edu.ph
 *   php spark synapse:promote-superadmin owner@foundationu.edu.ph --confirm   (production)
 *
 * Idempotent: promoting an existing superadmin is a no-op. The audit
 * trail records every successful promotion (rbac.privileged_role_granted).
 */
final class PromoteSuperadmin extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:promote-superadmin';
    protected $description = 'Promote a user (by email) to the superadmin (Platform Owner) role. Idempotent; the only sanctioned bootstrap path.';
    protected $usage       = 'synapse:promote-superadmin <email> [options]';
    protected $arguments   = [
        'email' => 'Email address (auth_identities secret) of the user to promote.',
    ];
    protected $options = [
        '--confirm' => 'Required to run in production (same posture as boot security checks).',
    ];

    public function run(array $params): int
    {
        $email = strtolower(trim((string) ($params[0] ?? '')));
        if ($email === '') {
            CLI::error('Usage: php spark synapse:promote-superadmin <email>');
            return 1;
        }

        if (ENVIRONMENT === 'production' && ! ($params['confirm'] ?? false)) {
            CLI::error('Refusing to run in production without --confirm.');
            return 1;
        }

        $db = Services::database();
        $identity = $db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->get()
            ->getRowArray();
        if ($identity === null) {
            CLI::error("No user found with email '{$email}'.");
            return 1;
        }
        $userId = (int) $identity['user_id'];

        $group = $db->table('auth_groups')->where('name', 'superadmin')->get()->getRowArray();
        if ($group === null) {
            CLI::error("The 'superadmin' group does not exist. Run migrations + the PermissionsAndGroupsSeeder first.");
            return 1;
        }
        $groupId = (int) $group['id'];

        $member = $db->table('auth_groups_users')
            ->where(['group_id' => $groupId, 'user_id' => $userId])
            ->countAllResults();
        if ($member > 0) {
            CLI::write("User '{$email}' is already a superadmin — nothing to do.", 'yellow');
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $db->table('auth_groups_users')->insert([
            'group_id'   => $groupId,
            'user_id'    => $userId,
            'created_at' => $now,
        ]);

        // CLI bootstrap has no acting user — actor_user_id stays null and
        // the reason_code names the command (audit context whitelist).
        Services::auditOutbox()->enqueue('rbac.privileged_role_granted', 'users', $userId, null, [
            'resource_code' => 'role#superadmin',
            'reason_code'   => 'synapse:promote-superadmin',
            'outcome'       => 'promoted',
        ]);

        CLI::write("User '{$email}' (id {$userId}) is now a superadmin (Platform Owner).", 'green');
        CLI::write('Remember: the audit outbox is drained by synapse:audit-drain / the post-system hook.', 'dark_gray');
        return 0;
    }
}
