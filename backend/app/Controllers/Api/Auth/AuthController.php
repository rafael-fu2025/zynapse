<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Auth\AccountStateService;
use App\Auth\CurrentUser;
use App\Auth\DeviceFingerprint;
use App\Auth\JwtService;
use App\Auth\LoginThrottleService;
use App\Auth\Mfa\MfaService;
use App\Auth\RefreshTokenService;
use App\Auth\RefreshSessionPolicy;
use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Exceptions\FuMisInvalidCredentialsException;
use App\Services\Audit\AuditOutboxService;
use App\Services\FuMis\FuMisAuthService;
use App\Services\UniversityEmail;
use CodeIgniter\HTTP\ResponseInterface;
use Config\FuMis;
use Config\Services;

final class AuthController extends ApiController
{
    /**
     * Bcrypt digest of an unguessable random string. Only ever fed to
     * password_verify() for unknown emails so the login timing profile
     * matches the wrong-password branch. The preimage is unknowable, so
     * this is not a credential for anything.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$10$HiJvC1zJqA58l6WSvw0y0eZToyAyCSrChBTI.YaRtF6JUcFBiV5j.';

    private readonly JwtService $jwt;
    private readonly RefreshTokenService $refreshTokens;
    private readonly AuditOutboxService $audit;
    private readonly LoginThrottleService $throttle;
    private readonly AccountStateService $accountState;
    private readonly ?FuMisAuthService $fuMis;
    private readonly ?MfaService $mfa;

    public function __construct(
        ?JwtService $jwt = null,
        ?RefreshTokenService $refreshTokens = null,
        ?AuditOutboxService $audit = null,
        ?LoginThrottleService $throttle = null,
        ?AccountStateService $accountState = null,
        ?FuMisAuthService $fuMis = null,
        ?MfaService $mfa = null,
    ) {
        $this->jwt           = $jwt ?? Services::jwt();
        $this->refreshTokens = $refreshTokens ?? Services::refreshTokenService();
        $this->audit         = $audit ?? Services::auditOutbox();
        $this->throttle      = $throttle ?? new LoginThrottleService();
        $this->accountState  = $accountState ?? new AccountStateService();
        $this->fuMis         = $fuMis;
        $this->mfa           = $mfa;
    }

    /**
     * Login — two credential paths, one endpoint.
     *
     *   {identifier, password} → MIS-delegated (FUMIS_ENABLED=true):
     *       student/employee number verified by the university API,
     *       JIT-provisioned local account. This is the person path.
     *   {email, password}      → local Shield identity: the admin and
     *       operational-account path (also the only path while
     *       FUMIS_ENABLED=false, e.g. off-campus dev/CI).
     *
     * Both paths share throttling (keyed on the presented credential),
     * audit events, and the token issuance in finalizeAuth().
     */
    public function login(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $identifier = trim((string) ($payload['identifier'] ?? ''));
        $email      = strtolower(trim((string) ($payload['email'] ?? '')));

        if ($identifier !== '' && $email !== '') {
            // Ambiguous — one credential kind per request.
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'Provide either identifier or email, not both.',
                'field'   => 'identifier',
            ]]);
        }

        if ($identifier !== '') {
            if (mb_strlen($identifier) > 64 || mb_strlen((string) ($payload['password'] ?? '')) > 256) {
                throw ApiException::validationFailure($this->validationErrorList());
            }

            return $this->loginWithIdentifier($identifier, (string) ($payload['password'] ?? ''));
        }

        return $this->loginWithEmail($email, (string) ($payload['password'] ?? ''));
    }

    /**
     * MIS-delegated path (student/employee number).
     */
    private function loginWithIdentifier(string $identifier, string $password): ResponseInterface
    {
        $config = new FuMis();
        if (! $config->enabled) {
            // MIS delegation is off (off-campus dev/CI): refuse the
            // identifier path so nobody silently falls back to a local
            // password check that MIS users do not have.
            throw new ApiException('auth.mis_unavailable', 503);
        }

        if ($password === '') {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'The password field is required.',
                'field'   => 'password',
            ]]);
        }

        if ($this->throttle->isLocked($identifier)) {
            $this->audit->enqueue(
                'auth.login_locked',
                'auth_sessions',
                null,
                null,
                ['auth_method' => 'fumis', 'outcome' => 'locked', ...$this->provenance()],
            );
            $this->response->setHeader('Retry-After', (string) $this->throttle->retryAfterSeconds());
            throw new ApiException(\App\Exceptions\ApiErrorCode::AUTH_LOGIN_LOCKED, 429);
        }

        try {
            // FuMisAuthService throws FuMisUpstreamException (502/503)
            // on unavailability and FuMisInvalidCredentialsException
            // (401, auth.credentials_invalid) on rejection.
            $service = $this->fuMis ?? Services::fuMisAuthService();
            $userId = $service->loginWithIdentifier($identifier, $password);
        } catch (FuMisInvalidCredentialsException $e) {
            $this->throttle->registerFailure($identifier);
            $this->audit->enqueue(
                'auth.login_failed',
                'auth_sessions',
                null,
                null,
                ['auth_method' => 'fumis', 'outcome' => 'failure', ...$this->provenance()],
            );
            throw $e;
        }

        $state = $this->accountState->forUser($userId);
        if ($state === null || ! $state['active']) {
            throw ApiException::unauthorized('auth.account_disabled');
        }

        $this->throttle->clear($identifier);

        Services::database()->table('users')->where('id', $userId)->update([
            'last_active' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->enqueue(
            'auth.login_succeeded',
            'auth_sessions',
            $userId,
            $userId,
            ['auth_method' => 'fumis', 'outcome' => 'success', ...$this->provenance()],
        );

        return $this->finalizeAuth($userId);
    }

    /**
     * Local Shield-identity path (email) — admins, operational accounts,
     * and the dev/CI mode where FUMIS_ENABLED=false.
     */
    private function loginWithEmail(string $email, string $password): ResponseInterface
    {
        $payload = ['email' => $email, 'password' => $password];

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
                ['auth_method' => 'password', 'outcome' => 'locked', ...$this->provenance()],
            );
            $this->response->setHeader('Retry-After', (string) $this->throttle->retryAfterSeconds());
            throw new ApiException(\App\Exceptions\ApiErrorCode::AUTH_LOGIN_LOCKED, 429);
        }

        $users = Services::getAuthProvider();
        $user  = $users->findByCredentials(['email' => $payload['email']]);
        if ($user === null) {
            // Equalize timing with the wrong-password branch below so an
            // unknown email is not distinguishable by response latency.
            password_verify($payload['password'], self::DUMMY_PASSWORD_HASH);
        }
        if ($user === null || ! password_verify($payload['password'], (string) $user->password_hash)) {
            $this->throttle->registerFailure($payload['email']);
            $this->audit->enqueue(
                'auth.login_failed',
                'auth_sessions',
                null,
                null,
                ['auth_method' => 'password', 'outcome' => 'failure', ...$this->provenance()],
            );
            throw ApiException::unauthorized('auth.credentials_invalid');
        }

        $state = $this->accountState->forUser((int) $user->id);
        if ($state === null || ! $state['active']) {
            throw ApiException::unauthorized('auth.account_disabled');
        }

        $this->throttle->clear($payload['email']);

        // Second factor gate (2026-10 audit G-1): local identities with an
        // enabled TOTP enrollment — or privileged-group members while
        // enforcement is on — complete login via the MFA challenge flow.
        // NO refresh cookie and NO usable access token are issued here; the
        // challenge token carries scope=mfa_challenge and is rejected by
        // ApiAuthFilter everywhere except POST /auth/mfa/verify-login.
        // The MIS identifier path never reaches this branch by design.
        $mfa = $this->mfa ?? new MfaService();
        if ($mfa->loginRequiresMfa((int) $user->id)) {
            $challenge = $this->jwt->sign((int) $user->id, (int) $state['token_epoch'], ['scope' => 'mfa_challenge']);

            return $this->respondMfaRequired($payload['email'], $challenge);
        }

        Services::database()->table('users')->where('id', (int) $user->id)->update([
            'last_active' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->enqueue(
            'auth.login_succeeded',
            'auth_sessions',
            (int) $user->id,
            (int) $user->id,
            ['auth_method' => 'password', 'outcome' => 'success', ...$this->provenance()],
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
                ['auth_method' => 'refresh_token', 'outcome' => 'failure', ...$this->provenance()],
            );
            throw ApiException::unauthorized('auth.refresh_missing');
        }

        $result = $this->refreshTokens->rotate($refresh, DeviceFingerprint::fromRequest($this->request));

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
                    ...$this->provenance(),
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
                    ...$this->provenance(),
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
                ...$this->provenance(),
            ],
        );

        // Grace-window hit: the rotation already happened in a racing tab;
        // the client already holds the valid replacement cookie. Issue a
        // fresh access token but do NOT overwrite the cookie.
        if (! empty($result['grace'])) {
            $access = $this->sessionAccessToken($userId, $result['mint']);
            return $this->ok([
                'access_token' => $access,
                'token_type'   => 'Bearer',
                'expires_in'   => max(0, (int) $this->jwt->verify($access)['exp'] - time()),
            ]);
        }

        return $this->finalizeAuth($userId, $result['mint']);
    }

    public function logout(): ResponseInterface
    {
        $userId = CurrentUser::assert();
        $this->refreshTokens->revokeAllFor($userId, true);
        $this->response->setCookie([
            ...$this->refreshCookieAttributes(),
            'value' => '',
            'expire' => new \DateTimeImmutable('@' . (time() - 3600)),
        ]);

        $this->audit->enqueue(
            'auth.logout',
            'auth_sessions',
            $userId,
            $userId,
            ['auth_method' => 'session', 'outcome' => 'success', ...$this->provenance()],
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
        // Identity-consolidated: the user IS the person. No persons /
        // patient_identifiers join — the profile lives on `users`.
        $userRow = $db->table('users u')
            ->select('u.id, u.kind AS person_kind, u.first_name AS person_first_name, u.last_name AS person_last_name, u.is_teaching, u.archived_at, u.student_number, u.employee_number')
            ->where('u.id', $userId)
            ->get()->getRowArray();

        // The university ID number (student or employee, whichever this
        // person is) — the primary login identifier for MIS users.
        $identifier = null;
        if ($userRow !== null) {
            $identifier = $userRow['student_number'] ?? $userRow['employee_number'] ?? null;
            if ($identifier !== null && (string) $identifier === '') {
                $identifier = null;
            }
        }

        $personName = null;
        if ($userRow !== null) {
            $first = isset($userRow['person_first_name']) ? trim((string) $userRow['person_first_name']) : '';
            $last  = isset($userRow['person_last_name'])  ? trim((string) $userRow['person_last_name'])  : '';
            if ($first !== '' || $last !== '') {
                $personName = trim($first . ' ' . $last);
            }
        }

        return $this->ok([
            'id'          => (int)    $user->id,
            // MIS-provisioned users carry no email identity (the MIS API
            // never returns one) — derive the university address from the
            // person name so every surface (UserMenu, portals, mobile)
            // shows the real @foundationu.com handle. Display-only: it
            // never opens the email+password login path.
            'email'       => $user->email !== null && $user->email !== ''
                ? (string) $user->email
                : (UniversityEmail::derive(
                    $userRow['person_first_name'] ?? null,
                    $userRow['person_last_name'] ?? null,
                ) ?? ''),
            'username'    => (string) $user->username,
            'identifier'  => $identifier !== null ? (string) $identifier : null,
            'is_active'   => (bool)   $user->active,
            // True only for accounts with a local email_password identity
            // (admins, clinic-minted portal accounts). MIS-provisioned
            // users authenticate against the university API and manage
            // their password through the FU helpdesk.
            'has_local_password' => (bool) ($user->has_local_password ?? false),
            'force_reset' => (bool)   ($user->force_reset ?? false),
            'person_kind' => isset($userRow['person_kind']) && $userRow['person_kind'] !== null ? (string) $userRow['person_kind'] : null,
            'person_name' => $personName,
            // Teaching flag for employee accounts — the frontend hides
            // the "New referral" action for non-teaching staff (the
            // service gate still enforces it server-side).
            'is_teaching' => isset($userRow['is_teaching']) && $userRow['is_teaching'] !== null ? (bool) $userRow['is_teaching'] : null,
            'permissions' => $permissions,
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

        if ($identity === null) {
            // MIS-provisioned users have no local password to rotate —
            // the university API owns their credentials. Say so
            // explicitly instead of a misleading "wrong password" 401.
            $this->audit->enqueue(
                'auth.password_change_failed',
                'auth_sessions',
                $userId,
                $userId,
                ['auth_method' => 'fumis', 'outcome' => 'failure', 'reason' => 'no_local_identity', ...$this->provenance()],
            );
            throw new ApiException(\App\Exceptions\ApiErrorCode::AUTH_PASSWORD_MIS_MANAGED, 403);
        }

        if (! password_verify((string) $payload['current_password'], (string) $identity['secret2'])) {
            $this->audit->enqueue(
                'auth.password_change_failed',
                'auth_sessions',
                $userId,
                $userId,
                ['auth_method' => 'password', 'outcome' => 'failure', ...$this->provenance()],
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

        // Kill every other session atomically: revoke all refresh tokens and
        // bump the epoch under the same user-row lock so no concurrent refresh
        // can slip in between and capture a pre-bump epoch.
        $this->refreshTokens->revokeAllFor($userId, true);

        $this->audit->enqueue(
            'auth.password_changed',
            'auth_sessions',
            $userId,
            $userId,
            ['auth_method' => 'password', 'outcome' => 'success', ...$this->provenance()],
        );

        return $this->finalizeAuth($userId);
    }

    /**
     * Network provenance for auth audit events — source IP + user agent,
     * truncated defensively. Never PII beyond what the request already
     * carries; recorded so failed/successful logins are attributable.
     *
     * @return array{ip_address: string, user_agent: string}
     */
    private function provenance(): array
    {
        $ip = trim((string) $this->request->getIPAddress());
        $ua = trim((string) $this->request->getUserAgent()->getAgentString());

        return [
            'ip_address' => substr($ip, 0, 45),
            'user_agent' => substr($ua, 0, 255),
        ];
    }

    private function finalizeAuth(int $userId, ?array $preIssued = null): ResponseInterface
    {
        $device = DeviceFingerprint::fromRequest($this->request);
        $refresh = $preIssued ?? $this->refreshTokens->issue($userId, $device);
        $access = $this->sessionAccessToken($userId, $refresh);

        $this->response->setCookie([
            ...$this->refreshCookieAttributes(),
            'value' => $refresh['plain'],
            'expire' => new \DateTimeImmutable('@' . (new RefreshSessionPolicy())->timestamp($refresh['expires_at'])),
        ]);

        return $this->ok([
            'access_token' => $access,
            'token_type'   => 'Bearer',
            'expires_in'   => max(0, (int) $this->jwt->verify($access)['exp'] - time()),
        ]);
    }

    private function sessionAccessToken(int $userId, array $refresh): string
    {
        $policy = new RefreshSessionPolicy();
        $deadline = $policy->timestamp($refresh['session_expires_at']);
        $policy->assertLive($deadline, time());
        // Preserve the epoch captured under the rotation lock. Reading it again
        // could bless a pre-logout refresh with the post-logout epoch.
        return $this->jwt->sign($userId, (int) $refresh['token_epoch'], ['session_exp' => $deadline]);
    }

    /**
     * 401 + auth.mfa_required envelope carrying the short-lived challenge
     * token. Deliberately a data-bearing 401 (not an exception) so the SPA
     * login form can flip to its code step from the envelope alone.
     */
    private function respondMfaRequired(string $email, string $challenge): ResponseInterface
    {
        $this->audit->enqueue(
            'auth.mfa_challenge_issued',
            'auth_mfa',
            null,
            null,
            ['auth_method' => 'password', 'outcome' => 'mfa_required', ...$this->provenance()],
        );

        return Services::response()->setStatusCode(401)->setJSON([
            'success' => false,
            'data'    => ['mfa_token' => $challenge],
            'errors'  => [[
                'code'    => \App\Exceptions\ApiErrorCode::AUTH_MFA_REQUIRED,
                'message' => 'Enter the 6-digit code from your authenticator app.',
            ]],
            'meta'    => null,
        ]);
    }

    /**
     * Session issuance after a completed MFA challenge — invoked by
     * MfaController::verifyLogin so token/cookie semantics live in one place.
     */
    public function completeMfaLogin(int $userId): ResponseInterface
    {
        Services::database()->table('users')->where('id', $userId)->update([
            'last_active' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->enqueue(
            'auth.login_succeeded',
            'auth_sessions',
            $userId,
            $userId,
            ['auth_method' => 'password+mfa', 'outcome' => 'success', ...$this->provenance()],
        );

        return $this->finalizeAuth($userId);
    }

    private function refreshCookieAttributes(): array
    {
        return [
            'name' => (string) (getenv('REFRESH_COOKIE_NAME') ?: 'synapse_rt'),
            // Scoped to the auth subtree so the credential is never sent to
            // any other endpoint. Login/refresh/logout all live under
            // /api/v1/auth.
            'path' => (string) (getenv('REFRESH_COOKIE_PATH') ?: '/api/v1/auth'),
            'secure' => filter_var(getenv('REFRESH_COOKIE_SECURE') ?: '1', FILTER_VALIDATE_BOOL),
            'httponly' => true,
            'samesite' => (string) (getenv('REFRESH_COOKIE_SAMESITE') ?: 'Strict'),
        ];
    }

    /**
     * Invalidate every outstanding access token for the user by advancing
     * users.token_epoch. The second argument is raw SQL ("token_epoch + 1")
     * by design — a query-builder literal would be escaped as a string.
     */
    private function bumpTokenEpoch(int $userId): void
    {
        Services::database()->table('users')
            ->where('id', $userId)
            ->set('token_epoch', 'token_epoch + 1', false)
            ->update();
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
