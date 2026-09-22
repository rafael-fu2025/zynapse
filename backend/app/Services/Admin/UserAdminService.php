<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use App\Pagination\KeysetPaginator;
use App\Services\Rbac\PermissionService;
use App\Services\Rbac\PrivilegedRoles;
use App\Services\CurrentTenant;
use App\Services\UniversityEmail;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;

/**
 * UserAdminService — identity-consolidated admin user management.
 *
 * Users ARE the person: list/create read `users.kind`, `users.first_name`
 * and `users.last_name` directly (no `persons` join, no `person_id` link).
 *
 * 2026-09 RBAC rework governance (D2, see PrivilegedRoles):
 *   - Granting/revoking a privileged role (set P) requires
 *     `rbac.privileged.manage` — superadmin only.
 *   - Kiosk machine accounts are created/reset only by clinic_admin
 *     or superadmin.
 *   - No user can revoke their own last privileged role; the last
 *     holder of any privileged role is irremovable.
 *   - Every privileged grant/revoke is audited.
 */
final class UserAdminService extends BaseService
{
    public function __construct(
        private readonly AuditOutboxService $audit,
        private readonly PermissionService $permissions = new PermissionService(),
    ) {
        parent::__construct();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function list(
        ?string $cursor,
        int $limit,
        string $search = '',
        string $status = 'all',
        string $group = 'all',
        string $sort = 'newest',
    ): array {
        $builder = $this->db->table('users u')
            ->select("u.id, u.username, u.status, u.active, u.created_at, u.updated_at, u.last_active, i.secret AS email, COALESCE(i.force_reset, 0) AS force_reset, u.kind AS person_kind, u.first_name AS person_first_name, u.last_name AS person_last_name", false)
            ->join("auth_identities i", "i.user_id = u.id AND i.type = 'email_password'", 'left')
            ->where('u.tenant_id', CurrentTenant::id())
            ->where('u.deleted_at', null);

        if ($search !== '') {
            // Name columns are searched too: MIS-provisioned users have no
            // email identity and a synthetic `stu-XXXX`/`emp-XXXX` username,
            // so without name matching they are unfindable in the admin list.
            $builder->groupStart()
                ->like('u.username', $search)
                ->orLike('i.secret', $search)
                ->orLike('u.first_name', $search)
                ->orLike('u.last_name', $search)
                ->groupEnd();
        }
        if ($status !== 'all') {
            $builder->where('u.active', $status === 'active' ? 1 : 0);
        }
        if ($group !== 'all') {
            $builder
                ->join('auth_groups_users filter_gu', 'filter_gu.user_id = u.id')
                ->join('auth_groups filter_g', 'filter_g.id = filter_gu.group_id')
                ->where('filter_g.name', $group);
        }

        $direction = $sort === 'oldest' ? 'ASC' : 'DESC';
        $builder->orderBy('u.created_at', $direction)->orderBy('u.id', $direction);

        $builder->limit($limit + 1);
        if (($decoded = KeysetPaginator::decode($cursor)) !== null) {
            $operator = $direction === 'ASC' ? '>' : '<';
            $builder
                ->groupStart()
                    ->where('u.created_at ' . $operator, $decoded['created_at'])
                    ->orGroupStart()
                        ->where('u.created_at', $decoded['created_at'])
                        ->where('u.id ' . $operator, $decoded['id'])
                    ->groupEnd()
                ->groupEnd();
        }
        $rows = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit, 'u.created_at');

        $groupsByUser = $this->groupsFor(array_map(static fn (array $r) => (int) $r['id'], $final['rows']));

        $data = array_map(static fn (array $r): array => [
            'id'             => (int)    $r['id'],
            'username'       => $r['username'] !== null ? (string) $r['username'] : null,
            // MIS-provisioned users have no email identity — derive the
            // university address from the person name (display-only).
            'email'          => $r['email'] !== null
                ? (string) $r['email']
                : UniversityEmail::derive($r['person_first_name'], $r['person_last_name']),
            'active'         => (bool)   $r['active'],
            'status'         => (string) $r['status'],
            'groups'         => $groupsByUser[(int) $r['id']] ?? [],
            'person_kind'    => $r['person_kind'] !== null ? (string) $r['person_kind'] : null,
            'person_name'    => self::composePersonName($r),
            'created_at'     => (string) $r['created_at'],
            'updated_at'     => (string) $r['updated_at'],
            'last_active'    => $r['last_active'] !== null ? (string) $r['last_active'] : null,
            'force_reset'    => (bool)   $r['force_reset'],
            'is_directory_record' => false,
        ], $final['rows']);

        if ($search !== '' && mb_strlen(trim($search)) >= 2 && $cursor === null && ($status === 'all' || $status === 'active')) {
            try {
                $fuMis = Services::fuMisAuthService();
                $known = [];
                foreach ($data as $u) {
                    if ($u['username'] !== null) {
                        $known[strtolower($u['username'])] = true;
                    }
                }
                $syntheticId = -1;
                if ($group === 'all' || $group === 'student') {
                    $misStudents = $fuMis->searchStudents($search, 10);
                    foreach ($misStudents as $ms) {
                        $uname = 'stu-' . ($ms['identifier'] ?? '');
                        if (isset($known[strtolower($uname)])) {
                            continue;
                        }
                        $known[strtolower($uname)] = true;
                        $pName = trim(($ms['last_name'] ?? '') . ', ' . ($ms['first_name'] ?? ''));
                        $data[] = [
                            'id'             => $syntheticId--,
                            'username'       => $uname,
                            'email'          => UniversityEmail::derive($ms['first_name'] ?? '', $ms['last_name'] ?? ''),
                            'active'         => true,
                            'status'         => 'active',
                            'groups'         => ['student'],
                            'person_kind'    => 'student',
                            'person_name'    => $pName !== '' ? $pName : null,
                            'created_at'     => '',
                            'updated_at'     => '',
                            'last_active'    => null,
                            'force_reset'    => false,
                            'is_directory_record' => true,
                            'directory_identifier' => $ms['identifier'],
                        ];
                    }
                }
                if ($group === 'all' || $group === 'employee') {
                    $misEmployees = $fuMis->searchEmployees($search, 10);
                    foreach ($misEmployees as $me) {
                        $uname = 'emp-' . ($me['identifier'] ?? '');
                        if (isset($known[strtolower($uname)])) {
                            continue;
                        }
                        $known[strtolower($uname)] = true;
                        $pName = trim(($me['last_name'] ?? '') . ', ' . ($me['first_name'] ?? ''));
                        $data[] = [
                            'id'             => $syntheticId--,
                            'username'       => $uname,
                            'email'          => UniversityEmail::derive($me['first_name'] ?? '', $me['last_name'] ?? ''),
                            'active'         => true,
                            'status'         => 'active',
                            'groups'         => ['employee'],
                            'person_kind'    => 'employee',
                            'person_name'    => $pName !== '' ? $pName : null,
                            'created_at'     => '',
                            'updated_at'     => '',
                            'last_active'    => null,
                            'force_reset'    => false,
                            'is_directory_record' => true,
                            'directory_identifier' => $me['identifier'],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                log_message('warning', sprintf('UserAdminService MIS search failed: %s', $e->getMessage()));
            }
        }

        return [
            'data'  => $data,
            'next'  => $final['nextCursor'],
            'count' => count($data),
        ];
    }

    public function get(int $userId): array
    {
        $row = $this->db->table('users u')
            ->select("u.id, u.username, u.status, u.active, u.created_at, u.updated_at, u.last_active, i.secret AS email, COALESCE(i.force_reset, 0) AS force_reset, u.kind AS person_kind, u.first_name AS person_first_name, u.last_name AS person_last_name", false)
            ->join("auth_identities i", "i.user_id = u.id AND i.type = 'email_password'", 'left')
            ->where('u.tenant_id', CurrentTenant::id())
            ->where('u.id', $userId)
            ->where('u.deleted_at', null)
            ->get()->getRowArray();

        if ($row === null) {
            throw ApiException::notFound('resource.not_found');
        }

        $groups = $this->groupsFor([$userId])[$userId] ?? [];

        return [
            'id'             => (int)    $row['id'],
            'username'       => $row['username'] !== null ? (string) $row['username'] : null,
            'email'          => $row['email'] !== null
                ? (string) $row['email']
                : UniversityEmail::derive($row['person_first_name'], $row['person_last_name']),
            'active'         => (bool)   $row['active'],
            'status'         => (string) $row['status'],
            'groups'         => $groups,
            'person_kind'    => $row['person_kind'] !== null ? (string) $row['person_kind'] : null,
            'person_name'    => self::composePersonName($row),
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
            'last_active'    => $row['last_active'] !== null ? (string) $row['last_active'] : null,
            'force_reset'    => (bool)   $row['force_reset'],
            'is_directory_record' => false,
        ];
    }

    /**
     * Resolve (or JIT-provision) a university-directory person and
     * ADDITIVELY grant the requested roles on top of whatever the person
     * already holds.
     *
     * Ordering is the safety property: `rbac.manage`, the strict
     * identifier/kind shape check, known-role validation and the
     * privileged-grant + kiosk gates all run BEFORE any MIS lookup or
     * local write. An unauthorized or malformed request therefore never
     * creates a user and never grants a role. Resolve/provision, the
     * membership inserts and the audit rows share ONE transaction, so a
     * failure leaves no partial user or grant.
     *
     * The requested roles are a UNION with the ACTUAL current memberships
     * read under the target's row lock — never a replacement, and never
     * seeded from the caller's synthetic directory defaults. Revocation
     * stays on the explicit replaceGroups() path.
     *
     * @param list<string> $groups roles to ADD
     * @return array<string, mixed>
     */
    public function provisionDirectoryUser(string $identifier, string $kind, array $groups = []): array
    {
        $actorId = \App\Auth\CurrentUser::assert();
        if (! $this->permissions->userHas($actorId, 'rbac.manage')) {
            throw ApiException::forbidden('auth.forbidden');
        }

        $identifier = trim($identifier);
        $this->assertDirectoryIdentity($identifier, $kind);

        $groups = array_values(array_unique(array_map(
            static fn ($group): string => trim((string) $group),
            $groups,
        )));
        // Known roles + privileged grant + kiosk gates on the REQUESTED
        // roles, before any MIS work. `rbac.privileged.manage` is never
        // relaxed: the superadmin wildcard is the only way to satisfy it.
        $this->assertKnownGroups($groups);
        $this->assertMayAssignGroups($actorId, $groups);
        $this->assertKioskAccountAccess($actorId, $groups);
        // An identifier already owned by another tenant is a conflict —
        // never adopt (or leak) another tenant's person.
        $this->assertNoCrossTenantIdentity($identifier, $kind);

        return $this->txn(function () use ($identifier, $kind, $groups, $actorId): array {
            $fuMis = Services::fuMisAuthService();
            $userId = $kind === 'student'
                ? $fuMis->ensureStudentProvisioned($identifier)
                : $fuMis->ensureEmployeeProvisioned($identifier);

            if ($userId === null) {
                throw ApiException::notFound('resource.not_found');
            }

            // Lock the target before reading its memberships so the union
            // is computed from the real rows, not a stale snapshot.
            $row = $this->selectForUpdate('users', [
                'id'         => $userId,
                'tenant_id'  => CurrentTenant::id(),
                'deleted_at' => null,
            ]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $previousGroups = $this->groupsFor([$userId])[$userId] ?? [];
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $added = $this->addGroupsInTxn($userId, $groups, $previousGroups, $now);

            if ($added !== []) {
                $this->audit->enqueue('admin.user_groups_changed', 'users', $userId, $actorId, [
                    'resource_code' => 'groups#' . implode(',', $added),
                ]);
            }
            $this->auditPrivilegedRoleDiff(
                $actorId,
                $userId,
                $previousGroups,
                array_values(array_unique(array_merge($previousGroups, $groups))),
            );

            return $this->get($userId);
        });
    }

    /**
     * Trigger a bulk batch sync of the university directory into local users.
     * Accessible by admins holding `rbac.manage`.
     *
     * @return array<string, mixed> Sync summary {success, dry_run, tenant_id, kinds, error?}
     */
    public function syncDirectory(string $kind = 'all', bool $dryRun = false, int $pageSize = 100, int $maxPages = 1000): array
    {
        $actorId = \App\Auth\CurrentUser::assert();
        if (! $this->permissions->userHas($actorId, 'rbac.manage')) {
            throw ApiException::forbidden('auth.forbidden');
        }

        $syncService = new \App\Services\FuMis\FuMisDirectorySyncService();
        $summary = $syncService->run($kind, $dryRun, $pageSize, $maxPages);

        if (! $dryRun && ($summary['success'] ?? false)) {
            $this->audit->enqueue('mis.directory_sync_triggered', 'users', null, $actorId, [
                'resource_code' => $kind,
                'reason_code'   => 'admin.sync_directory',
                'outcome'       => 'completed',
            ]);
        }

        return $summary;
    }

    /**
     * @param list<string> $groups
     * @return array{id:int, email:string, username:?string, groups:list<string>, temporary_password:string, force_reset:true}
     */
    public function create(string $email, ?string $password, ?string $username, array $groups): array
    {
        $actorId = \App\Auth\CurrentUser::assert();
        $this->assertAtLeastOneGroup($groups);
        $this->assertMayAssignGroups($actorId, $groups);
        $this->assertKioskAccountAccess($actorId, $groups);
        $email = strtolower(trim($email));
        $temporaryPassword = $password !== null && $password !== ''
            ? $password
            : rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');

        return $this->txn(function () use ($email, $temporaryPassword, $username, $groups, $actorId): array {
            $exists = $this->db->table('auth_identities')
                ->where('type', 'email_password')
                ->where('secret', $email)
                ->get()->getRowArray();
            if ($exists !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => 'Email already registered.', 'field' => 'email'],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('users')->insert([
                'tenant_id'  => CurrentTenant::id(),
                'username'   => $username,
                'status'     => 'active',
                'active'     => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $this->db->insertID();

            try {
                $this->db->table('auth_identities')->insert([
                    'user_id'     => $userId,
                    'type'        => 'email_password',
                    'secret'      => $email,
                    'secret2'     => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                    'force_reset' => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            } catch (DatabaseException $e) {
                if ((int) $e->getCode() === 1062 || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    throw new ApiException('resource.conflict', 409, [
                        ['code' => 'resource.conflict', 'message' => 'Email already registered.', 'field' => 'email'],
                    ], previous: $e);
                }
                throw $e;
            }

            $this->replaceGroupsInTxn($userId, $groups, $now);

            $this->audit->enqueue('admin.user_created', 'users', $userId, $actorId, [
                'resource_code' => 'groups#' . implode(',', $groups),
                'next_status'   => 'active',
            ]);
            $this->auditPrivilegedRoleDiff($actorId, $userId, [], $groups);

            return [
                'id'                 => $userId,
                'email'              => $email,
                'username'           => $username,
                'groups'             => array_values($groups),
                'temporary_password' => $temporaryPassword,
                'force_reset'        => true,
            ];
        });
    }

    public function setActive(int $userId, bool $active): array
    {
        $actorId = \App\Auth\CurrentUser::assert();
        if ($userId === $actorId && ! $active) {
            throw new ApiException('request.validation_failed', 422, [
                ['code' => 'validation.invalid', 'message' => 'You cannot deactivate your own account.'],
            ]);
        }

        return $this->txn(function () use ($userId, $active, $actorId): array {
            $row = $this->selectForUpdate('users', ['id' => $userId, 'tenant_id' => CurrentTenant::id(), 'deleted_at' => null]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('users')->where('tenant_id', CurrentTenant::id())->where('id', $userId)->update([
                'active'     => $active ? 1 : 0,
                'status'     => $active ? 'active' : 'disabled',
                'updated_at' => $now,
            ]);

            if (! $active) {
                Services::refreshTokenService()->revokeAllFor($userId);
            }

            $this->audit->enqueue('admin.user_status_changed', 'users', $userId, $actorId, [
                'previous_status' => (string) $row['status'],
                'next_status'     => $active ? 'active' : 'disabled',
            ]);

            return ['id' => $userId, 'active' => $active];
        });
    }

    /**
     * @param list<string> $groups
     */
    public function replaceGroups(int $userId, array $groups): array
    {
        $actorId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($userId, $groups, $actorId): array {
            $row = $this->selectForUpdate('users', ['id' => $userId, 'tenant_id' => CurrentTenant::id(), 'deleted_at' => null]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $previousGroups = $this->groupsFor([$userId])[$userId] ?? [];

            $this->assertAtLeastOneGroup($groups);
            $this->assertPrivilegedRemovalSafe($actorId, $userId, $previousGroups, $groups);
            // Authorize the full symmetric diff: a REVOKE of a privileged
            // role is the same authorization problem as a grant, so the
            // desired list alone is not enough.
            $this->assertMayChangePrivilegedGroups($actorId, $previousGroups, $groups);
            $this->assertKioskAccountAccess($actorId, $groups);

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->replaceGroupsInTxn($userId, $groups, $now);

            $this->audit->enqueue('admin.user_groups_changed', 'users', $userId, $actorId, [
                'resource_code' => 'groups#' . implode(',', $groups),
            ]);
            $this->auditPrivilegedRoleDiff($actorId, $userId, $previousGroups, $groups);

            return ['id' => $userId, 'groups' => array_values($groups)];
        });
    }

    public function resetPassword(int $userId): array
    {
        $actorId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($userId, $actorId): array {
            // Kiosk machine accounts: reset restricted to clinic_admin
            // or superadmin (D2).
            $this->assertKioskAccountAccess($actorId, $this->groupsFor([$userId])[$userId] ?? []);

            $identity = $this->selectForUpdate('auth_identities', ['user_id' => $userId, 'type' => 'email_password']);
            if ($identity === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $temp = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('auth_identities')
                ->where('id', (int) $identity['id'])
                ->update([
                    'secret2'     => password_hash($temp, PASSWORD_DEFAULT),
                    'force_reset' => 1,
                    'updated_at'  => $now,
                ]);

            Services::refreshTokenService()->revokeAllFor($userId);

            $this->audit->enqueue('admin.user_password_reset', 'users', $userId, $actorId, [
                'outcome' => 'reset',
            ]);

            return ['id' => $userId, 'temporary_password' => $temp, 'force_reset' => true];
        });
    }

    /**
     * @param list<string> $groups
     */
    private function replaceGroupsInTxn(int $userId, array $groups, string $now): void
    {
        $known = $this->assertKnownGroups($groups);

        $this->db->table('auth_groups_users')->where('user_id', $userId)->delete();
        foreach ($groups as $g) {
            $this->db->table('auth_groups_users')->insert([
                'group_id'   => $known[$g],
                'user_id'    => $userId,
                'created_at' => $now,
            ]);
        }
    }

    /**
     * Additive membership write: insert only the roles that are actually
     * missing, leaving every existing membership untouched.
     *
     * @param list<string> $groups         requested roles
     * @param list<string> $existingGroups the target's ACTUAL current roles
     * @return list<string> the roles actually inserted
     */
    private function addGroupsInTxn(int $userId, array $groups, array $existingGroups, string $now): array
    {
        $known = $this->assertKnownGroups($groups);
        $existing = array_flip($existingGroups);
        $added = [];
        foreach ($groups as $g) {
            if (isset($existing[$g])) {
                continue;
            }
            $this->db->table('auth_groups_users')->insert([
                'group_id'   => $known[$g],
                'user_id'    => $userId,
                'created_at' => $now,
            ]);
            $existing[$g] = true;
            $added[] = $g;
        }
        return $added;
    }

    /**
     * Validate every requested role against the authoritative group table
     * BEFORE any write. Shared by create/replace/provision so an unknown
     * role can never reach a membership insert.
     *
     * @param list<string> $groups
     * @return array<string, int> group name => group id
     */
    private function assertKnownGroups(array $groups): array
    {
        $known = [];
        foreach ($this->db->table('auth_groups')->select('id, name')->get()->getResultArray() as $g) {
            $known[(string) $g['name']] = (int) $g['id'];
        }

        foreach ($groups as $g) {
            if (! isset($known[$g])) {
                throw new ApiException('request.validation_failed', 422, [
                    ['code' => 'validation.field', 'message' => "Unknown group '{$g}'.", 'field' => 'groups'],
                ]);
            }
        }

        return $known;
    }

    /**
     * Strict shape check for a directory identifier + kind, run before any
     * MIS lookup so malformed input never triggers upstream work.
     */
    private function assertDirectoryIdentity(string $identifier, string $kind): void
    {
        if (! in_array($kind, ['student', 'employee'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'kind must be student or employee.', 'field' => 'kind'],
            ]);
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $identifier) !== 1) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'identifier is invalid.', 'field' => 'identifier'],
            ]);
        }
    }

    /**
     * A directory identifier already owned by a DIFFERENT tenant must not
     * be adopted here: the global unique key would reject the insert, and
     * resolving the foreign row would leak another tenant's person.
     */
    private function assertNoCrossTenantIdentity(string $identifier, string $kind): void
    {
        $column = $kind === 'student' ? 'student_number' : 'employee_number';
        $row = $this->db->table('users')
            ->select('id')
            ->where($column, $identifier)
            ->where('tenant_id !=', CurrentTenant::id())
            ->where('deleted_at', null)
            ->get()->getRowArray();

        if ($row !== null) {
            throw ApiException::conflict('resource.conflict', 'This university identifier belongs to another tenant.');
        }
    }

    /**
     * Privileged role set P (superadmin, clinic_admin, guidance_admin,
     * bmg_admin) may only be granted/revoked by a holder of
     * `rbac.privileged.manage` — the superadmin wildcard satisfies it.
     *
     * Grant-side check for the desired list (used by create() and the
     * additive directory path). replaceGroups() uses the symmetric
     * assertMayChangePrivilegedGroups() so revokes are authorized too.
     *
     * @param list<string> $groups
     */
    private function assertMayAssignGroups(int $actorId, array $groups): void
    {
        $privilegedTargets = array_intersect($groups, PrivilegedRoles::SET_P);
        if ($privilegedTargets !== [] && ! $this->permissions->userHas($actorId, 'rbac.privileged.manage')) {
            throw ApiException::forbidden('rbac.escalation_forbidden');
        }
    }

    /**
     * Authorize a role change by its FULL symmetric diff. A privileged
     * revoke is the same authorization problem as a privileged grant, so
     * inspecting only the desired list lets a `rbac.manage`-only unit
     * admin strip a privileged role. Never relax
     * `rbac.privileged.manage`.
     *
     * @param list<string> $previousGroups
     * @param list<string> $nextGroups
     */
    private function assertMayChangePrivilegedGroups(int $actorId, array $previousGroups, array $nextGroups): void
    {
        $changed = array_unique(array_merge(
            array_diff($nextGroups, $previousGroups),
            array_diff($previousGroups, $nextGroups),
        ));
        if (array_intersect($changed, PrivilegedRoles::SET_P) === []) {
            return;
        }
        if (! $this->permissions->userHas($actorId, 'rbac.privileged.manage')) {
            throw ApiException::forbidden('rbac.escalation_forbidden');
        }
    }

    /**
     * Kiosk accounts are MACHINE accounts: creation/reset is restricted
     * to the clinic unit administrator (or superadmin) per D2.
     *
     * @param list<string> $groups the kiosk-bearing group set being
     *                             created/assigned (or the target's
     *                             current groups when resetting)
     */
    private function assertKioskAccountAccess(int $actorId, array $groups): void
    {
        if (! in_array(PrivilegedRoles::KIOSK_GROUP, $groups, true)) {
            return;
        }
        if ($this->permissions->userHas($actorId, 'rbac.privileged.manage')) {
            return; // superadmin (wildcard) or an explicit platform grant.
        }
        $actorGroups = $this->groupsFor([$actorId])[$actorId] ?? [];
        if (in_array(PrivilegedRoles::CLINIC_ADMIN_GROUP, $actorGroups, true)) {
            return;
        }
        throw ApiException::forbidden('rbac.kiosk_restricted');
    }

    /**
     * Own-protection + continuity for privileged roles, generalized from
     * the former own/last-admin guard to the whole of set P:
     *   - a user can never remove their own LAST privileged role;
     *   - the last holder of any privileged role is irremovable
     *     (otherwise privileged management could be locked out entirely).
     *
     * @param list<string> $previousGroups the target's groups before the change
     * @param list<string> $nextGroups     the target's groups after the change
     */
    private function assertPrivilegedRemovalSafe(int $actorId, int $userId, array $previousGroups, array $nextGroups): void
    {
        $removedPrivileged = array_diff(array_intersect($previousGroups, PrivilegedRoles::SET_P), $nextGroups);
        if ($removedPrivileged === []) {
            return;
        }

        $remainingPrivileged = array_diff(array_intersect($previousGroups, PrivilegedRoles::SET_P), $removedPrivileged);
        if ($userId === $actorId && $remainingPrivileged === []) {
            throw new ApiException('request.validation_failed', 422, [
                ['code' => 'validation.invalid', 'message' => 'You cannot remove your own last privileged role.'],
            ]);
        }

        foreach ($removedPrivileged as $role) {
            if ($this->groupHolderCount((string) $role) <= 1) {
                throw new ApiException('request.validation_failed', 422, [
                    ['code' => 'validation.invalid', 'message' => "Cannot remove the last holder of the privileged role '{$role}'."],
                ]);
            }
        }
    }

    /**
     * Audits every change to set-P membership so privileged elevation is
     * always traceable (D8 action codes).
     *
     * @param list<string> $previousGroups
     * @param list<string> $nextGroups
     */
    private function auditPrivilegedRoleDiff(int $actorId, int $userId, array $previousGroups, array $nextGroups): void
    {
        foreach (array_intersect(array_diff($nextGroups, $previousGroups), PrivilegedRoles::SET_P) as $role) {
            $this->audit->enqueue('rbac.privileged_role_granted', 'users', $userId, $actorId, [
                'resource_code' => 'role#' . $role,
            ]);
        }
        foreach (array_intersect(array_diff($previousGroups, $nextGroups), PrivilegedRoles::SET_P) as $role) {
            $this->audit->enqueue('rbac.privileged_role_revoked', 'users', $userId, $actorId, [
                'resource_code' => 'role#' . $role,
            ]);
        }
    }

    /** @param list<string> $groups */
    private function assertAtLeastOneGroup(array $groups): void
    {
        if ($groups === []) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Select at least one group.', 'field' => 'groups'],
            ]);
        }
    }

    private function groupHolderCount(string $groupName): int
    {
        return (int) $this->db->table('auth_groups_users gu')
            ->join('auth_groups g', 'g.id = gu.group_id')
            ->where('g.name', $groupName)
            ->countAllResults();
    }

    /**
     * @param list<int> $userIds
     * @return array<int, list<string>>
     */
    private function groupsFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = $this->db->table('auth_groups_users gu')
            ->select('gu.user_id, g.name')
            ->join('auth_groups g', 'g.id = gu.group_id')
            ->whereIn('gu.user_id', $userIds)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']][] = (string) $r['name'];
        }
        return $out;
    }

    /**
     * Phase 1.5: build a display name from first + last.
     */
    private static function composePersonName(array $r): ?string
    {
        $first = isset($r['person_first_name']) ? trim((string) $r['person_first_name']) : '';
        $last  = isset($r['person_last_name'])  ? trim((string) $r['person_last_name'])  : '';
        if ($first === '' && $last === '') {
            return null;
        }
        return trim($first . ' ' . $last);
    }
}
