<?php

declare(strict_types=1);

namespace App\Auth;

use CodeIgniter\Config\BaseConfig;
use Config\Services;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use UnexpectedValueException;

/**
 * JwtService — short-lived access tokens (HS256 in Phase 1).
 *
 * Refresh tokens are opaque, stored hashed, and rotated on every refresh.
 * They live ONLY in an HttpOnly Secure SameSite=Strict cookie.
 *
 * NEVER log `sign()` output or `decode()` payloads in plaintext.
 */
final class JwtService
{
    /**
     * Issue a fresh access token. The `sub` is the internal user id.
     *
     * @param array<string, mixed> $extraClaims Custom claims (e.g., perms[]).
     */
    public function sign(int $userId, array $extraClaims = []): string
    {
        /** @var \Config\Auth $cfg */
        $cfg = config('Config\\Auth');
        $now = time();

        $payload = array_merge([
            'iss' => config('Config\\App')->baseURL,
            'sub' => (string) $userId,
            'iat' => $now,
            'exp' => $now + (int) $cfg->jwtAccessTtl,
            'jti' => bin2hex(random_bytes(16)),
        ], $extraClaims);

        return JWT::encode($payload, $this->secret(), $cfg->jwtAlgorithm);
    }

    /**
     * Verify a token. Throws on invalid/expired/tampered tokens.
     *
     * @return array<string, mixed>
     * @throws UnexpectedValueException
     */
    public function verify(string $token): array
    {
        $cfg = config('Config\\Auth');
        $decoded = JWT::decode($token, new Key($this->secret(), $cfg->jwtAlgorithm));

        return (array) $decoded;
    }

    /**
     * Issue a refresh token (opaque, base64url of 32 random bytes).
     * The plaintext token is returned to the caller; only its HMAC-SHA-256
     * hash is ever persisted.
     */
    public function issueRefreshToken(): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash  = hash_hmac('sha256', $plain, $this->secret());

        return ['plain' => $plain, 'hash' => $hash];
    }

    public function hashRefresh(string $plain): string
    {
        return hash_hmac('sha256', $plain, $this->secret());
    }

    private function secret(): string
    {
        $secret = (string) (getenv('JWT_SECRET') ?: '');
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET is not configured.');
        }
        return $secret;
    }
}