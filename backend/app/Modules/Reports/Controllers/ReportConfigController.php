<?php

declare(strict_types=1);

namespace Modules\Reports\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Reports\Services\ReportConfigService;
use Modules\Reports\Services\ReportService;

/**
 * ReportConfigController — saved report configurations + generated report
 * archive (Phase P6).
 *
 * `reports.read` gates the read surfaces; `reports.configure` gates
 * creating configurations + running them; `reports.export` gates the
 * secure file download (same split as the streaming CSV export).
 */
final class ReportConfigController extends ApiController
{
    private readonly ReportConfigService $service;

    public function __construct(?ReportConfigService $service = null)
    {
        $this->service = $service ?? new ReportConfigService(new ReportService(), Services::auditOutbox());
    }

    public function listConfigs(): ResponseInterface
    {
        $this->authorize('reports.read');
        $archived = (string) ($this->request->getGet('include_archived') ?? '');
        return $this->ok($this->service->listConfigs(
            $archived === '1' || $archived === 'true',
            max(1, (int) ($this->request->getGet('page') ?? 1)),
            max(1, (int) ($this->request->getGet('limit') ?? ReportConfigService::DEFAULT_PAGE_SIZE)),
            $this->queryFilter('module'),
        ));
    }

    public function createConfig(): ResponseInterface
    {
        $this->authorize('reports.configure');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'name'          => 'required|max_length[120]',
            'module'        => 'required|in_list[clinic,counselling,inventory,referrals,facilities]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        if (isset($payload['parameters']) && ! is_array($payload['parameters'])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'parameters must be an object.', 'field' => 'parameters'],
            ]);
        }

        return $this->ok($this->service->createConfig($payload), null, 201);
    }

    public function run(int $id): ResponseInterface
    {
        $this->authorize('reports.configure');
        return $this->ok($this->service->run($id), null, 202);
    }

    public function updateConfig(int $id): ResponseInterface
    {
        $this->authorize('reports.configure');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'name'          => 'permit_empty|max_length[120]',
            'module'        => 'permit_empty|in_list[clinic,counselling,inventory,referrals,facilities]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        if (isset($payload['parameters']) && ! is_array($payload['parameters'])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'parameters must be an object.', 'field' => 'parameters'],
            ]);
        }

        return $this->ok($this->service->updateConfig($id, $payload));
    }

    public function archiveConfig(int $id): ResponseInterface
    {
        $this->authorize('reports.configure');
        $this->service->archiveConfig($id);
        return $this->ok(['archived' => true]);
    }

    public function unarchiveConfig(int $id): ResponseInterface
    {
        $this->authorize('reports.configure');
        return $this->ok($this->service->unarchiveConfig($id));
    }

    public function listGenerated(): ResponseInterface
    {
        $this->authorize('reports.read');
        return $this->ok($this->service->listGenerated(
            max(1, (int) ($this->request->getGet('page') ?? 1)),
            max(1, (int) ($this->request->getGet('limit') ?? ReportConfigService::DEFAULT_PAGE_SIZE)),
            $this->queryFilter('module'),
            $this->queryFilter('status'),
        ));
    }

    public function download(int $id): ResponseInterface
    {
        $this->authorize('reports.export');
        $meta = $this->service->fileForDownload($id);

        Services::auditOutbox()->enqueue(
            'reports.downloaded',
            'generated_reports',
            $id,
            \App\Auth\CurrentUser::assert(),
            ['resource_code' => $meta['name']],
        );

        return $this->response
            ->download($meta['path'], null)
            ->setFileName($meta['name'])
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    private function collectErrors(): array
    {
        $errs = [];
        foreach ($this->validation->getErrors() as $field => $msg) {
            $errs[] = ['code' => 'validation.field', 'message' => (string) $msg, 'field' => (string) $field];
        }
        return $errs;
    }

    private function queryFilter(string $name): ?string
    {
        $value = $this->request->getGet($name);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        return trim($value);
    }
}
