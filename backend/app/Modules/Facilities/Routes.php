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
            $r->get('sop-documents',                  'BmgController::listSopDocuments');
            $r->post('sop-documents',                 'BmgController::createSopDocument');
            $r->post('sop-documents/(:num)',          'BmgController::updateSopDocument/$1');
            $r->get('waste-categories',               'BmgController::listWasteCategories');
            $r->post('waste-categories',              'BmgController::createWasteCategory');
            $r->post('waste-categories/(:num)',       'BmgController::updateWasteCategory/$1');
            $r->post('waste-categories/(:num)/archive','BmgController::archiveWasteCategory/$1');
            $r->post('waste-categories/(:num)/unarchive','BmgController::unarchiveWasteCategory/$1');
            $r->delete('waste-categories/(:num)',     'BmgController::deleteWasteCategory/$1');
            $r->post('batches/(:num)/output',         'BmgController::recordOutput/$1');
            $r->post('batches/(:num)/finish',         'BmgController::finishBatch/$1');
            $r->post('batches/(:num)/cancel',         'BmgController::cancelBatch/$1');
            $r->post('batches/(:num)/curing',         'BmgController::moveToCuring/$1');
            $r->get('batches/(:num)/logs',            'BmgController::listProcessLogs/$1');
            $r->post('batches/(:num)/logs',           'BmgController::addProcessLog/$1');
            $r->get('batches/(:num)/alerts',          'BmgController::listAlerts/$1');
            $r->post('alerts/(:num)/acknowledge',     'BmgController::acknowledgeAlert/$1');
            $r->post('batches/(:num)/inputs',         'BmgController::addBatchInput/$1');
            $r->post('batches/(:num)/outputs',        'BmgController::addBatchOutput/$1');
            $r->post('batches/(:num)/losses',         'BmgController::addBatchLoss/$1');
            $r->get('batches/(:num)/losses',          'BmgController::listBatchLosses/$1');
            $r->get('batches/(:num)/analytics',       'BmgController::batchAnalytics/$1');
        });
    }
}