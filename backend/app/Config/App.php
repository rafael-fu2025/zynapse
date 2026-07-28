<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * App — SYNAPSE base application config (stock CI 4.7 property shape).
 *
 * NOTE (Phase 6 retrofit): CSRF settings live in `Config\Security`,
 * cookie settings in `Config\Cookie`, and CSP in
 * `Config\ContentSecurityPolicy`. CSRF stays disabled for the stateless
 * JWT API — the refresh cookie is protected by SameSite=Strict + HttpOnly.
 */
class App extends BaseConfig
{
    /**
     * Base URL — overridden via `app.baseURL` in `.env`.
     */
    public string $baseURL = 'http://localhost:8080/';

    /**
     * Allowed hostnames besides the baseURL host. None — single origin API.
     *
     * @var list<string>
     */
    public array $allowedHostnames = [];

    /**
     * No index page — clean URLs behind the front controller.
     */
    public string $indexPage = '';

    /**
     * URI resolution protocol.
     */
    public string $uriProtocol = 'REQUEST_URI';

    /**
     * Permitted URI characters.
     */
    public string $permittedURIChars = 'a-z 0-9~%.:_\-';

    public string $defaultLocale = 'en';

    public bool $negotiateLocale = false;

    /**
     * @var list<string>
     */
    public array $supportedLocales = ['en'];

    /**
     * Backend persistence timezone is STRICTLY UTC; `Asia/Manila` is a
     * render-side concern (frontend, date-fns-tz). See directive §2C.
     */
    public string $appTimezone = 'UTC';

    public string $charset = 'UTF-8';

    /**
     * Force HTTPS in production (enforced via ForceHTTPS filter/env).
     */
    public bool $forceGlobalSecureRequests = false;

    /**
     * @var array<string, string>
     */
    public array $proxyIPs = [];

    /**
     * CSP disabled — API returns JSON only; browsers never render it.
     */
    public bool $CSPEnabled = false;
}
