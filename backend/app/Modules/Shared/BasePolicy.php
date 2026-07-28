<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Services\Rbac\PermissionService;
use Config\Services;

/**
 * BasePolicy — Gate/Policy base class.
 *
 * Subclasses implement predicate methods (e.g., `view()`) that return
 * a bool. Service code MUST call `$policy->check($action, $record)`
 * before any state change.
 *
 * Two-stage check:
 *   1. Module-level permission (`can($code)`).
 *   2. Record-level ownership (`canOnRecord($userId, $record, $action)`).
 *
 * The record-level check defaults to `true` so policies without
 * per-record semantics (e.g. referrals, facilities units) keep their
 * module-level behavior unchanged.
 */
abstract class BasePolicy
{
    protected PermissionService $permissions;

    public function __construct(?PermissionService $permissions = null)
    {
        $this->permissions = $permissions ?? Services::permissionService();
    }

    protected function currentUserId(): int
    {
        return CurrentUser::assert();
    }

    protected function can(string $code): bool
    {
        return $this->permissions->userHas($this->currentUserId(), $code);
    }

    /**
     * Default record-level ownership check. Override in subclasses to
     * express "user can only act on their own rows" semantics.
     *
     * @param array<string, mixed>|object|null $record
     */
    protected function canOnRecord(int $userId, mixed $record, string $action): bool
    {
        return true;
    }

    /**
     * Two-stage enforcement. Subclasses resolve `$action` to a
     * permission code, then call this to run both gates.
     */
    protected function enforce(string $permissionCode, string $action, mixed $record = null): void
    {
        if (! $this->can($permissionCode)) {
            $this->deny('rbac.permission_denied:' . $permissionCode);
        }
        if ($record !== null && ! $this->canOnRecord($this->currentUserId(), $record, $action)) {
            $this->deny('rbac.record.forbidden');
        }
    }

    /**
     * Throw unless the predicate passes.
     */
    abstract public function check(string $action, mixed $record = null): void;

    protected function deny(string $code = 'rbac.forbidden'): never
    {
        throw ApiException::forbidden($code);
    }
}