<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FuMis\FuMisClient;
use App\Services\FuMis\FuMisException;
use App\Services\FuMis\HttpTransport;
use Config\FuMis;
use PHPUnit\Framework\TestCase;

/**
 * FuMisClient wire-format tests via an in-memory HttpTransport double.
 *
 * Pins the request shapes against the MIS API documentation (URL paths,
 * API-Key header, JSON payload keys) and the response contract checks
 * (missing token fields are contract failures, not silent nulls). The
 * double records requests so assertions run against what would actually
 * hit the wire.
 */
final class FuMisClientTest extends TestCase
{
    private FuMis $config;

    protected function setUp(): void
    {
        $this->config = new FuMis();
    }

    public function testStudentLoginSendsDocumentedWireFormat(): void
    {
        $double = new RecordingTransport([
            'data' => ['student_id' => '20261234'], 'access_token' => 'at',
            'refresh_token' => 'rt', 'expires_at' => '2026-09-08T12:00:00+08:00',
        ]);
        $client = new FuMisClient($this->config, $double);

        $result = $client->studentLogin('20261234', 'secret');

        $this->assertSame('20261234', $result['data']['student_id']);
        $req = $double->requests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertSame($this->config->baseUrl . '/api/v1/students/login', $req['url']);
        $this->assertSame(['API-Key' => $this->config->apiKey], $req['headers']);
        $this->assertSame(['student_id' => '20261234', 'password' => 'secret'], $req['body']);
    }

    public function testEmployeeLoginSendsDocumentedWireFormat(): void
    {
        $double = new RecordingTransport([
            'data' => ['employee_id' => '20269001'], 'access_token' => 'at',
            'refresh_token' => 'rt', 'expires_at' => '2026-09-08T12:00:00+08:00',
        ]);
        $client = new FuMisClient($this->config, $double);

        $client->employeeLogin('20269001', 'secret');

        $req = $double->requests[0];
        $this->assertSame($this->config->baseUrl . '/api/v1/employees/login', $req['url']);
        $this->assertSame(['employee_id' => '20269001', 'password' => 'secret'], $req['body']);
    }

    public function testListStudentsSendsBearerAndQuery(): void
    {
        $double = new RecordingTransport(['data' => [], 'limit' => 25, 'current_page' => 1, 'max_page' => 1]);
        $client = new FuMisClient($this->config, $double);

        $client->listStudents('the-access-token', ['type' => 'College', 'limit' => 25]);

        $req = $double->requests[0];
        $this->assertSame('GET', $req['method']);
        $this->assertSame(
            $this->config->baseUrl . '/api/v1/students?type=College&limit=25',
            $req['url'],
        );
        $this->assertSame(
            ['API-Key' => $this->config->apiKey, 'Authorization' => 'Bearer the-access-token'],
            $req['headers'],
        );
    }

    public function testTokenEndpointsFollowDocumentedShapes(): void
    {
        $double = new RecordingTransport(['refresh_token' => 'rt-1']);
        $client = new FuMisClient($this->config, $double);

        $generated = $client->generateToken();
        $this->assertSame('rt-1', $generated['refresh_token']);
        // generate is API-Key only — no body.
        $this->assertNull($double->requests[0]['body']);

        $double->queue(['access_token' => 'at-2', 'refresh_token' => 'rt-2', 'expires_at' => 'soon']);
        $refreshed = $client->refreshToken('rt-1');
        $this->assertSame('at-2', $refreshed['access_token']);
        $this->assertSame(
            ['API-Key' => $this->config->apiKey, 'Authorization' => 'Bearer rt-1'],
            $double->requests[1]['headers'],
        );
    }

    public function testMissingTokenFieldsAreContractFailures(): void
    {
        $double = new RecordingTransport(['access_token' => 'at']); // no refresh_token
        $client = new FuMisClient($this->config, $double);

        $this->expectException(FuMisException::class);
        $client->generateToken();
    }

    public function testTransportFailuresPropagate(): void
    {
        $double = new ThrowingTransport();
        $client = new FuMisClient($this->config, $double);

        try {
            $client->studentLogin('20261234', 'secret');
            $this->fail('FuMisException must propagate.');
        } catch (FuMisException $e) {
            $this->assertTrue($e->isTransportFailure());
            $this->assertSame(0, $e->upstreamStatus);
        }
    }

    public function testBaseUrlTrailingSlashIsTolerated(): void
    {
        $this->config->baseUrl = 'https://mis.foundationu.com/sandbox/';
        $double = new RecordingTransport(['data' => [], 'access_token' => 'at', 'refresh_token' => 'rt', 'expires_at' => 'x']);
        $client = new FuMisClient($this->config, $double);

        $client->studentLogin('1', 'p');

        $this->assertSame(
            'https://mis.foundationu.com/sandbox/api/v1/students/login',
            $double->requests[0]['url'],
        );
    }
}

/**
 * In-memory HttpTransport double: returns queued responses in order
 * (the single constructor response first, then any queue() entries).
 */
final class RecordingTransport implements HttpTransport
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:array<string,mixed>|null}> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    private array $queued = [];

    public function __construct(private readonly array $response)
    {
    }

    public function queue(array $response): void
    {
        $this->queued[] = $response;
    }

    public function request(
        string $method,
        string $url,
        array $headers,
        ?array $body = null,
        int $timeoutSeconds = 10,
    ): array {
        $this->requests[] = [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body,
        ];

        return array_shift($this->queued) ?? $this->response;
    }
}

final class ThrowingTransport implements HttpTransport
{
    public function request(
        string $method,
        string $url,
        array $headers,
        ?array $body = null,
        int $timeoutSeconds = 10,
    ): array {
        throw new FuMisException('fumis.transport', 0);
    }
}
