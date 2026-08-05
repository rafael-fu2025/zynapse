<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use App\Pagination\KeysetPaginator;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;

/**
 * UserAdminService — identity-consolidated admin user management.
 *
 * Users ARE the person: list/create read `users.kind`, `users.first_name`
 * and `users.last_name` directly (no `persons` join, no `person_id` link).
 */
final class UserAdminService extends BaseService
{
    public function __construct(private readonly AuditOutboxService $audit)
    {
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
            ->where('u.deleted_at', null);

        if ($search !== '') {
            $builder->groupStart()
                ->like('u.username', $search)
                ->orLike('i.secret', $search)
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

        return [
            'data' => array_map(static fn (array $r): array => [
                'id'             => (int)    $r['id'],
                'username'       => $r['username'] !== null ? (string) $r['username'] : null,
                'email'          => $r['email'] !== null ? (string) $r['email'] : null,
                'active'         => (bool)   $r['active'],
                'status'         => (string) $r['status'],
                'groups'         => $groupsByUser[(int) $r['id']] ?? [],
                'person_kind'    => $r['person_kind'] !== null ? (string) $r['person_kind'] : null,
                'person_name'    => self::composePersonName($r),
                'created_at'     => (string) $r['created_at'],
                'updated_at'     => (string) $r['updated_at'],
                'last_active'    => $r['last_active'] !== null ? (string) $r['last_active'] : null,
                'force_reset'    => (bool)   $r['force_reset'],
            ], $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => count($final['rows']),
        ];
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
            $row = $this->selectForUpdate('users', ['id' => $userId, 'deleted_at' => null]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('users')->where('id', $userId)->update([
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
            $row = $this->selectForUpdate('users', ['id' => $userId, 'deleted_at' => null]);
            if ($row === null) {
                throw ApiException::notFound('resource.not_found');
            }

            $this->assertAtLeastOneGroup($groups);
            $this->assertMayAssignGroups($actorId, $groups);
            $this->assertNotRemovingLastOrOwnAdmin($actorId, $userId, $groups);

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->replaceGroupsInTxn($userId, $groups, $now);

            $this->audit->enqueue('admin.user_groups_changed', 'users', $userId, $actorId, [
                'resource_code' => 'groups#' . implode(',', $groups),
            ]);

            return ['id' => $userId, 'groups' => array_values($groups)];
        });
    }

    public function resetPassword(int $userId): array
    {
        $actorId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($userId, $actorId): array {
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
     * @param list<string> $groups
     */
    private function assertMayAssignGroups(int $actorId, array $groups): void
    {
        if (in_array('admin', $groups, true) && ! $this->userIsAdmin($actorId)) {
            throw ApiException::forbidden('rbac.escalation_forbidden');
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

    /**
     * @param list<string> $groups
     */
    private function assertNotRemovingLastOrOwnAdmin(int $actorId, int $userId, array $groups): void
    {
        if (in_array('admin', $groups, true)) {
            return;
        }
        if (! $this->userIsAdmin($userId)) {
            return;
        }
        if ($userId === $actorId) {
            throw new ApiException('request.validation_failed', 422, [
                ['code' => 'validation.invalid', 'message' => 'You cannot remove your own admin role.'],
            ]);
        }
        if ($this->adminCount() <= 1) {
            throw new ApiException('request.validation_failed', 422, [
                ['code' => 'validation.invalid', 'message' => 'Cannot remove the last administrator.'],
            ]);
        }
    }

    private function userIsAdmin(int $userId): bool
    {
        return (int) $this->db->table('auth_groups_users gu')
            ->join('auth_groups g', 'g.id = gu.group_id')
            ->where('g.name', 'admin')
            ->where('gu.user_id', $userId)
            ->countAllResults() > 0;
    }

    private function adminCount(): int
    {
        return (int) $this->db->table('auth_groups_users gu')
            ->join('auth_groups g', 'g.id = gu.group_id')
            ->where('g.name', 'admin')
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
