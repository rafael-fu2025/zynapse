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
    private const MODULES = ReportService::MODULES;

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
            'referrals'   => $this->service->referrals($range),
            'facilities'  => $this->service->facilities($range),
        };

        return $this->ok($data);
    }

    /** Persist a deterministic narrative through an explicit write action. */
    public function narrative(string $module): ResponseInterface
    {
        $this->authorize('reports.configure');
        $this->assertModule($module);

        $payload = $this->request->getJSON(true) ?? [];
        $range = $this->service->range(
            is_string($payload['start'] ?? null) ? $payload['start'] : null,
            is_string($payload['end'] ?? null) ? $payload['end'] : null,
        );
        $data = match ($module) {
            'clinic'      => $this->service->clinic($range),
            'counselling' => $this->service->counselling($range),
            'inventory'   => $this->service->inventory($range),
            'referrals'   => $this->service->referrals($range),
            'facilities'  => $this->service->facilities($range),
        };

        return $this->ok($this->service->summarize($module, $range, $data), null, 201);
    }

    public function export(string $module): ResponseInterface
    {
        $this->authorize('reports.export');
        $this->assertModule($module);

        $range = $this->rangeFromQuery();
        [$headers, $rows] = $this->service->exportStream($module, $range);

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
        $start = $this->request->getGet('start');
        $end = $this->request->getGet('end');
        if (($start !== null && ! is_string($start)) || ($end !== null && ! is_string($end))) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Report dates must be scalar YYYY-MM-DD values.', 'field' => 'start'],
            ]);
        }
        return $this->service->range(
            $start,
            $end,
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
