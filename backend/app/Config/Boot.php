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
 *   - JWT_SECRET missing in production
 *   - COUNSELLING_KEY missing or wrong length in production
 *   - Production with wildcard `CORS_ALLOWED_ORIGINS`
 */
class Boot
{
    public static function assertSecurityPosture(): void
    {
        if (! defined('ENVIRONMENT')) {
            return; // Called outside a framework boot (e.g. unit tests).
        }

        $errors = [];

        if (ENVIRONMENT === 'production') {
            $cors = (string) (getenv('CORS_ALLOWED_ORIGINS') ?: '');
            if (str_contains($cors, '*')) {
                $errors[] = 'CORS_ALLOWED_ORIGINS must not contain wildcards in production.';
            }
            if (! getenv('JWT_SECRET')) {
                $errors[] = 'JWT_SECRET must be set in production.';
            }
            $hex = (string) (getenv('COUNSELLING_KEY') ?: '');
            if (strlen($hex) !== 64) {
                $errors[] = 'COUNSELLING_KEY must be a 64-char hex string in production.';
            }
        }

        if ($errors !== []) {
            $msg = '[SYNAPSE Boot] Hard fail: ' . implode(' | ', $errors);
            // Use stderr in CLI; on web we 503 immediately. We log AND
            // exit because misconfiguration is non-negotiable.
            fwrite(STDERR, $msg . PHP_EOL);
            if (PHP_SAPI === 'cli') {
                exit(1);
            }
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
}
