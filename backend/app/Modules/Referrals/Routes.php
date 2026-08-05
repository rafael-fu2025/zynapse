<?php

declare(strict_types=1);

namespace Modules\Referrals;

use App\Modules\Shared\BaseRoutes;
use CodeIgniter\Router\RouteCollection;

final class Routes implements BaseRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // Public, minimum-disclosure verify — explicitly NOT under api_auth.
        // Leading backslash prevents the router's default namespace prefix.
        $routes->post('api/v1/referrals/verify', '\\Modules\\Referrals\\Controllers\\ReferralController::verify');

        $routes->group('api/v1/referrals', ['namespace' => 'Modules\\Referrals\\Controllers', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
            $r->get('patient-lookup',                    'ReferralController::lookupPatient');
            $r->get('',                                  'ReferralController::list');
            $r->post('',                                 'ReferralController::create');
            $r->post('(:num)/acknowledge',               'ReferralController::acknowledge/$1');
            $r->post('(:num)/review',                    'ReferralController::review/$1');
            $r->post('(:num)/close',                     'ReferralController::close/$1');
            $r->post('(:num)/issue-qr',                  'ReferralController::issueQr/$1');
            $r->post('(:num)/revoke-qr',                 'ReferralController::revokeQr/$1');
        });
    }
}