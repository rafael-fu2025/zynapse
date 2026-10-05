<?php

declare(strict_types=1);

namespace Modules\Facilities;

use App\Modules\Shared\BaseRoutes;
use CodeIgniter\Router\RouteCollection;

final class Routes implements BaseRoutes
{
    public static function register(RouteCollection $routes): void
    {
        $routes->group('api/v1/facilities', ['namespace' => 'Modules\\Facilities\\Controllers', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
            $r->get('units',                          'BmgController::listUnits');
            $r->post('units',                         'BmgController::createUnit');
            $r->post('units/(:num)',                  'BmgController::updateUnit/$1');
            $r->delete('units/(:num)',                'BmgController::archiveUnit/$1');
            // POST variant of archive — carries the optional
            // `relocate_device_to_unit_id` body for one-step ESP32
            // relocation when a drum is retired.
            $r->post('units/(:num)/archive',          'BmgController::archiveUnit/$1');
            $r->post('units/(:num)/unarchive',        'BmgController::unarchiveUnit/$1');
            $r->post('units/(:num)/start',            'BmgController::startBatch/$1');
            $r->post('units/(:num)/maintenance',      'BmgController::setUnitMaintenance/$1');
            $r->get('units/suggest',                  'BmgController::suggestUnit');
            $r->get('batches',                        'BmgController::listBatches');
            $r->get('batches/active',                 'BmgController::listActiveBatches');
            $r->get('batches/(:num)/compliance',      'BmgController::batchCompliance/$1');
            $r->get('batches/(:num)/blend-cn',        'BmgController::blendCn/$1');
            $r->post('batches/(:num)/release',        'BmgController::releaseBatch/$1');
            $r->get('alerts/open',                    'BmgController::listOpenAlerts');
            $r->get('waste-categories/deviation',     'BmgController::wasteCategoryDeviation');
            $r->get('waste-categories',               'BmgController::listWasteCategories');
            $r->post('waste-categories',              'BmgController::createWasteCategory');
            $r->post('waste-categories/(:num)',       'BmgController::updateWasteCategory/$1');
            $r->post('waste-categories/(:num)/archive','BmgController::archiveWasteCategory/$1');
            $r->post('waste-categories/(:num)/unarchive','BmgController::unarchiveWasteCategory/$1');
            $r->delete('waste-categories/(:num)',     'BmgController::deleteWasteCategory/$1');
            $r->post('batches/(:num)/output',         'BmgController::recordOutput/$1');
            $r->post('batches/(:num)/finish',         'BmgController::finishBatch/$1');
            $r->post('batches/(:num)/cancel',         'BmgController::cancelBatch/$1');
            $r->post('batches/(:num)/update',         'BmgController::addBatchUpdate/$1');
            $r->get('batches/(:num)/updates',         'BmgController::listBatchUpdates/$1');
            $r->get('batches/(:num)/logs',            'BmgController::listProcessLogs/$1');
            $r->post('batches/(:num)/logs',           'BmgController::addProcessLog/$1');
            $r->get('batches/(:num)/alerts',          'BmgController::listAlerts/$1');
            $r->post('alerts/(:num)/acknowledge',     'BmgController::acknowledgeAlert/$1');
            $r->post('batches/(:num)/inputs',         'BmgController::addBatchInput/$1');
            $r->post('batches/(:num)/outputs',        'BmgController::addBatchOutput/$1');
            $r->post('batches/(:num)/losses',         'BmgController::addBatchLoss/$1');
            $r->get('batches/(:num)/losses',          'BmgController::listBatchLosses/$1');
            $r->get('batches/(:num)/analytics',       'BmgController::batchAnalytics/$1');

            // Device administration (mechanized tumbler). The device's
            // own INGEST surface lives under api/v1/devices with
            // device_auth; these are the human admin endpoints.
            $r->get('devices',                        'BmgController::listDevices');
            $r->post('devices',                       'BmgController::createDevice');
            $r->post('devices/(:num)',                'BmgController::updateDevice/$1');
            $r->delete('devices/(:num)',              'BmgController::archiveDevice/$1');
            $r->post('devices/(:num)/status',         'BmgController::setDeviceStatus/$1');
            $r->post('devices/(:num)/regenerate-token','BmgController::regenerateDeviceToken/$1');
        });

        // Automated BMG hardware (mechanized tumbler): static device
        // token via DeviceAuthFilter, disjoint from the human JWT
        // surface. Deliberately OUTSIDE the api_auth group above.
        $routes->group('api/v1/devices', ['namespace' => 'Modules\\Facilities\\Controllers', 'filter' => 'device_auth'], static function (RouteCollection $r): void {
            $r->post('bmg/turn-sessions', 'DeviceTurnSessionController::store');
            // Outbound command channel — the board polls for queued
            // commands (batch start) and ACKs after actuating.
            $r->get('bmg/commands',       'DeviceCommandController::index');
            $r->post('bmg/commands/ack',  'DeviceCommandController::ack');
        });
    }
}