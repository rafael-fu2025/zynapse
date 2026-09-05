<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * The four deliberately-public routes (no api_auth filter) must stay
 * reachable WITHOUT a token — and stay that way. This is the
 * minimum-disclosure contract: each of these serves exactly one
 * unauthenticated purpose (lobby queue feed, QR verification, kiosk
 * media), so a refactor that accidentally attaches api_auth breaks the
 * lobby display and QR scanners in the field, and one that widens the
 * data would be a disclosure regression.
 *
 * The correct failure mode for these routes is 4xx validation/not-found —
 * never 401 (wrong filter attached) and never a leak (over-broad data).
 */
final class PublicRoutesTest extends FeatureTestCase
{
    public function testLobbyQueueStateIsReachableWithoutAuth(): void
    {
        $result = $this->call('get', 'api/v1/clinic/queue/state');

        $result->assertStatus(200);
        $body = $this->envelope($result);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['data']);
    }

    /**
     * Minimum-disclosure shape of the lobby feed (audit 2026-09-05,
     * F16): guidance rows must carry ONLY the queue-number abstraction.
     * Guidance attendance is materially more sensitive than a clinic
     * visit, so `display_name` / `patient_school_id` leaking back into
     * the guidance branch is a disclosure regression. Clinic rows keep
     * name + school ID by design.
     */
    public function testLobbyGuidanceFeedCarriesNoPatientIdentity(): void
    {
        $result = $this->call('get', 'api/v1/clinic/queue/state');

        $result->assertStatus(200);
        $body = $this->envelope($result);
        $this->assertTrue($body['success']);

        $guidance = $body['data']['guidance'];
        $this->assertIsArray($guidance);
        $allowed = ['position', 'queue_number', 'est_wait_minutes'];
        foreach (['active', 'waiting'] as $section) {
            foreach ($guidance[$section] ?? [] as $row) {
                foreach (array_keys($row) as $key) {
                    $this->assertContains($key, $allowed, "guidance {$section} leaked key '{$key}' to the unauthenticated lobby feed");
                }
            }
        }
        if ($guidance['now_serving'] !== null) {
            foreach (array_keys($guidance['now_serving']) as $key) {
                $this->assertContains($key, $allowed, "guidance now_serving leaked key '{$key}' to the unauthenticated lobby feed");
            }
        }
    }

    public function testAppointmentVerifyIsPublicButRejectsGarbage(): void
    {
        $result = $this->withBodyFormat('json')->call(
            'post',
            'api/v1/appointments/verify',
            ['qr_token' => 'not-a-real-token'],
        );

        // Public: any failure must be a *verification* failure, not auth.
        $this->assertNotSame(401, $result->response()->getStatusCode());
        $this->assertNotSame(403, $result->response()->getStatusCode());
        $body = $this->envelope($result);
        $this->assertFalse($body['success']);
    }

    public function testReferralVerifyIsPublicButRejectsGarbage(): void
    {
        $result = $this->withBodyFormat('json')->call(
            'post',
            'api/v1/referrals/verify',
            ['qr_token' => 'not-a-real-token'],
        );

        $this->assertNotSame(401, $result->response()->getStatusCode());
        $this->assertNotSame(403, $result->response()->getStatusCode());
        $body = $this->envelope($result);
        $this->assertFalse($body['success']);
    }

    public function testKioskMediaThumbnailIsPublicAndUnknownUuidIsNotFound(): void
    {
        // Unauthenticated reachability is the point: the kiosk display
        // loads thumbnails without holding a user token. An unknown UUID
        // must be a clean 404 — the UUID is the entire access control.
        $result = $this->call('get', 'api/v1/kiosk-media/00000000-0000-0000-0000-000000000000/thumbnail');

        $this->assertSame(404, $result->response()->getStatusCode());
        $this->envelope($result);
    }
}
