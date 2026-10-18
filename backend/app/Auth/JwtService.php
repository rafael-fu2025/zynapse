<?php

declare(strict_types=1);

namespace App\Auth;


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
     * The `epoch` claim mirrors `users.token_epoch` at issuance; the auth
     * filter compares it against the current column value so bumping the
     * epoch (logout / password change / refresh replay) kills every
     * outstanding token immediately. Tokens signed before this column
     * existed carry no claim and read as epoch 0.
     *
     * @param array<string, mixed> $extraClaims Custom claims (e.g., perms[]).
     */
    public function sign(int $userId, int $epoch = 0, array $extraClaims = []): string
    {
        /** @var \Config\Auth $cfg */
        $cfg = config('Config\\Auth');
        $now = time();

        if ($userId < 1 || $epoch < 0 || $cfg->jwtAccessTtl < 1) {
            throw new \InvalidArgumentException('Invalid access-token identity or lifetime.');
        }
        foreach (['iss', 'aud', 'sub', 'iat', 'exp', 'jti', 'epoch'] as $reserved) {
            if (array_key_exists($reserved, $extraClaims)) {
                throw new \InvalidArgumentException('Reserved access-token claims cannot be overridden.');
            }
        }
        $expiresAt = $now + $cfg->jwtAccessTtl;
        if (array_key_exists('session_exp', $extraClaims)) {
            if (! is_int($extraClaims['session_exp']) || $extraClaims['session_exp'] <= $now) {
                throw new \InvalidArgumentException('Authentication session has expired.');
            }
            $expiresAt = min($expiresAt, $extraClaims['session_exp']);
        }
        $payload = array_merge($extraClaims, [
            'iss'   => $this->issuer(),
            'aud'   => $cfg->jwtAudience,
            'sub'   => (string) $userId,
            'iat'   => $now,
            'exp'   => $expiresAt,
            'jti'   => bin2hex(random_bytes(16)),
            'epoch' => $epoch,
        ]);

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
        $payload = (array) JWT::decode($token, new Key($this->secret(), $cfg->jwtAlgorithm));
        $audience = $payload['aud'] ?? null;
        $validAudience = $audience === $cfg->jwtAudience
            || (is_array($audience) && in_array($cfg->jwtAudience, $audience, true));
        if (($payload['iss'] ?? null) !== $this->issuer() || ! $validAudience
            || ! is_int($payload['iat'] ?? null) || ! is_int($payload['exp'] ?? null)
            || $payload['iat'] > time() || $payload['exp'] <= time()
            || $payload['exp'] <= $payload['iat']
            || ! is_string($payload['sub'] ?? null)
            || preg_match('/^[1-9][0-9]*$/D', $payload['sub']) !== 1
            || filter_var($payload['sub'], FILTER_VALIDATE_INT) === false
            || ! is_string($payload['jti'] ?? null) || $payload['jti'] === ''
            || (isset($payload['epoch']) && (! is_int($payload['epoch']) || $payload['epoch'] < 0))
            || (array_key_exists('session_exp', $payload)
                && (! is_int($payload['session_exp']) || $payload['exp'] > $payload['session_exp']))) {
            throw new UnexpectedValueException('Invalid access-token claims.');
        }
        return $payload;
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
        $algorithm = config('Config\\Auth')->jwtAlgorithm;
        $minimum = ['HS256' => 32, 'HS384' => 48, 'HS512' => 64][$algorithm] ?? null;
        if ($minimum === null || strlen($secret) < $minimum) {
            throw new \RuntimeException('JWT_SECRET must meet the configured HMAC algorithm key length.');
        }
        return $secret;
    }

    private function issuer(): string
    {
        $cfg = config('Config\\Auth');
        $issuer = $cfg->jwtIssuer !== '' ? $cfg->jwtIssuer : config('Config\\App')->baseURL;
        if ($issuer === '' || $cfg->jwtAudience === '') {
            throw new \RuntimeException('JWT issuer and audience must be configured.');
        }
        return $issuer;
    }
}