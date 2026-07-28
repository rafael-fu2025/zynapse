<?php

declare(strict_types=1);

namespace Modules\Counselling\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Counselling\Services\CounsellingService;
use Modules\Counselling\Policies\CounsellingPolicy;
use Config\Services;

final class CounsellingController extends ApiController
{
    private readonly CounsellingService $service;

    public function __construct(?CounsellingService $service = null)
    {
        $this->service = $service ?? new CounsellingService(
            new CounsellingPolicy(),
            Services::auditOutbox(),
            Services::encryptionService(),
        );
    }

    public function listSessions(): ResponseInterface
    {
        $cursor = (string) ($this->request->getGet('cursor') ?? '');
        $limit  = (int)    ($this->request->getGet('limit')  ?? 25);

        $page = $this->service->listSessions($cursor !== '' ? $cursor : null, $limit);
        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    public function openSession(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = ['patient_school_id' => 'required|max_length[32]'];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->openSession((string) $payload['patient_school_id']);
        return $this->ok($dto->toArray(), null, 201);
    }

    public function writeNotes(int $sessionId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = ['plaintext' => 'required|string|max_length[16384]'];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->writeNotes($sessionId, (string) $payload['plaintext']);
        return $this->ok($dto->toArray(), null, 201);
    }

    public function readNotes(int $sessionId): ResponseInterface
    {
        $notes = $this->service->readNotes($sessionId);
        return $this->ok(['notes' => $notes]);
    }

    public function closeSession(int $sessionId): ResponseInterface
    {
        $dto = $this->service->closeSession($sessionId);
        return $this->ok($dto->toArray());
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