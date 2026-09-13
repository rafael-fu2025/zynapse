<?php

declare(strict_types=1);

namespace Modules\External\Services;

use App\Auth\ApiKeyContext;
use Config\ExternalApps;
use Config\Services;

/**
 * ExternalAudit — sampled `external.request` audit events (D8).
 *
 * Shared by the HTTP filter path (ExternalV1Controller) and the sandbox
 * executor so both surfaces audit identically. Sampling rate is per key
 * env (Config\ExternalApps::$auditSampleRates): live traffic is fully
 * audited, sandbox is sampled. Only the key PREFIX is ever recorded —
 * never the secret or its hash.
 */
final class ExternalAudit
{
    public static function log(
        string $endpoint,
        string $httpMethod,
        int $httpStatus,
        string $outcome,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        if (! ApiKeyContext::bound()) {
            return;
        }

        $config = config(ExternalApps::class);
        $rate = $config->auditSampleRates[ApiKeyContext::env()] ?? 1.0;
        if ($rate < 1.0 && random_int(1, 10000) > (int) round($rate * 10000)) {
            return;
        }

        Services::auditOutbox()->enqueue('external.request', 'api_keys', ApiKeyContext::keyId(), null, [
            'app_id'      => ApiKeyContext::appId(),
            'key_id'      => ApiKeyContext::keyId(),
            'key_env'     => ApiKeyContext::env(),
            'key_prefix'  => ApiKeyContext::prefix(),
            'endpoint'    => $endpoint,
            'http_method' => strtoupper($httpMethod),
            'http_status' => $httpStatus,
            'outcome'     => $outcome,
            'auth_method' => 'api_key',
            'ip_address'  => $ipAddress,
            'user_agent'  => $userAgent,
        ]);
    }
}
