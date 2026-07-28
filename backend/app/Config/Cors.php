<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * CORS — strict origin allowlist. Wildcards are PROHIBITED in production.
 *
 * Shape matches `CodeIgniter\Filters\Cors` expectations (`$default`).
 * Origins are read from the comma-separated `CORS_ALLOWED_ORIGINS` env;
 * a literal `*` empties the allowlist (deny-all beats allow-all).
 */
class Cors extends BaseConfig
{
    /**
     * @var array{
     *      allowedOrigins: list<string>,
     *      allowedOriginsPatterns: list<string>,
     *      supportsCredentials: bool,
     *      allowedHeaders: list<string>,
     *      exposedHeaders: list<string>,
     *      allowedMethods: list<string>,
     *      maxAge: int,
     *  }
     */
    public array $default = [
        'allowedOrigins'         => [],
        'allowedOriginsPatterns' => [],

        // Allow credentials so the HttpOnly refresh cookie can be sent.
        'supportsCredentials'    => true,

        'allowedHeaders'         => ['Authorization', 'Content-Type', 'Accept', 'X-Request-Id', 'X-Idempotency-Key'],

        // NEVER expose Authorization back.
        'exposedHeaders'         => ['X-Request-Id', 'X-RateLimit-Remaining'],

        'allowedMethods'         => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

        // Cache preflight result for 1 day.
        'maxAge'                 => 86400,
    ];

    public function __construct()
    {
        parent::__construct();

        $raw = getenv('CORS_ALLOWED_ORIGINS') ?: 'http://localhost:5173';

        $origins = array_values(array_filter(array_map(
            static fn (string $o): string => trim($o),
            explode(',', (string) $raw)
        )));

        // Production safety: forbid literal `*`.
        if (in_array('*', $origins, true)) {
            $origins = [];
        }

        $this->default['allowedOrigins'] = $origins;
    }
}
