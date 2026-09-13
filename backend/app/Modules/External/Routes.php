<?php

declare(strict_types=1);

namespace Modules\External;

use App\Modules\Shared\BaseRoutes;
use CodeIgniter\Router\RouteCollection;

/**
 * External module — two distinct surfaces:
 *
 *   1. api/v1/external/v1/... — the EXTERNAL data API. Authenticated by
 *      `api_key_auth` (X-Api-Key / Bearer syn_…), NOT by JWT. Tenant
 *      binding comes from the key row (test → sandbox, live → prod).
 *   2. api/v1/developer/... — the superadmin management surface (apps,
 *      keys, sandbox explorer). JWT `api_auth`, gated by api_apps.*.
 */
final class Routes implements BaseRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // -----------------------------------------------------------------
        // External API v1 — API-key authenticated.
        // -----------------------------------------------------------------
        $routes->group('api/v1/external/v1', ['namespace' => 'Modules\\External\\Controllers', 'filter' => 'api_key_auth'], static function (RouteCollection $r): void {
            $r->get('ping',                 'ExternalV1Controller::ping');
            $r->get('me/scopes',            'ExternalV1Controller::meScopes');
            $r->get('aggregates/visits',    'ExternalV1Controller::visits');
            $r->get('aggregates/referrals', 'ExternalV1Controller::referrals');
        });

        // -----------------------------------------------------------------
        // Developer portal backend — JWT-authenticated (superadmin).
        // -----------------------------------------------------------------
        $routes->group('api/v1/developer', ['namespace' => 'Modules\\External\\Controllers\\Admin', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
            $r->get('apps',                 'DeveloperAdminController::index');
            $r->post('apps',                'DeveloperAdminController::createApp');
            $r->post('apps/(:num)/status',  'DeveloperAdminController::setAppStatus/$1');
            $r->get('apps/(:num)/keys',     'DeveloperAdminController::listKeys/$1');
            $r->post('apps/(:num)/keys',    'DeveloperAdminController::createKey/$1');
            $r->post('keys/(:num)/revoke',  'DeveloperAdminController::revokeKey/$1');
            $r->post('sandbox/execute',     'DeveloperAdminController::sandboxExecute');
        });
    }
}
