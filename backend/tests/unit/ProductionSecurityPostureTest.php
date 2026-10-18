<?php

declare(strict_types=1);

namespace Tests\Unit;

use Config\Boot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductionSecurityPostureTest extends TestCase
{
    private function secureSettings(): array
    {
        return [
            'JWT_SECRET' => str_repeat('test-only-secret-', 4),
            'JWT_ALG' => 'HS256',
            'JWT_AUDIENCE' => 'synapse-api',
            'app.baseURL' => 'https://api.example.test/',
            'app.forceGlobalSecureRequests' => 'true',
            'REFRESH_COOKIE_SECURE' => 'true',
            'REFRESH_COOKIE_SAMESITE' => 'Strict',
            'CORS_ALLOWED_ORIGINS' => 'https://app.example.test,https://admin.example.test',
            'COUNSELLING_KEY' => str_repeat('ab', 32),
        ];
    }

    public function testSecureProductionConfigurationPasses(): void
    {
        self::assertSame([], Boot::productionErrors($this->secureSettings()));
    }

    #[DataProvider('unsafeSettings')]
    public function testUnsafeProductionSettingsFailClosed(string $key, string $value): void
    {
        $settings = $this->secureSettings();
        $settings[$key] = $value;
        self::assertNotEmpty(Boot::productionErrors($settings));
    }

    public static function unsafeSettings(): array
    {
        return [
            ['JWT_SECRET', 'short'],
            ['JWT_ALG', 'none'],
            ['JWT_AUDIENCE', ''],
            ['app.baseURL', 'http://api.example.test/'],
            ['app.forceGlobalSecureRequests', 'false'],
            ['app.forceGlobalSecureRequests', 'invalid'],
            ['REFRESH_COOKIE_SECURE', 'false'],
            ['REFRESH_COOKIE_SAMESITE', 'None'],
            ['CORS_ALLOWED_ORIGINS', ''],
            ['CORS_ALLOWED_ORIGINS', '*'],
            ['CORS_ALLOWED_ORIGINS', 'https://*.example.test'],
            ['CORS_ALLOWED_ORIGINS', 'http://app.example.test'],
            ['COUNSELLING_KEY', str_repeat('zz', 32)],
        ];
    }

    public function testStrongerAlgorithmsRequireLongerKeys(): void
    {
        $settings = $this->secureSettings();
        $settings['JWT_ALG'] = 'HS512';
        $settings['JWT_SECRET'] = str_repeat('a', 32);
        self::assertNotEmpty(Boot::productionErrors($settings));
        $settings['JWT_SECRET'] = str_repeat('a', 64);
        self::assertSame([], Boot::productionErrors($settings));
    }
}
