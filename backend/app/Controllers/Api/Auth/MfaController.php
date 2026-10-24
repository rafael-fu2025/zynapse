<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Auth\AccountStateService;
use App\Auth\CurrentUser;
use App\Auth\JwtService;
use App\Auth\Mfa\MfaService;
use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use App\Exceptions\ApiErrorCode;
use App\Services\Audit\AuditOutboxService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * MfaController — TOTP second factor for the LOCAL identity path.
 *
 *   GET  api/v1/auth/mfa/status        self: {enabled, staged, required}
 *   POST api/v1/auth/mfa/setup         self: stage enrollment (secret shown ONCE)
 *   POST api/v1/auth/mfa/confirm       self: activate with a valid code
 *   POST api/v1/auth/mfa/disable       self: disable (code + current password)
 *   POST api/v1/auth/mfa/reset/(:num)  rbac.privileged.manage: admin recovery
 *   POST api/v1/auth/mfa/verify-login  PUBLIC: complete an mfa_required login
 *                                      with the challenge token + code.
 *
 * The MIS-delegated login path never touches this controller — the
 * university owns those credentials, and no second factor can be added
 * to it from here.
 */
final class MfaController extends ApiController
{
    public function __construct(
        private readonly ?MfaService $mfa = null,
        private readonly ?AuditOutboxService $audit = null,
        private readonly ?JwtService $jwt = null,
    ) {
    }

    private function service(): MfaService
    {
        return $this->mfa ?? new MfaService();
    }

    private function audit(): AuditOutboxService
    {
        return $this->audit ?? Services::auditOutbox();
    }

    public function status(): ResponseInterface
    {
        $userId = CurrentUser::assert();

        return $this->ok([
            'enabled' => $this->service()->isEnabled($userId),
            'staged'  => $this->service()->isStaged($userId),
            'required' => $this->service()->loginRequiresMfa($userId),
        ]);
    }

    public function setup(): ResponseInterface
    {
        $userId = CurrentUser::assert();

        $user = Services::getAuthProvider()->findById($userId);
        if ($user === null || ($user->email === null || $user->email === '')) {
            // MIS-provisioned identities have no local password — there is
            // no local credential for a second factor to complement.
            throw new ApiException(ApiErrorCode::AUTH_PASSWORD_MIS_MANAGED, 403);
        }

        try {
            $enrollment = $this->service()->setup($userId, ['email' => $user->email, 'username' => $user->username]);
        } catch (\DomainException $e) {
            throw new ApiException('validation.invalid', 400, [[
                'code'    => 'validation.invalid',
                'message' => $e->getMessage(),
            ]]);
        }

        $this->audit()->enqueue('auth.mfa_setup_started', 'auth_mfa', $userId, $userId, ['outcome' => 'success']);

        // The secret is returned exactly once; only its encrypted copy is kept.
        return $this->ok($enrollment);
    }

