<?php

declare(strict_types=1);

namespace Modules\External\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\CurrentTenant;
use App\Services\External\ApiKeyService;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;

/**
 * DeveloperAppsService — superadmin management of API apps + keys (D4).
 *
 * Apps are registered under the caller's (production) tenant. Key env
 * separation is enforced by ApiKeyService (test → sandbox tenant, live →
 * production tenant). Issue of a LIVE key requires the app to carry a
 * DPA acknowledgement (recorded here with actor + timestamp). Responses
 * expose prefix + last4 only — key_hash and the plaintext secret never
 * leave this service except the one-time creation return.
 */
final class DeveloperAppsService extends BaseService
{
    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listApps(): array
    {
        $rows = $this->db->table('api_apps a')
            ->select('a.id, a.name, a.description, a.owner_contact, a.status, a.dpa_acknowledged_at, a.created_at, COUNT(k.id) AS key_count', false)
            ->where('a.tenant_id', CurrentTenant::id())
            ->join('api_keys k', 'k.app_id = a.id', 'left')
            ->groupBy('a.id')
            ->orderBy('a.created_at', 'DESC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'                  => (int) $r['id'],
            'name'                => (string) $r['name'],
            'description'         => $r['description'] !== null ? (string) $r['description'] : null,
            'owner_contact'       => $r['owner_contact'] !== null ? (string) $r['owner_contact'] : null,
            'status'              => (string) $r['status'],
            'dpa_acknowledged_at' => $r['dpa_acknowledged_at'] !== null ? (string) $r['dpa_acknowledged_at'] : null,
            'key_count'           => (int) $r['key_count'],
            'created_at'          => (string) $r['created_at'],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createApp(array $input): array
    {
        $actorId = CurrentUser::assert();
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'App name is required (max 120 chars).', 'field' => 'name'],
            ]);
        }

        return $this->txn(function () use ($input, $name, $actorId): array {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('api_apps')->insert([
                'tenant_id'     => CurrentTenant::id(),
                'name'          => $name,
                'description'   => isset($input['description']) && $input['description'] !== '' ? (string) $input['description'] : null,
                'owner_contact' => isset($input['owner_contact']) && $input['owner_contact'] !== '' ? (string) $input['owner_contact'] : null,
                'status'        => 'active',
                'created_by'    => $actorId,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
            $appId = (int) $this->db->insertID();

            Services::auditOutbox()->enqueue('api_app.created', 'api_apps', $appId, $actorId, [
                'resource_code' => 'name#' . $name,
            ]);

            return [
                'id'            => $appId,
                'name'          => $name,
                'status'        => 'active',
                'key_count'     => 0,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function setAppStatus(int $appId, string $status): array
    {
        $actorId = CurrentUser::assert();
        if (! in_array($status, ['active', 'suspended'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'status must be active or suspended.', 'field' => 'status'],
            ]);
        }

        return $this->txn(function () use ($appId, $status, $actorId): array {
            $app = $this->selectForUpdate('api_apps', ['id' => $appId, 'tenant_id' => CurrentTenant::id()]);
            if ($app === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('api_apps')
                ->where('id', $appId)
                ->where('tenant_id', CurrentTenant::id())
                ->update(['status' => $status, 'updated_at' => $now]);

            // Suspending an app kills its keys' traffic immediately (the
            // filter checks app status on every request).
            Services::auditOutbox()->enqueue(
                $status === 'suspended' ? 'api_app.suspended' : 'api_app.reactivated',
                'api_apps',
                $appId,
                $actorId,
                ['previous_status' => (string) $app['status'], 'next_status' => $status],
            );

            return ['id' => $appId, 'status' => $status];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listKeys(int $appId): array
    {
        $app = $this->db->table('api_apps')
            ->where('id', $appId)
            ->where('tenant_id', CurrentTenant::id())
            ->get()->getRowArray();
        if ($app === null) {
            throw ApiException::notFound('resource.not_found');
        }

        $rows = $this->db->table('api_keys')
            ->select('id, app_id, env, prefix, last4, scopes, rate_limit_per_min, expires_at, revoked_at, last_used_at, created_at')
            ->where('app_id', $appId)
            ->orderBy('created_at', 'DESC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => self::sanitizeKey($r), $rows);
    }

    /**
     * Issues a key and returns the sanitized row + the ONE-TIME secret.
     *
     * @param list<string> $scopes
     * @return array{key: array<string, mixed>, secret: string}
     */
    public function createKey(int $appId, array $input): array
    {
        $actorId = CurrentUser::assert();
        $env = (string) ($input['env'] ?? 'test');
        if (! in_array($env, ['test', 'live'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'env must be test or live.', 'field' => 'env'],
            ]);
        }
        $scopes = is_array($input['scopes'] ?? null)
            ? array_values(array_map(static fn ($s): string => trim((string) $s), $input['scopes']))
            : [];

        return $this->txn(function () use ($appId, $input, $env, $scopes, $actorId) {
            $app = $this->selectForUpdate('api_apps', ['id' => $appId, 'tenant_id' => CurrentTenant::id()]);
            if ($app === null) {
                throw ApiException::notFound('resource.not_found');
            }

            // DPA gate: LIVE keys require an acknowledged Data Sharing
            // agreement (RA 10173 posture — see docs/DATA-SHARING.md).
            if ($env === 'live' && $app['dpa_acknowledged_at'] === null) {
                if (($input['dpa_acknowledged'] ?? null) !== true) {
                    throw ApiException::validationFailure([
                        [
                            'code'    => 'validation.field',
                            'message' => 'Issuing a live key requires acknowledging the data-sharing agreement (dpa_acknowledged).',
                            'field'   => 'dpa_acknowledged',
                        ],
                    ]);
                }
                $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
                $this->db->table('api_apps')
                    ->where('id', $appId)
                    ->update([
                        'dpa_acknowledged_at' => $now,
                        'dpa_acknowledged_by' => $actorId,
                        'updated_at'          => $now,
                    ]);
            }

            $result = (new ApiKeyService())->createKey(
                $appId,
                $env,
                $scopes,
                isset($input['rate_limit_per_min']) ? (int) $input['rate_limit_per_min'] : null,
                isset($input['ttl_days']) ? (int) $input['ttl_days'] : null,
                $actorId,
            );
            $key = $result['key'];

            Services::auditOutbox()->enqueue('api_key.created', 'api_keys', (int) $key['id'], $actorId, [
                'app_id'     => $appId,
                'key_id'     => (int) $key['id'],
                'key_env'    => (string) $key['env'],
                'key_prefix' => (string) $key['prefix'],
            ]);

            return ['key' => self::sanitizeKey($key), 'secret' => $result['secret']];
        });
    }

    /**
     * Revokes a key immediately (request-time enforcement — the filter
     * checks on every request). Idempotent.
     *
     * @return array<string, mixed>
     */
    public function revokeKey(int $keyId, ?string $reason): array
    {
        $actorId = CurrentUser::assert();

        return $this->txn(function () use ($keyId, $reason, $actorId): array {
            $key = $this->db->table('api_keys k')
                ->select('k.id, k.prefix, k.env, k.revoked_at')
                ->join('api_apps a', 'a.id = k.app_id')
                ->where('k.id', $keyId)
                ->where('a.tenant_id', CurrentTenant::id())
                ->get()->getRowArray();
            if ($key === null) {
                throw ApiException::notFound('resource.not_found');
            }

            if ($key['revoked_at'] === null) {
                $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
                $this->db->table('api_keys')
                    ->where('id', $keyId)
                    ->update(['revoked_at' => $now, 'updated_at' => $now]);

                Services::auditOutbox()->enqueue('api_key.revoked', 'api_keys', (int) $key['id'], $actorId, [
                    'key_id'      => (int) $key['id'],
                    'key_env'     => (string) $key['env'],
                    'key_prefix'  => (string) $key['prefix'],
                    'reason_code' => $reason !== null && $reason !== '' ? $reason : 'manual_revocation',
                ]);
            }

            return ['id' => (int) $key['id'], 'prefix' => (string) $key['prefix'], 'revoked' => true];
        });
    }

    /**
     * Strips credential material — the stored hash and any secret never
     * travel in list/read responses (AC8).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function sanitizeKey(array $row): array
    {
        $scopes = json_decode((string) ($row['scopes'] ?? '[]'), true);
        $revokedAt = $row['revoked_at'] ?? null;
        $expiresAt = $row['expires_at'] ?? null;
        $expired = $expiresAt !== null && strtotime((string) $expiresAt) <= time();

        return [
            'id'                 => (int) $row['id'],
            'app_id'             => (int) $row['app_id'],
            'env'                => (string) $row['env'],
            'prefix'             => (string) $row['prefix'],
            'last4'              => (string) $row['last4'],
            'scopes'             => is_array($scopes) ? array_values($scopes) : [],
            'rate_limit_per_min' => (int) $row['rate_limit_per_min'],
            'expires_at'         => $expiresAt !== null ? (string) $expiresAt : null,
            'revoked_at'         => $revokedAt !== null ? (string) $revokedAt : null,
            'last_used_at'       => isset($row['last_used_at']) && $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
            'created_at'         => (string) $row['created_at'],
            // Display status: revoked beats expired.
            'status'             => $revokedAt !== null ? 'revoked' : ($expired ? 'expired' : 'active'),
        ];
    }
}
