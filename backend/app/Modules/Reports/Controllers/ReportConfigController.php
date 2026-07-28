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
        return $this->ok($this->service->listConfigs());
    }

    public function createConfig(): ResponseInterface
    {
        $this->authorize('reports.configure');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'name'          => 'required|max_length[120]',
            'module'        => 'required|in_list[clinic,counselling,inventory]',
            'report_type'   => 'permit_empty|max_length[32]',
            'schedule_cron' => 'permit_empty|max_length[64]',
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
        return $this->ok($this->service->run($id), null, 201);
    }

    public function updateConfig(int $id): ResponseInterface
    {
        $this->authorize('reports.configure');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'name'          => 'permit_empty|max_length[120]',
            'module'        => 'permit_empty|in_list[clinic,counselling,inventory]',
            'report_type'   => 'permit_empty|max_length[32]',
            'schedule_cron' => 'permit_empty|max_length[64]',
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

    public function listGenerated(): ResponseInterface
    {
        $this->authorize('reports.read');
        return $this->ok($this->service->listGenerated());
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

        $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $meta['name'] . '"');
        $this->response->setHeader('Cache-Control', 'no-store');
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        $this->response->setBody((string) file_get_contents($meta['path']));

        return $this->response;
    }

    private function collectErrors(): array
    {
        $errs = [];
        foreach ($this->validation->getErrors() as $field => $msg) {
            $errs[] = ['code' => 'validation.field', 'message' => (string) $msg, 'field' => (string) $field];
        }
        return $errs;
    }
}
