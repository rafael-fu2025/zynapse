<?php

declare(strict_types=1);

namespace App\Auth;

use CodeIgniter\Database\BaseConnection;
use Config\Services;
use RuntimeException;

/** Opaque rotation with a mandatory, immutable family deadline. */
final class RefreshTokenService
{
    private readonly JwtService $jwt;
    private BaseConnection $db;
    private readonly RefreshSessionPolicy $policy;

    public function __construct(?JwtService $jwt = null, ?BaseConnection $db = null)
    {
        $this->jwt = $jwt ?? Services::jwt();
        $this->db = $db ?? Services::database();
        $this->policy = new RefreshSessionPolicy();
    }

    /** Mint a NEW family. Only rotate() may mint descendants. */
    public function issue(int $userId): array
    {
        $this->begin();
        try {
            $user = $this->lockUser($userId);
            $mint = $this->mint($userId, $this->uuidv4(), time() + $this->policy->maxAgeSeconds(), (int) $user['token_epoch']);
            $this->complete();
            return $mint;
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    private function mint(int $userId, string $familyId, int $deadline, int $epoch): array
    {
        $now = time();
        $expiresAt = gmdate('Y-m-d H:i:s', $this->policy->refreshExpiry($now, $deadline));
        $sessionExpiresAt = gmdate('Y-m-d H:i:s', $deadline);
        $env = $this->jwt->issueRefreshToken();
        if (! $this->db->table('auth_refresh_tokens')->insert([
            'user_id' => $userId,
            'family_id' => $familyId,
            'token_hash' => $env['hash'],
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => $expiresAt,
            'session_expires_at' => $sessionExpiresAt,
            'revoked_at' => null,
        ])) {
            throw new RuntimeException('Refresh token insert failed.');
        }
        return [
            ...$env,
            'family_id' => $familyId,
            'expires_at' => $expiresAt,
            'session_expires_at' => $sessionExpiresAt,
            'token_epoch' => $epoch,
        ];
    }

    /** No credential is returned unless the transaction succeeded. */
    public function rotate(string $plain): array
    {
        $hash = $this->jwt->hashRefresh($plain);
        $this->begin();
        try {
            // Locate first, then always lock user BEFORE token rows. Logout uses
            // the same lock, so it cannot miss a concurrently inserted descendant.
            $locator = $this->db->table('auth_refresh_tokens')->select('user_id')->where('token_hash', $hash)->get()->getRowArray();
            if ($locator === null) {
                $this->db->transRollback();
                return ['status' => 'invalid', 'reason' => 'not_found'];
            }
            $user = $this->lockUser((int) $locator['user_id']);
            $row = $this->db->query(
                'SELECT * FROM `auth_refresh_tokens` WHERE `token_hash` = ? LIMIT 1 FOR UPDATE',
                [$hash],
            )->getRowArray();
            if ($row === null) {
                $this->db->transRollback();
                return ['status' => 'invalid', 'reason' => 'not_found'];
            }
            $now = time();
            $identity = ['family_id' => (string) $row['family_id'], 'user_id' => (int) $row['user_id']];
            // Missing legacy deadlines fail closed, including the grace path.
            if ($row['session_expires_at'] === null
                || $this->policy->timestamp($row['session_expires_at']) <= $now
                || $this->policy->timestamp($row['expires_at']) <= $now) {
                $this->db->transRollback();
                return ['status' => 'invalid', 'reason' => 'expired', ...$identity];
            }
            $deadline = $this->policy->timestamp($row['session_expires_at']);
            if ($row['revoked_at'] !== null) {
                $age = $now - $this->policy->timestamp($row['revoked_at']);
                if ($row['replaced_by_hash'] !== null && $age >= 0 && $age < $this->policy->graceSeconds()) {
                    $replacement = $this->db->query(
                        'SELECT * FROM `auth_refresh_tokens` WHERE `token_hash` = ? AND `revoked_at` IS NULL LIMIT 1 FOR UPDATE',
                        [$row['replaced_by_hash']],
                    )->getRowArray();
                    if ($replacement !== null
                        && (int) $replacement['user_id'] === (int) $row['user_id']
                        && $replacement['family_id'] === $row['family_id']
                        && $replacement['session_expires_at'] === $row['session_expires_at']
                        && $this->policy->timestamp($replacement['expires_at']) > $now) {
                        $mint = [
                            'plain' => '', // The racing response must not overwrite the new cookie.
                            'hash' => (string) $replacement['token_hash'],
                            'family_id' => (string) $replacement['family_id'],
                            'expires_at' => (string) $replacement['expires_at'],
                            'session_expires_at' => (string) $replacement['session_expires_at'],
                            'token_epoch' => (int) $user['token_epoch'],
                        ];
                        $this->complete();
                        return ['status' => 'rotated', 'mint' => $mint, 'grace' => true, ...$identity];
                    }
                }
                $this->db->table('auth_refresh_tokens')->where('family_id', $row['family_id'])
                    ->where('user_id', (int) $row['user_id'])->where('revoked_at', null)
                    ->update(['revoked_at' => gmdate('Y-m-d H:i:s', $now)]);
                $this->db->table('users')->where('id', (int) $row['user_id'])
                    ->set('token_epoch', 'token_epoch + 1', false)->update();
                $this->complete();
                return ['status' => 'replayed', ...$identity];
            }
            $mint = $this->mint((int) $row['user_id'], (string) $row['family_id'], $deadline, (int) $user['token_epoch']);
            $this->db->table('auth_refresh_tokens')->where('id', $row['id'])->update([
                'revoked_at' => gmdate('Y-m-d H:i:s', $now),
                'replaced_by_hash' => $mint['hash'],
            ]);
            $this->complete();
            return ['status' => 'rotated', 'mint' => $mint, ...$identity];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    public function revokeAllFor(int $userId, bool $bumpEpoch = false): void
    {
        $this->begin();
        try {
            $this->lockUser($userId);
            $this->db->table('auth_refresh_tokens')->where('user_id', $userId)->where('revoked_at', null)
                ->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
            if ($bumpEpoch) {
                $this->db->table('users')->where('id', $userId)->set('token_epoch', 'token_epoch + 1', false)->update();
            }
            $this->complete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    private function lockUser(int $userId): array
    {
        $user = $this->db->query('SELECT `token_epoch` FROM `users` WHERE `id` = ? FOR UPDATE', [$userId])->getRowArray();
        if ($user === null) {
            throw new RuntimeException('Refresh session user no longer exists.');
        }
        return $user;
    }

    private function begin(): void
    {
        if (! $this->db->transBegin()) {
            throw new RuntimeException('Refresh transaction could not start.');
        }
    }

    private function complete(): void
    {
        if (! $this->db->transStatus() || ! $this->db->transCommit()) {
            throw new RuntimeException('Refresh transaction failed.');
        }
    }

    private function uuidv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
