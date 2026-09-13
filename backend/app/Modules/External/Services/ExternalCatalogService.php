<?php

declare(strict_types=1);

namespace Modules\External\Services;

use App\Auth\ApiKeyContext;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Rbac\PermissionService;
use Modules\Reports\Services\ReportService;

/**
 * ExternalCatalogService — the v1 external data catalog (D5).
 *
 * De-identified, aggregate-only reads backed by the EXISTING tenant-
 * scoped report services (ReportService — every query runs under
 * CurrentTenant, ranges resolve in Asia/Manila via ReportRange).
 * NO patient-record endpoints in v1: any future PHI-returning scope
 * requires an explicit superadmin grant AND a documented lawful basis
 * under RA 10173 (see docs/DATA-SHARING.md).
 *
 * Scope enforcement lives HERE (not in the controller) so the HTTP
 * filter path and the sandbox executor path enforce identically.
 */
final class ExternalCatalogService extends BaseService
{
    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
    }

    /**
     * @param array<string, mixed>|null $query
     * @return array<string, mixed>
     */
    public function visits(?array $query = null): array
    {
        $this->assertScope('reports.read');
        $range  = $this->range($query);
        $report = (new ReportService($this->db))->clinic($range);

        // Curated projection — the external contract stays stable even if
        // the internal report grows. Aggregate counts only; the complaint
        // categories are already privacy-safe buckets (no free text).
        return [
            'range'                  => $report['range'],
            'total_encounters'       => $report['total_encounters'],
            'status_breakdown'       => $report['status_breakdown'],
            'daily_trend'            => $report['daily_trend'],
            'complaint_categories'   => $report['complaint_categories'],
            'checkin_outcomes'       => $report['checkin_outcomes'],
            'patient_type_breakdown' => $report['patient_type_breakdown'],
            'unique_patients'        => $report['unique_patients'],
            'avg_per_day'            => $report['avg_per_day'],
            'avg_visits_per_patient' => $report['avg_visits_per_patient'],
        ];
    }

    /**
     * @param array<string, mixed>|null $query
     * @return array<string, mixed>
     */
    public function referrals(?array $query = null): array
    {
        $this->assertScope('referrals.read');
        $range  = $this->range($query);
        $report = (new ReportService($this->db))->referrals($range);

        return [
            'range'            => $report['range'],
            'total_referrals'  => $report['total_referrals'],
            'closed_count'     => $report['closed_count'],
            'closed_rate'      => $report['closed_rate'],
            'status_breakdown' => $report['status_breakdown'],
            'flow_breakdown'   => $report['flow_breakdown'],
            'daily_trend'      => $report['daily_trend'],
        ];
    }

    /**
     * Self-description for integrators (`GET me/scopes`) — app/key
     * identity (prefix + last4 only), effective scopes, and the
     * endpoint catalog.
     *
     * @return array<string, mixed>
     */
    public function selfDescription(): array
    {
        return [
            'app' => [
                'id'   => ApiKeyContext::appId(),
                'name' => ApiKeyContext::appName(),
            ],
            'key' => [
                'env'                => ApiKeyContext::env(),
                'prefix'             => ApiKeyContext::prefix(),
                'last4'              => ApiKeyContext::last4(),
                'scopes'             => ApiKeyContext::scopes(),
                'rate_limit_per_min' => ApiKeyContext::rateLimitPerMin(),
                'expires_at'         => ApiKeyContext::expiresAt(),
            ],
            'endpoints' => [
                ['method' => 'GET', 'path' => '/api/v1/external/v1/ping',                 'scope' => null],
                ['method' => 'GET', 'path' => '/api/v1/external/v1/me/scopes',            'scope' => null],
                ['method' => 'GET', 'path' => '/api/v1/external/v1/aggregates/visits',    'scope' => 'reports.read'],
                ['method' => 'GET', 'path' => '/api/v1/external/v1/aggregates/referrals', 'scope' => 'referrals.read'],
            ],
        ];
    }

    /**
     * Scope gate for key principals — same 403 code+shape as the JWT
     * side (`rbac.permission_denied:<code>`).
     */
    private function assertScope(string $code): void
    {
        /** @var \Config\AuthGroups $groups */
        $groups = config('Config\\AuthGroups');
        if (! (new PermissionService())->grants(ApiKeyContext::scopes(), $code, $groups->adminWildcard)) {
            throw ApiException::forbidden('rbac.permission_denied:' . $code);
        }
    }

    /**
     * @param array<string, mixed>|null $query
     * @return array{start: string, end: string}
     */
    private function range(?array $query): array
    {
        $start = isset($query['start']) && is_string($query['start']) ? $query['start'] : null;
        $end   = isset($query['end']) && is_string($query['end']) ? $query['end'] : null;

        // ReportRange::resolve validates and defaults to the last 30
        // Manila days, throwing the canonical 422 envelope on bad input.
        return (new \Modules\Reports\Services\ReportRange())->resolve($start, $end);
    }
}
