<?php

declare(strict_types=1);

namespace Modules\Counselling;

use App\Modules\Shared\BaseRoutes;
use CodeIgniter\Router\RouteCollection;

final class Routes implements BaseRoutes
{
    public static function register(RouteCollection $routes): void
    {
        $routes->group('api/v1/counselling', ['namespace' => 'Modules\\Counselling\\Controllers', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
            $r->get('sessions',                       'CounsellingController::listSessions');
            $r->post('sessions',                      'CounsellingController::openSession');
            $r->post('sessions/(:num)/notes',         'CounsellingController::writeNotes/$1');
            $r->get('sessions/(:num)/notes',          'CounsellingController::readNotes/$1');
            $r->post('sessions/(:num)/close',         'CounsellingController::closeSession/$1');

            // Scheduling (Phase 15 — recycled from synapse_ag).
            $r->get('availability',                   'ScheduleController::listAvailability');
            $r->post('availability',                  'ScheduleController::addSlot');
            $r->post('availability/(:num)/remove',    'ScheduleController::removeSlot/$1');
            $r->get('appointments',                   'ScheduleController::listAppointments');
            $r->post('appointments',                  'ScheduleController::book');
            $r->post('appointments/(:num)/transition','ScheduleController::transition/$1');

            // Scheduling analytics (Phase P5a — deterministic no-show optimizer).
            $r->get('analytics',                      'ScheduleController::listAnalytics');
            $r->post('analytics/recompute',           'ScheduleController::recomputeAnalytics');
        });
    }
}