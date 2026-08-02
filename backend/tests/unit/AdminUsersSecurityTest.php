<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\AccountStateService;
use App\Exceptions\ApiErrorCode;
use PHPUnit\Framework\TestCase;

final class AdminUsersSecurityTest extends TestCase
{
    public function testMissingAndDisabledAccountsAreRejected(): void
    {
        $this->assertSame(
            AccountStateService::ACCESS_UNAUTHORIZED,
            AccountStateService::accessDecision(null, '/api/v1/dashboard/counters'),
        );
        $this->assertSame(
            AccountStateService::ACCESS_UNAUTHORIZED,
            AccountStateService::accessDecision(['active' => false, 'force_reset' => false], '/api/v1/dashboard/counters'),
        );
    }

    public function testForcedResetAccountsCannotUseOrdinaryProtectedApis(): void
    {
        $state = ['active' => true, 'force_reset' => true];

        $this->assertSame(
            AccountStateService::ACCESS_PASSWORD_CHANGE_REQUIRED,
            AccountStateService::accessDecision($state, '/api/v1/admin/users'),
        );
        $this->assertSame(403, ApiErrorCode::defaultStatus(ApiErrorCode::AUTH_PASSWORD_CHANGE_REQUIRED));
    }

    /** @dataProvider forcedResetRecoveryRoutes */
    public function testForcedResetAccountsRetainOnlyRecoveryRoutes(string $path): void
    {
        $this->assertSame(
            AccountStateService::ACCESS_GRANTED,
            AccountStateService::accessDecision(['active' => true, 'force_reset' => true], $path),
        );
    }

    /** @return array<string, array{string}> */
    public static function forcedResetRecoveryRoutes(): array
    {
        return [
            'session inspection' => ['/api/v1/auth/me'],
            'password change' => ['/api/v1/auth/change-password'],
            'logout' => ['/api/v1/auth/logout'],
        ];
    }

    public function testOrdinaryActiveAccountIsGrantedAccess(): void
    {
        $this->assertSame(
            AccountStateService::ACCESS_GRANTED,
            AccountStateService::accessDecision(['active' => true, 'force_reset' => false], '/api/v1/admin/users'),
        );
    }
}
