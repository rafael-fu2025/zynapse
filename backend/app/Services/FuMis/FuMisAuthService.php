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
        return $this->client->listStudents($this->serviceAccessToken(), $query);
    }

    /**
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    public function listEmployees(array $query = []): array
    {
        return $this->client->listEmployees($this->serviceAccessToken(), $query);
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
    private function upsertStudent(array $profile): int
    {
        $db   = $this->db ?? Services::database();
        $now  = date('Y-m-d H:i:s');

        $row = $db->table('users')
            ->select('id')
            ->where('student_number', $profile['identifier'])
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        $fields = array_filter([
            'first_name'  => $profile['first_name'],
            'middle_name' => $profile['middle_name'],
            'last_name'   => $profile['last_name'],
            'course'      => $profile['course'],
            'year_level'  => $profile['year_level'],
            'department'  => $profile['department'] ?? null,
            'section'     => $profile['section'],
            'kind'        => 'student',
        ], static fn ($v) => $v !== null);

        if ($row === null) {
            $fields['student_number'] = $profile['identifier'];
            $fields['username']       = 'stu-' . $profile['identifier'];
            $fields['status']         = 'active';
            $fields['active']         = 1;
            $fields['created_at']     = $now;
            $fields['updated_at']     = $now;

            $db->table('users')->insert($fields);
            $userId = (int) $db->insertID();
        } else {
            $userId = (int) $row['id'];
            $fields['updated_at'] = $now;
            // MIS knows better than a stale local row — but never
            // resurrect an archived/deactivated account from here.
            $db->table('users')->where('id', $userId)->update($fields);
        }

        $this->assignGroup($userId, 'student');

        return $userId;
    }

    /**
     * Create or refresh the local employee row. Keyed by employee_number.
     *
     * @param array{identifier: string, first_name: ?string, middle_name: ?string, last_name: ?string, department: ?string, position: ?string, employment_status: ?string, is_teaching: ?bool} $profile
     */
    private function upsertEmployee(array $profile): int
    {
        $db   = $this->db ?? Services::database();
        $now  = date('Y-m-d H:i:s');

        $row = $db->table('users')
            ->select('id')
            ->where('employee_number', $profile['identifier'])
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        $fields = array_filter([
            'first_name'        => $profile['first_name'],
            'middle_name'       => $profile['middle_name'],
            'last_name'         => $profile['last_name'],
            'department'        => $profile['department'],
            'position'          => $profile['position'],
            'employment_status' => $profile['employment_status'],
            'is_teaching'       => $profile['is_teaching'],
            'kind'              => 'employee',
        ], static fn ($v) => $v !== null);

        if ($row === null) {
            $fields['employee_number'] = $profile['identifier'];
            $fields['username']        = 'emp-' . $profile['identifier'];
            $fields['status']          = 'active';
            $fields['active']          = 1;
            $fields['created_at']      = $now;
            $fields['updated_at']      = $now;

            $db->table('users')->insert($fields);
            $userId = (int) $db->insertID();
        } else {
            $userId = (int) $row['id'];
            $fields['updated_at'] = $now;
            $db->table('users')->where('id', $userId)->update($fields);
        }

        $this->assignGroup($userId, 'employee');

        return $userId;
    }

    /**
     * Idempotent group membership (same shape as the seeders).
     */
    private function assignGroup(int $userId, string $group): void
    {
        $db  = $this->db ?? Services::database();
        $gid = $db->table('auth_groups')->select('id')->where('name', $group)->get()->getRowArray();

        if ($gid === null) {
            return;
        }

        $member = $db->table('auth_groups_users')
            ->where(['group_id' => (int) $gid['id'], 'user_id' => $userId])
            ->get()
            ->getRowArray();

        if ($member === null) {
            $db->table('auth_groups_users')->insert([
                'group_id'   => (int) $gid['id'],
                'user_id'    => $userId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
