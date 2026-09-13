<?php

declare(strict_types=1);

namespace Modules\External\Controllers\Admin;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\External\Services\DeveloperAppsService;
use Modules\External\Services\SandboxExecutor;

/**
 * DeveloperAdminController — superadmin surface for API apps + keys and
 * the sandbox explorer (D4/D7).
 *
 * Gates: reads `api_apps.read`, mutations `api_apps.manage` — the
 * superadmin wildcard satisfies both; unit admins hold neither, so the
 * developer portal is unreachable for them (403, server-enforced).
 */
final class DeveloperAdminController extends ApiController
{
    private DeveloperAppsService $service;
    private SandboxExecutor $executor;

    public function __construct(?DeveloperAppsService $service = null, ?SandboxExecutor $executor = null)
    {
        $this->service  = $service ?? new DeveloperAppsService();
        $this->executor = $executor ?? new SandboxExecutor();
    }

    public function index(): ResponseInterface
    {
        $this->authorize('api_apps.read');
        return $this->ok($this->service->listApps());
    }

    public function createApp(): ResponseInterface
    {
        $this->authorize('api_apps.manage');
        $payload = $this->request->getJSON(true) ?? [];
        return $this->ok($this->service->createApp(is_array($payload) ? $payload : []), null, 201);
    }

    public function setAppStatus(int $appId): ResponseInterface
    {
        $this->authorize('api_apps.manage');
        $payload = $this->request->getJSON(true) ?? [];
        $status = is_array($payload) ? (string) ($payload['status'] ?? '') : '';
        return $this->ok($this->service->setAppStatus($appId, $status));
    }

    public function listKeys(int $appId): ResponseInterface
    {
        $this->authorize('api_apps.read');
        return $this->ok($this->service->listKeys($appId));
    }

    public function createKey(int $appId): ResponseInterface
    {
        $this->authorize('api_apps.manage');
        $payload = $this->request->getJSON(true) ?? [];
        $result = $this->service->createKey($appId, is_array($payload) ? $payload : []);
        // The secret travels ONCE, in this response — never again.
        return $this->ok(['key' => $result['key'], 'secret' => $result['secret']], null, 201);
    }

    public function revokeKey(int $keyId): ResponseInterface
    {
        $this->authorize('api_apps.manage');
        $payload = $this->request->getJSON(true) ?? [];
        $reason = is_array($payload) && isset($payload['reason']) ? (string) $payload['reason'] : null;
        return $this->ok($this->service->revokeKey($keyId, $reason));
    }

    /**
     * Sandbox explorer execution — re-runs the key chain server-side for
     * a selected TEST key (no raw secret ever reaches this endpoint).
     */
    public function sandboxExecute(): ResponseInterface
    {
        $this->authorize('api_apps.manage');
        $payload = $this->request->getJSON(true) ?? [];
        $payload = is_array($payload) ? $payload : [];

        $keyId = (int) ($payload['key_id'] ?? 0);
        if ($keyId < 1) {
            return $this->fail([[
                'code'    => 'validation.field',
                'message' => 'key_id is required.',
                'field'   => 'key_id',
            ]], 422);
        }

        $result = $this->executor->execute(
            $keyId,
            (string) ($payload['method'] ?? 'GET'),
            (string) ($payload['path'] ?? ''),
            is_array($payload['query'] ?? null) ? $payload['query'] : [],
            is_array($payload['body'] ?? null) ? $payload['body'] : [],
        );

        return $this->ok($result);
    }
}
