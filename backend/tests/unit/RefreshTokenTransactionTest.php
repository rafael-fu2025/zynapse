<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\JwtService;
use App\Auth\RefreshTokenService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RefreshTokenTransactionTest extends TestCase
{
    public static function failedBegins(): iterable
    {
        yield 'issue' => ['issue', 1];
        yield 'rotate' => ['rotate', 'opaque-test-input'];
        yield 'logout' => ['revokeAllFor', 1];
    }

    #[DataProvider('failedBegins')]
    public function testBeginFailureNeverQueriesOrReturnsCredentials(string $operation, int|string $argument): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('transBegin')->willReturn(false);
        $db->expects($this->never())->method('query');
        $previous = getenv('JWT_SECRET');
        putenv('JWT_SECRET=' . bin2hex(random_bytes(32)));
        try {
            $this->expectException(RuntimeException::class);
            (new RefreshTokenService(new JwtService(), $db))->{$operation}($argument);
        } finally {
            putenv($previous === false ? 'JWT_SECRET' : 'JWT_SECRET=' . $previous);
        }
    }

    public static function failedWrites(): iterable
    {
        yield 'insert fails' => [false, true, true];
        yield 'transaction poisoned' => [true, false, true];
        yield 'commit fails' => [true, true, false];
    }

    #[DataProvider('failedWrites')]
    public function testFailedIssuanceRollsBackInsteadOfReturningMint(bool $insert, bool $status, bool $commit): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('transBegin')->willReturn(true);
        $db->method('transStatus')->willReturn($status);
        $db->expects($this->exactly($insert && $status ? 1 : 0))->method('transCommit')->willReturn($commit);
        $db->expects($this->once())->method('transRollback')->willReturn(true);
        $result = $this->createMock(BaseResult::class);
        $result->method('getRowArray')->willReturn(['token_epoch' => 0]);
        $db->method('query')->willReturn($result);
        $builder = $this->getMockBuilder(BaseBuilder::class)->disableOriginalConstructor()->onlyMethods(['insert'])->getMock();
        $builder->method('insert')->willReturn($insert);
        $db->method('table')->willReturn($builder);
        $previous = getenv('JWT_SECRET');
        putenv('JWT_SECRET=' . bin2hex(random_bytes(32)));
        try {
            $this->expectException(RuntimeException::class);
            (new RefreshTokenService(new JwtService(), $db))->issue(1);
        } finally {
            putenv($previous === false ? 'JWT_SECRET' : 'JWT_SECRET=' . $previous);
        }
    }
}
