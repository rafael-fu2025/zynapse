<?php

declare(strict_types=1);

namespace App\Filters;

use App\Auth\AccountStateService;
use App\Auth\CurrentDevice;
use App\Auth\CurrentUser;
use App\Exceptions\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Services\CurrentTenant;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * DeviceAuthFilter — authenticates automated BMG hardware (the
 * mechanized tumbler) by static device token.
 *
 * The device presents `X-Device-Token: dev_<64 hex>` (an
 * `Authorization: Bearer dev_…` header is accepted as a fallback so
 * generic HTTP clients work unmodified). Only the SHA-256 hash is
 * stored (`facilities_bmg_devices.token_hash`), minted once by
 * `synapse:bmg-device-register`.
 *
 * On success the request is bound to the DEVICE'S LINKED MACHINE USER —
 * `CurrentUser` / `CurrentTenant` are hydrated exactly like
 * ApiAuthFilter does, so every downstream service (policies, audit
 * outbox, notification outbox) behaves identically for device traffic.
 * The device cannot present a user JWT and a user cannot present a
 * device token: the two surfaces are disjoint by header + token shape.
 *
 * Route groups carrying `device_auth` MUST be outside any `api_auth`
 * group — the two filters are mutually exclusive by design.
 */
final class DeviceAuthFilter implements FilterInterface
{
    /**
     * @return RequestInterface|ResponseInterface Returning a Response
     *         short-circuits the request.
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // Defensive reset before any binding: a no-op on FPM, but under
        // a persistent runtime a previous request's device identity
        // would otherwise leak into this one.
        CurrentDevice::forget();

        $token = $this->presentedToken($request);
        if ($token === '') {
            return $this->reject(ApiException::unauthorized(ApiErrorCode::DEVICE_UNAUTHORIZED));
        }

        $device = Services::database()
            ->table('facilities_bmg_devices')
            ->select('id, tenant_id, unit_id, code, status, archived_at, linked_user_id')
            ->where('token_hash', hash('sha256', $token))
            ->get()
            ->getRowArray();

        if ($device === null) {
            return $this->reject(ApiException::unauthorized(ApiErrorCode::DEVICE_UNAUTHORIZED));
        }
        if ($device['status'] !== 'active' || $device['archived_at'] !== null) {
            return $this->reject(new ApiException(
                ApiErrorCode::DEVICE_DISABLED,
                403,
                [['code' => ApiErrorCode::DEVICE_DISABLED, 'message' => 'This device has been disabled.']],
            ));
        }

        // Same account-state gate ApiAuthFilter applies to humans: a
        // disabled/archived machine user must not ingest either.
        $userId   = (int) $device['linked_user_id'];
        $state    = (new AccountStateService())->forUser($userId);
        $decision = AccountStateService::accessDecision($state, $request->getUri()->getPath());
        if ($decision === AccountStateService::ACCESS_UNAUTHORIZED) {
            return $this->reject(new ApiException(
                ApiErrorCode::DEVICE_DISABLED,
                403,
                [['code' => ApiErrorCode::DEVICE_DISABLED, 'message' => 'This device account is disabled.']],
            ));
        }

        CurrentUser::bind($userId);
        CurrentTenant::set((int) $state['tenant_id']);
        CurrentDevice::bind($device);

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return $response;
    }

    /**
     * Device tokens come from the dedicated header first; the Bearer
     * fallback only accepts the `dev_` prefix so a leaked user JWT in
     * that header can never authenticate on the device surface.
     */
    private function presentedToken(RequestInterface $request): string
    {
        $header = trim((string) $request->getHeaderLine('X-Device-Token'));
        if ($header !== '') {
            return $header;
        }

        $auth = trim((string) $request->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\s+(dev_[0-9a-f]{64})$/i', $auth, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    private function reject(ApiException $exception): ResponseInterface
    {
        $response = Services::response()->setStatusCode($exception->httpStatus);

        return ApiExceptionFilter::fromThrowable($exception, $response);
    }
}
