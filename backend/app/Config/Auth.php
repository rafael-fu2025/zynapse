<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\Auth as ShieldAuth;

class Auth extends ShieldAuth
{
    /**
     * Primary authenticator is JWT bearer token. Shield's session
     * authenticator is preserved for first-party browser fallback.
     */
    public array $authenticators = [
        'session' => \CodeIgniter\Shield\Authentication\Authenticators\Session::class,
        'tokens'  => \CodeIgniter\Shield\Authentication\Authenticators\AccessTokens::class,
        'jwt'     => \App\Auth\JwtAuthenticator::class,
    ];

    /** JWT specifics — driven by env, never hardcoded. */
    public int    $jwtAccessTtl  = 900;
    public int    $jwtRefreshTtl = 2592000;
    public string $jwtAlgorithm  = 'HS256';

    /** Refresh-token cookie configuration. */
    public string $refreshCookieName    = 'synapse_rt';
    public bool   $refreshCookieSecure  = true;
    public bool   $refreshCookieHttpOnly = true;
    public string $refreshCookieSameSite = 'Strict';

    public function __construct()
    {
        parent::__construct();

        $env = static fn (string $k, $default) => getenv($k) !== false ? getenv($k) : $default;

        $this->jwtAccessTtl        = (int) $env('JWT_ACCESS_TTL_SECONDS', 900);
        $this->jwtRefreshTtl       = (int) $env('JWT_REFRESH_TTL_SECONDS', 2592000);
        $this->jwtAlgorithm        = (string) $env('JWT_ALG', 'HS256');
        $this->refreshCookieName    = (string) $env('REFRESH_COOKIE_NAME', 'synapse_rt');
        $this->refreshCookieSecure  = (bool) $env('REFRESH_COOKIE_SECURE', '1');
        $this->refreshCookieHttpOnly = (bool) $env('REFRESH_COOKIE_HTTPONLY', '1');
        $this->refreshCookieSameSite = (string) $env('REFRESH_COOKIE_SAMESITE', 'Strict');
    }
}