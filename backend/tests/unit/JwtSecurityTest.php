<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\JwtService;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JwtSecurityTest extends TestCase
{
    private string|false $previousSecret;
    private array $previousConfig;
    private JwtService $jwt;

    protected function setUp(): void
    {
        $this->previousSecret = getenv('JWT_SECRET');
        putenv('JWT_SECRET=' . str_repeat('test-only-secret-', 4));
        $cfg = config('Config\\Auth');
        $this->previousConfig = [$cfg->jwtAlgorithm, $cfg->jwtIssuer, $cfg->jwtAudience, $cfg->jwtAccessTtl];
        $cfg->jwtAlgorithm = 'HS256';
        $cfg->jwtIssuer = 'https://api.example.test/';
        $cfg->jwtAudience = 'synapse-api';
        $cfg->jwtAccessTtl = 900;
        $this->jwt = new JwtService();
    }

    protected function tearDown(): void
    {
        putenv($this->previousSecret === false ? 'JWT_SECRET' : 'JWT_SECRET=' . $this->previousSecret);
        $cfg = config('Config\\Auth');
        [$cfg->jwtAlgorithm, $cfg->jwtIssuer, $cfg->jwtAudience, $cfg->jwtAccessTtl] = $this->previousConfig;
    }

    public function testSessionDeadlineCapsSignedExpiry(): void
    {
        $deadline = time() + 60;
        $claims = $this->jwt->verify($this->jwt->sign(42, 3, ['session_exp' => $deadline]));
        self::assertSame($deadline, $claims['exp']);
        self::assertSame($deadline, $claims['session_exp']);
        self::assertSame('42', $claims['sub']);
        self::assertSame(3, $claims['epoch']);
        self::assertSame('synapse-api', $claims['aud']);
    }

    public function testExpiredSessionCannotBeSigned(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->jwt->sign(42, 0, ['session_exp' => time()]);
    }

    #[DataProvider('reservedClaims')]
    public function testReservedClaimsCannotBeOverridden(string $claim): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->jwt->sign(42, 0, [$claim => 'override']);
    }

    public static function reservedClaims(): array
    {
        return array_map(static fn (string $key): array => [$key], ['iss', 'aud', 'sub', 'iat', 'exp', 'jti', 'epoch']);
    }

    #[DataProvider('invalidClaims')]
    public function testRejectsCorrectlySignedTokensWithInvalidClaims(string $claim, mixed $value, bool $remove = false): void
    {
        $claims = $this->jwt->verify($this->jwt->sign(42));
        if ($remove) {
            unset($claims[$claim]);
        } else {
            $claims[$claim] = $value;
        }
        $token = JWT::encode($claims, (string) getenv('JWT_SECRET'), 'HS256');
        $this->expectException(\UnexpectedValueException::class);
        $this->jwt->verify($token);
    }

    public static function invalidClaims(): array
    {
        return [
            'wrong issuer' => ['iss', 'https://other.example.test/'],
            'wrong audience' => ['aud', 'other-api'],
            'missing audience' => ['aud', null, true],
            'missing expiration' => ['exp', null, true],
            'string expiration' => ['exp', '9999999999'],
            'missing issued time' => ['iat', null, true],
            'negative subject' => ['sub', '-1'],
            'numeric subject' => ['sub', 42],
            'overflow subject' => ['sub', '999999999999999999999999999999'],
            'empty token id' => ['jti', ''],
            'negative epoch' => ['epoch', -1],
            'string epoch' => ['epoch', '0'],
            'expired family' => ['session_exp', 1],
            'string family expiry' => ['session_exp', '9999999999'],
        ];
    }

    public function testAudienceListContainingExpectedAudienceIsAccepted(): void
    {
        $claims = $this->jwt->verify($this->jwt->sign(42));
        $claims['aud'] = ['other-api', 'synapse-api'];
        $token = JWT::encode($claims, (string) getenv('JWT_SECRET'), 'HS256');
        self::assertSame('42', $this->jwt->verify($token)['sub']);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $parts = explode('.', $this->jwt->sign(42));
        $parts[2][0] = $parts[2][0] === 'A' ? 'B' : 'A';
        $this->expectException(\UnexpectedValueException::class);
        $this->jwt->verify(implode('.', $parts));
    }

    public function testShortSigningAndRefreshHashKeysFailClosed(): void
    {
        putenv('JWT_SECRET=short');
        $this->expectException(\RuntimeException::class);
        $this->jwt->issueRefreshToken();
    }

    public function testUnsupportedAlgorithmFailsClosed(): void
    {
        config('Config\\Auth')->jwtAlgorithm = 'RS256';
        $this->expectException(\RuntimeException::class);
        $this->jwt->sign(42);
    }
}
