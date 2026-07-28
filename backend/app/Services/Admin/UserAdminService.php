<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use App\Pagination\KeysetPaginator;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;

/**
 * UserAdminService — administrative user lifecycle (Phase 10).
 *
 * Gated by `rbac.manage` at the controller. Rules:
 *   - NEVER physical DELETE: deactivation flips `users.active` only.
 *   - Password resets store a new hash and set `force_reset`; the
 *     plaintext is returned ONCE to the admin caller and never logged.
 *   - Audit context carries user ids and group codes — never emails
 *     or password material.
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
    public function list(?string $cursor, int $limit): array
    {
        $builder = $this->db->table('users u')
            ->select("u.id, u.username, u.status, u.active, u.created_at, i.secret AS email")
            ->join("auth_identities i", "i.user_id = u.id AND i.type = 'email_password'", 'left')
            ->where('u.deleted_at', null)
            ->orderBy('u.created_at', 'DESC')
            ->orderBy('u.id', 'DESC');

        KeysetPaginator::apply($builder, $cursor, $limit, 'u.created_at', 'u.id');
        $rows = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit, 'u.created_at');

        $groupsByUser = $this->groupsFor(array_map(static fn (array $r) => (int) $r['id'], $final['rows']));

        return [
            'data' => array_map(static fn (array $r): array => [
                'id'         => (int)    $r['id'],
                'username'   => $r['username'] !== null ? (string) $r['username'] : null,
                'email'      => $r['email'] !== null ? (string) $r['email'] : null,
                'active'     => (bool)   $r['active'],
                'status'     => (string) $r['status'],
                'groups'     => $groupsByUser[(int) $r['id']] ?? [],
                'created_at' => (string) $r['created_at'],
            ], $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    /**
     * @param list<string> $groups
     * @return array{id:int, email:string, username:?string, groups:list<string>}
     */
    public function create(string $email, string $password, ?string $username, array $groups): array
    {
        $actorId = \App\Auth\CurrentUser::assert();
        $email = strtolower(trim($email));

        return $this->txn(function () use ($email, $password, $username, $groups, $actorId): array {
            $exists = $this->db->table('auth_identities')
                ->where('type', 'email_password')
                ->where('LOWER(secret)', $email)
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

            $this->db->table('auth_identities')->insert([
                'user_id'    => $userId,
                'type'       => 'email_password',
                'secret'     => $email,
                'secret2'    => password_hash($password, PASSWORD_DEFAULT),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->replaceGroupsInTxn($userId, $groups, $now);

            $this->audit->enqueue('admin.user_created', 'users', $userId, $actorId, [
                'resource_code' => 'groups#' . implode(',', $groups),
                'next_status'   => 'active',
            ]);

            return ['id' => $userId, 'email' => $email, 'username' => $username, 'groups' => array_values($groups)];
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

            // Kill refresh-token families on deactivation.
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

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->replaceGroupsInTxn($userId, $groups, $now);

            $this->audit->enqueue('admin.user_groups_changed', 'users', $userId, $actorId, [
                'resource_code' => 'groups#' . implode(',', $groups),
            ]);

            return ['id' => $userId, 'groups' => array_values($groups)];
        });
    }

    /**
     * Admin-driven password reset. Returns a CSPRNG temporary password
     * exactly once; `force_reset` marks the identity for rotation at
     * next login (UI flow).
     */
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

        // Membership is not an operational record — replacement is the
        // whole point of the endpoint, and every change is audited.
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
}
