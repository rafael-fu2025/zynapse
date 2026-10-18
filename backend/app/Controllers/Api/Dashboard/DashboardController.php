<?php

declare(strict_types=1);

namespace App\Controllers\Api\Dashboard;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Services\CurrentTenant;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * DashboardController — cheap, aggregated counters for the SPA dashboard.
 *
 * Each counter is gated by the permission needed to view the underlying
 * domain. If the user lacks that permission, the counter is omitted.
 */
final class DashboardController extends ApiController
{
    public function clinic(): ResponseInterface
    {
        return $this->workstation('clinic');
    }

    public function guidance(): ResponseInterface
    {
        return $this->workstation('guidance');
    }

    public function facilities(): ResponseInterface
    {
        return $this->workstation('facilities');
    }

    private function workstation(string $unit): ResponseInterface
    {
        $data = (new \App\Services\WorkstationDashboardService(Services::database(), $this->permissions))
            ->dashboard($unit, \App\Auth\CurrentUser::assert());
        return $this->ok($data)->setHeader('Cache-Control', 'private, no-store');
    }

    public function counters(): ResponseInterface
    {
        $user = \App\Auth\CurrentUser::assert();
        $tenantId = CurrentTenant::id();

        $clinicPerm      = $this->permissions->userHas($user, 'clinic.encounters.read');
        $counsellingPerm = $this->permissions->userHas($user, 'counselling.records.read');
        $facilitiesPerm  = $this->permissions->userHas($user, 'facilities.units.read');
        $referralsPerm   = $this->permissions->userHas($user, 'referrals.read');
        $auditPerm       = $this->permissions->userHas($user, 'audit.read');

        $out = [];
        $db = Services::database();

        if ($clinicPerm) {
            $out['clinic'] = [
                'open_encounters'   => (int) $db->table('clinic_encounters')->where('tenant_id', $tenantId)->where('status', 'open')->where('archived_at', null)->countAllResults(),
                'closed_encounters' => (int) $db->table('clinic_encounters')->where('tenant_id', $tenantId)->where('status', 'closed')->where('archived_at', null)->countAllResults(),
            ];
        }

        if ($counsellingPerm) {
            $sessionQuery = static function () use ($db, $tenantId, $user): \CodeIgniter\Database\BaseBuilder {
                $q = $db->table('counselling_sessions')->where('tenant_id', $tenantId)->where('archived_at', null);
                if (! Services::permissionService()->userHas($user, 'counselling.records.read_any')) {
                    $q->where('counsellor_user_id', $user);
                }
                return $q;
            };
            $out['counselling'] = [
                'open_sessions'   => (int) $sessionQuery()->where('ended_at', null)->countAllResults(),
                'closed_sessions' => (int) $sessionQuery()->where('ended_at !=', null)->countAllResults(),
            ];
        }

        if ($facilitiesPerm) {
            $out['facilities'] = [
                'units_idle'      => (int) $db->table('facilities_bmg_units')->where('tenant_id', $tenantId)->where('status', 'idle')->where('archived_at', null)->countAllResults(),
                'units_processing'=> (int) $db->table('facilities_bmg_units')->where('tenant_id', $tenantId)->where('status', 'processing')->where('archived_at', null)->countAllResults(),
                'units_awaiting'  => (int) $db->table('facilities_bmg_units')->where('tenant_id', $tenantId)->where('status', 'awaiting_output')->where('archived_at', null)->countAllResults(),
                // Count distinct live batches, not the number of outstanding alerts.
                'at_risk'         => (int) $db->table('facilities_bmg_alerts AS a')
                    ->join('facilities_bmg_batches AS b', 'b.id = a.batch_id AND b.tenant_id = a.tenant_id')
                    ->join('facilities_bmg_units AS u', 'u.id = b.unit_id AND u.tenant_id = b.tenant_id')
                    ->select('b.id')->distinct()
                    ->where('b.tenant_id', $tenantId)->where('b.archived_at', null)
                    ->where('u.tenant_id', $tenantId)->where('u.archived_at', null)
                    ->where('a.tenant_id', $tenantId)
                    ->where('a.acknowledged_at', null)
                    ->whereIn('b.status', ['processing', 'awaiting_output'])
                    ->countAllResults(),
            ];
        }

        if ($referralsPerm) {
            $out['referrals'] = [
                'submitted'   => (int) $db->table('referral_referrals')->where('tenant_id', $tenantId)->where('status', 'submitted')->where('archived_at', null)->countAllResults(),
                'acknowledged'=> (int) $db->table('referral_referrals')->where('tenant_id', $tenantId)->where('status', 'acknowledged')->where('archived_at', null)->countAllResults(),
                'under_review'=> (int) $db->table('referral_referrals')->where('tenant_id', $tenantId)->where('status', 'under_review')->where('archived_at', null)->countAllResults(),
                'closed'      => (int) $db->table('referral_referrals')->where('tenant_id', $tenantId)->where('status', 'closed')->where('archived_at', null)->countAllResults(),
            ];
        }

        if ($auditPerm) {
            $out['audit'] = [
                'events_last_24h' => (int) $db->table('audit_events')
                    ->where('tenant_id', $tenantId)
                    ->where('commited_at >=', date('Y-m-d H:i:s', time() - 86_400))
                    ->countAllResults(),
            ];
        }

        return $this->ok($out)->setHeader('Cache-Control', 'private, no-store');
    }

    /** For callers that prefer a single permission probe. */
    public function ping(): ResponseInterface
    {
        if (\App\Auth\CurrentUser::id() === null) {
            throw ApiException::unauthorized('auth.unauthorized');
        }
        return $this->ok(['now' => gmdate('c')]);
    }
}
