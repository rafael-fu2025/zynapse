<?php

declare(strict_types=1);

namespace App\Services\External;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\CurrentTenant;
use Config\ExternalApps;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * ApiKeyService — generation and verification of external API keys
 * (2026-09, D4).
 *
 * Design (researched against Stripe/GitHub practice):
 *   - Key format  `syn_<env>_<prefix4>_<43+ chars>`; the secret tail is
 *     32 CSPRNG bytes base64url-encoded (256-bit entropy, 43 chars).
 *   - Storage is HASH-ONLY: `key_hash` = SHA-256 of the full secret.
 *     SHA-256 (not bcrypt/Argon2) is correct for high-entropy secrets —
 *     brute-force resistance comes from the entropy, and the hot path
 *     must stay cheap. hash_equals() provides the constant-time compare.
 *   - The prefix segment (`syn_live_ab12`) is UNIQUE + indexed and is
 *     the lookup key; the resolved key row then carries the tenant.
 *     THIS LOOKUP IS DELIBERATELY TENANT-AGNOSTIC: unlike every other
     *     table, the credential identifies the tenant instead of being
 *     identified by it. All tenant-scoped business queries still run
 *     under CurrentTenant::set(key.tenant_id) bound by the filter.
 *   - Env separation: a `test` key is pinned to the sandbox tenant, a
 *     `live` key to the caller's (production) tenant — D5.
 */
