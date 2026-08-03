<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Auth\AccountStateService;
use App\Auth\CurrentUser;
use App\Auth\JwtService;
use App\Auth\LoginThrottleService;
use App\Auth\RefreshTokenService;
use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Services\Audit\AuditOutboxService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

final class AuthController extends ApiController
{
    private readonly JwtService $jwt;
    private readonly RefreshTokenService $refreshTokens;
    private readonly AuditOutboxService $audit;
    private readonly LoginThrottleService $throttle;
    private readonly AccountStateService $accountState;

    public function __construct(
        ?JwtService $jwt = null,
        ?RefreshTokenService $refreshTokens = null,
        ?AuditOutboxService $audit = null,
        ?LoginThrottleService $throttle = null,
        ?AccountStateService $accountState = null,
    ) {
        $this->jwt           = $jwt ?? Services::jwt();
        $this->refreshTokens = $refreshTokens ?? Services::refreshTokenService();
        $this->audit         = $audit ?? Services::auditOutbox();
        $this->throttle      = $throttle ?? new LoginThrottleService();
        $this->accountState  = $accountState ?? new AccountStateService();
    }

    public function login(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'email'    => 'required|valid_email|max_length[255]',
            'password' => 'required|min_length[8]|max_length[256]',
        ];

        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->validationErrorList());
        }

        if ($this->throttle->isLocked($payload['email'])) {
            $this->audit->enqueue(
                'auth.login_locked',
                'auth_sessions',
                null,
                null,
                ['auth_method' => 'password', 'outcome' => 'locked'],
            );
            $this->response->setHeader('Retry-After', (string) $this->throttle->retryAfterSeconds());
            throw new ApiException(\App\Exceptions\ApiErrorCode::AUTH_LOGIN_LOCKED, 429);
        }

        $users = Services::getAuthProvider();
        $user  = $users->findByCredentials(['email' => $payload['email']]);
        if ($user === null || ! password_verify($payload['password'], (string) $user->password_hash)) {
            $this->throttle->registerFailure($payload['email']);
            $this->audit->enqueue(
                'auth.login_failed',
                'auth_sessions',
                null,
                null,
                ['auth_method' => 'password', 'outcome' => 'failure'],
            );
            throw ApiException::unauthorized('auth.credentials_invalid');
        }

        $state = $this->accountState->forUser((int) $user->id);
        if ($state === null || ! $state['active']) {
            throw ApiException::unauthorized('auth.account_disabled');
        }

        $this->throttle->clear($payload['email']);

        Services::database()->table('users')->where('id', (int) $user->id)->update([
            'last_active' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->enqueue(
            'auth.login_succeeded',
            'auth_sessions',
            (int) $user->id,
            (int) $user->id,
            ['auth_method' => 'password', 'outcome' => 'success'],
        );

        return $this->finalizeAuth((int) $user->id);
    }

    public function refresh(): ResponseInterface
    {
        $cookieName = (string) (getenv('REFRESH_COOKIE_NAME') ?: 'synapse_rt');
        $refresh = (string) $this->request->getCookie($cookieName);
        if ($refresh === '') {
            $this->audit->enqueue(
                'auth.refresh_failed',
                'auth_sessions',
                null,
                null,
                ['auth_method' => 'refresh_token', 'outcome' => 'failure'],
            );
            throw ApiException::unauthorized('auth.refresh_missing');
        }

        $result = $this->refreshTokens->rotate($refresh);

        if ($result['status'] === 'replayed') {
            $this->audit->enqueue(
                'auth.refresh_replayed',
                'auth_sessions',
                $result['user_id'] ?? null,
                $result['user_id'] ?? null,
                [
                    'auth_method' => 'refresh_token',
                    'outcome'     => 'replayed',
                    'family_id'   => $result['family_id'] ?? null,
                ],
            );
            throw ApiException::unauthorized('auth.refresh_invalid_or_replayed');
        }

        if ($result['status'] === 'invalid' || ! isset($result['mint'])) {
            $this->audit->enqueue(
                'auth.refresh_failed',
                'auth_sessions',
                $result['user_id'] ?? null,
                $result['user_id'] ?? null,
                [
                    'auth_method' => 'refresh_token',
                    'outcome'     => 'failure',
                    'family_id'   => $result['family_id'] ?? null,
                ],
            );
            throw ApiException::unauthorized('auth.refresh_invalid_or_replayed');
        }

        $userId = (int) ($result['user_id'] ?? 0);
        if ($userId <= 0) {
            throw ApiException::unauthorized('auth.refresh_invalid_or_replayed');
        }

        $state = $this->accountState->forUser($userId);
        if ($state === null || ! $state['active']) {
            $this->refreshTokens->revokeAllFor($userId);
            throw ApiException::unauthorized('auth.account_disabled');
        }

        $this->audit->enqueue(
            'auth.refresh_succeeded',
            'auth_sessions',
            $userId,
            $userId,
            [
                'auth_method' => 'refresh_token',
                'outcome'     => 'success',
                'family_id'   => $result['family_id'] ?? null,
            ],
        );

        return $this->finalizeAuth($userId, $result['mint']);
    }

    public function logout(): ResponseInterface
    {
        $userId = CurrentUser::assert();
        $this->refreshTokens->revokeAllFor($userId);

        $this->audit->enqueue(
            'auth.logout',
            'auth_sessions',
            $userId,
            $userId,
            ['auth_method' => 'session', 'outcome' => 'success'],
        );

        return $this->ok(['logged_out' => true]);
    }

    /**
     * Phase 1.1: /auth/me now exposes persons_id, person_kind, and
     * patient_identifier_id so the SPA can render the unified identity
     * context (UserMenu, portal pages, AdminUsersPage).
     */
    public function me(): ResponseInterface
    {
        $userId = CurrentUser::assert();
        $user = Services::getAuthProvider()->findById($userId);
        if ($user === null) {
            throw ApiException::notFound('auth.user_not_found');
        }

        $permissions = $this->permissions->allForUser($userId);

        $db = Services::database();
        $personRow = $db->table('persons p')
            ->select('p.id AS persons_id, p.kind AS person_kind, p.first_name AS person_first_name, p.last_name AS person_last_name, pi.id AS patient_identifier_id')
            ->join('patient_identifiers pi', 'pi.persons_id = p.id AND pi.archived_at IS NULL', 'left')
            ->where('p.user_id', $userId)
            ->where('p.archived_at IS NULL')
            ->get()->getRowArray();

        $personName = null;
        if ($personRow !== null) {
            $first = isset($personRow['person_first_name']) ? trim((string) $personRow['person_first_name']) : '';
            $last  = isset($personRow['person_last_name'])  ? trim((string) $personRow['person_last_name'])  : '';
            if ($first !== '' || $last !== '') {
                $personName = trim($first . ' ' . $last);
            }
        }

        return $this->ok([
            'id'                    => (int)    $user->id,
            'email'                 => (string) $user->email,
            'username'              => (string) $user->username,
            'is_active'             => (bool)   $user->active,
            'force_reset'           => (bool)   ($user->force_reset ?? false),
            'persons_id'            => isset($personRow['persons_id']) ? (int) $personRow['persons_id'] : null,
            'person_kind'           => isset($personRow['person_kind']) ? (string) $personRow['person_kind'] : null,
            'person_name'           => $personName,
            'patient_identifier_id' => isset($personRow['patient_identifier_id']) ? (int) $personRow['patient_identifier_id'] : null,
            'permissions'           => $permissions,
        ]);
    }

    public function changePassword(): ResponseInterface
    {
        $userId  = CurrentUser::assert();
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'current_password' => 'required|min_length[8]|max_length[256]',
            'new_password'     => 'required|min_length[12]|max_length[256]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->validationErrorList());
        }

        $db = Services::database();
        $identity = $db->table('auth_identities')
            ->where('user_id', $userId)
            ->where('type', 'email_password')
            ->get()->getRowArray();

        if ($identity === null || ! password_verify((string) $payload['current_password'], (string) $identity['secret2'])) {
            $this->audit->enqueue(
                'auth.password_change_failed',
                'auth_sessions',
                $userId,
                $userId,
                ['auth_method' => 'password', 'outcome' => 'failure'],
            );
            throw ApiException::unauthorized('auth.credentials_invalid');
        }

        $db->table('auth_identities')
            ->where('id', (int) $identity['id'])
            ->update([
                'secret2'     => password_hash((string) $payload['new_password'], PASSWORD_DEFAULT),
                'force_reset' => 0,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);

        $this->refreshTokens->revokeAllFor($userId);

        $this->audit->enqueue(
            'auth.password_changed',
            'auth_sessions',
            $userId,
            $userId,
            ['auth_method' => 'password', 'outcome' => 'success'],
        );

        return $this->finalizeAuth($userId);
    }

    private function finalizeAuth(int $userId, ?array $preIssued = null): ResponseInterface
    {
        $access = $this->jwt->sign($userId);
        $refresh = $preIssued ?? $this->refreshTokens->issue($userId);

        $this->response->setCookie([
            'name'     => (string) (getenv('REFRESH_COOKIE_NAME') ?: 'synapse_rt'),
            'value'    => $refresh['plain'],
            'expire'   => time() + (int) (getenv('JWT_REFRESH_TTL_SECONDS') ?: 2592000),
            'path'     => '/',
            'secure'   => filter_var(getenv('REFRESH_COOKIE_SECURE') ?: '1', FILTER_VALIDATE_BOOL),
            'httponly' => true,
            'samesite' => (string) (getenv('REFRESH_COOKIE_SAMESITE') ?: 'Strict'),
        ]);

        return $this->ok([
            'access_token' => $access,
            'token_type'   => 'Bearer',
            'expires_in'   => (int) (getenv('JWT_ACCESS_TTL_SECONDS') ?: 900),
        ]);
    }

    private function validationErrorList(): array
    {
        $errs = [];
        foreach ($this->validation->getErrors() as $field => $msg) {
            $errs[] = ['code' => 'validation.field', 'message' => (string) $msg, 'field' => (string) $field];
        }
        return $errs;
    }
}
