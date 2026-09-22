<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\PromoteSuperadmin;

/**
 * `synapse:promote-superadmin` — the only sanctioned Platform Owner
 * bootstrap path.
 *
 * The command originally resolved users ONLY through the local
 * `email_password` identity. MIS-delegated accounts (provisioned by the
 * login/sync flow) have no such identity, so they were unpromotable.
 * These tests pin the narrow fix:
 *
 *   - positional email still works (lowercased + trimmed);
 *   - explicit `--employee` (MIS employee id → users.employee_number) and
 *     `--user-id` selectors resolve LOCAL users only;
 *   - exactly one selector, strictly validated;
 *   - the target is scoped to the active tenant and must be active,
 *     non-deleted and non-archived — a cross-tenant / disabled / deleted /
 *     archived row is never promoted;
 *   - membership is additive and idempotent (existing groups untouched,
 *     no duplicate row, no duplicate audit);
 *   - the audit row is written only for an actual grant, in the same
 *     transaction;
 *   - production requires --confirm.
 *
 * Fixtures are written directly (like PrivilegedRoleAssignmentTest) so the
 * suite pins the COMMAND's behaviour, not the provisioning service. The
 * command never touches the network: an unprovisioned MIS id must fail.
 */
final class PromoteSuperadminCommandTest extends FeatureTestCase
{
    // --- happy paths ---------------------------------------------------

    public function testPromotesMisEmployeeWithNoLocalEmail(): void
    {
        $employee = $this->uniqueEmployee();
        $userId   = $this->createEmployeeUser($employee, ['employee']);

        $this->assertSame(0, $this->promote(['employee' => $employee]));

        $this->assertTrue($this->hasGroup($userId, 'superadmin'));
        $this->assertSame(1, $this->superadminMemberships($userId));
        $this->assertSame(1, $this->grantAuditCount($userId));
        $this->assertNull($this->latestGrantAudit($userId)['actor_user_id'] ?? null);
        $this->assertSame(
            'synapse:promote-superadmin',
            $this->contextValue($this->latestGrantAudit($userId), 'reason_code'),
        );

        // No local credential identity was invented for the MIS account.
        $this->assertSame(
            0,
            (int) db_connect()->table('auth_identities')->where('user_id', $userId)->countAllResults(),
        );
    }

    public function testEmployeeEqualsSyntaxIsAccepted(): void
    {
        $employee = $this->uniqueEmployee();
        $userId   = $this->createEmployeeUser($employee, ['employee']);

        // Real `php spark ... --employee=<id>` lands as a literal key in
        // CI 4.7's parseCommandLine (it does not split `--opt=value`).
        $this->assertSame(0, $this->promote(['employee=' . $employee => null]));

        $this->assertTrue($this->hasGroup($userId, 'superadmin'));
    }

    public function testPreservesBaseRoleAndExtraRoles(): void
    {
        $employee = $this->uniqueEmployee();
        $userId   = $this->createEmployeeUser($employee, ['employee', 'counsellor']);

        $this->assertSame(0, $this->promote(['employee' => $employee]));

        // Additive only: the pre-existing memberships survive untouched.
        $this->assertTrue($this->hasGroup($userId, 'employee'));
        $this->assertTrue($this->hasGroup($userId, 'counsellor'));
        $this->assertTrue($this->hasGroup($userId, 'superadmin'));
        $this->assertSame(3, $this->totalMemberships($userId));
    }

    public function testSecondRunIsIdempotentAndAuditsNothingNew(): void
    {
        $employee = $this->uniqueEmployee();
        $userId   = $this->createEmployeeUser($employee, ['employee']);

        $this->assertSame(0, $this->promote(['employee' => $employee]));
        $this->assertSame(1, $this->grantAuditCount($userId));

        $this->assertSame(0, $this->promote(['employee' => $employee]));

        $this->assertSame(1, $this->superadminMemberships($userId));
        $this->assertSame(1, $this->grantAuditCount($userId), 'A no-op re-run must not append another grant.');
    }

    public function testLegacyPositionalEmailStillWorks(): void
    {
        $email  = $this->uniqueEmail('owner');
        $userId = $this->createUser([], $email)['id'];

        $this->assertSame(0, $this->promote([$email]));

        $this->assertTrue($this->hasGroup($userId, 'superadmin'));
        $this->assertSame(1, $this->grantAuditCount($userId));
    }

