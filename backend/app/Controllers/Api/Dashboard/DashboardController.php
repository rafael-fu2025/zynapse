<?php

declare(strict_types=1);

namespace App\Controllers\Api\Dashboard;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
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
    public function counters(): ResponseInterface
    {
        $out = [];

        $db = Services::database();

        if ($this->permissions->userHas(\App\Auth\CurrentUser::assert(), 'clinic.encounters.read')) {
            $out['clinic'] = [
                'open_encounters'   => (int) $db->table('clinic_encounters')->where('status', 'Open')->where('archived_at', null)->countAllResults(),
                'closed_encounters' => (int) $db->table('clinic_encounters')->where('status', 'Closed')->where('archived_at', null)->countAllResults(),
            ];
        }

        if ($this->permissions->userHas(\App\Auth\CurrentUser::assert(), 'counselling.records.read')) {
            $out['counselling'] = [
                'open_sessions'   => (int) $db->table('counselling_sessions')->where('ended_at', null)->where('archived_at', null)->countAllResults(),
                'closed_sessions' => (int) $db->table('counselling_sessions')->where('ended_at !=', null)->where('archived_at', null)->countAllResults(),
            ];
        }

        if ($this->permissions->userHas(\App\Auth\CurrentUser::assert(), 'facilities.units.read')) {
            $out['facilities'] = [
                'units_idle'      => (int) $db->table('facilities_bmg_units')->where('status', 'Idle')->where('archived_at', null)->countAllResults(),
                'units_processing'=> (int) $db->table('facilities_bmg_units')->where('status', 'Processing')->where('archived_at', null)->countAllResults(),
                'units_awaiting'  => (int) $db->table('facilities_bmg_units')->where('status', 'AwaitingOutput')->where('archived_at', null)->countAllResults(),
            ];
        }

        if ($this->permissions->userHas(\App\Auth\CurrentUser::assert(), 'referrals.read')) {
            $out['referrals'] = [
                'submitted'   => (int) $db->table('referral_referrals')->where('status', 'Submitted')->where('archived_at', null)->countAllResults(),
                'acknowledged'=> (int) $db->table('referral_referrals')->where('status', 'Acknowledged')->where('archived_at', null)->countAllResults(),
                'under_review'=> (int) $db->table('referral_referrals')->where('status', 'UnderReview')->where('archived_at', null)->countAllResults(),
                'closed'      => (int) $db->table('referral_referrals')->where('status', 'Closed')->where('archived_at', null)->countAllResults(),
            ];
        }

        if ($this->permissions->userHas(\App\Auth\CurrentUser::assert(), 'audit.read')) {
            $out['audit'] = [
                'events_last_24h' => (int) $db->table('audit_events')
                    ->where('commited_at >=', date('Y-m-d H:i:s', time() - 86_400))
                    ->countAllResults(),
            ];
        }

        return $this->ok($out);
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
