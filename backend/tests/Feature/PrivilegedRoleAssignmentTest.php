<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Privileged-role governance (2026-09 RBAC rework, D2) — end-to-end
 * through the real /admin/users routes:
 *
 *   - `rbac.manage` holders (unit admins) may grant/revoke
 *     NON-privileged roles;
 *   - granting/revoking any privileged role (set P: superadmin,
 *     clinic_admin, guidance_admin, bmg_admin) requires
 *     `rbac.privileged.manage` → 403 otherwise;
 *   - no user can revoke their own LAST privileged role → 422;
 *   - kiosk machine accounts are created/reset only by clinic_admin
 *     or superadmin → 403 rbac.kiosk_restricted otherwise.
 *
 * Fixtures are created directly in the DB (FeatureTestCase::createUser),
 * bypassing the service — so these tests pin the SERVICE-side guards,
 * not the fixtures.
 */
final class PrivilegedRoleAssignmentTest extends FeatureTestCase
{
    public function testUnitAdminGrantsNonPrivilegedRole(): void
    {
        $actor   = $this->login(['guidance_admin']);
        $student = $this->createUser(['student']);

        $result = $this->authed($actor['token'], 'post', 'api/v1/admin/users/' . $student['id'] . '/groups', [
            'groups' => ['counsellor'],
        ]);

        $result->assertStatus(200);
        $body = $this->envelope($result);
        $this->assertTrue($body['success']);
        $this->assertSame(['counsellor'], $body['data']['groups'] ?? null);
    }

    public function testUnitAdminCannotGrantPrivilegedRole(): void
    {
        $actor   = $this->login(['guidance_admin']);
        $student = $this->createUser(['student']);

        $result = $this->authed($actor['token'], 'post', 'api/v1/admin/users/' . $student['id'] . '/groups', [
            'groups' => ['clinic_admin'],
        ]);

        $result->assertStatus(403);
        $this->assertErrorCode('rbac.escalation_forbidden', $result);
    }

    public function testUnitAdminCannotGrantPrivilegedRoleAtCreation(): void
    {
        $actor = $this->login(['bmg_admin']);

        $result = $this->authed($actor['token'], 'post', 'api/v1/admin/users', [
            'email'  => $this->uniqueEmail('esc'),
            'groups' => ['clinic_admin'],
        ]);

        $result->assertStatus(403);
        $this->assertErrorCode('rbac.escalation_forbidden', $result);
    }

    public function testSuperadminGrantsPrivilegedRole(): void
    {
        $actor   = $this->login(['superadmin']);
        $student = $this->createUser(['student']);

        $result = $this->authed($actor['token'], 'post', 'api/v1/admin/users/' . $student['id'] . '/groups', [
            'groups' => ['clinic_admin'],
        ]);

        $result->assertStatus(200);
        $body = $this->envelope($result);
        $this->assertSame(['clinic_admin'], $body['data']['groups'] ?? null);
    }

    public function testOwnLastPrivilegedRoleRevocationIsBlocked(): void
    {
        $actor = $this->login(['clinic_admin']);

        // The only privileged role the actor holds is clinic_admin —
        // replacing it with a non-privileged role must be refused 422.
        $result = $this->authed($actor['token'], 'post', 'api/v1/admin/users/' . $actor['userId'] . '/groups', [
            'groups' => ['counsellor'],
        ]);

        $result->assertStatus(422);
        $body = $this->envelope($result);
        $this->assertFalse($body['success']);
    }

    public function testPrivilegedRoleRevocationAllowedWhenAnotherHolderRemains(): void
    {
        // A unit admin touching any privileged role — even their own —
        // needs rbac.privileged.manage, so the actor here is a superadmin.
        $actor  = $this->login(['superadmin']);
        $target = $this->createUser(['clinic_admin', 'guidance_admin']);
        // A second guidance_admin holder so the removal cannot orphan the role.
        $this->createUser(['guidance_admin']);

        $result = $this->authed($actor['token'], 'post', 'api/v1/admin/users/' . $target['id'] . '/groups', [
            'groups' => ['clinic_admin', 'counsellor'],
        ]);

        $result->assertStatus(200);
        $body = $this->envelope($result);
        $this->assertSame(['clinic_admin', 'counsellor'], $body['data']['groups'] ?? null);
    }

    public function testSuperadminCannotRemoveTheLastHolderOfAPrivilegedRole(): void
    {
        $actor  = $this->login(['superadmin']);
        $target = $this->createUser(['clinic_admin']);

        // The feature schema is stateful (never truncated), so holders
        // accumulate across runs — demote every OTHER clinic_admin holder
        // first so the target genuinely is the last one. Those demotions
        // are legal: the target still holds the role while each happens.
        $db = db_connect();
        $otherHolderIds = $db->table('auth_groups_users gu')
            ->select('gu.user_id')
            ->join('auth_groups g', 'g.id = gu.group_id')
            ->where('g.name', 'clinic_admin')
            ->where('gu.user_id !=', (int) $target['id'])
            ->get()
            ->getResultArray();
        foreach ($otherHolderIds as $holder) {
            $this->authed($actor['token'], 'post', 'api/v1/admin/users/' . (int) $holder['user_id'] . '/groups', [
                'groups' => ['student'],
            ])->assertStatus(200);
        }

        // Even a superadmin cannot remove the last holder of a
        // privileged role — privileged management must never be lockable
        // out of existence.
        $result = $this->authed($actor['token'], 'post', 'api/v1/admin/users/' . $target['id'] . '/groups', [
            'groups' => ['counsellor'],
        ]);

        $result->assertStatus(422);
        $body = $this->envelope($result);
        $this->assertFalse($body['success']);
    }

    public function testUnitAdminCannotCreateKioskAccount(): void
    {
        $actor = $this->login(['guidance_admin']);

        $result = $this->authed($actor['token'], 'post', 'api/v1/admin/users', [
            'email'  => $this->uniqueEmail('kiosk-denied'),
            'groups' => ['kiosk'],
        ]);

        $result->assertStatus(403);
        $this->assertErrorCode('rbac.kiosk_restricted', $result);
    }

    public function testClinicAdminCanCreateKioskAccount(): void
    {
        $actor = $this->login(['clinic_admin']);

        $result = $this->authed($actor['token'], 'post', 'api/v1/admin/users', [
            'email'  => $this->uniqueEmail('kiosk-allowed'),
            'groups' => ['kiosk'],
        ]);

        $result->assertStatus(201);
        $body = $this->envelope($result);
        $this->assertSame(['kiosk'], $body['data']['groups'] ?? null);
    }

    public function testUnitAdminCannotResetKioskPassword(): void
    {
        $guidance = $this->login(['guidance_admin']);
        $clinic   = $this->login(['clinic_admin']);

        $kiosk = $this->authed($clinic['token'], 'post', 'api/v1/admin/users', [
            'email'  => $this->uniqueEmail('kiosk-reset'),
            'groups' => ['kiosk'],
        ]);
        $kiosk->assertStatus(201);
        $kioskId = (int) ($this->envelope($kiosk)['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $kioskId);

        $result = $this->authed($guidance['token'], 'post', 'api/v1/admin/users/' . $kioskId . '/reset-password');

        $result->assertStatus(403);
        $this->assertErrorCode('rbac.kiosk_restricted', $result);
    }
}
