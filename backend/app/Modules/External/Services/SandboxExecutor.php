<?php

declare(strict_types=1);

namespace Modules\External\Services;

use App\Auth\ApiKeyContext;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\CurrentTenant;
use App\Services\External\ApiKeyService;

/**
 * SandboxExecutor — server-side request explorer for TEST keys (D7).
 *
 * The explorer UI (superadmin-only) picks an existing test key by ID;
 * this service re-runs the FULL key verification chain, binds the same
 * request-scoped context the real filter would (CurrentTenant +
 * ApiKeyContext), dispatches to the shared catalog service, and returns
 * status + body. The raw secret is never needed — hash-only storage
 * (AC8) is preserved because everything runs server-side.
 *
 * v1 is read-only: only GET endpoints are executable. The caller's
 * tenant binding is restored in a finally block (the static context is
 * request-scoped and this runs mid-JWT-request).
 */
final class SandboxExecutor extends BaseService
{
    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @return array{status: int, headers: array<string, string>, body: array<string, mixed>}
     */
    public function execute(int $keyId, string $method, string $path, array $query = [], array $body = []): array
    {
        $method = strtoupper(trim($method));
        if ($method !== 'GET') {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'The v1 external catalog is read-only (GET only).', 'field' => 'method'],
            ]);
        }
        $path = trim($path, '/');

        $resolved = (new ApiKeyService())->resolveSandboxKey($keyId);

        $previousTenant = CurrentTenant::id();
        try {
            CurrentTenant::set((int) $resolved['key']['tenant_id']);
            ApiKeyContext::bind($resolved['key'], $resolved['app']);

            $catalog = new ExternalCatalogService();
            $data = match ($path) {
                'ping' => [
                    'service' => 'synapse-external',
                    'version' => 'v1',
                    'time'    => gmdate('Y-m-d H:i:s'),
                ],
                'me/scopes' => $catalog->selfDescription(),
                'aggregates/visits' => $catalog->visits($query ?: null),
                'aggregates/referrals' => $catalog->referrals($query ?: null),
                default => throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Unknown sandbox path: ' . $path, 'field' => 'path'],
                ]),
            };

            ExternalAudit::log($path, 'GET', 200, 'sandbox');

            return ['status' => 200, 'headers' => [], 'body' => $data];
        } finally {
            ApiKeyContext::reset();
            CurrentTenant::set($previousTenant);
        }
    }
}
