<?php

declare(strict_types=1);

namespace App\Commands;

use App\Auth\Mfa\MfaService;
use App\Services\CurrentTenant;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;

/**
 * MfaReset — clear a user's TOTP enrollment from the shell.
 *
 *   php spark synapse:mfa-reset <email> [--confirm]  (production)
 *
 * Recovery path for a privileged user who lost their authenticator: the
 * API path (POST /auth/mfa/reset/:id) needs an rbac.privileged.manage
 * holder, and total lockout has none signed in — this is the break-glass
 * equivalent, mirroring synapse:promote-superadmin's posture: tenant-
 * scoped, refuses deleted/archived/inactive accounts, --confirm required
 * in production, audited in the same transaction.
 */
final class MfaReset extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:mfa-reset';
    protected $description = 'Clear a local user\'s TOTP MFA enrollment (break-glass recovery).';
    protected $usage       = 'synapse:mfa-reset <email> [--confirm]';
    protected $arguments   = [
        'email' => 'Email of the local email_password identity whose MFA enrollment should be cleared.',
    ];
    protected $options = [
        '--confirm' => 'Required to run in production (same posture as promote-superadmin).',
    ];

    protected ?string $environmentOverride = null;

    public function run(array $params): int
    {
        $email = strtolower(trim((string) ($params[0] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            CLI::error('Usage: php spark synapse:mfa-reset <email>');
            return 1;
        }

        if ($this->isProduction() && ! array_key_exists('confirm', $params)) {
            CLI::error('Refusing to run in production without --confirm.');
            return 1;
        }

        $db = Services::database();
        $tenantId = CurrentTenant::id();

        $identity = $db->table('auth_identities')
            ->select('user_id')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->get()->getRowArray();
        if ($identity === null) {
            CLI::error("No local identity for {$email}.");
            return 1;
        }
        $userId = (int) $identity['user_id'];

        $user = $db->table('users')
            ->select('id, active, deleted_at, archived_at')
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->get()->getRowArray();
        if ($user === null || $user['deleted_at'] !== null || $user['archived_at'] !== null || ! $user['active']) {
            CLI::error("User for {$email} is not eligible (missing, deleted, archived, or inactive).");
            return 1;
        }

        $db->transStart();
        try {
            $removed = (new MfaService())->disable($userId);
            Services::auditOutbox()->enqueue('auth.mfa_reset', 'auth_mfa', $userId, null, [
                'reason_code' => 'synapse:mfa-reset',
                'outcome'     => $removed ? 'success' : 'noop',
            ]);
            $db->transComplete();
        } catch (\Throwable) {
            $db->transRollback();
            CLI::error('Reset failed and was rolled back.');
            return 1;
        }

        CLI::write($removed
            ? "MFA enrollment cleared for user id {$userId} ({$email})."
            : "User id {$userId} ({$email}) had no MFA enrollment — nothing to do.", 'green');

        return 0;
    }

    private function isProduction(): bool
    {
        $environment = $this->environmentOverride ?? (defined('ENVIRONMENT') ? ENVIRONMENT : 'production');

        return $environment === 'production';
    }
}
