<?php

declare(strict_types=1);

namespace Modules\Referrals;

use App\Modules\Shared\BaseRoutes;
use CodeIgniter\Router\RouteCollection;

final class Routes implements BaseRoutes
{
    public static function register(RouteCollection $routes): void
    {
        $routes->group('api/v1/referrals', ['namespace' => 'Modules\\Referrals\\Controllers', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
            $r->get('patient-lookup',                    'ReferralController::lookupPatient');
            $r->get('',                                  'ReferralController::list');
            $r->post('',                                 'ReferralController::create');
            $r->post('(:num)/acknowledge',               'ReferralController::acknowledge/$1');
            $r->post('(:num)/review',                    'ReferralController::review/$1');
            $r->post('(:num)/close',                     'ReferralController::close/$1');
            $r->post('(:num)/queue-handoff',             'ReferralController::handoffToQueue/$1');
        });
    }
}
