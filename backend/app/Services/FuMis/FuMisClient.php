<?php

declare(strict_types=1);

namespace App\Services\FuMis;

use Config\FuMis;

/**
 * FuMisClient — typed wrapper for the university MIS API.
 *
 * Endpoint map (Foundation University MIS API):
 *   POST /api/v1/students/login      {student_id, password}
 *   POST /api/v1/employees/login     {employee_id, password}
 *   GET  /api/v1/students/{student}  + type/search/department/program/level/limit/page
 *   GET  /api/v1/employees/{employee} + search/department/limit/page
 *   POST /api/tokens/generate        (API-Key only)
 *   POST /api/tokens/refresh         (API-Key + Bearer refresh token)
 *
 * Login endpoints authenticate the person AND return the person data —
 * Synapse uses the response for both decisions and JIT provisioning.
 * Retrieve endpoints need a Bearer access token; see FuMisAuthService
 * for token lifecycle (generate → refresh, cache-backed).
 *
 * Never log credentials or response bodies.
 */
final class FuMisClient
{
    public function __construct(
        private readonly FuMis $config,
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * @return array{data: array<string, mixed>, access_token: string, refresh_token: string, expires_at: string}
     * @throws FuMisException 401 upstream = wrong credentials
     */
    public function studentLogin(string $studentId, string $password): array
    {
        return $this->post('/api/v1/students/login', [
            'student_id' => $studentId,
            'password'   => $password,
        ]);
    }

    /**
     * @return array{data: array<string, mixed>, access_token: string, refresh_token: string, expires_at: string}
     * @throws FuMisException 401 upstream = wrong credentials
     */
    public function employeeLogin(string $employeeId, string $password): array
    {
        return $this->post('/api/v1/employees/login', [
            'employee_id' => $employeeId,
            'password'    => $password,
        ]);
    }

    /**
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    public function listStudents(string $accessToken, array $query = []): array
    {
        return $this->get('/api/v1/students', $query, $accessToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function getStudent(string $accessToken, string $studentId): array
    {
        return $this->get('/api/v1/students/' . rawurlencode($studentId), [], $accessToken);
    }

    /**
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    public function listEmployees(string $accessToken, array $query = []): array
    {
        return $this->get('/api/v1/employees', $query, $accessToken);
    }

    /**
     * @return array<string, mixed>
     */
    public function getEmployee(string $accessToken, string $employeeId): array
    {
        return $this->get('/api/v1/employees/' . rawurlencode($employeeId), [], $accessToken);
    }

    /**
     * Mint a fresh service refresh token. The API-Key header alone
     * authenticates; no body required.
     *
     * @return array{refresh_token: string}
     */
    public function generateToken(): array
    {
        $result = $this->post('/api/tokens/generate', null);

        $refreshToken = $this->tokenField($result, 'refresh_token');

        if ($refreshToken === null) {
            throw new FuMisException('fumis.missing_refresh_token', 200);
        }

        return ['refresh_token' => $refreshToken];
    }

    /**
     * Exchange a service refresh token for a fresh access/refresh pair.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: string}
     */
    public function refreshToken(string $refreshToken): array
    {
        $result = $this->post('/api/tokens/refresh', null, [
            'Authorization' => 'Bearer ' . $refreshToken,
        ]);

        $pair = [];

        foreach (['access_token', 'refresh_token', 'expires_at'] as $field) {
            $value = $this->tokenField($result, $field);

            if ($value === null) {
                throw new FuMisException('fumis.missing_' . $field, 200);
            }

            $pair[$field] = $value;
        }

        return $pair;
    }

    /**
     * The live token endpoints wrap their payload as
     * {status, message, data:{…tokens…}}; the documented contract puts
     * the fields at the top level. Accept both — top level first, so a
     * deployment that answers flat is read exactly as before.
     *
     * @param array<string, mixed> $result
     */
    private function tokenField(array $result, string $field): ?string
    {
        $nested = is_array($result['data'] ?? null) ? $result['data'] : [];

        foreach ([$result, $nested] as $source) {
            $value = $source[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function post(string $path, ?array $body, array $extraHeaders = []): array
    {
        return $this->transport->request(
            'POST',
            $this->url($path),
            $this->headers($extraHeaders),
            $body,
            $this->config->timeoutSeconds,
        );
    }

    /**
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query, string $accessToken): array
    {
        $qs = $query === [] ? '' : '?' . http_build_query($query);

        return $this->transport->request(
            'GET',
            $this->url($path . $qs),
            $this->headers(['Authorization' => 'Bearer ' . $accessToken]),
            null,
            $this->config->timeoutSeconds,
        );
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function headers(array $extra): array
    {
        // Website/IP restriction flavor — the backend calls server-side,
        // so no Android/iOS package headers.
        return array_merge([
            'API-Key' => $this->config->apiKey,
        ], $extra);
    }

    private function url(string $path): string
    {
        return rtrim($this->config->baseUrl, '/') . $path;
    }
}
