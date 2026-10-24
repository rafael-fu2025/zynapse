<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\TestResponse;

/**
 * Carousel CRUD + upload validation over the real routes.
 *
 * Pins the upload hardening added 2026-10: the application-level size cap
 * (CAROUSEL_MAX_UPLOAD_BYTES, default 5 MB) in front of the MIME sniff,
 * and the exec-denying .htaccess materialized in the uploads directory.
 */
final class CarouselRoutesTest extends FeatureTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var array{token:string, userId:int, email:string} */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->login(['superadmin']);
    }

    /**
     * Seed a multipart image for the next call() — same superglobal path
     * the screenshot tests use (see SurveyLinkTest::upload). The request
     * body itself is JSON, which CarouselController::collectData() merges
     * over $_POST, so text fields travel as JSON while the file travels
     * in $_FILES.
     */
    private function seedImage(string $contents, string $name, ?int $declaredSize = null): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'syn_car');
        $this->assertIsString($tmp);
        file_put_contents($tmp, $contents);

        service('superglobals')->setFilesArray([
            'light_image' => [
                'name'     => $name,
                'type'     => 'image/png',
                'tmp_name' => $tmp,
                'error'    => 0,
                'size'     => $declaredSize ?? strlen($contents),
            ],
        ]);
    }

    private function createSlide(string $title, string $contents = 'not an image', string $name = 'x.png', ?int $declaredSize = null): TestResponse
    {
        $this->seedImage($contents === 'png' ? (string) base64_decode(self::PNG, true) : $contents, $name, $declaredSize);

        return $this->authed($this->admin['token'], 'post', 'api/v1/admin/carousel', ['title' => $title]);
    }

    public function testCarouselAdminRequiresRbacManage(): void
    {
        $student = $this->login(['student']);

        $this->authed($student['token'], 'get', 'api/v1/admin/carousel')->assertStatus(403);
    }

    public function testCreateWithValidPngPersistsSlide(): void
    {
        $res = $this->createSlide('Valid', 'png', 'valid.png');
        $res->assertStatus(201);

        $slide = $this->envelope($res)['data'];
        $this->assertSame('Valid', $slide['title']);
        $this->assertMatchesRegularExpression('#\Auploads/carousel/slide_light_[0-9a-f]{32}\.png\z#', (string) $slide['light_image']);
    }

    public function testCreateRejectsOversizeUpload(): void
    {
        // Declared multipart size trips the cap without materializing 6 MB
        // of bytes (same trick as the screenshot oversize test).
        $this->createSlide('Big', 'png', 'big.png', 6 * 1024 * 1024)->assertStatus(422);
    }

    public function testCreateRejectsNonImageContent(): void
    {
        $this->createSlide('Fake', 'definitely not an image', 'fake.png')->assertStatus(422);
    }

    public function testToggleReorderAndDeleteRoundTrip(): void
    {
        $a = $this->envelope($this->createSlide('A', 'png', 'a.png'))['data'];
        $b = $this->envelope($this->createSlide('B', 'png', 'b.png'))['data'];

        // Toggle A off.
        $this->authed($this->admin['token'], 'post', "api/v1/admin/carousel/{$a['id']}/toggle")->assertStatus(200);

        // Public list shows only the active slide.
        $public = $this->call('get', 'api/v1/auth/carousel-slides');
        $public->assertStatus(200);
        $titles = array_column($this->envelope($public)['data'], 'title');
        $this->assertContains('B', $titles);
        $this->assertNotContains('A', $titles);

        // Reorder B before A, then delete B.
        $this->authed($this->admin['token'], 'post', 'api/v1/admin/carousel/reorder', ['ordered_ids' => [$b['id'], $a['id']]])->assertStatus(200);
        $this->authed($this->admin['token'], 'post', "api/v1/admin/carousel/{$b['id']}/delete")->assertStatus(200);
    }

    public function testUploadDirectoryCarriesExecDenyHtaccess(): void
    {
        $this->createSlide('Ht', 'png', 'ht.png')->assertStatus(201);

        $ht = FCPATH . 'uploads/carousel/.htaccess';
        $this->assertFileExists($ht);
        $content = (string) file_get_contents($ht);
        $this->assertStringContainsString('Require all denied', $content);
        $this->assertStringContainsString('php_flag engine off', $content);
    }
}
