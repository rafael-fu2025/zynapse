<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * FuMis — Foundation University MIS API (integration 2026-09).
 *
 * The MIS API is campus-network-only: it cannot be reached from outside
 * the university WiFi, and Synapse calls it strictly server-side (the
 * Website/IP restriction applies — never expose the API key to a client).
 *
 * `enabled` is the dev/prod toggle:
 *   false (dev default) → login stays on the local email/password path
 *       with the demo seeders; you can develop off-campus.
 *   true  (production)  → student/employee-number logins are delegated
 *       to the MIS API (full delegation, JIT provisioning).
 *
 * Docs: zynapseV2/synapse_v2_docs/MIS-API-DOCUMENTATION/markdown/.
 */
class FuMis extends BaseConfig
{
    /** Sandbox base; the production base URL replaces this when issued. */
    public string $baseUrl = 'https://mis.foundationu.com/sandbox';

    /** Issued by the MIS Department; server-side secret, never committed. */
    public string $apiKey = '';

    /** Master switch for MIS-delegated authentication. */
    public bool $enabled = false;

    /** Outbound call budget. Fail fast — login latency is user-facing. */
    public int $timeoutSeconds = 10;

    /** How long a cached MIS token pair may serve lookups before refresh. */
    public int $tokenCacheSeconds = 600;

    public function __construct()
    {
        parent::__construct();

        $env = static function (string $k, $default) {
            if (isset($_ENV[$k]) && $_ENV[$k] !== '') {
                return $_ENV[$k];
            }
            if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') {
                return $_SERVER[$k];
            }
            $val = getenv($k);
            return ($val !== false && $val !== '') ? $val : $default;
        };

        $this->baseUrl           = (string) $env('FUMIS_BASE_URL', $this->baseUrl);
        $this->apiKey            = (string) $env('FUMIS_API_KEY', $this->apiKey);
        $this->enabled           = filter_var($env('FUMIS_ENABLED', $this->enabled ? '1' : '0'), FILTER_VALIDATE_BOOL);
        $this->timeoutSeconds    = max(2, (int) $env('FUMIS_TIMEOUT_SECONDS', $this->timeoutSeconds));
        $this->tokenCacheSeconds = max(60, (int) $env('FUMIS_TOKEN_CACHE_SECONDS', $this->tokenCacheSeconds));
    }
}
