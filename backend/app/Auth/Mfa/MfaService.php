<?php

declare(strict_types=1);

namespace App\Auth\Mfa;

use App\Auth\Totp\TotpService;
use App\Services\Audit\AuditOutboxService;
use App\Services\Crypto\EncryptionService;
use App\Services\Rbac\PrivilegedRoles;
use CodeIgniter\Database\BaseConnection;
use Config\Services;

/**
 * MfaService — TOTP enrollment, status, and verification for the LOCAL
 * identity path (2026-10 audit G-1).
 *
 * Availability vs enforcement:
 *   - AVAILABLE to any local email/password identity.
 *   - REQUIRED for members of a privileged group (PrivilegedRoles::SET_P)
 *     when MFA_ENFORCE_FOR_PRIVILEGED is on (default): their login refuses
 *     to issue tokens until a valid code completes the challenge.
 *   - NEVER applies to the MIS-delegated path — the university owns those
 *     credentials, and the login controller never consults this service
 *     on that branch.
 *
 * Secret at rest: AES-256-GCM under the MFA_KEY env ref; material never
 * touches the database.
 */
final class MfaService
{
    private const KEY_REF = 'MFA_KEY';

    public function __construct(
        private readonly TotpService $totp = new TotpService(),
        private readonly ?BaseConnection $db = null,
        private readonly ?EncryptionService $crypto = null,
        private readonly ?AuditOutboxService $audit = null,
    ) {
    }

    private function db(): BaseConnection
    {
        return $this->db ?? Services::database();
    }

    private function crypto(): EncryptionService
    {
        return $this->crypto ?? new EncryptionService();
    }

    public function enforcementEnabled(): bool
    {
        $raw = getenv('MFA_ENFORCE_FOR_PRIVILEGED');

        return $raw === false || $raw === '' || filter_var($raw, FILTER_VALIDATE_BOOL);
    }

    /** Is MFA active (confirmed by a valid code) for this user? */
    public function isEnabled(int $userId): bool
    {
        $row = $this->db()->table('auth_mfa')
            ->select('enabled_at')
            ->where('user_id', $userId)
            ->get()->getRowArray();

        return $row !== null && $row['enabled_at'] !== null;
    }

    /** Is an enrollment staged (setup generated, code not yet confirmed)? */
    public function isStaged(int $userId): bool
    {
        $row = $this->db()->table('auth_mfa')
            ->select('enabled_at')
            ->where('user_id', $userId)
            ->get()->getRowArray();

        return $row !== null && $row['enabled_at'] === null;
    }

    /**
     * Does this login REQUIRE the second factor? True when the user HAS an
     * enabled enrollment, or is privileged while enforcement is on.
     */
    public function loginRequiresMfa(int $userId): bool
    {
        if ($this->isEnabled($userId)) {
            return true;
        }

        return $this->enforcementEnabled() && $this->isPrivileged($userId);
    }

    public function isPrivileged(int $userId): bool
    {
        $rows = $this->db()->table('auth_groups_users gu')
            ->select('g.name')
            ->join('auth_groups g', 'g.id = gu.group_id')
            ->where('gu.user_id', $userId)
            ->get()->getResultArray();

        foreach ($rows as $row) {
            if (in_array((string) $row['name'], PrivilegedRoles::SET_P, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stage a fresh enrollment. Returns the secret + provisioning URI for
     * ONE display; the stored copy is encrypted, and confirm() is the only
     * way to activate it.
     *
     * @param array<string, mixed>|null $user Row from the users table (needs email/username for the URI label).
     * @return array{secret:string, otpauth_uri:string}
     */
    public function setup(int $userId, ?array $user = null): array
    {
        $secret = $this->totp->generateSecret();
        $env = $this->crypto()->encryptWith(self::KEY_REF, $secret);
        $now = date('Y-m-d H:i:s');

        $existing = $this->db()->table('auth_mfa')->select('user_id')->where('user_id', $userId)->get()->getRowArray();
        if ($existing !== null) {
            // Regenerating a staged secret is safe; regenerating an ENABLED
            // one would silently weaken the account — refuse.
            if ($this->isEnabled($userId)) {
                throw new \DomainException('MFA is already enabled. Disable it before starting a new enrollment.');
            }
            $this->db()->table('auth_mfa')->where('user_id', $userId)->update([
                'secret_cipher' => $env['ciphertext'],
                'secret_nonce'  => $env['nonce'],
                'updated_at'    => $now,
            ]);
        } else {
            $this->db()->table('auth_mfa')->insert([
                'user_id'       => $userId,
                'secret_cipher' => $env['ciphertext'],
                'secret_nonce'  => $env['nonce'],
                'enabled_at'    => null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        $label = (string) ($user['email'] ?? $user['username'] ?? ('user-' . $userId));

        return ['secret' => $secret, 'otpauth_uri' => $this->totp->provisioningUri($secret, $label)];
    }

    /**
     * Confirm a staged enrollment with a valid code. Idempotent-safe:
     * only activates when not already enabled.
     */
    public function confirm(int $userId, string $code): bool
    {
        $row = $this->row($userId);
        if ($row === null || $row['enabled_at'] !== null) {
            return false;
        }

        $secret = $this->secret($row);
        $step = $this->totp->verify($secret, $code, null);
        if ($step === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $this->db()->table('auth_mfa')->where('user_id', $userId)->update([
            'enabled_at'         => $now,
            'last_used_timestep' => $step,
            'updated_at'         => $now,
        ]);

        return true;
    }

    /**
     * Verify a code for LOGIN (or a high-risk action). Records the used
     * timestep so the same code cannot be replayed.
     */
    public function verifyLogin(int $userId, string $code): bool
    {
        $row = $this->row($userId);
        if ($row === null || $row['enabled_at'] === null) {
            return false;
        }

        $secret = $this->totp->verify($this->secret($row), $code, $row['last_used_timestep'] !== null ? (int) $row['last_used_timestep'] : null);
        if ($secret === null) {
            return false;
        }

        $this->db()->table('auth_mfa')
            ->where('user_id', $userId)
            ->update(['last_used_timestep' => $secret, 'updated_at' => date('Y-m-d H:i:s')]);

        return true;
    }

    /** Disable (self-service with password check done by the caller). */
    public function disable(int $userId): bool
    {
        $ok = $this->db()->table('auth_mfa')->where('user_id', $userId)->delete();

        return (bool) $ok;
    }

    /** @return array<string, mixed>|null */
    private function row(int $userId): ?array
    {
        return $this->db()->table('auth_mfa')->where('user_id', $userId)->get()->getRowArray();
    }

    /** @param array<string, mixed> $row */
    private function secret(array $row): string
    {
        return $this->crypto()->decryptWith(self::KEY_REF, (string) $row['secret_cipher'], (string) $row['secret_nonce']);
    }
}
