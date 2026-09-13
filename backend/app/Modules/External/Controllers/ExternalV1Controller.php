<?php

declare(strict_types=1);

namespace Modules\External\Controllers;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\External\Services\ExternalAudit;
use Modules\External\Services\ExternalCatalogService;

/**
 * ExternalV1Controller — thin HTTP surface over ExternalCatalogService.
 *
 * Scope checks live in the catalog service (shared with the sandbox
 * executor). Every successful response is audited as `external.request`
 * (sampled per env by ExternalAudit). This controller NEVER touches
 * CurrentUser — the principal is the API key (ApiKeyContext).
 */
final class ExternalV1Controller extends ApiController
{
    private ExternalCatalogService $catalog;

    public function __construct(?ExternalCatalogService $catalog = null)
    {
        $this->catalog = $catalog ?? new ExternalCatalogService();
    }

    public function ping(): ResponseInterface
    {
        return $this->respondExternal('ping', [
            'service' => 'synapse-external',
            'version' => 'v1',
            'time'    => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function meScopes(): ResponseInterface
    {
        return $this->respondExternal('me/scopes', $this->catalog->selfDescription());
    }

    public function visits(): ResponseInterface
    {
        return $this->respondExternal('aggregates/visits', $this->catalog->visits($this->query()));
    }

    public function referrals(): ResponseInterface
    {
        return $this->respondExternal('aggregates/referrals', $this->catalog->referrals($this->query()));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function query(): ?array
    {
        $raw = $this->request->getGet();
        return is_array($raw) && $raw !== [] ? $raw : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function respondExternal(string $endpoint, array $data): ResponseInterface
    {
        ExternalAudit::log(
            $endpoint,
            strtoupper($this->request->getMethod()),
            200,
            'ok',
            $this->request->getIPAddress(),
            (string) $this->request->getUserAgent(),
        );
        return $this->ok($data);
    }
}
