<?php

declare(strict_types=1);

namespace App\Controllers\Api\Rbac;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;

final class RoleController extends ApiController
{
    public function index(): ResponseInterface
    {
        $this->authorize('rbac.read');

        $roles = config('Config\\AuthGroups')->groups;
        return $this->ok([
            'roles' => array_map(static fn (string $code, string $name) => [
                'code'        => $code,
                'name'        => $name,
                'permissions' => config('Config\\AuthGroups')->groupPermissions[$code] ?? [],
            ], array_keys($roles), $roles),
        ]);
    }
}
