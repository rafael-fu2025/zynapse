<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * The RBAC 403 matrix, end-to-end: a permission the caller holds passes,
 * one it lacks is refused, and the refusal is the canonical envelope —
 * not a framework error page. Group→permission grants live in
 * app/Config/AuthGroups.php; the wildcard '*' is superadmin-only
 * (2026-09 RBAC rework).
 */
final class RbacMatrixTest extends FeatureTestCase
{
    public function testRoleWithoutAuditPermissionIsForbidden(): void
    {
        // Students carry only portal/notification grants (AuthGroups
        // 'student', Phase 13) — audit.read is deliberately absent.
        $session = $this->login(['student']);

        $result = $this->authed($session['token'], 'get', 'api/v1/audit/events');

        $result->assertStatus(403);
        $body = $this->envelope($result);
        $this->assertFalse($body['success']);
        $this->assertNull($body['data'], 'A forbidden response must not leak data.');
    }

    public function testRoleWithAuditPermissionCanReadAuditEvents(): void
    {
        $session = $this->login(['audit_reader']);

        $result = $this->authed($session['token'], 'get', 'api/v1/audit/events');

        $result->assertStatus(200);
        $body = $this->envelope($result);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['data']);
    }

    public function testStudentCanStillReadOwnNotifications(): void
    {
        // Guards against the matrix degenerating into "everything 403s":
        // students must pass a route they DO hold (notifications.read).
        $session = $this->login(['student']);

        $result = $this->authed($session['token'], 'get', 'api/v1/notifications');

        $result->assertStatus(200);
        $body = $this->envelope($result);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['data']);
    }

    public function testSuperadminWildcardGrantsEverything(): void
    {
        // 2026-09 RBAC rework: superadmin (Platform Owner) is the ONLY
        // wildcard holder.
        $session = $this->login(['superadmin']);

        $result = $this->authed($session['token'], 'get', 'api/v1/audit/events');

        $result->assertStatus(200);
    }

    public function testClinicAdminHasExplicitMatrixWithoutAudit(): void
    {
        // The former `admin` wildcard tier is now clinic_admin with an
        // explicit matrix — audit.* is deliberately absent (strict D1).
        $session = $this->login(['clinic_admin']);

        $result = $this->authed($session['token'], 'get', 'api/v1/audit/events');

        $result->assertStatus(403);
        $body = $this->envelope($result);
        $this->assertFalse($body['success']);
    }

    public function testClinicAdminKeepsClinicSurface(): void
    {
        // Guards the rename contract: former admin functionality on the
        // clinic unit (queue read) survives the move to an explicit matrix.
        $session = $this->login(['clinic_admin']);

        $result = $this->authed($session['token'], 'get', 'api/v1/clinic/queue/state');

        $result->assertStatus(200);
    }
}
