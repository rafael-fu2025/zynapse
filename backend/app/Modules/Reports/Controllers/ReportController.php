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
        $data = $this->service->summary($this->rangeFromQuery());
        // Unit-gated modules disappear from the overview entirely — a
        // caller without the module's read code never sees its counts.
        foreach (array_keys(ReportService::MODULE_EXTRA_PERMISSIONS) as $module) {
            if (! $this->canViewModule((string) $module)) {
                unset($data[$module]);
            }
        }

        return $this->ok($data);
    }

    public function module(string $module): ResponseInterface
    {
        $this->authorize('reports.read');
        $this->authorizeModule($module);

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

    /** Inventory stockout forecast aggregate (trailing 30-day usage model). */
    public function inventoryForecast(): ResponseInterface
    {
        $this->authorize('reports.read');

        $withinDays = $this->request->getGet('within_days');
        $within = 90;
        if (is_string($withinDays)) {
            $within = (int) $withinDays;
        }
        if ($within < 1 || $within > 365) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'within_days must be between 1 and 365.', 'field' => 'within_days'],
            ]);
        }

        return $this->ok($this->service->inventoryForecast($within));
    }

    /** Purchase (reorder) activity for the selected range. */
    public function inventoryPurchases(): ResponseInterface
    {
        $this->authorize('reports.read');

        return $this->ok($this->service->inventoryPurchases($this->rangeFromQuery()));
    }

    /** Persist a deterministic narrative through an explicit write action. */
    public function narrative(string $module): ResponseInterface
    {
        $this->authorize('reports.configure');
        $this->authorizeModule($module);

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
        $this->authorizeModule($module);

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
        $writer->flush();

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

    /**
     * Unit-scoped module gate on top of the surface permission:
     * `reports.read`/`reports.export` opens the shared analytics, but a
     * module in ReportService::MODULE_EXTRA_PERMISSIONS additionally
     * requires its unit's read code.
     */
    private function authorizeModule(string $module): void
    {
        $this->assertModule($module);
        $extra = ReportService::MODULE_EXTRA_PERMISSIONS[$module] ?? null;
        if (is_string($extra)) {
            $this->authorize($extra);
        }
    }

    private function canViewModule(string $module): bool
    {
        $extra = ReportService::MODULE_EXTRA_PERMISSIONS[$module] ?? null;
        return ! is_string($extra) || $this->permissions->userHas(\App\Auth\CurrentUser::assert(), $extra);
    }
}
