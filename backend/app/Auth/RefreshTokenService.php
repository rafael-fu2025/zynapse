<?php

declare(strict_types=1);

namespace App\Auth;

use CodeIgniter\Database\BaseConnection;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * RefreshTokenService — opaque-rotating refresh tokens with a
 * family/replay-detection chain.
 *
 * On every refresh:
 *   1. Look up the supplied token by hash.
 *   2. If it has been revoked (or the row is gone): reject.
 *   3. If it has been replaced before (replaced_by_hash is set):
 *      REPLAY DETECTED — revoke the entire family and reject.
 *   4. Otherwise: mark this hash as replaced, mint a new token in the
 *      same family, write the new hash, return the new plaintext.
 *
 * On login: mint a fresh family_id.
 */
final class RefreshTokenService
{
    private readonly JwtService $jwt;
    private BaseConnection $db;

    public function __construct(?JwtService $jwt = null, ?BaseConnection $db = null)
    {
        $this->jwt = $jwt ?? Services::jwt();
        $this->db  = $db ?? Services::database();
    }

    /**
     * @return array{plain:string, hash:string, family_id:string, expires_at:string}
     */
    public function issue(int $userId, ?string $familyId = null): array
    {
        $env = $this->jwt->issueRefreshToken();
        $familyId ??= $this->uuidv4();
        $ttl = (int) (getenv('JWT_REFRESH_TTL_SECONDS') ?: 2592000);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $ttl . ' seconds')
            ->format('Y-m-d H:i:s');

        $this->db->table('auth_refresh_tokens')->insert([
            'user_id'    => $userId,
            'family_id'  => $familyId,
            'token_hash' => $env['hash'],
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
            'revoked_at' => null,
        ]);

        return [
            'plain'      => $env['plain'],
            'hash'       => $env['hash'],
            'family_id'  => $familyId,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Atomically rotate a token. Returns a structured result:
     *
     *   - status 'rotated'  → new pair minted in the same family.
     *   - status 'replayed' → REPLAY DETECTED, family revoked.
     *   - status 'invalid'  → token not found / expired / malformed.
     *
     * On 'rotated', `mint` contains the new pair. On 'replayed' / 'invalid',
     * `user_id` is the user whose token was used (if the row existed); this
     * lets the controller attribute the failure.
     *
     * @return array{
     *     status: 'rotated'|'replayed'|'invalid',
     *     mint?: array{plain:string, hash:string, family_id:string, expires_at:string},
     *     family_id?: string,
     *     user_id?: int|null,
     *     reason?: string,
     * }
     */
    public function rotate(string $plain): array
    {
        $hash = $this->jwt->hashRefresh($plain);

        return $this->db->transStart() === false
            ? ['status' => 'invalid', 'reason' => 'trans_start_failed']
            : $this->rotateInTxn($hash);
    }

    /**
     * @return array{
     *     status: 'rotated'|'replayed'|'invalid',
     *     mint?: array{plain:string, hash:string, family_id:string, expires_at:string},
     *     family_id?: string,
     *     user_id?: int|null,
     *     reason?: string,
     * }
     */
    private function rotateInTxn(string $hash): array
    {
        try {
            $row = $this->db->query(
                'SELECT * FROM `auth_refresh_tokens` WHERE `token_hash` = ? LIMIT 1 FOR UPDATE',
                [$hash],
            )->getRowArray();

            if ($row === null) {
                $this->db->transRollback();
                return ['status' => 'invalid', 'reason' => 'not_found'];
            }

            $now = date('Y-m-d H:i:s');

            if ($row['revoked_at'] !== null) {
                // Replay — kill the entire family.
                $this->db->table('auth_refresh_tokens')
                    ->where('family_id', $row['family_id'])
                    ->where('revoked_at', null)
                    ->update(['revoked_at' => $now]);
                $this->db->transComplete();
                return [
                    'status'    => 'replayed',
                    'family_id' => (string) $row['family_id'],
                    'user_id'   => (int) $row['user_id'],
                ];
            }

            if (strtotime((string) $row['expires_at']) < time()) {
                $this->db->table('auth_refresh_tokens')
                    ->where('id', $row['id'])
                    ->update(['revoked_at' => $now]);
                $this->db->transComplete();
                return [
                    'status'    => 'invalid',
                    'reason'    => 'expired',
                    'family_id' => (string) $row['family_id'],
                    'user_id'   => (int) $row['user_id'],
                ];
            }

            // Mint a new token in the same family.
            $userId = (int) $row['user_id'];
            $familyId = (string) $row['family_id'];
            $mint = $this->issue($userId, $familyId);

            // Mark the old token as replaced.
            $this->db->table('auth_refresh_tokens')
                ->where('id', $row['id'])
                ->update([
                    'revoked_at'       => $now,
                    'replaced_by_hash' => $mint['hash'],
                ]);

            $this->db->transComplete();
            return [
                'status'    => 'rotated',
                'mint'      => $mint,
                'family_id' => $familyId,
                'user_id'   => $userId,
            ];
        } catch (\Throwable $t) {
            $this->db->transRollback();
            throw $t;
        }
    }

    public function revokeAllFor(int $userId): void
    {
        $this->db->table('auth_refresh_tokens')
            ->where('user_id', $userId)
            ->where('revoked_at', null)
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }

    private function uuidv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
