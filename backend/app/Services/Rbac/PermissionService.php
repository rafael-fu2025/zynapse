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
     *
     * Kept as a mechanism (consulted by `grants()`) even though it is
     * currently empty. `counselling.records.*` were previously excluded
     * for segregation of duties (RBAC_SECURITY_REVIEW R1) — mental-health
     * notes are the most sensitive data in the system. Per product
     * decision the admin restriction under counselling has been lifted,
     * so a bare administrator's wildcard now grants those codes too.
     * Re-add codes here if a future permission must be wildcard-exempt.
     *
     * @var array<int, string>
     */
    public const WILDCARD_EXCLUSIONS = [];
    
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
     * The list is currently empty (the counselling records R1 exclusion
     * was lifted), so every code is satisfiable by the wildcard today.
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
     * Resolve every user id whose effective grants include `$code`.
     *
     * Permission-driven notification fan-out ("notify everyone who can
     * acknowledge counselling-bound referrals"): group memberships are
     * resolved from `Config\AuthGroups::$groupPermissions` (the source
     * of truth), the admin group always qualifies via the wildcard, and
     * explicit per-user grants in `user_permissions` are folded in.
     *
     * @return array<int, int>
     */
    public function userIdsWithPermission(string $code): array
    {
        /** @var AuthGroups $groups */
        $groups = config(AuthGroups::class);

        $wantedGroups = [];
        foreach ($groups->groupPermissions as $groupName => $perms) {
            if ($groupName === 'admin' || $this->grants($perms, $code, $groups->adminWildcard)) {
                $wantedGroups[] = $groupName;
            }
        }

        $db = Services::database();
        $ids = [];

        if ($wantedGroups !== []) {
            $rows = $db->table('auth_groups_users AS gu')
                ->select('gu.user_id')
                ->join('auth_groups AS g', 'g.id = gu.group_id')
                ->whereIn('g.name', $wantedGroups)
                ->get()
                ->getResultArray();
            foreach ($rows as $r) {
                $ids[] = (int) $r['user_id'];
            }
        }

        // Explicit per-user grants also satisfy the code.
        $direct = $db->table('user_permissions')
            ->select('user_id')
            ->where('permission_code', $code)
            ->get()
            ->getResultArray();
        foreach ($direct as $r) {
            $ids[] = (int) $r['user_id'];
        }

        return array_values(array_unique($ids));
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