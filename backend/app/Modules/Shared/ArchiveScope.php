<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use CodeIgniter\Database\BaseBuilder;

/**
 * Which records a registry list shows, by archive state.
 *
 *   active   — live records only (the default)
 *   archived — ONLY archived records
 *   all      — live and archived together
 *
 * Lists used to take a boolean `include_archived`, which can only express
 * "live" or "live + archived". There was no way to look at archived records
 * on their own, so finding one to restore meant scanning past every live
 * row. The boolean is still accepted on the wire (the mobile client sends
 * it): `true` maps to `all`, `false` to `active`.
 *
 * Archive is soft — `archived_at IS NOT NULL`. Permanent deletion is
 * a separate, explicitly guarded operation.
 */
final class ArchiveScope
{
    public const ACTIVE = 'active';
    public const ARCHIVED = 'archived';
    public const ALL = 'all';

    /**
     * Normalise any accepted representation to one of the three scopes.
     * Unknown values fall back to `active`, the safe default: an
     * unrecognised scope must never widen what a caller can see.
     */
    public static function from(bool|string|null $value): string
    {
        if (is_bool($value)) {
            return $value ? self::ALL : self::ACTIVE;
        }
        return match (strtolower(trim((string) $value))) {
            self::ARCHIVED => self::ARCHIVED,
            self::ALL => self::ALL,
            default => self::ACTIVE,
        };
    }

    /**
     * Resolve the scope from a request's query string. The explicit
     * `archived=active|archived|all` parameter wins; otherwise the legacy
     * `include_archived=1|true` flag means `all`.
     */
    public static function fromRequest(?string $archived, ?string $includeArchived): string
    {
        if ($archived !== null && trim($archived) !== '') {
            return self::from($archived);
        }
        $legacy = strtolower(trim((string) $includeArchived));
        return self::from($legacy === '1' || $legacy === 'true');
    }

    /** Pattern A: an omitted flag preserves the caller's legacy list behavior. */
    public static function archivedFlag(mixed $value): ?bool
    {
        return $value === null ? null : in_array($value, ['1', 'true', 1, true], true);
    }

    /** Constrain a builder to the scope. `all` adds nothing. */
    public static function apply(BaseBuilder $builder, bool|string|null $scope, string $column = 'archived_at'): void
    {
        switch (self::from($scope)) {
            case self::ACTIVE:
                $builder->where($column, null);
                break;
            case self::ARCHIVED:
                $builder->where($column . ' IS NOT NULL', null, false);
                break;
        }
    }
}
