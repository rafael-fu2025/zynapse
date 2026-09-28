<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * StudentRegistrationAccountLinkTest — what happens when the portal email a
 * registration wants to mint already exists.
 *
 * `auth_identities` (type=email_password, secret=email) is globally unique,
 * so "register a student for an email that already logs in" cannot insert a
 * second identity. Before the insertOrAdoptPatient branch, that collision
 * died on the unique index and txn() surfaced it as an opaque
 * "Database transaction failed" 409.
 *
 * The contract now:
 *   - fresh email            → 201 + portal_account envelope (temp password);
 *   - identity-only shell    → 201, NO envelope: the patient fields are
 *     written onto the EXISTING user row (the dev seeder creates exactly
 *     such shells for student@/employee@synapse.dev), credentials untouched;
 *   - email owned by a real record → 409 naming it on `account_email`, and
 *     the half-created patient row is rolled back.
 */
final class StudentRegistrationAccountLinkTest extends FeatureTestCase
{
    private function uniqueNumber(string $prefix): string
    {
        return $prefix . random_int(100000, 999999) . strtoupper(bin2hex(random_bytes(2)));
    }

    /**
     * Seed an identity-only account shell: a users row with NO kind and NO
     * identifier — exactly the shape DevUserSeeder leaves behind for
     * student@synapse.dev / employee@synapse.dev.
     *
     * @return array{id: int, email: string, password_hash: string}
     */
    private function seedShell(string $email, string $group): array
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->table('users')->insert([
            'username'   => 'shell-' . bin2hex(random_bytes(5)),
            'status'     => 'active',
            'active'     => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = (int) $db->insertID();

        $passwordHash = password_hash('ShellPassw0rd!', PASSWORD_DEFAULT);
        $db->table('auth_identities')->insert([
            'user_id'     => $userId,
            'type'        => 'email_password',
            'secret'      => $email,
            'secret2'     => $passwordHash,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $groupRow = $db->table('auth_groups')->where('name', $group)->get()->getRowArray();
        if ($groupRow !== null) {
            $db->table('auth_groups_users')->insert([
                'group_id'   => (int) $groupRow['id'],
                'user_id'    => $userId,
                'created_at' => $now,
            ]);
        }

        return ['id' => $userId, 'email' => $email, 'password_hash' => $passwordHash];
    }

    private function identityCount(string $email): int
    {
        return db_connect()->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->countAllResults();
    }

    public function testFreshEmailMintsThePortalAccount(): void
    {
        $admin  = $this->login(['clinic_admin']);
        $number = $this->uniqueNumber('S');

        $res = $this->authed($admin['token'], 'post', 'api/v1/clinic/students', [
            'student_number' => $number,
            'first_name'     => 'Fresh',
            'last_name'      => 'Mint',
            'account_email'  => 'fresh-' . bin2hex(random_bytes(4)) . '@synapse.dev',
        ]);

        $res->assertStatus(201);
        $data = $this->envelope($res)['data'];
        $this->assertArrayHasKey('portal_account', $data);
        $this->assertNotEmpty($data['portal_account']['temporary_password']);
    }

    public function testRegistrationAdoptsAnIdentityOnlyShell(): void
    {
        $admin = $this->login(['clinic_admin']);
        $shell = $this->seedShell('adopt-' . bin2hex(random_bytes(4)) . '@synapse.dev', 'student');
        $number = $this->uniqueNumber('S');

        $res = $this->authed($admin['token'], 'post', 'api/v1/clinic/students', [
            'student_number' => $number,
            'first_name'     => 'Adopted',
            'last_name'      => 'Shell',
            'course'         => 'BSIT',
            'account_email'  => $shell['email'],
        ]);

        $res->assertStatus(201);
        $data = $this->envelope($res)['data'];

        // The shell IS the patient now — same row, no second account.
        $this->assertSame($shell['id'], (int) $data['id']);
        $this->assertArrayNotHasKey('portal_account', $data);

        $db   = db_connect();
        $user = $db->table('users')->where('id', $shell['id'])->get()->getRowArray();
        $this->assertSame('student', $user['kind']);
        $this->assertSame($number, (string) $user['student_number']);
        $this->assertSame('Adopted', $user['first_name']);
        $this->assertSame(1, (int) $user['active']);

        // Exactly one identity, and its credentials are the shell's own —
        // no temporary password was minted over them.
        $this->assertSame(1, $this->identityCount($shell['email']));
        $identity = $db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $shell['email'])
            ->get()->getRowArray();
        $this->assertSame($shell['password_hash'], $identity['secret2']);
    }

    public function testAdoptedShellKeepsItsOriginalCreationDate(): void
    {
        $admin = $this->login(['clinic_admin']);
        $shell = $this->seedShell('adopt-ts-' . bin2hex(random_bytes(4)) . '@synapse.dev', 'student');

        // Backdate the shell so "adoption did not overwrite created_at" is
        // actually observable.
        $db = db_connect();
        $db->table('users')->where('id', $shell['id'])->update(['created_at' => '2020-01-02 03:04:05']);

        $res = $this->authed($admin['token'], 'post', 'api/v1/clinic/students', [
            'student_number' => $this->uniqueNumber('S'),
            'first_name'     => 'Keep',
            'last_name'      => 'Created',
            'account_email'  => $shell['email'],
        ]);

        $res->assertStatus(201);
        $user = $db->table('users')->where('id', $shell['id'])->get()->getRowArray();
        $this->assertSame('2020-01-02 03:04:05', (string) $user['created_at']);
    }

    public function testEmailOwnedByARealStudentConflictsWithFieldLevelError(): void
    {
        $admin = $this->login(['clinic_admin']);
        $email = 'taken-' . bin2hex(random_bytes(4)) . '@synapse.dev';

        $first = $this->authed($admin['token'], 'post', 'api/v1/clinic/students', [
            'student_number' => $this->uniqueNumber('S'),
            'first_name'     => 'First',
            'last_name'      => 'Owner',
            'account_email'  => $email,
        ]);
        $first->assertStatus(201);

        $second = $this->authed($admin['token'], 'post', 'api/v1/clinic/students', [
            'student_number' => $this->uniqueNumber('S'),
            'first_name'     => 'Second',
            'last_name'      => 'Clash',
            'account_email'  => $email,
        ]);

        $second->assertStatus(409);
        $errors = $this->envelope($second)['errors'];
        $this->assertNotEmpty($errors);
        $this->assertSame('account_email', $errors[0]['field'] ?? null);
        $this->assertStringContainsString('already linked', $errors[0]['message'] ?? '');
    }

    public function testConflictedRegistrationLeavesNoHalfCreatedRow(): void
    {
        $admin = $this->login(['clinic_admin']);
        $email = 'rollback-' . bin2hex(random_bytes(4)) . '@synapse.dev';

        $this->authed($admin['token'], 'post', 'api/v1/clinic/students', [
            'student_number' => $this->uniqueNumber('S'),
            'first_name'     => 'First',
            'last_name'      => 'Owner',
            'account_email'  => $email,
        ])->assertStatus(201);

        $clashingNumber = $this->uniqueNumber('S');
        $this->authed($admin['token'], 'post', 'api/v1/clinic/students', [
            'student_number' => $clashingNumber,
            'first_name'     => 'Second',
            'last_name'      => 'Clash',
            'account_email'  => $email,
        ])->assertStatus(409);

        // The transaction rolled back: no patient row for the clash.
        $this->assertSame(
            0,
            db_connect()->table('users')->where('student_number', $clashingNumber)->countAllResults(),
        );
    }

    public function testEmployeeRegistrationAdoptsAnIdentityOnlyShell(): void
    {
        $admin = $this->login(['clinic_admin']);
        $shell = $this->seedShell('emp-adopt-' . bin2hex(random_bytes(4)) . '@synapse.dev', 'employee');
        $number = $this->uniqueNumber('E');

        $res = $this->authed($admin['token'], 'post', 'api/v1/clinic/employees', [
            'employee_number' => $number,
            'first_name'      => 'Adopted',
            'last_name'       => 'Employee',
            'account_email'   => $shell['email'],
        ]);

        $res->assertStatus(201);
        $data = $this->envelope($res)['data'];
        $this->assertSame($shell['id'], (int) $data['id']);
        $this->assertArrayNotHasKey('portal_account', $data);

        $user = db_connect()->table('users')->where('id', $shell['id'])->get()->getRowArray();
        $this->assertSame('employee', $user['kind']);
        $this->assertSame($number, (string) $user['employee_number']);
    }
}
