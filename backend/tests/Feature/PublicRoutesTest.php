<?php

declare(strict_types=1);

namespace Tests\Feature;

/** Retired QR routes must be absent, not merely gated by authentication. */
final class PublicRoutesTest extends FeatureTestCase
{
    public function testAppointmentVerifyIsAbsent(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->expectExceptionCode(404);
        $this->withBodyFormat('json')->call(
            'post',
            'api/v1/appointments/verify',
            ['token' => 'retired-token'],
        );

    }

    public function testAppointmentQrIssuanceIsAbsentForStaff(): void
    {
        $staff = $this->login(['clinic_admin']);
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->expectExceptionCode(404);
        $this->authed($staff['token'], 'post', 'api/v1/clinic/appointments/1/qr', []);
    }

    public function testReferralVerifyRemainsAbsent(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->expectExceptionCode(404);
        $this->withBodyFormat('json')->call('post', 'api/v1/referrals/verify', ['token' => 'retired-token']);
    }
}
