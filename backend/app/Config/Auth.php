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
    public string $jwtIssuer     = '';
    public string $jwtAudience   = 'synapse-api';

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
        $this->jwtIssuer           = (string) $env('JWT_ISSUER', '');
        $this->jwtAudience         = (string) $env('JWT_AUDIENCE', 'synapse-api');
        $this->refreshCookieName    = (string) $env('REFRESH_COOKIE_NAME', 'synapse_rt');
        $this->refreshCookieSecure  = filter_var($env('REFRESH_COOKIE_SECURE', '1'), FILTER_VALIDATE_BOOL);
        $this->refreshCookieHttpOnly = true;
        $this->refreshCookieSameSite = (string) $env('REFRESH_COOKIE_SAMESITE', 'Strict');
    }

    public static function __set_state(array $array)
    {
        $obj = new static();
        return parent::__set_state(array_merge(get_object_vars($obj), $array));
    }
}