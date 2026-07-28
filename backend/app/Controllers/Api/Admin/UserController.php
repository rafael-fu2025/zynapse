<?php

declare(strict_types=1);

namespace App\Controllers\Api\Admin;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Services\Admin\UserAdminService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * UserController — administrative user lifecycle. Every endpoint
 * requires `rbac.manage` (list additionally accepts `rbac.read`).
 *
 * The temporary password from `resetPassword` appears ONLY in the
 * response body — never in logs or audit rows.
 */
final class UserController extends ApiController
{
    private readonly UserAdminService $service;

    public function __construct(?UserAdminService $service = null)
    {
        $this->service = $service ?? new UserAdminService(Services::auditOutbox());
    }

    public function index(): ResponseInterface
    {
        $this->authorize('rbac.read');

        $cursor = (string) ($this->request->getGet('cursor') ?? '');
        $limit  = (int)    ($this->request->getGet('limit')  ?? 25);

        $page = $this->service->list($cursor !== '' ? $cursor : null, $limit);

        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    public function create(): ResponseInterface
    {
        $this->authorize('rbac.manage');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'email'    => 'required|valid_email|max_length[255]',
            'password' => 'required|min_length[12]|max_length[256]',
            'username' => 'permit_empty|alpha_dash|max_length[64]',
            'groups'   => 'permit_empty',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $groups = is_array($payload['groups'] ?? null) ? array_values(array_map('strval', $payload['groups'])) : [];

        $out = $this->service->create(
            (string) $payload['email'],
            (string) $payload['password'],
            isset($payload['username']) && $payload['username'] !== '' ? (string) $payload['username'] : null,
            $groups,
        );
        return $this->ok($out, null, 201);
    }

    public function setStatus(int $userId): ResponseInterface
    {
        $this->authorize('rbac.manage');
        $payload = $this->request->getJSON(true) ?? [];

        // NOTE: CI4's `required` rule treats boolean false as "empty",
        // so `{"active": false}` must be validated by hand.
        if (! array_key_exists('active', $payload) || ! is_bool($payload['active'])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'active must be a boolean.', 'field' => 'active'],
            ]);
        }

        return $this->ok($this->service->setActive($userId, $payload['active']));
    }

    public function setGroups(int $userId): ResponseInterface
    {
        $this->authorize('rbac.manage');
        $payload = $this->request->getJSON(true) ?? [];

        if (! is_array($payload['groups'] ?? null)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'groups must be an array of group codes.', 'field' => 'groups'],
            ]);
        }

        $groups = array_values(array_map('strval', $payload['groups']));
        return $this->ok($this->service->replaceGroups($userId, $groups));
    }

    public function resetPassword(int $userId): ResponseInterface
    {
        $this->authorize('rbac.manage');
        return $this->ok($this->service->resetPassword($userId));
    }

    private function collectErrors(): array
    {
        $errs = [];
        foreach ($this->validation->getErrors() as $field => $msg) {
            $errs[] = ['code' => 'validation.field', 'message' => (string) $msg, 'field' => (string) $field];
        }
        return $errs;
    }
}
