<?php

declare(strict_types=1);

namespace Modules\Reports\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Services\Export\CsvWriter;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Reports\Services\ReportService;

/**
 * ReportController — read-only analytics + audited CSV export
 * (Phase 18, recycled from legacy synapse_ag Reports\ReportController).
 *
 * `reports.read` gates the JSON analytics; `reports.export` gates the
 * CSV surface (same split as audit.read / audit.export). Exports are
 * audited with the module + range in the whitelist context.
 */
final class ReportController extends ApiController
{
    private const MODULES = ['clinic', 'counselling', 'inventory'];

    private readonly ReportService $service;

    public function __construct(?ReportService $service = null)
    {
        $this->service = $service ?? new ReportService();
    }

    public function summary(): ResponseInterface
    {
        $this->authorize('reports.read');
        return $this->ok($this->service->summary($this->rangeFromQuery()));
    }

    public function module(string $module): ResponseInterface
    {
        $this->authorize('reports.read');
        $this->assertModule($module);

        $range = $this->rangeFromQuery();
        $data = match ($module) {
            'clinic'      => $this->service->clinic($range),
            'counselling' => $this->service->counselling($range),
            'inventory'   => $this->service->inventory($range),
        };

        if ((string) ($this->request->getGet('summarize') ?? '') === '1') {
            $data['narrative'] = $this->service->summarize($module, $range, $data)['narrative'];
        }

        return $this->ok($data);
    }

    public function export(string $module): ResponseInterface
    {
        $this->authorize('reports.export');
        $this->assertModule($module);

        $range = $this->rangeFromQuery();
        [$headers, $rows] = $this->service->exportRows($module, $range);

        Services::auditOutbox()->enqueue(
            'reports.exported',
            'reports',
            0,
            \App\Auth\CurrentUser::assert(),
            ['resource_code' => $module . ':' . $range['start'] . '..' . $range['end']],
        );

        $writer = new CsvWriter($this->response, 'synapse-report-' . $module);
        $writer->writeHeader($headers);
        foreach ($rows as $row) {
            $writer->writeRow($row);
        }
        $writer->close();

        return $this->response;
    }

    /**
     * @return array{start: string, end: string}
     */
    private function rangeFromQuery(): array
    {
        return $this->service->range(
            $this->request->getGet('start'),
            $this->request->getGet('end'),
        );
    }

    private function assertModule(string $module): void
    {
        if (! in_array($module, self::MODULES, true)) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Unknown report module '{$module}'."],
            ]);
        }
    }
}
