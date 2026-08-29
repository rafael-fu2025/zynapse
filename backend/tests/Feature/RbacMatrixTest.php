<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * The RBAC 403 matrix, end-to-end: a permission the caller holds passes,
 * one it lacks is refused, and the refusal is the canonical envelope —
 * not a framework error page. Group→permission grants live in
 * app/Config/AuthGroups.php; the wildcard '*' is admin-only.
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

    public function testAdminWildcardGrantsEverything(): void
    {
        $session = $this->login(['admin']);

        $result = $this->authed($session['token'], 'get', 'api/v1/audit/events');

        $result->assertStatus(200);
    }
}
