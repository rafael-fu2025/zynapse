<?php

declare(strict_types=1);

namespace Modules\Counselling\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Counselling\Services\CounsellingService;
use Modules\Counselling\Services\QueueService;
use Modules\Counselling\Policies\CounsellingPolicy;
use Modules\Referrals\Policies\ReferralPolicy;
use Modules\Referrals\Services\ReferralService;
use Config\Services;

final class CounsellingController extends ApiController
{
    private readonly CounsellingService $service;
    private readonly QueueService $queueService;
    private readonly ReferralService $referrals;

    public function __construct(?CounsellingService $service = null, ?QueueService $queueService = null, ?ReferralService $referrals = null)
    {
        $this->service = $service ?? new CounsellingService(
            new CounsellingPolicy(),
            Services::auditOutbox(),
            Services::encryptionService(),
        );
        $this->queueService = $queueService ?? new QueueService(
            new CounsellingPolicy(),
            Services::auditOutbox(),
            Services::notificationOutbox(),
        );
        $this->referrals = $referrals ?? new ReferralService(new ReferralPolicy(), Services::auditOutbox(), Services::encryptionService(), Services::notificationOutbox());
    }

    public function createReferral(int $sessionId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        if (! $this->makeValidation([
            'reason_code' => 'permit_empty|max_length[64]',
            'notes_plaintext' => 'permit_empty|max_length[8192]',
        ])->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        $dto = $this->referrals->createFromSession(
            $sessionId,
            isset($payload['reason_code']) && $payload['reason_code'] !== '' ? (string) $payload['reason_code'] : null,
            isset($payload['notes_plaintext']) && $payload['notes_plaintext'] !== '' ? (string) $payload['notes_plaintext'] : null,
        );
        return $this->ok($dto->toArray(), null, 201);
    }

    public function getSession(int $sessionId): ResponseInterface
    {
        return $this->ok($this->service->getSession($sessionId));
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

    /**
     * Patient autocomplete for the counselling forms — narrow,
     * counselling-scoped lookup (gated by `counselling.records.create`,
     * not `clinic.patients.read`). Mirrors the referrals lookup.
     */
    public function lookupPatient(): ResponseInterface
    {
        $q = trim((string) ($this->request->getGet('q') ?? ''));
        if (mb_strlen($q) < 2) {
            return $this->ok([]);
        }
        return $this->ok($this->service->lookupPatient($q, (int) ($this->request->getGet('limit') ?? 8)));
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

        $rules = [
            'plaintext'          => 'required|string|max_length[16384]',
            'supersedes_note_id' => 'permit_empty|is_natural_no_zero',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $supersedes = isset($payload['supersedes_note_id']) && $payload['supersedes_note_id'] !== ''
            ? (int) $payload['supersedes_note_id']
            : null;

        $dto = $this->service->writeNotes($sessionId, (string) $payload['plaintext'], $supersedes);
        return $this->ok($dto->toArray(), null, 201);
    }

    public function readNotes(int $sessionId): ResponseInterface
    {
        $notes = $this->service->readNotes($sessionId);
        return $this->ok(['notes' => $notes]);
    }

    public function closeSession(int $sessionId): ResponseInterface
    {
        $linked = $this->queueService->completeLinkedSession($sessionId);
        if ($linked !== null) {
            return $this->ok($linked->toArray());
        }
        $dto = $this->service->closeSession($sessionId);
        return $this->ok($dto->toArray());
    }

    /**
     * Oversight-only ownership transfer (audit 2026-09-05, F2) — the
     * recorded act that replaces the old standing any-counsellor access.
     */
    public function reassignSession(int $sessionId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = ['counsellor_user_id' => 'required|is_natural_no_zero'];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->reassignSession($sessionId, (int) $payload['counsellor_user_id']);
        return $this->ok($dto->toArray());
    }

    public function archiveSession(int $sessionId): ResponseInterface
    {
        $dto = $this->service->archiveSession($sessionId);
        return $this->ok($dto->toArray());
    }

    public function unarchiveSession(int $sessionId): ResponseInterface
    {
        $dto = $this->service->unarchiveSession($sessionId);
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
