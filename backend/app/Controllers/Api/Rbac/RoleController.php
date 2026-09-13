<?php

declare(strict_types=1);

namespace App\Controllers\Api\Rbac;

use App\Controllers\Api\ApiController;
use App\Services\Rbac\PrivilegedRoles;
use CodeIgniter\HTTP\ResponseInterface;

final class RoleController extends ApiController
{
    public function index(): ResponseInterface
    {
        $this->authorize('rbac.read');

        /** @var \Config\AuthGroups $groups */
        $groups = config('Config\\AuthGroups');
        $roles  = $groups->groups;
        return $this->ok([
            'roles' => array_map(static fn (string $code, string $name) => [
                'code'        => $code,
                'name'        => $name,
                'permissions' => $groups->groupPermissions[$code] ?? [],
                // Privileged set P — grant/revoke requires
                // `rbac.privileged.manage` (superadmin only); the UI
                // mirrors this but the backend remains the gate.
                'privileged'  => in_array($code, PrivilegedRoles::SET_P, true),
                // The ONLY wildcard holder ('*').
                'wildcard'    => $code === PrivilegedRoles::WILDCARD_GROUP,
            ], array_keys($roles), $roles),
        ]);
    }
}
