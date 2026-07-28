<?php

declare(strict_types=1);

namespace App\Controllers\Api\Rbac;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;

final class PermissionController extends ApiController
{
    public function index(): ResponseInterface
    {
        $this->authorize('rbac.read');
        return $this->ok(['permissions' => $this->permissions->all()]);
    }
}
