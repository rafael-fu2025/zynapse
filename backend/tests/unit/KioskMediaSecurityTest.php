<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Services\Kiosk\KioskMediaService;
use PHPUnit\Framework\TestCase;

final class KioskMediaSecurityTest extends TestCase
{
    public function testApprovedPhotoAndVideoTypesAreClassifiedFromDetectedMime(): void
    {
        $this->assertSame(['kind' => 'photo', 'extension' => 'jpg'], KioskMediaService::classify('image/jpeg', 1024));
        $this->assertSame(['kind' => 'photo', 'extension' => 'webp'], KioskMediaService::classify('image/webp', 1024));
        $this->assertSame(['kind' => 'video', 'extension' => 'mp4'], KioskMediaService::classify('video/mp4', 1024));
        $this->assertSame(['kind' => 'video', 'extension' => 'webm'], KioskMediaService::classify('video/webm', 1024));
        $this->assertSame(['kind' => 'video', 'extension' => 'mp4'], KioskMediaService::classify('video/mp4', 2 * 1024 * 1024 * 1024));
    }

    /** @dataProvider rejectedUploads */
    public function testUnsafeTypesAndSizesAreRejected(string $mime, int $size): void
    {
        $this->expectException(ApiException::class);
        KioskMediaService::classify($mime, $size);
    }

    /** @return array<string,array{string,int}> */
    public static function rejectedUploads(): array
    {
        return [
            'svg can execute active content' => ['image/svg+xml', 1024],
            'php/script content' => ['text/x-php', 1024],
            'empty file' => ['image/png', 0],
        ];
    }

    public function testRoutesSeparatePublicContentFromAuthenticatedManagement(): void
    {
        $routes = $this->read('app/Config/Routes.php');
        $controller = $this->read('app/Controllers/Api/Admin/KioskMediaController.php');
        $service = $this->read('app/Services/Kiosk/KioskMediaService.php');
        $this->assertStringContainsString("api/v1/kiosk-media/(:segment)/content", $routes);
        $this->assertStringContainsString("api/v1/kiosk-media/(:segment)/thumbnail", $routes);
        $this->assertStringContainsString("options('api/v1/(:any)'", $routes);
        $this->assertStringContainsString("group('api/v1/admin'", $routes);
        $this->assertStringContainsString("authorize('kiosk.content.manage')", $controller);
        $this->assertStringNotContainsString("authorize('rbac.manage')", $controller);
        $this->assertStringContainsString("where('tenant_id', CurrentTenant::id())", $service);
        $this->assertStringNotContainsString('@unlink($path);\n        return $this->setArchived', $service);
    }

    private function read(string $path): string
    {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        $this->assertIsString($source);
        return $source;
    }
}
