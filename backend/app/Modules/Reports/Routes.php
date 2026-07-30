<?php

declare(strict_types=1);

namespace Modules\Reports;

use App\Modules\Shared\BaseRoutes;
use CodeIgniter\Router\RouteCollection;

final class Routes implements BaseRoutes
{
    public static function register(RouteCollection $routes): void
    {
        $routes->group('api/v1/reports', ['namespace' => 'Modules\\Reports\\Controllers', 'filter' => 'api_auth'], static function (RouteCollection $r): void {
            $r->get('summary',               'ReportController::summary');
            $r->get('export/(:segment)',     'ReportController::export/$1');

            // Saved & generated reports (Phase P6). Static segments MUST
            // precede the (:segment) module catch-all below.
            $r->get('configs',                   'ReportConfigController::listConfigs');
            $r->post('configs',                  'ReportConfigController::createConfig');
            $r->post('configs/(:num)/run',       'ReportConfigController::run/$1');
            $r->post('configs/(:num)/archive',   'ReportConfigController::archiveConfig/$1');
            $r->post('configs/(:num)/unarchive', 'ReportConfigController::unarchiveConfig/$1');
            $r->post('configs/(:num)',           'ReportConfigController::updateConfig/$1');
            $r->get('generated',                 'ReportConfigController::listGenerated');
            $r->get('generated/(:num)/download', 'ReportConfigController::download/$1');

            $r->get('(:segment)',            'ReportController::module/$1');
        });
    }
}
