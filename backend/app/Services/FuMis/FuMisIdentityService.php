<?php

declare(strict_types=1);

namespace App\Services\FuMis;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\CurrentTenant;

/**
 * One identity writer for login, directory provisioning and directory sync.
 * MIS owns profile fields, not Synapse credentials, roles or account state.
 * Identifiers are globally unique; cross-tenant/cross-kind matches fail closed.
 */
final class FuMisIdentityService extends BaseService
{
    /** @var array<string, bool> */
    private array $checkedIndexes = [];

    public static function identifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($identifier === '' || mb_strlen($identifier) > 50 || preg_match('/[\x00-\x1F\x7F]/', $identifier)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'field' => 'identifier', 'message' => 'A valid MIS identifier (1-50 characters) is required.'],
            ]);
        }
        return $identifier;
    }

    public static function column(string $kind): string
    {
        return match ($kind) {
            'student' => 'student_number',
            'employee' => 'employee_number',
            default => throw ApiException::validationFailure([
                ['code' => 'validation.field', 'field' => 'kind', 'message' => 'Kind must be student or employee.'],
            ]),
        };
    }

    public function findUserId(string $kind, string $identifier): ?int
    {
        $column = self::column($kind);
        $identifier = self::identifier($identifier);
        $row = $this->find($column, $identifier);
        if ($row === null) {
            return null;
        }
        $this->assertMatch($row, $kind, $column, $identifier);
        if ($row['deleted_at'] !== null || $row['archived_at'] !== null) {
            throw ApiException::conflict('resource.conflict', 'This MIS identity is archived or deleted; restore it explicitly before provisioning.');
        }
        return (int) $row['id'];
    }

    /** @return array{id: ?int, outcome: string} */
    public function upsert(string $kind, array $profile, bool $dryRun = false): array
    {
        $column = self::column($kind);
        $identifier = self::identifier((string) ($profile['identifier'] ?? ''));
        // Older migrations could fall back to a NON-unique index on dirty data.
        // Never pretend check-then-insert is safe in that schema.
        $this->assertUniqueIndex($column);
        $fields = $this->profileFields($kind, $profile);
        $group = $this->db->table('auth_groups')->select('id')->where('name', $kind)->get()->getRowArray();
        if ($group === null) {
            throw ApiException::conflict('resource.conflict', 'MIS base role is missing; run the RBAC seeder first.');
        }

        if ($dryRun) {
            $row = $this->find($column, $identifier);
            if ($row !== null) {
                $this->assertMatch($row, $kind, $column, $identifier);
                if ($this->hidden($row)) {
                    return ['id' => (int) $row['id'], 'outcome' => 'skipped'];
                }
                $changed = $this->changed($row, $fields) || ! $this->hasGroup((int) $row['id'], (int) $group['id']);
                return ['id' => (int) $row['id'], 'outcome' => $changed ? 'updated' : 'unchanged'];
            }
            $this->assertUsernameAvailable($kind, $identifier);
            return ['id' => null, 'outcome' => 'created'];
        }

        return $this->txn(function () use ($kind, $column, $identifier, $fields, $group): array {
            $row = $this->find($column, $identifier);
            $created = false;
            $now = gmdate('Y-m-d H:i:s');
            if ($row === null) {
                $insert = $fields + [
                    'tenant_id' => CurrentTenant::id(), $column => $identifier,
                    'username' => ($kind === 'student' ? 'stu-' : 'emp-') . $identifier,
                    'active' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ];
                // Bound values, code-owned column names. The unique identifier
                // wins races with concurrent login/provision/sync. A duplicate
                // username never merges people: assertMatch checks the winner.
                $columns = '`' . implode('`, `', array_keys($insert)) . '`';
                $placeholders = implode(', ', array_fill(0, count($insert), '?'));
                $this->db->query(
                    "INSERT INTO `users` ({$columns}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)",
                    array_values($insert),
                );
                $created = $this->db->affectedRows() === 1;
                $userId = (int) $this->db->insertID();
            } else {
                $userId = (int) $row['id'];
            }
            // The exact identity/tenant is checked AFTER locking, including a
            // row returned by any unique-key conflict. Never update it first.
            $row = $this->selectForUpdate('users', ['id' => $userId]);
            if ($row === null) {
                throw ApiException::conflict('resource.conflict', 'MIS identity could not be resolved safely.');
            }
            $this->assertMatch($row, $kind, $column, $identifier);
            if ($this->hidden($row)) {
                return ['id' => $userId, 'outcome' => 'skipped'];
            }
            $changed = $this->changed($row, $fields);
            if ($changed) {
                $this->db->table('users')->where('tenant_id', CurrentTenant::id())->where('id', $userId)
                    ->update($fields + ['updated_at' => $now]);
            }
            if (! $this->hasGroup($userId, (int) $group['id'])) {
                $this->db->table('auth_groups_users')->insert([
                    'user_id' => $userId, 'group_id' => (int) $group['id'], 'created_at' => $now,
                ]);
                $changed = true;
            }
            return ['id' => $userId, 'outcome' => $created ? 'created' : ($changed ? 'updated' : 'unchanged')];
        });
    }

    private function find(string $column, string $identifier): ?array
    {
        // Global lookup is required by the global identity index. Tenant access
        // is checked before any data is returned to a caller or written.
        $rows = $this->db->table('users')->where($column, $identifier)->limit(2)->get()->getResultArray();
        if (count($rows) > 1) {
            throw ApiException::conflict('resource.conflict', 'Duplicate MIS identities require manual reconciliation.');
        }
        return $rows[0] ?? null;
    }

    private function assertMatch(array $row, string $kind, string $column, string $identifier): void
    {
        if ((int) $row['tenant_id'] !== CurrentTenant::id()) {
            throw ApiException::notFound('resource.not_found');
        }
        if (strcasecmp(trim((string) ($row[$column] ?? '')), $identifier) !== 0
            || ($row['kind'] !== null && $row['kind'] !== $kind)) {
            throw ApiException::conflict('resource.conflict', 'MIS identifier conflicts with an existing local identity.');
        }
    }

    private function hidden(array $row): bool
    {
        return $row['deleted_at'] !== null || $row['archived_at'] !== null || ! (bool) $row['active'];
    }

    private function changed(array $row, array $fields): bool
    {
        foreach ($fields as $key => $value) {
            if ((string) ($row[$key] ?? '') !== (string) $value) {
                return true;
            }
        }
        return false;
    }

    private function hasGroup(int $userId, int $groupId): bool
    {
        return $this->db->table('auth_groups_users')->where(['user_id' => $userId, 'group_id' => $groupId])->countAllResults() > 0;
    }

    private function assertUsernameAvailable(string $kind, string $identifier): void
    {
        if ($this->db->table('users')->where('username', ($kind === 'student' ? 'stu-' : 'emp-') . $identifier)->countAllResults() > 0) {
            throw ApiException::conflict('resource.conflict', 'MIS username conflicts with an existing local account.');
        }
    }

    private function assertUniqueIndex(string $column): void
    {
        if (isset($this->checkedIndexes[$column])) {
            return;
        }
        $indexes = [];
        foreach ($this->db->query('SHOW INDEX FROM `users`')->getResultArray() as $index) {
            if ((int) $index['Non_unique'] === 0) {
                $indexes[$index['Key_name']][] = [$index['Column_name'], $index['Sub_part']];
            }
        }
        foreach ($indexes as $parts) {
            if ($parts === [[$column, null]]) {
                $this->checkedIndexes[$column] = true;
                return;
            }
        }
        throw ApiException::conflict('resource.conflict', 'MIS sync requires a full unique identifier index; reconcile legacy duplicates first.');
    }

    /** Only mapped demographic fields: never passwords, roles, active or archive flags. */
    private function profileFields(string $kind, array $profile): array
    {
        $limits = ['first_name' => 100, 'middle_name' => 100, 'last_name' => 100, 'department' => 100];
        $limits += $kind === 'student' ? ['course' => 100, 'section' => 20] : ['position' => 100, 'employment_status' => 20];
        $fields = ['kind' => $kind];
        foreach ($limits as $field => $limit) {
            if (($profile[$field] ?? null) !== null) {
                if (! is_string($profile[$field]) || mb_strlen($profile[$field]) > $limit) {
                    throw ApiException::validationFailure([
                        ['code' => 'validation.field', 'field' => $field, 'message' => 'MIS profile field has an invalid type or length.'],
                    ]);
                }
                $fields[$field] = $profile[$field];
            }
        }
        if ($kind === 'student' && ($profile['year_level'] ?? null) !== null) {
            if (! is_int($profile['year_level']) || $profile['year_level'] < 0 || $profile['year_level'] > 255) {
                throw ApiException::validationFailure([['code' => 'validation.field', 'field' => 'year_level', 'message' => 'Invalid MIS year level.']]);
            }
            $fields['year_level'] = $profile['year_level'];
        }
        if ($kind === 'employee' && ($profile['is_teaching'] ?? null) !== null) {
            $fields['is_teaching'] = $profile['is_teaching'] ? 1 : 0;
        }
        return $fields;
    }
}