    public function testEmailSelectorIsTrimmedAndLowercased(): void
    {
        $email  = $this->uniqueEmail('mixed');
        $userId = $this->createUser([], $email)['id'];

        $this->assertSame(0, $this->promote(['  ' . strtoupper($email) . '  ']));

        $this->assertTrue($this->hasGroup($userId, 'superadmin'));
    }

    public function testExplicitUserIdSelectorWorks(): void
    {
        $userId = $this->createUser([])['id'];

        $this->assertSame(0, $this->promote(['user-id' => (string) $userId]));

        $this->assertTrue($this->hasGroup($userId, 'superadmin'));
    }

    // --- selector validation -------------------------------------------

    public function testNoSelectorIsRejected(): void
    {
        $this->assertSame(1, $this->promote([]));
    }

    public function testConflictingSelectorsAreRejected(): void
    {
        $email    = $this->uniqueEmail('conflict');
        $this->createUser([], $email);
        $employee = $this->uniqueEmployee();
        $employeeUserId = $this->createEmployeeUser($employee, ['employee']);

        $this->assertSame(1, $this->promote([$email, 'employee' => $employee]));
        $this->assertFalse($this->hasGroup($employeeUserId, 'superadmin'));
    }

    public function testUserIdSelectorMustBeAPositiveInteger(): void
    {
        $this->assertSame(1, $this->promote(['user-id' => 'abc']));
        $this->assertSame(1, $this->promote(['user-id' => '0']));
        $this->assertSame(1, $this->promote(['user-id' => '-3']));
    }

    public function testEmployeeSelectorRequiresAValue(): void
    {
        $this->assertSame(1, $this->promote(['employee' => null]));
    }

    // --- ineligible / unscoped targets ---------------------------------

    public function testUnprovisionedMisEmployeeIsRefused(): void
    {
        // Never calls the network: an unknown MIS id is simply not local.
        $this->assertSame(1, $this->promote(['employee' => $this->uniqueEmployee()]));
    }

    public function testUnknownUserIdIsRefused(): void
    {
        $this->assertSame(1, $this->promote(['user-id' => '999999999']));
    }

    public function testDeletedUserIsRefused(): void
    {
        $employee = $this->uniqueEmployee();
        $userId   = $this->createEmployeeUser($employee, ['employee'], ['deleted_at' => date('Y-m-d H:i:s')]);

        $this->assertSame(1, $this->promote(['employee' => $employee]));
        $this->assertFalse($this->hasGroup($userId, 'superadmin'));
        $this->assertSame(0, $this->grantAuditCount($userId));
    }

    public function testDisabledUserIsRefused(): void
    {
        $employee = $this->uniqueEmployee();
        $userId   = $this->createEmployeeUser($employee, ['employee'], ['active' => 0, 'status' => 'disabled']);

        $this->assertSame(1, $this->promote(['employee' => $employee]));
        $this->assertFalse($this->hasGroup($userId, 'superadmin'));
        $this->assertSame(0, $this->grantAuditCount($userId));
    }

    public function testArchivedUserIsRefused(): void
    {
        $employee = $this->uniqueEmployee();
        $userId   = $this->createEmployeeUser($employee, ['employee'], ['archived_at' => date('Y-m-d H:i:s')]);

        $this->assertSame(1, $this->promote(['employee' => $employee]));
        $this->assertFalse($this->hasGroup($userId, 'superadmin'));
    }

    public function testCrossTenantUserIsRefused(): void
    {
        $employee = $this->uniqueEmployee();
        $userId   = $this->createEmployeeUser($employee, ['employee'], ['tenant_id' => 2]);

        $this->assertSame(1, $this->promote(['employee' => $employee]));
        $this->assertFalse($this->hasGroup($userId, 'superadmin'));
        $this->assertSame(0, $this->grantAuditCount($userId));
    }

    public function testCrossTenantUserByIdIsRefused(): void
    {
        $userId = $this->createEmployeeUser($this->uniqueEmployee(), ['employee'], ['tenant_id' => 2]);

        $this->assertSame(1, $this->promote(['user-id' => (string) $userId]));
        $this->assertFalse($this->hasGroup($userId, 'superadmin'));
    }

