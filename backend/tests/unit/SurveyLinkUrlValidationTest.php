<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ApiException;
use Modules\Counselling\Services\SurveyService;
use PHPUnit\Framework\TestCase;

/**
 * External-link URL validation (dynamic survey links, 2026-10) — the
 * stored-data half of the SSRF posture: only public http(s) targets
 * ever enter the database, so any future server-side fetch starts from
 * vetted data. Pure static — no framework boot required.
 */
final class SurveyLinkUrlValidationTest extends TestCase
{
    public function testAcceptsPublicHttpUrls(): void
    {
        self::assertSame('https://example.org/mi-test', SurveyService::validateExternalUrl(' https://example.org/mi-test '));
        self::assertSame('http://forms.example.edu/survey?id=7', SurveyService::validateExternalUrl('http://forms.example.edu/survey?id=7'));
        self::assertSame('https://docs.google.com/forms/d/e/1FAIpQL/viewform', SurveyService::validateExternalUrl('https://docs.google.com/forms/d/e/1FAIpQL/viewform'));
        self::assertSame('https://[2606:4700::6810:85e5]/x', SurveyService::validateExternalUrl('https://[2606:4700::6810:85e5]/x'));
    }

    public function testRejectsNonHttpSchemes(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html;base64,PGI+', 'ftp://files.example.org/x', 'file:///etc/passwd'] as $url) {
            $this->assertRejected($url, 'scheme');
        }
    }

    public function testRejectsIncompleteUrls(): void
    {
        foreach (['', null, '   ', 'example.com/form', 'not a url', 'https://', str_repeat('https://example.org/aaaa/bbbb/', 60)] as $url) {
            $this->assertRejected($url, 'shape/length');
        }
    }

    public function testRejectsEmbeddedCredentials(): void
    {
        $this->assertRejected('https://user:pass@example.org/form', 'credentials');
    }

    public function testRejectsLoopbackAndPrivateHosts(): void
    {
        foreach ([
            'https://localhost/x',
            'https://localhost:8080/x',
            'https://myhost.localhost/x',
            'http://127.0.0.1/x',
            'http://[::1]/x',
            'http://10.1.2.3/x',
            'http://172.16.0.9/x',
            'http://192.168.1.10/x',
            'http://169.254.169.254/latest/meta-data',
            'http://0.0.0.0/x',
        ] as $url) {
            $this->assertRejected($url, 'loopback/private/reserved');
        }
    }

    public function testRejectsSingleLabelAndDottedNumericHosts(): void
    {
        // Single-label intranet names and octal-IP lookalikes resolve
        // only inside the campus network — refused at the door.
        foreach (['https://intranet/form', 'http://counseling/x', 'http://0177.0.0.1/x', 'http://2130706433/x'] as $url) {
            $this->assertRejected($url, 'single-label/dotted-numeric');
        }
    }

    /**
     * @param mixed $url
     */
    private function assertRejected(mixed $url, string $why): void
    {
        try {
            SurveyService::validateExternalUrl($url);
            $this->fail(sprintf('Expected rejection (%s) for: %s', $why, var_export($url, true)));
        } catch (ApiException) {
            $this->addToAssertionCount(1);
        }
    }
}
