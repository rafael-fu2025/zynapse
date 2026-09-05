<?php

declare(strict_types=1);

namespace Modules\Counselling\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Counselling\Policies\CounsellingPolicy;
use Modules\Counselling\Services\QueueService;

final class QueueController extends ApiController
{
    private readonly QueueService $service;

    public function __construct(?QueueService $service = null)
    {
        $this->service = $service ?? new QueueService(
            new CounsellingPolicy(),
            Services::auditOutbox(),
            Services::notificationOutbox(),
        );
    }

    public function today(): ResponseInterface
    {
        return $this->ok($this->service->today());
    }

    public function callNext(): ResponseInterface
    {
        return $this->ok($this->service->callNext());
    }

    public function transition(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        if (! $this->makeValidation(['action' => 'required|in_list[start,skip,complete]'])->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        return $this->ok($this->service->transition($id, (string) $payload['action']));
    }

    public function repairSession(int $id): ResponseInterface
    {
        return $this->ok($this->service->repairSession($id));
    }

    public function reassign(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        if (! $this->makeValidation(['assigned_counsellor_user_id' => 'permit_empty|is_natural_no_zero'])->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        $target = isset($payload['assigned_counsellor_user_id']) && $payload['assigned_counsellor_user_id'] !== ''
            ? (int) $payload['assigned_counsellor_user_id']
            : null;
        return $this->ok($this->service->reassign($id, $target));
    }

    private function collectErrors(): array
    {
        $errors = [];
        foreach ($this->validation->getErrors() as $field => $message) {
            $errors[] = ['code' => 'validation.field', 'message' => (string) $message, 'field' => (string) $field];
        }
        return $errors;
    }
}