    public function testCrossTenantEmailIsRefused(): void
    {
        $email  = $this->uniqueEmail('cross');
        $userId = $this->createUser([], $email)['id'];
        db_connect()->table('users')->where('id', $userId)->update(['tenant_id' => 2]);

        $this->assertSame(1, $this->promote([$email]));
        $this->assertFalse($this->hasGroup($userId, 'superadmin'));
    }

    // --- production confirmation ---------------------------------------

    public function testProductionRequiresConfirmAndThenGrants(): void
    {
        $employee = $this->uniqueEmployee();
        $userId   = $this->createEmployeeUser($employee, ['employee']);

        $refused = $this->productionCommand();
        $this->assertSame(1, $refused->run(['employee' => $employee]));
        $this->assertFalse($this->hasGroup($userId, 'superadmin'), 'Production must refuse without --confirm.');

        $allowed = $this->productionCommand();
        $this->assertSame(0, $allowed->run(['employee' => $employee, 'confirm' => true]));
        $this->assertTrue($this->hasGroup($userId, 'superadmin'));
    }

    // --- helpers --------------------------------------------------------

    /** @param array<int|string, string|null|bool> $params */
    private function promote(array $params): int
    {
        return (new PromoteSuperadmin(service('logger'), service('commands')))->run($params);
    }

    private function productionCommand(): PromoteSuperadmin
    {
        $command = new PromoteSuperadmin(service('logger'), service('commands'));
        (new \ReflectionProperty(PromoteSuperadmin::class, 'environmentOverride'))->setValue($command, 'production');

        return $command;
    }

    private function uniqueEmployee(): string
    {
        return 'EMP-' . bin2hex(random_bytes(6));
    }

    /**
     * A local MIS-style employee row with NO email_password identity —
     * exactly what the login/sync flow leaves behind.
     *
     * @param list<string> $groups
     * @param array<string, mixed> $overrides
     */
    private function createEmployeeUser(string $employeeNumber, array $groups, array $overrides = []): int
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->table('users')->insert($overrides + [
            'username'        => 'emp-' . bin2hex(random_bytes(6)),
            'tenant_id'       => 1,
            'kind'            => 'employee',
            'employee_number' => $employeeNumber,
            'status'          => 'active',
            'active'          => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $userId = (int) $db->insertID();

        foreach ($groups as $name) {
            $group = $db->table('auth_groups')->where('name', $name)->get()->getRowArray();
            if ($group === null) {
                $this->fail(sprintf('Group "%s" does not exist; is PermissionsAndGroupsSeeder seeded?', $name));
            }
            $db->table('auth_groups_users')->insert([
                'group_id'   => (int) $group['id'],
                'user_id'    => $userId,
                'created_at' => $now,
            ]);
        }

        return $userId;
    }

    private function hasGroup(int $userId, string $groupName): bool
    {
        return (int) db_connect()->table('auth_groups_users gu')
            ->join('auth_groups g', 'g.id = gu.group_id')
            ->where('gu.user_id', $userId)
            ->where('g.name', $groupName)
            ->countAllResults() > 0;
    }

    private function superadminMemberships(int $userId): int
    {
        return (int) db_connect()->table('auth_groups_users gu')
            ->join('auth_groups g', 'g.id = gu.group_id')
            ->where('gu.user_id', $userId)
            ->where('g.name', 'superadmin')
            ->countAllResults();
    }

    private function totalMemberships(int $userId): int
    {
        return (int) db_connect()->table('auth_groups_users')->where('user_id', $userId)->countAllResults();
    }

    private function grantAuditCount(int $userId): int
    {
        return (int) db_connect()->table('audit_outbox')
            ->where('action_code', 'rbac.privileged_role_granted')
            ->where('entity_type', 'users')
            ->where('entity_id', $userId)
            ->countAllResults();
    }

    /** @return array<string, mixed>|null */
    private function latestGrantAudit(int $userId): ?array
    {
        return db_connect()->table('audit_outbox')
            ->where('action_code', 'rbac.privileged_role_granted')
            ->where('entity_id', $userId)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();
    }

    /** @param array<string, mixed>|null $audit */
    private function contextValue(?array $audit, string $key): ?string
    {
        if ($audit === null) {
            return null;
        }
        $context = json_decode((string) $audit['context_json'], true);

        return is_array($context) ? ($context[$key] ?? null) : null;
    }
}
