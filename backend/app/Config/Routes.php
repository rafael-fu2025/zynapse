<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/**
 * Routes — SYNAPSE API.
 *
 * All routes live under `/api/v1/`. URI-pattern filters (`api/*` in
 * Filters.php) add rate limiting, logging, and the exception envelope;
 * authentication is attached per group below.
 *
 * @var RouteCollection $routes
 */

// Health probe — unauthenticated, used by load balancers.
$routes->get('api/v1/health', static fn () => service('response')
    ->setJSON(['success' => true, 'data' => ['status' => 'ok'], 'errors' => null, 'meta' => null]));

// ---------------------------------------------------------------------
// Auth (Shield-adapted). Login/refresh carry a strict `auth`
// rate-limit bucket on top of the global `api/*` bucket.
// ---------------------------------------------------------------------
$routes->group('api/v1/auth', ['namespace' => 'App\Controllers\Api\Auth'], static function (RouteCollection $r): void {
    $r->post('login',   'AuthController::login',   ['filter' => 'api_ratelimit:auth']);
    $r->post('refresh', 'AuthController::refresh', ['filter' => 'api_ratelimit:auth']);
    $r->post('logout',  'AuthController::logout',  ['filter' => 'api_auth']);
    $r->post('change-password', 'AuthController::changePassword', ['filter' => 'api_auth']);
    $r->get('me',       'AuthController::me',      ['filter' => 'api_auth']);
});

// ---------------------------------------------------------------------
// RBAC introspection (DB-driven, used by SPA to gate UI)
// ---------------------------------------------------------------------
$routes->group('api/v1/rbac', ['namespace' => 'App\Controllers\Api\Rbac', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
    $r->get('permissions', 'PermissionController::index');
    $r->get('roles',       'RoleController::index');
});

// ---------------------------------------------------------------------
// Module routes — each module owns its own route loader, wired
// to `app/Modules/*/Routes.php`.
// ---------------------------------------------------------------------
Modules\Clinic\Routes::register($routes);
Modules\Counselling\Routes::register($routes);
Modules\Facilities\Routes::register($routes);
Modules\Referrals\Routes::register($routes);
Modules\Reports\Routes::register($routes);

// ---------------------------------------------------------------------
// Audit reader — append-only, hash-chained, DB-driven RBAC.
// ---------------------------------------------------------------------
$routes->group('api/v1/audit', ['namespace' => 'App\Controllers\Api\Audit', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
    $r->get('events',        'AuditEventController::index');
    $r->get('events/(:num)', 'AuditEventController::show/$1');
    $r->get('export',        'AuditEventController::export');
    $r->get('verify/(:num)', 'AuditEventController::verify/$1');
});

// ---------------------------------------------------------------------
// Admin — user lifecycle (rbac.manage). Phase 10.
// ---------------------------------------------------------------------
$routes->group('api/v1/admin', ['namespace' => 'App\Controllers\Api\Admin', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
    $r->get('users',                       'UserController::index');
    $r->post('users',                      'UserController::create');
    $r->post('users/(:num)/status',        'UserController::setStatus/$1');
    $r->post('users/(:num)/groups',        'UserController::setGroups/$1');
    $r->post('users/(:num)/reset-password','UserController::resetPassword/$1');
});

// ---------------------------------------------------------------------
// Notifications — in-app, strictly self-scoped (Phase 9).
// ---------------------------------------------------------------------
$routes->group('api/v1/notifications', ['namespace' => 'App\Controllers\Api\Notify', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
    $r->get('',                'NotificationController::index');
    $r->post('(:num)/read',    'NotificationController::markRead/$1');
});

// ---------------------------------------------------------------------
// Dashboard — permission-aware counters.
// ---------------------------------------------------------------------
$routes->group('api/v1/dashboard', ['namespace' => 'App\Controllers\Api\Dashboard', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
    $r->get('counters', 'DashboardController::counters');
    $r->get('ping',     'DashboardController::ping');
});
