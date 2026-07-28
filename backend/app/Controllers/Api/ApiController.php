<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Services\Rbac\PermissionService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Validation\ValidationInterface;
use Config\Services;
use Psr\Log\LoggerInterface;

/**
 * BaseApiController — thin-controller contract.
 *
 * Subclasses:
 *   1. Validate input (via `$this->makeValidation(...)` + rule arrays).
 *   2. Authorize via `$this->authorize('module.action')`.
 *   3. Delegate to a Service.
 *   4. Respond with `$this->ok($dto)`.
 *
 * NO business logic, NO direct DB access.
 */
abstract class ApiController extends Controller
{
    protected PermissionService $permissions;

    /**
     * Last validator built by `makeValidation()` — controllers read
     * `$this->validation->getErrors()` after a failed run.
     */
    protected ValidationInterface $validation;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->permissions = Services::permissionService();
    }

    /**
     * Authorize a permission. Throws 403 on denial.
     */
    protected function authorize(string $code): void
    {
        $userId = CurrentUser::assert();
        if (! $this->permissions->userHas($userId, $code)) {
            throw ApiException::forbidden('rbac.permission_denied:'.$code);
        }
    }

    /**
     * Build a fresh JSON validator for the given rule set. Named
     * `makeValidation` because `Controller::validate()` is final-shaped
     * (returns bool) and cannot be re-declared with a fluent return.
     */
    protected function makeValidation(array $rules, array $messages = []): ValidationInterface
    {
        $this->validation = Services::validation(null, false)->setRules($rules, $messages);
        return $this->validation;
    }

    protected function ok(mixed $data = null, ?array $meta = null, int $status = 200): ResponseInterface
    {
        $body = \App\Http\ApiResponse::success($data, $meta, $status);
        return Services::response()
            ->setStatusCode($body['status'])
            ->setJSON($body['body']);
    }

    protected function fail(array $errors, int $status = 400): ResponseInterface
    {
        $body = \App\Http\ApiResponse::failure($errors, $status);
        return Services::response()
            ->setStatusCode($body['status'])
            ->setJSON($body['body']);
    }
}