<?php

declare(strict_types=1);

namespace App\Controllers\Api\Admin;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Pagination\KeysetPaginator;
use App\Services\Admin\UserAdminService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

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

        $cursorRaw = $this->request->getGet('cursor');
        $cursor = is_string($cursorRaw) ? trim($cursorRaw) : '';
        $limitRaw = $this->request->getGet('limit');
        $limit = $limitRaw === null ? 25 : filter_var($limitRaw, FILTER_VALIDATE_INT);
        $search = trim((string) ($this->request->getGet('q') ?? ''));
        $status = trim((string) ($this->request->getGet('status') ?? 'all'));
        $group = trim((string) ($this->request->getGet('group') ?? 'all'));
        $sort = trim((string) ($this->request->getGet('sort') ?? 'newest'));
        $kind = trim((string) ($this->request->getGet('kind') ?? 'all'));

        $errors = [];
        if ($limit === false || $limit < 1 || $limit > 100) {
            $errors[] = ['code' => 'validation.field', 'message' => 'limit must be between 1 and 100.', 'field' => 'limit'];
        }
        if ($cursor !== '' && KeysetPaginator::decode($cursor) === null) {
            $errors[] = ['code' => 'validation.field', 'message' => 'cursor is invalid.', 'field' => 'cursor'];
        }
        if (mb_strlen($search) > 100) {
            $errors[] = ['code' => 'validation.field', 'message' => 'q must not exceed 100 characters.', 'field' => 'q'];
        }
        if (! in_array($status, ['all', 'active', 'disabled'], true)) {
            $errors[] = ['code' => 'validation.field', 'message' => 'status must be all, active, or disabled.', 'field' => 'status'];
        }
        if ($group !== 'all' && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $group) !== 1) {
            $errors[] = ['code' => 'validation.field', 'message' => 'group is invalid.', 'field' => 'group'];
        }
        if (! in_array($sort, ['newest', 'oldest'], true)) {
            $errors[] = ['code' => 'validation.field', 'message' => 'sort must be newest or oldest.', 'field' => 'sort'];
        }
        // `unlinked` is the NULL-kind bucket, not an enum member.
        if (! in_array($kind, ['all', 'student', 'employee', 'contractor', 'alumni', 'unlinked'], true)) {
            $errors[] = ['code' => 'validation.field', 'message' => 'kind must be all, student, employee, contractor, alumni, or unlinked.', 'field' => 'kind'];
        }
        if ($errors !== []) {
            throw ApiException::validationFailure($errors);
        }

        $page = $this->service->list(
            $cursor !== '' ? $cursor : null,
            (int) $limit,
            $search,
            $status,
            $group,
            $sort,
            $kind,
        );

        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta((int) $limit, $page['next'], null) + [
                'result_count' => $page['count'],
            ],
        );
    }

    /**
     * Person-type facet options for the Users list filter — the distinct
     * `users.kind` values present in the tenant, plus `unlinked` when any
     * platform account carries no person record.
     */
    public function facets(): ResponseInterface
    {
        $this->authorize('rbac.read');

        return $this->ok($this->service->kindFacets());
    }

    public function create(): ResponseInterface
    {
        $this->authorize('rbac.manage');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'email'    => 'required|valid_email|max_length[255]',
            'password' => 'permit_empty|min_length[12]|max_length[256]',
            'username' => 'permit_empty|alpha_dash|max_length[64]',
            'groups'   => 'permit_empty',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $groups = is_array($payload['groups'] ?? null)
            ? array_values(array_unique(array_map(static fn ($group): string => trim((string) $group), $payload['groups'])))
            : [];

        $out = $this->service->create(
            (string) $payload['email'],
            isset($payload['password']) && $payload['password'] !== '' ? (string) $payload['password'] : null,
            isset($payload['username']) && $payload['username'] !== '' ? (string) $payload['username'] : null,
            $groups,
        );
        return $this->ok($out, null, 201);
    }

    public function provision(): ResponseInterface
    {
        $this->authorize('rbac.manage');
        $payload = $this->request->getJSON(true) ?? [];

        $identifier = trim((string) ($payload['identifier'] ?? ''));
        $kind = trim((string) ($payload['kind'] ?? ''));

        if ($identifier === '' || ! in_array($kind, ['student', 'employee'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Valid identifier and kind (student/employee) are required.', 'field' => 'identifier'],
            ]);
        }

        if (array_key_exists('groups', $payload) && ! is_array($payload['groups'])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'groups must be an array of group codes.', 'field' => 'groups'],
            ]);
        }

        $groups = is_array($payload['groups'] ?? null)
            ? array_values(array_unique(array_map(static fn ($group): string => trim((string) $group), $payload['groups'])))
            : [];

        return $this->ok($this->service->provisionDirectoryUser($identifier, $kind, $groups), null, 200);
    }

    public function syncDirectory(): ResponseInterface
    {
        $this->authorize('rbac.manage');
        $payload = $this->request->getJSON(true) ?? [];

        $kind = trim((string) ($payload['kind'] ?? 'all'));
        if (! in_array($kind, ['all', 'student', 'employee'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'kind must be all, student, or employee.', 'field' => 'kind'],
            ]);
        }

        $dryRun = (bool) ($payload['dry_run'] ?? false);
        $pageSize = filter_var($payload['page_size'] ?? 100, FILTER_VALIDATE_INT) ?: 100;
        $maxPages = filter_var($payload['max_pages'] ?? 1000, FILTER_VALIDATE_INT) ?: 1000;

        $summary = $this->service->syncDirectory($kind, $dryRun, $pageSize, $maxPages);

        return $this->ok($summary, null, 200);
    }

    public function setStatus(int $userId): ResponseInterface
    {
        $this->authorize('rbac.manage');
        $payload = $this->request->getJSON(true) ?? [];

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

        $groups = array_values(array_unique(array_map(static fn ($group): string => trim((string) $group), $payload['groups'])));
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
