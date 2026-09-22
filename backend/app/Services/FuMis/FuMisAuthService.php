<?php

declare(strict_types=1);

namespace App\Services\FuMis;

use App\Exceptions\FuMisInvalidCredentialsException;
use App\Exceptions\FuMisUpstreamException;
use CodeIgniter\Database\BaseConnection;
use Config\FuMis;
use Config\Services;

/**
 * FuMisAuthService — delegated authentication + JIT provisioning.
 *
 * Login flow (FUMIS_ENABLED=true):
 *   1. Dispatch the identifier: try student login, then employee login.
 *      (IDs are distinct namespaces, so one of the two 401s; a 401 on
 *      both = wrong credentials, never "which kind is this user" state.)
 *   2. Map the MIS `data` payload to users columns (FuMisProfileMapper).
 *   3. JIT upsert: find users by student_number / employee_number —
 *      create with kind + group on first login, refresh profile fields
 *      on every subsequent login. MIS-authenticated users never get an
 *      email_password identity (no local password to drift or leak).
 *   4. Hand the local user id back to AuthController for token issuance.
 *
 * MIS service tokens (generate → refresh) are cached in the shared
 * cache and NEVER exposed to clients. They are only needed for
 * directory lookups (listStudents/listEmployees) — the login endpoints
 * take the API key directly.
 */
final class FuMisAuthService
{
    private const TOKEN_CACHE_KEY = 'fumis_service_tokens';

    public function __construct(
        private readonly FuMis $config,
        private readonly FuMisClient $client,
        private readonly FuMisProfileMapper $mapper,
        private readonly ?BaseConnection $db = null,
    ) {
    }

    /**
     * Verify identifier+password against the MIS API and return the
     * local user id (JIT-provisioned or refreshed).
     *
     * @return int users.id
     * @throws FuMisUpstreamException on transport failure/unavailability
     * @throws FuMisInvalidCredentialsException when both namespaces 401
     */
    public function loginWithIdentifier(string $identifier, string $password): int
    {
        // Student first, employee second — IDs are distinct namespaces.
        $studentResult = $this->tryLogin(
            fn () => $this->client->studentLogin($identifier, $password),
        );

        if ($studentResult !== null) {
            $this->cacheLoginTokens($studentResult);
            $profile = $this->mapper->mapStudent($studentResult['data'] ?? []);

            if ($profile['identifier'] === null || $profile['identifier'] === '') {
                // MIS authenticated but returned no usable id — treat as
                // an upstream contract failure, not a local error.
                throw new FuMisUpstreamException('fumis.missing_identifier', 503);
            }

            return $this->upsertStudent($profile);
        }

        $employeeResult = $this->tryLogin(
            fn () => $this->client->employeeLogin($identifier, $password),
        );

        if ($employeeResult !== null) {
            $this->cacheLoginTokens($employeeResult);
            $profile = $this->mapper->mapEmployee($employeeResult['data'] ?? []);

            if ($profile['identifier'] === null || $profile['identifier'] === '') {
                throw new FuMisUpstreamException('fumis.missing_identifier', 503);
            }

            return $this->upsertEmployee($profile);
        }

        throw new FuMisInvalidCredentialsException($identifier);
    }

    /**
     * Directory lookups (admin tooling, future sync) need an access
     * token. Cached refresh-token flow; callers never see the tokens.
     *
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    public function listStudents(array $query = []): array
    {
        return $this->directoryRequest(fn (string $token): array => $this->client->listStudents($token, $query));
    }

    /**
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    public function listEmployees(array $query = []): array
    {
        return $this->directoryRequest(fn (string $token): array => $this->client->listEmployees($token, $query));
    }

    /**
     * Bounded directory search for students. Returns normalized mapped profiles.
     * Gracefully fails to [] if MIS is unreachable or disabled.
     *
     * @return list<array{identifier: string, first_name: ?string, middle_name: ?string, last_name: ?string, course: ?string, year_level: ?int, section: ?string, department: ?string}>
     */
    public function searchStudents(string $query, int $limit = 20): array
    {
        if (! $this->config->enabled || trim($query) === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $cacheKey = 'fumis_search_students_' . md5(strtolower(trim($query)) . '_' . $limit);
        $cached = Services::cache()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $raw = $this->listStudents(['search' => trim($query), 'limit' => $limit]);
            $items = $this->extractListRecords($raw);
            $profiles = [];
            foreach ($items as $item) {
                $mapped = $this->mapper->mapStudent($item);
                if ($mapped['identifier'] !== null && $mapped['identifier'] !== '') {
                    $profiles[] = $mapped;
                }
            }
            Services::cache()->save($cacheKey, $profiles, 60);
            return $profiles;
        } catch (\Throwable $e) {
            log_message('warning', sprintf('FuMis searchStudents failed: %s', $e->getMessage()));
            return [];
        }
    }

