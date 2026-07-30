<?php

declare(strict_types=1);

namespace Modules\Clinic;

use App\Modules\Shared\BaseRoutes;
use CodeIgniter\Router\RouteCollection;

final class Routes implements BaseRoutes
{
    public static function register(RouteCollection $routes): void
    {
        // PUBLIC waiting-room feed (Phase 14) — no auth by design: the
        // lobby TV / kiosk poll it. Minimum disclosure enforced in the
        // service (position + display name only). Global api/* rate
        // limiting still applies.
        $routes->get(
            'api/v1/clinic/queue/state',
            '\\Modules\\Clinic\\Controllers\\QueueController::state',
        );

        // Employee portal (Phase 11) — authenticated, self-scoped.
        // We mount these at `/api/v1/me/...` so the SPA doesn't need
        // to know the caller's employee id. Static "me" segment
        // precedes the existing clinic dynamic routes below.
        $routes->group('api/v1/me', ['namespace' => 'Modules\\Clinic\\Controllers', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
            $r->get('employee-profile',  'EmployeeSelfController::profile');
            $r->post('employee-profile', 'EmployeeSelfController::updateProfile');
            $r->get('clinic-visits',     'EmployeeSelfController::clinicVisits');

            // Student portal (Phase 13) — same shape, separate
            // controller so the staff and student surfaces stay
            // independent. The `/me/student-*` paths are siblings
            // of the `/me/employee-*` paths, never nested.
            $r->get('student-profile',  'StudentSelfController::profile');
            $r->get('student-clinic-visits', 'StudentSelfController::clinicVisits');
        });

        $routes->group('api/v1/clinic', ['namespace' => 'Modules\\Clinic\\Controllers', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
            $r->get('encounters',                            'ClinicController::listEncounters');
            $r->post('encounters',                           'ClinicController::createEncounter');
            $r->post('encounters/import',                    'ClinicController::importEncounters');
            $r->post('encounters/(:num)/vitals',             'ClinicController::recordVitals/$1');
            $r->post('encounters/(:num)/close',              'ClinicController::closeEncounter/$1');
            $r->post('encounters/(:num)/assessment',         'ClinicController::setAssessment/$1');
            $r->get('encounters/(:num)/treatments',          'ClinicController::listTreatments/$1');
            $r->post('encounters/(:num)/treatments',         'ClinicController::addTreatment/$1');

            // Triage assist (Phase P2a — deterministic heuristic).
            $r->post('triage/suggest',                       'ClinicController::suggestTriage');
            $r->post('triage/(:num)/decision',               'ClinicController::decideTriage/$1');

            // Inventory (Phase 8)
            $r->get('inventory',                             'InventoryController::listItems');
            $r->post('inventory',                            'InventoryController::createItem');
            $r->post('inventory/(:num)',                     'InventoryController::updateItem/$1');
            $r->post('inventory/(:num)/archive',             'InventoryController::archiveItem/$1');
            $r->post('inventory/(:num)/unarchive',           'InventoryController::unarchiveItem/$1');
            $r->post('inventory/(:num)/move',                'InventoryController::moveStock/$1');
            $r->post('inventory/(:num)/receive',             'InventoryController::receiveOrdered/$1');
            $r->get('inventory/(:num)/movements',            'InventoryController::listMovements/$1');

            // Appointments (Phase 9)
            $r->get('appointments',                          'AppointmentController::list');
            $r->post('appointments',                         'AppointmentController::schedule');
            $r->get('appointments/(:num)',                   'AppointmentController::show/$1');
            $r->post('appointments/(:num)',                   'AppointmentController::update/$1');
            $r->post('appointments/(:num)/transition',       'AppointmentController::transition/$1');

            // Patient registry (Phase 11 — recycled from synapse_ag)
            $r->get('students',                              'PatientController::listStudents');
            $r->get('students/search',                       'PatientController::searchStudents');
            $r->post('students',                             'PatientController::createStudent');
            $r->get('students/(:num)',                       'PatientController::showStudent/$1');
            $r->post('students/(:num)',                      'PatientController::updateStudent/$1');
            $r->post('students/(:num)/archive',              'PatientController::setStudentArchived/$1');
            $r->post('students/(:num)/allergies',            'PatientController::addAllergy/$1');
            $r->post('students/(:num)/contacts',             'PatientController::addContact/$1');
            $r->get('employees',                             'PatientController::listEmployees');
            $r->get('employees/search',                      'PatientController::searchEmployees');
            $r->post('employees/sync-hr',                    'PatientController::syncHrEmployees');
            $r->post('employees',                            'PatientController::createEmployee');
            $r->get('employees/(:num)',                      'PatientController::showEmployee/$1');
            $r->post('employees/(:num)',                     'PatientController::updateEmployee/$1');
            $r->post('employees/(:num)/archive',             'PatientController::setEmployeeArchived/$1');
            $r->get('departments',                           'PatientController::listDepartments');
            $r->post('departments',                          'PatientController::createDepartment');

            // Staff schedules (Phase P5b — recycled from synapse_ag).
            $r->get('staff-schedules',                       'StaffScheduleController::list');
            $r->post('staff-schedules',                      'StaffScheduleController::create');
            $r->post('staff-schedules/(:num)',               'StaffScheduleController::update/$1');
            $r->post('staff-schedules/(:num)/archive',       'StaffScheduleController::archive/$1');
            $r->post('staff-schedules/(:num)/unarchive',     'StaffScheduleController::unarchive/$1');

            // Medicines (Phase 12 — recycled from synapse_ag). Static
            // segments MUST precede the (:num) catch-alls.
            $r->get('medicines/low-stock',                   'MedicineController::lowStock');
            $r->get('medicines/expiring',                    'MedicineController::expiring');
            $r->get('medicines',                             'MedicineController::list');
            $r->post('medicines',                            'MedicineController::create');
            $r->get('medicines/(:num)',                      'MedicineController::show/$1');
            $r->post('medicines/(:num)',                     'MedicineController::update/$1');
            $r->post('medicines/(:num)/archive',             'MedicineController::archive/$1');
            $r->post('medicines/(:num)/unarchive',           'MedicineController::unarchive/$1');
            $r->post('medicines/(:num)/batches',             'MedicineController::addBatch/$1');
            $r->post('medicines/(:num)/dispense',            'MedicineController::dispense/$1');
            $r->get('medicines/(:num)/transactions',         'MedicineController::transactions/$1');
            $r->post('medicines/(:num)/forecast',            'MedicineController::computeForecast/$1');
            $r->get('medicines/(:num)/forecast',             'MedicineController::getForecast/$1');

            // Reorders (Phase 13 — procurement workflow).
            $r->get('reorders',                              'ReorderController::list');
            $r->post('reorders',                             'ReorderController::create');
            $r->post('reorders/auto-check',                  'ReorderController::autoCheck');
            $r->post('reorders/(:num)/transition',           'ReorderController::transition/$1');

            // Queue (Phase 14 — walk-in queue; `state` is public above).
            $r->get('queue',                                 'QueueController::today');
            $r->post('queue',                                'QueueController::enqueue');
            $r->post('queue/call-next',                      'QueueController::callNext');
            $r->post('queue/(:num)/transition',              'QueueController::transition/$1');

            // Check-in kiosk (Phase 17 — recycled from synapse_ag IoT).
            $r->get('checkins',                              'CheckinController::listToday');
            $r->post('checkins',                             'CheckinController::scan');
        });
    }
}