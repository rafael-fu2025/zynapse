<?php

declare(strict_types=1);

namespace App\Services\Rbac;

use Config\AuthGroups;
use Config\Services;

/**
 * PermissionService — DB-driven RBAC.
 *
 * Effective permissions for `user_id` are computed as
 *   effective = (global_permissions UNION group_permissions) + adminWildcard
 *
 * Caches the result per-process to avoid hammering the DB on every
 * `authorize()` call. Cache is keyed on `(userId, groupSnapshot)`.
 *
 * NEVER hardcode role checks; always ask this service.
 */
final class PermissionService
{
    /** @var array<int|string, array<int, string>> */
    private array $userCache = [];
    
    /**
     * Permission codes the admin wildcard does NOT satisfy on its own.
     * They MUST be granted explicitly (via a group or a per-user grant),
     * so a bare "system administrator" cannot read or write clinical
     * counselling records without a deliberate, separate grant.
     *
     * Segregation of duties (RBAC_SECURITY_REVIEW R1): mental-health notes
     * are the most sensitive data in the system; standing wildcard access
     * violates least privilege.
     *
     * @var array<int, string>
     */
    public const WILDCARD_EXCLUSIONS = [
        'counselling.records.create',
        'counselling.records.read',
        'counselling.records.write',
    ];
    
    public function userHas(int $userId, string $code): bool
    {
        /** @var AuthGroups $groups */
        $groups = config(AuthGroups::class);

        return $this->grants($this->allForUser($userId), $code, $groups->adminWildcard);
    }
    
    /**
     * Pure grant decision over an already-resolved effective list.
     * Extracted so the wildcard-exclusion policy is unit-testable without
     * a database.
     *
     * A code in WILDCARD_EXCLUSIONS is granted ONLY when it appears
     * explicitly in `$effective`; the wildcard alone never satisfies it.
     * Because `allForUser()` collapses a wildcard holder's list to
     * `['*']`, an admin (even one carrying legacy per-user grants) is
     * denied excluded codes — a dedicated non-admin role must hold them.
     *
     * @param array<int, string> $effective
     */
    public function grants(array $effective, string $code, string $wildcard = '*'): bool
    {
        if (in_array($code, self::WILDCARD_EXCLUSIONS, true)) {
            return in_array($code, $effective, true);
        }
    
        if (in_array($wildcard, $effective, true)) {
            return true;
        }
    
        return in_array($code, $effective, true);
    }

    /**
     * @return array<int, string>
     */
    public function allForUser(int $userId): array
    {
        if (isset($this->userCache[$userId])) {
            return $this->userCache[$userId];
        }

        /** @var \Config\AuthGroups $groups */
        $groups = config('Config\\AuthGroups');

        // Step 1: Pull the user's group memberships from Shield.
        $userGroups = $this->fetchUserGroups($userId);

        $agg = [];
        foreach ($userGroups as $g) {
            foreach ($groups->groupPermissions[$g] ?? [] as $p) {
                $agg[$p] = true;
            }
        }

        // Step 2: Pull per-user explicit permissions from `permissions`/`user_permissions`.
        $extra = $this->fetchUserDirectPermissions($userId);
        foreach ($extra as $p) {
            $agg[$p] = true;
        }

        // Step 3: Apply admin wildcard.
        if (in_array('admin', $userGroups, true)) {
            $agg[$groups->adminWildcard] = true;
        }

        $list = array_keys($agg);
        // If wildcard present, return wildcard only — semantics are "all permissions".
        if (in_array($groups->adminWildcard, $list, true)) {
            $list = [$groups->adminWildcard];
        }

        return $this->userCache[$userId] = $list;
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        // Pull every distinct permission code from DB for SPA gating.
        $rows = Services::database()
            ->table('permissions')
            ->select('code')
            ->orderBy('code', 'ASC')
            ->get()
            ->getResultArray();

        return array_column($rows, 'code');
    }

    /**
     * @return array<int, string>
     */
    private function fetchUserGroups(int $userId): array
    {
        // `auth_groups_users` stores `group_id` — resolve to group names.
        $rows = Services::database()
            ->table('auth_groups_users AS gu')
            ->select('g.name')
            ->join('auth_groups AS g', 'g.id = gu.group_id')
            ->where('gu.user_id', $userId)
            ->get()
            ->getResultArray();

        return array_column($rows, 'name');
    }

    /**
     * @return array<int, string>
     */
    private function fetchUserDirectPermissions(int $userId): array
    {
        $rows = Services::database()
            ->table('user_permissions')
            ->select('permission_code')
            ->where('user_id', $userId)
            ->get()
            ->getResultArray();

        return array_column($rows, 'permission_code');
    }
}