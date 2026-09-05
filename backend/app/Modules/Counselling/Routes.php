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
            $r->get('sessions/(:num)',                'CounsellingController::getSession/$1');
            $r->post('sessions/(:num)/referrals',     'CounsellingController::createReferral/$1');
            $r->get('patient-lookup',                 'CounsellingController::lookupPatient');
            $r->post('sessions/(:num)/notes',         'CounsellingController::writeNotes/$1');
            $r->get('sessions/(:num)/notes',          'CounsellingController::readNotes/$1');
            $r->post('sessions/(:num)/close',         'CounsellingController::closeSession/$1');
            // Oversight-only ownership transfer (audit 2026-09-05, F2).
            $r->post('sessions/(:num)/reassign',      'CounsellingController::reassignSession/$1');
            // Oversight-only soft-delete / restore (audit 2026-09-05, F15).
            $r->post('sessions/(:num)/archive',       'CounsellingController::archiveSession/$1');
            $r->post('sessions/(:num)/unarchive',     'CounsellingController::unarchiveSession/$1');

            // Guidance queue (user-facing label); counselling remains the
            // internal module and route namespace.
            $r->get('queue',                          'QueueController::today');
            $r->post('queue/call-next',               'QueueController::callNext');
            $r->post('queue/(:num)/transition',       'QueueController::transition/$1');
            $r->post('queue/(:num)/repair-session',   'QueueController::repairSession/$1');
            $r->post('queue/(:num)/reassign',         'QueueController::reassign/$1');

            // Scheduling (Phase 15 — recycled from synapse_ag).
            $r->get('availability',                   'ScheduleController::listAvailability');
            $r->get('counsellors',                    'ScheduleController::counsellors');
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
