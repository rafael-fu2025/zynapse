<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Services\Kiosk\KioskSettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class KioskSettingsValidationTest extends TestCase
{
    public function testDefaultsPassServerValidation(): void
    {
        self::validate(self::defaults());
        $this->addToAssertionCount(1);
    }

    public function testUnsafeMediaUrlIsRejected(): void
    {
        $settings = self::defaults();
        $settings['playlist'][] = [
            'id' => 'photo-1', 'type' => 'photo', 'label' => 'Unsafe',
            'source' => 'javascript:alert(1)', 'enabled' => true, 'order' => 0,
        ];
        $this->expectException(ApiException::class);
        self::validate($settings);
    }

    public function testDuplicatePlaylistIdsAreRejected(): void
    {
        $settings = self::defaults();
        $settings['playlist'] = [
            ['id' => 'same', 'type' => 'photo', 'source' => '/one.jpg'],
            ['id' => 'same', 'type' => 'video', 'source' => '/two.mp4'],
        ];
        $this->expectException(ApiException::class);
        self::validate($settings);
    }

    /** @return array<string,mixed> */
    private static function defaults(): array
    {
        $method = new ReflectionMethod(KioskSettingsService::class, 'defaults');
        /** @var array<string,mixed> */
        return $method->invoke(null);
    }

    /** @param array<string,mixed> $settings */
    private static function validate(array $settings): void
    {
        $method = new ReflectionMethod(KioskSettingsService::class, 'validate');
        $method->invoke(null, $settings);
    }
}
