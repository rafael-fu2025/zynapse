<?php

declare(strict_types=1);

namespace Config;

/**
 * Boot — SYNAPSE security preflight.
 *
 * `assertSecurityPosture()` is invoked from `app/Config/Constants.php`,
 * which the framework loads (web AND spark) after `.env` is parsed and
 * `CI_ENVIRONMENT` is defined. Misconfiguration is non-negotiable:
 *
 *   - Weak/unsupported signing configuration or invalid encryption key
 *   - Insecure HTTPS/refresh-cookie configuration
 *   - Missing CORS allowlist or non-HTTPS/wildcard origins
 */
class Boot
{
    public static function assertSecurityPosture(): void
    {
        if (! defined('ENVIRONMENT')) {
            return; // Called outside a framework boot (e.g. unit tests).
        }

        $errors = ENVIRONMENT === 'production' ? self::productionErrors([
            'JWT_SECRET' => getenv('JWT_SECRET') ?: '',
            'JWT_ALG' => getenv('JWT_ALG') ?: 'HS256',
            'JWT_SECRET_NEXT' => getenv('JWT_SECRET_NEXT') ?: '',
            'JWT_AUDIENCE' => getenv('JWT_AUDIENCE') === false ? 'synapse-api' : getenv('JWT_AUDIENCE'),
            'REFRESH_HMAC_KEY' => getenv('REFRESH_HMAC_KEY') ?: '',
            'CORS_ALLOWED_ORIGINS' => getenv('CORS_ALLOWED_ORIGINS') ?: '',
            'COUNSELLING_KEY' => getenv('COUNSELLING_KEY') ?: '',
            'MFA_KEY' => getenv('MFA_KEY') ?: '',
            'MFA_ENFORCE_FOR_PRIVILEGED' => getenv('MFA_ENFORCE_FOR_PRIVILEGED') === false
                ? 'true' : getenv('MFA_ENFORCE_FOR_PRIVILEGED'),
            'app.baseURL' => getenv('app.baseURL') ?: '',
            'app.forceGlobalSecureRequests' => getenv('app.forceGlobalSecureRequests') === false
                ? 'true' : getenv('app.forceGlobalSecureRequests'),
            'REFRESH_COOKIE_SECURE' => getenv('REFRESH_COOKIE_SECURE') === false ? 'true' : getenv('REFRESH_COOKIE_SECURE'),
            'REFRESH_COOKIE_SAMESITE' => getenv('REFRESH_COOKIE_SAMESITE') === false ? 'Strict' : getenv('REFRESH_COOKIE_SAMESITE'),
        ]) : [];

        if ($errors !== []) {
            $msg = '[SYNAPSE Boot] Hard fail: ' . implode(' | ', $errors);
            // Use stderr in CLI; on web we 503 immediately. We log AND
            // exit because misconfiguration is non-negotiable.
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, $msg . PHP_EOL);
                exit(1);
            }
            error_log($msg);
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'data'    => null,
                'errors'  => [['code' => 'boot.misconfigured', 'message' => 'Service misconfigured.']],
                'meta'    => null,
            ]);
            exit(1);
        }
    }

    /** Pure validation so preflight can run before framework services exist. */
    public static function productionErrors(array $settings): array
    {
        $errors = [];
        $minimum = ['HS256' => 32, 'HS384' => 48, 'HS512' => 64][$settings['JWT_ALG'] ?? 'HS256'] ?? null;
        if ($minimum === null || strlen((string) ($settings['JWT_SECRET'] ?? '')) < $minimum) {
            $errors[] = 'JWT_SECRET must meet the configured HMAC algorithm key length (32/48/64 bytes).';
        }
        // Rotation overlap window: optional, but if set it must be as strong
        // as the primary secret.
        if ((string) ($settings['JWT_SECRET_NEXT'] ?? '') !== ''
            && strlen((string) $settings['JWT_SECRET_NEXT']) < $minimum) {
            $errors[] = 'JWT_SECRET_NEXT must meet the configured HMAC algorithm key length while set.';
        }
        // Dedicated refresh HMAC key (G-6): optional; hex-validated when set.
        if ((string) ($settings['REFRESH_HMAC_KEY'] ?? '') !== ''
            && (! ctype_xdigit((string) $settings['REFRESH_HMAC_KEY']) || strlen((string) $settings['REFRESH_HMAC_KEY']) !== 64)) {
            $errors[] = 'REFRESH_HMAC_KEY must be a 64-char hex string while set.';
        }
        if (trim((string) ($settings['JWT_AUDIENCE'] ?? 'synapse-api')) === '') {
            $errors[] = 'JWT_AUDIENCE must not be empty in production.';
        }
        $base = (string) ($settings['app.baseURL'] ?? '');
        if (filter_var($base, FILTER_VALIDATE_URL) === false || parse_url($base, PHP_URL_SCHEME) !== 'https') {
            $errors[] = 'app.baseURL must be an HTTPS URL in production.';
        }
        foreach (['app.forceGlobalSecureRequests', 'REFRESH_COOKIE_SECURE'] as $setting) {
            if (filter_var($settings[$setting] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== true) {
                $errors[] = $setting . ' must be enabled in production.';
            }
        }
        if (($settings['REFRESH_COOKIE_SAMESITE'] ?? 'Strict') !== 'Strict') {
            $errors[] = 'REFRESH_COOKIE_SAMESITE must be Strict in production.';
        }
        $origins = array_filter(array_map('trim', explode(',', (string) ($settings['CORS_ALLOWED_ORIGINS'] ?? ''))));
        if ($origins === []) {
            $errors[] = 'CORS_ALLOWED_ORIGINS must be explicitly configured in production.';
        }
        foreach ($origins as $origin) {
            if (str_contains($origin, '*') || filter_var($origin, FILTER_VALIDATE_URL) === false
                || parse_url($origin, PHP_URL_SCHEME) !== 'https') {
                $errors[] = 'CORS_ALLOWED_ORIGINS must contain only explicit HTTPS origins in production.';
                break;
            }
        }
        $hex = (string) ($settings['COUNSELLING_KEY'] ?? '');
        if (strlen($hex) !== 64 || ! ctype_xdigit($hex)) {
            $errors[] = 'COUNSELLING_KEY must be a 64-char hex string in production.';
        }
        $mfaEnforced = filter_var($settings['MFA_ENFORCE_FOR_PRIVILEGED'] ?? 'true', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
        if ($mfaEnforced) {
            $mfaKey = (string) ($settings['MFA_KEY'] ?? '');
            if (strlen($mfaKey) !== 64 || ! ctype_xdigit($mfaKey)) {
                $errors[] = 'MFA_KEY must be a 64-char hex string in production while MFA_ENFORCE_FOR_PRIVILEGED is on (set MFA_ENFORCE_FOR_PRIVILEGED=false to consciously disable privileged MFA).';
            }
        }
        return $errors;
    }
}