    public function confirm(): ResponseInterface
    {
        $userId = CurrentUser::assert();
        $payload = $this->request->getJSON(true) ?? [];
        $code = (string) ($payload['code'] ?? '');

        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'code must be a 6-digit authenticator code.',
                'field'   => 'code',
            ]]);
        }

        if (! $this->service()->confirm($userId, $code)) {
            throw new ApiException(ApiErrorCode::AUTH_MFA_INVALID, 401);
        }

        $this->audit()->enqueue('auth.mfa_enabled', 'auth_mfa', $userId, $userId, ['outcome' => 'success']);

        return $this->ok(['enabled' => true]);
    }

    public function disable(): ResponseInterface
    {
        $userId = CurrentUser::assert();
        $payload = $this->request->getJSON(true) ?? [];
        $code = (string) ($payload['code'] ?? '');
        $password = (string) ($payload['current_password'] ?? '');

        // Disable needs BOTH factors: a live code AND the password.
        if (! $this->service()->verifyLogin($userId, $code)) {
            throw new ApiException(ApiErrorCode::AUTH_MFA_INVALID, 401);
        }

        $db = Services::database();
        $identity = $db->table('auth_identities')
            ->where('user_id', $userId)
            ->where('type', 'email_password')
            ->get()->getRowArray();
        if ($identity === null || ! password_verify($password, (string) $identity['secret2'])) {
            throw ApiException::unauthorized(ApiErrorCode::AUTH_CREDENTIALS_INVALID);
        }

        $this->service()->disable($userId);
        $this->audit()->enqueue('auth.mfa_disabled', 'auth_mfa', $userId, $userId, ['outcome' => 'success']);

        return $this->ok(['enabled' => false]);
    }

    /** Admin recovery: clear a locked-out user's enrollment (privileged.manage only). */
    public function reset(int $userId): ResponseInterface
    {
        $actorId = CurrentUser::assert();
        $this->authorize('rbac.privileged.manage');

        $target = Services::database()->table('users')
            ->select('id, tenant_id')
            ->where('id', $userId)
            ->get()->getRowArray();
        if ($target === null) {
            throw ApiException::notFound('auth.user_not_found');
        }

        $removed = $this->service()->disable($userId);
        $this->audit()->enqueue('auth.mfa_reset', 'auth_mfa', $userId, $actorId, [
            'outcome' => $removed ? 'success' : 'noop',
            'reason_code' => 'admin_reset',
        ]);

        return $this->ok(['reset' => true]);
    }

    /**
     * PUBLIC endpoint (outside api_auth): complete an mfa_required login.
     * Consumes the short-lived scope=mfa_challenge token issued by the
     * login response and issues the real session on success. Failed codes
     * feed the same per-account lockout bucket as password failures.
     */
    public function verifyLogin(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $challenge = (string) ($payload['mfa_token'] ?? '');
        $code = (string) ($payload['code'] ?? '');

        try {
            $claims = ($this->jwt ?? Services::jwt())->verify($challenge);
        } catch (\Throwable) {
            throw ApiException::unauthorized(ApiErrorCode::AUTH_TOKEN_REVOKED);
        }
        if (($claims['scope'] ?? null) !== 'mfa_challenge') {
            throw ApiException::unauthorized(ApiErrorCode::AUTH_TOKEN_REVOKED);
        }

        $userId = (int) $claims['sub'];
        $state = (new AccountStateService())->forUser($userId);
        if ($state === null || ! $state['active']) {
            throw ApiException::unauthorized(ApiErrorCode::AUTH_ACCOUNT_DISABLED);
        }

        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1 || ! $this->service()->verifyLogin($userId, $code)) {
            // Track failures per user so code guessing hits the same
            // lockout the password path uses.
            $emailRow = Services::database()->table('auth_identities')
                ->select('secret')
                ->where('user_id', $userId)
                ->where('type', 'email_password')
                ->get()->getRowArray();
            if ($emailRow !== null) {
                (new \App\Auth\LoginThrottleService())->registerFailure((string) $emailRow['secret']);
            }

            $this->audit()->enqueue('auth.mfa_verify_failed', 'auth_mfa', $userId, $userId, [
                'outcome' => 'failure',
                ...$this->provenance(),
            ]);
            throw new ApiException(ApiErrorCode::AUTH_MFA_INVALID, 401);
        }

        // Epoch re-check: the challenge must still match the live epoch, so
        // a password change / logout during the MFA step kills the attempt.
        if ((int) ($claims['epoch'] ?? 0) !== (int) $state['token_epoch']) {
            throw ApiException::unauthorized(ApiErrorCode::AUTH_TOKEN_REVOKED);
        }

        $this->audit()->enqueue('auth.mfa_verify_succeeded', 'auth_mfa', $userId, $userId, [
            'outcome' => 'success',
            ...$this->provenance(),
        ]);

        // Delegate issuance to the auth controller's canonical path. The
        // manual instance needs the framework's initController wiring
        // (request/response injection) that the router normally provides.
        $auth = new AuthController();
        $auth->initController($this->request, $this->response, service('logger'));

        return $auth->completeMfaLogin($userId);
    }

    /** @return array{ip_address:string, user_agent:string} */
    private function provenance(): array
    {
        return [
            'ip_address' => substr((string) $this->request->getIPAddress(), 0, 45),
            'user_agent' => substr((string) $this->request->getUserAgent()->getAgentString(), 0, 255),
        ];
    }
}