final class ApiKeyService extends BaseService
{
    private const KEY_PATTERN = '/^syn_(test|live)_([a-z0-9]{4})_([A-Za-z0-9_-]{43,})$/';

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
    }

    /**
     * Creates a key for an app and returns the row plus the ONE-TIME
     * plaintext secret. The secret is never persisted — only its SHA-256.
     *
     * @param list<string> $scopes
     * @return array{key: array<string, mixed>, secret: string}
     */
    public function createKey(
        int $appId,
        string $env,
        array $scopes,
        ?int $rateLimitPerMin,
        ?int $ttlDays,
        int $actorId,
    ): array {
        $config = config(ExternalApps::class);
        $env = $env === 'live' ? 'live' : 'test';

        $unknown = array_diff($scopes, array_keys($config->scopes));
        if ($scopes === [] || $unknown !== []) {
            throw ApiException::validationFailure([
                [
                    'code'    => 'validation.field',
                    'message' => $scopes === []
                        ? 'Select at least one scope.'
                        : 'Unknown scope(s): ' . implode(', ', $unknown) . '.',
                    'field'   => 'scopes',
                ],
            ]);
        }

        $tenantId = $env === 'test'
            ? $this->sandboxTenantId()
            : CurrentTenant::id();

        // The APP is registered under the caller's (management) tenant;
        // only the KEY's tenant pin follows the env (sandbox vs prod).
        $app = $this->findApp($appId, CurrentTenant::id());
        if ($app === null) {
            throw ApiException::notFound('resource.not_found');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $ttl = $ttlDays ?? $config->defaultKeyTtlDays;
        $expiresAt = $now->modify('+' . max(1, $ttl) . ' days');

        // Prefix collisions are rare (62^4) — retry with a fresh prefix.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $prefix4 = $this->randomToken(4, 'abcdefghijklmnopqrstuvwxyz0123456789');
            $secret = $this->randomToken(43, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_');
            $prefix = "syn_{$env}_{$prefix4}";

            $collision = $this->db->table('api_keys')->where('prefix', $prefix)->countAllResults();
            if ($collision > 0) {
                continue;
            }

            $row = [
                'tenant_id'          => $tenantId,
                'app_id'             => $appId,
                'env'                => $env,
                'prefix'             => $prefix,
                // Hash the FULL presented key (prefix + '_' + tail) so the
                // hash_matches what verify() computes from the presented
                // header value.
                'key_hash'           => hash('sha256', $prefix . '_' . $secret),
                'last4'              => substr($secret, -4),
                'scopes'             => json_encode(array_values($scopes), JSON_THROW_ON_ERROR),
                'rate_limit_per_min' => max(1, $rateLimitPerMin ?? $config->defaultRateLimitPerMin),
                'expires_at'         => $expiresAt->format('Y-m-d H:i:s'),
                'revoked_at'         => null,
                'last_used_at'       => null,
                'created_by'         => $actorId,
                'created_at'         => $now->format('Y-m-d H:i:s'),
                'updated_at'         => $now->format('Y-m-d H:i:s'),
            ];
            $this->db->table('api_keys')->insert($row);
            $row['id'] = (int) $this->db->insertID();

            return ['key' => $row, 'secret' => $prefix . '_' . $secret];
        }

        throw new RuntimeException('Unable to allocate a unique API key prefix.');
    }

    /**
     * Verifies a raw presented key end-to-end: parse → prefix lookup →
     * constant-time hash compare → state checks. Throws typed
     * ApiExceptions (the filter renders them as 401/403 envelopes).
     *
     * @return array{key: array<string, mixed>, app: array<string, mixed>}
     */
    public function verify(string $rawKey): array
    {
        if (preg_match(self::KEY_PATTERN, $rawKey, $m) !== 1) {
            throw $this->invalidKey();
        }
        $prefix = 'syn_' . $m[1] . '_' . $m[2];

        $key = $this->db->table('api_keys')->where('prefix', $prefix)->get()->getRowArray();
        if ($key === null || ! hash_equals((string) $key['key_hash'], hash('sha256', $rawKey))) {
            throw $this->invalidKey();
        }

        $app = $this->db->table('api_apps')->where('id', (int) $key['app_id'])->get()->getRowArray();
        if ($app === null) {
            throw $this->invalidKey();
        }

        if ($key['revoked_at'] !== null) {
            throw new ApiException('auth.api_key_revoked', 401, [[
                'code'    => 'auth.api_key_revoked',
                'message' => 'This API key has been revoked.',
            ]]);
        }

        $expiresAt = $key['expires_at'];
        if ($expiresAt !== null && strtotime((string) $expiresAt) <= time()) {
            throw new ApiException('auth.api_key_expired', 401, [[
                'code'    => 'auth.api_key_expired',
                'message' => 'This API key has expired. Issue a new key and rotate.',
            ]]);
        }

        if ((string) $app['status'] !== 'active') {
            throw new ApiException('api_app.suspended', 403, [[
                'code'    => 'api_app.suspended',
                'message' => 'The API app this key belongs to is suspended.',
            ]]);
        }

        return ['key' => $key, 'app' => $app];
    }

    /**
     * Resolves a key by ID for the sandbox explorer. ONLY test keys are
     * eligible, and the full state check chain still applies. Deliberately
     * not tenant-scoped: the explorer is a superadmin surface and test
     * keys live under the sandbox tenant, not the caller's.
     *
     * @return array{key: array<string, mixed>, app: array<string, mixed>}
     */
    public function resolveSandboxKey(int $keyId): array
    {
        $key = $this->db->table('api_keys')->where('id', $keyId)->get()->getRowArray();
        $app = $key !== null
            ? $this->db->table('api_apps')->where('id', (int) $key['app_id'])->get()->getRowArray()
            : null;

        if ($key === null || $app === null) {
            throw ApiException::notFound('resource.not_found');
        }
        if ((string) $key['env'] !== 'test') {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'The sandbox explorer only executes test keys.', 'field' => 'key_id'],
            ]);
        }

        // State checks mirror verify() — revoked/expired/suspended die here.
        if ($key['revoked_at'] !== null) {
            throw new ApiException('auth.api_key_revoked', 401, [[
                'code'    => 'auth.api_key_revoked',
                'message' => 'This API key has been revoked.',
            ]]);
        }
        $expiresAt = $key['expires_at'];
        if ($expiresAt !== null && strtotime((string) $expiresAt) <= time()) {
            throw new ApiException('auth.api_key_expired', 401, [[
                'code'    => 'auth.api_key_expired',
                'message' => 'This API key has expired. Issue a new key and rotate.',
            ]]);
        }
        if ((string) $app['status'] !== 'active') {
            throw new ApiException('api_app.suspended', 403, [[
                'code'    => 'api_app.suspended',
                'message' => 'The API app this key belongs to is suspended.',
            ]]);
        }

        return ['key' => $key, 'app' => $app];
    }

    /** Marks successful use (called from the filter's after()). */
    public function markUsed(int $keyId): void
    {
        // Write-amplification guard: at most one touch per minute per key.
        $this->db->table('api_keys')
            ->where('id', $keyId)
            ->groupStart()
                ->where('last_used_at IS NULL', null, false)
                ->orWhere('last_used_at <', gmdate('Y-m-d H:i:s', time() - 60))
            ->groupEnd()
            ->update(['last_used_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** @return array<string, mixed>|null */
    public function findApp(int $appId, ?int $tenantId = null): ?array
    {
        $builder = $this->db->table('api_apps')->where('id', $appId);
        if ($tenantId !== null) {
            $builder->where('tenant_id', $tenantId);
        }
        return $builder->get()->getRowArray();
    }

    public function sandboxTenantId(): int
    {
        $config = config(ExternalApps::class);
        $tenant = $this->db->table('tenants')
            ->where('slug', $config->sandboxTenantSlug)
            ->get()->getRowArray();
        if ($tenant === null) {
            throw new RuntimeException(
                "The sandbox tenant ('{$config->sandboxTenantSlug}') is missing — run migrations.",
            );
        }
        return (int) $tenant['id'];
    }

    private function invalidKey(): ApiException
    {
        return new ApiException('auth.api_key_invalid', 401, [[
            'code'    => 'auth.api_key_invalid',
            'message' => 'A valid API key is required (X-Api-Key header).',
        ]]);
    }

    /** Cryptographically random token over the given alphabet. */
    private function randomToken(int $length, string $alphabet): string
    {
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
