<?php

declare(strict_types=1);

namespace Modules\Referrals\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Referrals\Services\ReferralService;
use Modules\Referrals\Policies\ReferralPolicy;
use Config\Services;

final class ReferralController extends ApiController
{
    private readonly ReferralService $service;

    public function __construct(?ReferralService $service = null)
    {
        $this->service = $service ?? new ReferralService(
            new ReferralPolicy(),
            Services::auditOutbox(),
            Services::encryptionService(),
            Services::notificationOutbox(),
        );
    }

    public function list(): ResponseInterface
    {
        $cursor = (string) ($this->request->getGet('cursor') ?? '');
        $limit  = (int)    ($this->request->getGet('limit')  ?? 25);
        $status = $this->request->getGet('status');
        $status = is_string($status) ? $status : null;

        $page = $this->service->list($cursor !== '' ? $cursor : null, $limit, $status);
        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    /**
     * Patient autocomplete for the referral form — narrow, referrals-
     * scoped lookup (gated by referrals.create, not clinic.patients.read).
     */
    public function lookupPatient(): ResponseInterface
    {
        $q = trim((string) ($this->request->getGet('q') ?? ''));
        if (mb_strlen($q) < 2) {
            return $this->ok([]);
        }
        return $this->ok($this->service->lookupPatient($q, (int) ($this->request->getGet('limit') ?? 8)));
    }

    public function create(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'patient_school_id' => 'required|max_length[32]',
            'source_module'     => 'required|in_list[clinic,counselling]',
            'target_module'     => 'required|in_list[clinic,counselling]',
            'artifact_type'     => 'required|max_length[64]',
            'reason_code'       => 'permit_empty|max_length[64]',
            'notes_plaintext'   => 'permit_empty|max_length[8192]',
            'provider_user_id'  => 'permit_empty|is_natural_no_zero',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->create(
            (string) $payload['patient_school_id'],
            (string) $payload['source_module'],
            (string) $payload['target_module'],
            (string) $payload['artifact_type'],
            isset($payload['reason_code']) ? (string) $payload['reason_code'] : null,
            isset($payload['notes_plaintext']) ? (string) $payload['notes_plaintext'] : null,
            isset($payload['provider_user_id']) && $payload['provider_user_id'] !== '' ? (int) $payload['provider_user_id'] : null,
        );

        return $this->ok($dto->toArray(), null, 201);
    }

    public function acknowledge(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $providerUserId = isset($payload['provider_user_id']) && $payload['provider_user_id'] !== ''
            ? (int) $payload['provider_user_id']
            : null;
        if ($providerUserId !== null && $providerUserId <= 0) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'provider_user_id must be a positive integer.', 'field' => 'provider_user_id'],
            ]);
        }
        $dto = $this->service->acknowledge($id, $providerUserId);
        return $this->ok($dto->toArray());
    }

    public function review(int $id): ResponseInterface
    {
        $dto = $this->service->review($id);
        return $this->ok($dto->toArray());
    }

    public function close(int $id): ResponseInterface
    {
        $dto = $this->service->close($id);
        return $this->ok($dto->toArray());
    }

    public function revokeQr(int $id): ResponseInterface
    {
        $dto = $this->service->revokeQr($id);
        return $this->ok($dto->toArray());
    }

    public function issueQr(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $ttl = (int) ($payload['ttl_seconds'] ?? 3600);
        if ($ttl < 60 || $ttl > 86_400) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'ttl_seconds must be between 60 and 86400.', 'field' => 'ttl_seconds'],
            ]);
        }
        $tok = $this->service->issueQr($id, $ttl);
        return $this->ok($tok, null, 201);
    }

    /**
     * PUBLIC verify endpoint. NO api_auth filter. Returns ONLY
     * minimum-disclosure envelope — never PII.
     */
    public function verify(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $token = (string) ($payload['token'] ?? '');
        if ($token === '') {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'token is required.', 'field' => 'token'],
            ]);
        }

        $result = $this->service->verify($token);
        return $this->ok($result);
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