    /**
     * Bounded directory search for employees. Returns normalized mapped profiles.
     * Gracefully fails to [] if MIS is unreachable or disabled.
     *
     * @return list<array{identifier: string, first_name: ?string, middle_name: ?string, last_name: ?string, department: ?string, position: ?string, employment_status: ?string, is_teaching: ?bool}>
     */
    public function searchEmployees(string $query, int $limit = 20): array
    {
        if (! $this->config->enabled || trim($query) === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $cacheKey = 'fumis_search_employees_' . md5(strtolower(trim($query)) . '_' . $limit);
        $cached = Services::cache()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $raw = $this->listEmployees(['search' => trim($query), 'limit' => $limit]);
            $items = $this->extractListRecords($raw);
            $profiles = [];
            foreach ($items as $item) {
                $mapped = $this->mapper->mapEmployee($item);
                if ($mapped['identifier'] !== null && $mapped['identifier'] !== '') {
                    $profiles[] = $mapped;
                }
            }
            Services::cache()->save($cacheKey, $profiles, 60);
            return $profiles;
        } catch (\Throwable $e) {
            log_message('warning', sprintf('FuMis searchEmployees failed: %s', $e->getMessage()));
            return [];
        }
    }

    /**
     * Retrieve single student profile from MIS directory.
     *
     * @return array{identifier: string, first_name: ?string, middle_name: ?string, last_name: ?string, course: ?string, year_level: ?int, section: ?string, department: ?string}|null
     */
    public function getStudentProfile(string $studentId): ?array
    {
        if (! $this->config->enabled || trim($studentId) === '') {
            return null;
        }

        $cacheKey = 'fumis_student_profile_' . md5(strtolower(trim($studentId)));
        $cached = Services::cache()->get($cacheKey);
        if (is_array($cached) && isset($cached['identifier'])) {
            return $cached;
        }

        try {
            $raw = $this->client->getStudent($this->serviceAccessToken(), trim($studentId));
            $mapped = $this->mapper->mapStudent($raw['data'] ?? $raw);
            if ($mapped['identifier'] !== null && $mapped['identifier'] !== '') {
                Services::cache()->save($cacheKey, $mapped, 60);
                return $mapped;
            }
        } catch (\Throwable $e) {
            log_message('warning', sprintf('FuMis getStudentProfile failed: %s', $e->getMessage()));
        }

        // Fallback: try searching by ID
        $searched = $this->searchStudents($studentId, 5);
        foreach ($searched as $candidate) {
            if (strcasecmp($candidate['identifier'], trim($studentId)) === 0) {
                Services::cache()->save($cacheKey, $candidate, 60);
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Retrieve single employee profile from MIS directory.
     *
     * @return array{identifier: string, first_name: ?string, middle_name: ?string, last_name: ?string, department: ?string, position: ?string, employment_status: ?string, is_teaching: ?bool}|null
     */
    public function getEmployeeProfile(string $employeeId): ?array
    {
        if (! $this->config->enabled || trim($employeeId) === '') {
            return null;
        }

        $cacheKey = 'fumis_employee_profile_' . md5(strtolower(trim($employeeId)));
        $cached = Services::cache()->get($cacheKey);
        if (is_array($cached) && isset($cached['identifier'])) {
            return $cached;
        }

        try {
            $raw = $this->client->getEmployee($this->serviceAccessToken(), trim($employeeId));
            $mapped = $this->mapper->mapEmployee($raw['data'] ?? $raw);
            if ($mapped['identifier'] !== null && $mapped['identifier'] !== '') {
                Services::cache()->save($cacheKey, $mapped, 60);
                return $mapped;
            }
        } catch (\Throwable $e) {
            log_message('warning', sprintf('FuMis getEmployeeProfile failed: %s', $e->getMessage()));
        }

        // Fallback: try searching by ID
        $searched = $this->searchEmployees($employeeId, 5);
        foreach ($searched as $candidate) {
            if (strcasecmp($candidate['identifier'], trim($employeeId)) === 0) {
                Services::cache()->save($cacheKey, $candidate, 60);
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Ensure a student is provisioned in the local database. If missing locally,
     * fetches from the university directory and JIT-provisions the user.
     *
     * @return int|null Local users.id, or null if not found
     */
    public function ensureStudentProvisioned(string $studentNumber): ?int
    {
        $studentNumber = FuMisIdentityService::identifier($studentNumber);
        $userId = $this->identities()->findUserId('student', $studentNumber);
        if ($userId !== null) {
            return $userId;
        }

        $profile = $this->provisioningProfile('student', $studentNumber);
        if ($profile !== null) {
            return $this->upsertStudent($profile);
        }

        return null;
    }

    /**
     * Ensure an employee is provisioned in the local database. If missing locally,
     * fetches from the university directory and JIT-provisions the user.
     *
     * @return int|null Local users.id, or null if not found
     */
    public function ensureEmployeeProvisioned(string $employeeNumber): ?int
    {
        $employeeNumber = FuMisIdentityService::identifier($employeeNumber);
        $userId = $this->identities()->findUserId('employee', $employeeNumber);
        if ($userId !== null) {
            return $userId;
        }

        $profile = $this->provisioningProfile('employee', $employeeNumber);
        if ($profile !== null) {
            return $this->upsertEmployee($profile);
        }

        return null;
    }

    /**
     * Extract a list of item records from an MIS list response.
     *
     * @param array<string, mixed> $response
     * @return list<array<string, mixed>>
     */
    private function extractListRecords(array $response): array
    {
        $data = $response['data'] ?? $response;
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if (! is_array($data)) {
            return [];
        }
        $records = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                $records[] = $item;
            }
        }
        return $records;
    }

    /**
     * Run a login attempt; null when the upstream says 401 (wrong
     * credentials — try the other namespace). Transport failures and
     * 5xx propagate: no guessing when the API is unreachable.
     *
     * @param callable():array<string, mixed> $attempt
     * @return array<string, mixed>|null
     */
    private function tryLogin(callable $attempt): ?array
    {
        try {
            return $attempt();
        } catch (FuMisException $e) {
            if ($e->isInvalidCredentials()) {
                return null;
            }

            log_message('error', sprintf(
                'FuMisAuthService login attempt failed: [http %d] code=%s message=%s',
                $e->upstreamStatus,
                $e->upstreamCode,
                $e->getMessage(),
            ));

            // Transport failure (off-campus / down) or upstream 5xx.
            throw new FuMisUpstreamException(
                'auth.mis_unavailable',
                $e->isTransportFailure() ? 503 : 502,
            );
        }
    }

    /** Retry an expired directory bearer once; never retry authorization failures. */
    private function directoryRequest(callable $request): array
    {
        try {
            return $request($this->serviceAccessToken());
        } catch (FuMisException $e) {
            if ($e->upstreamStatus !== 401) {
                throw $e;
            }
            Services::cache()->delete(self::TOKEN_CACHE_KEY);
            return $request($this->serviceAccessToken());
        }
    }

    /**
     * Obtain a valid MIS access token for service lookups, via the
     * cached refresh token (refreshing the pair when it rotates).
     */
    private function serviceAccessToken(): string
    {
        $cached = Services::cache()->get(self::TOKEN_CACHE_KEY);
        $tokens = is_array($cached) ? $cached : [];

        if (isset($tokens['access_token']) && is_string($tokens['access_token']) && $tokens['access_token'] !== '') {
            return $tokens['access_token'];
        }

        // No usable access token: refresh from the cached refresh token,
        // or generate a new pair when even that is gone.
        $refreshToken = isset($tokens['refresh_token']) && is_string($tokens['refresh_token'])
            ? $tokens['refresh_token']
            : null;

        try {
            if ($refreshToken !== null) {
                $pair = $this->client->refreshToken($refreshToken);
            } else {
                $generated = $this->client->generateToken();
                $pair      = $this->client->refreshToken($generated['refresh_token']);
            }
        } catch (FuMisException $e) {
            // A dead refresh token (revoked/expired upstream) is not a
            // terminal state: mint a fresh pair once, then fail if that
            // also fails.
            if ($refreshToken === null) {
                throw new FuMisUpstreamException('auth.mis_unavailable', 503);
            }

            try {
                $generated = $this->client->generateToken();
                $pair      = $this->client->refreshToken($generated['refresh_token']);
            } catch (FuMisException) {
                throw new FuMisUpstreamException('auth.mis_unavailable', 503);
            }
        }

        Services::cache()->save(
            self::TOKEN_CACHE_KEY,
            ['access_token' => $pair['access_token'], 'refresh_token' => $pair['refresh_token']],
            $this->config->tokenCacheSeconds,
        );

        return $pair['access_token'];
    }

    /**
     * Cache tokens returned directly by a successful login.
     *
     * @param array<string, mixed> $result
     */
    private function cacheLoginTokens(array $result): void
    {
        $payload = is_array($result['data'] ?? null) ? $result['data'] : $result;
        $accessToken = $payload['access_token'] ?? $result['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? $result['refresh_token'] ?? null;

        if (is_string($accessToken) && $accessToken !== '' && is_string($refreshToken) && $refreshToken !== '') {
            Services::cache()->save(
                self::TOKEN_CACHE_KEY,
                ['access_token' => $accessToken, 'refresh_token' => $refreshToken],
                $this->config->tokenCacheSeconds,
            );
        }
    }

    /**
     * Create or refresh the local student row. Keyed by student_number.
     *
     * @param array{identifier: string, first_name: ?string, middle_name: ?string, last_name: ?string, course: ?string, year_level: ?int, section: ?string} $profile
     */
    public function upsertStudent(array $profile): int
    {
        $result = $this->identities()->upsert('student', $profile);
        // Archived/deleted accounts are never usable through JIT login.
        return $this->identities()->findUserId('student', (string) $profile['identifier']) ?? (int) $result['id'];
    }

    /**
     * Create or refresh the local employee row. Keyed by employee_number.
     *
     * @param array{identifier: string, first_name: ?string, middle_name: ?string, last_name: ?string, department: ?string, position: ?string, employment_status: ?string, is_teaching: ?bool} $profile
     */
    public function upsertEmployee(array $profile): int
    {
        $result = $this->identities()->upsert('employee', $profile);
        return $this->identities()->findUserId('employee', (string) $profile['identifier']) ?? (int) $result['id'];
    }

    private ?FuMisIdentityService $identityService = null;

    private function identities(): FuMisIdentityService
    {
        return $this->identityService ??= new FuMisIdentityService($this->db);
    }

    /**
     * A write must distinguish not-found from network failure. Unlike optional
     * search, never turn an outage into an empty successful provisioning result.
     * The returned identifier MUST match the requested namespace and ID.
     */
    private function provisioningProfile(string $kind, string $identifier): ?array
    {
        if (! $this->config->enabled) {
            return null; // Optional lookup is local-only when integration is explicitly disabled.
        }
        try {
            try {
                $raw = $this->directoryRequest(fn (string $token): array => $kind === 'student'
                    ? $this->client->getStudent($token, $identifier)
                    : $this->client->getEmployee($token, $identifier));
                $profile = $kind === 'student'
                    ? $this->mapper->mapStudent($raw['data'] ?? $raw)
                    : $this->mapper->mapEmployee($raw['data'] ?? $raw);
                if (($profile['identifier'] ?? null) !== null) {
                    if (strcasecmp($profile['identifier'], $identifier) !== 0) {
                        throw new FuMisUpstreamException('auth.mis_unavailable', 502);
                    }
                    return $profile;
                }
            } catch (FuMisException $e) {
                if ($e->upstreamStatus !== 404) {
                    throw $e;
                }
            }
            // Some MIS deployments expose identity lookup only through search.
            $raw = $kind === 'student'
                ? $this->listStudents(['search' => $identifier, 'limit' => 5])
                : $this->listEmployees(['search' => $identifier, 'limit' => 5]);
            foreach ($this->extractListRecords($raw) as $record) {
                $profile = $kind === 'student' ? $this->mapper->mapStudent($record) : $this->mapper->mapEmployee($record);
                if (isset($profile['identifier']) && strcasecmp($profile['identifier'], $identifier) === 0) {
                    return $profile;
                }
            }
            return null;
        } catch (FuMisException $e) {
            throw new FuMisUpstreamException('auth.mis_unavailable', $e->isTransportFailure() ? 503 : 502);
        }
    }
}
