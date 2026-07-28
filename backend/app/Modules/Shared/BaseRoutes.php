<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use CodeIgniter\Router\RouteCollection;

/**
 * BaseRoutes — interface every module's `Routes::register()` conforms to.
 *
 * Modules MUST NOT extend the route collection; they receive a reference
 * and attach their routes via the standard RouteCollection API. This
 * prevents cross-module coupling through inheritance.
 */
interface BaseRoutes
{
    public static function register(RouteCollection $routes): void;
}
