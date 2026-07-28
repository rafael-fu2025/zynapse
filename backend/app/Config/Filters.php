<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;

use App\Filters\ApiAuthFilter;
use App\Filters\ApiRateLimitFilter;
use App\Filters\ApiRequestLoggerFilter;
use App\Filters\ApiExceptionFilter;

class Filters extends BaseFilters
{
    /**
     * Global filters — applied to every request reaching the API router.
     * `api` alias is the canonical filter set used by `Routes.php`.
     */
    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,

        // SYNAPSE-specific filters
        'api_auth'      => ApiAuthFilter::class,
        'api_ratelimit' => ApiRateLimitFilter::class,
        'api_log'       => ApiRequestLoggerFilter::class,
        'api_exc'       => ApiExceptionFilter::class,
    ];

    /**
     * URI-pattern filters. `api/*` matches every `/api/v1/...` route
     * (a bare `api` would match only the literal URI `api` — Phase 6 fix).
     *
     * `api_auth` is intentionally NOT applied here: public endpoints
     * (login, refresh, health, referrals/verify) must stay reachable,
     * so authentication is attached per route-group in `Routes.php`.
     */
    public array $filters = [
        'api_exc'       => ['after'  => ['api/*']],
        'api_log'       => ['after'  => ['api/*']],
        'api_ratelimit' => ['before' => ['api/*']],
    ];

    public array $globals = [
        'before' => [
            'cors',
            'secureheaders',
        ],
        'after' => [
            'api_exc',
        ],
    ];

    public array $methods = [];

    public array $filtersDeprecated = [];
}
