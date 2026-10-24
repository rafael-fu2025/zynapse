<?php

declare(strict_types=1);

namespace App\Controllers\Api\Auth;

use App\Auth\CurrentUser;
use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * SessionController — self-service refresh-session inventory (2026-10
 * audit G-5).
 *
 *   GET  api/v1/auth/sessions          list the caller's active sessions
 *   POST api/v1/auth/sessions/revoke   revoke one OTHER session family
 *
 * A "session" is a refresh-token family. Strictly self-scoped: another
 * user's family id is indistinguishable from a nonexistent one (404).
 * The family backing the presented cookie cannot be revoked here — that
 * is what POST /auth/logout is for; refusing it prevents a UI foot-gun
 * where "revoke current session" leaves an unusable cookie behind.
 */
final class SessionController extends ApiController
{
    public function index(): ResponseInterface
    {
        $userId = CurrentUser::assert();

        // One row per family with at least one non-revoked token.
        $rows = Services::database()->table('auth_refresh_tokens')
            ->select('family_id, MAX(id) AS latest_id, COUNT(*) AS token_count')
            ->where('user_id', $userId)
            ->where('revoked_at', null)
            ->groupBy('family_id')
            ->get()->getResultArray();

        if ($rows === []) {
            return $this->ok([]);
        }

        $latestIds = array_map(static fn (array $r): int => (int) $r['latest_id'], $rows);
        $latest = Services::database()->table('auth_refresh_tokens t')
            ->select('t.family_id, t.user_agent_hash, t.ip_subnet, t.last_used_at, t.session_expires_at, t.created_at')
            ->whereIn('t.id', $latestIds)
            ->get()->getResultArray();
        $byFamily = [];
        foreach ($latest as $l) {
            $byFamily[(string) $l['family_id']] = $l;
        }

        // Family start = earliest created_at across the family's rows.
        $starts = Services::database()->table('auth_refresh_tokens')
            ->select('family_id, MIN(created_at) AS started_at')
            ->where('user_id', $userId)
            ->whereIn('family_id', array_keys($byFamily))
            ->groupBy('family_id')
            ->get()->getResultArray();
        $startByFamily = [];
        foreach ($starts as $s) {
            $startByFamily[(string) $s['family_id']] = (string) $s['started_at'];
        }

        $currentFamily = $this->currentFamilyId($userId);

        $sessions = [];
        foreach ($rows as $r) {
            $familyId = (string) $r['family_id'];
            $info = $byFamily[$familyId] ?? null;
            if ($info === null) {
                continue;
            }
            $sessions[] = [
                'family_id'         => $familyId,
                'started_at'        => $startByFamily[$familyId] ?? $info['created_at'],
                'last_used_at'      => $info['last_used_at'],
                'session_expires_at' => $info['session_expires_at'],
                'user_agent_hash'   => $info['user_agent_hash'],
                'ip_subnet'         => $info['ip_subnet'],
                'rotations'         => (int) $r['token_count'],
                'is_current'        => $familyId === $currentFamily,
            ];
        }

        usort($sessions, static fn (array $a, array $b): int => strcmp((string) $b['started_at'], (string) $a['started_at']));

        return $this->ok($sessions);
    }

    public function revoke(): ResponseInterface
    {
        $userId = CurrentUser::assert();
        $payload = $this->request->getJSON(true) ?? [];

        $familyId = trim((string) ($payload['family_id'] ?? ''));
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $familyId) !== 1) {
            throw ApiException::validationFailure([[
                'code'    => 'validation.field',
                'message' => 'family_id must be a session family identifier.',
                'field'   => 'family_id',
            ]]);
        }

        $currentFamily = $this->currentFamilyId($userId);
        if (strcasecmp($currentFamily ?? '', $familyId) === 0) {
            throw new ApiException('validation.invalid', 400, [[
                'code'    => 'validation.invalid',
                'message' => 'That is the session you are signed in with. Use sign out instead.',
            ]]);
        }

        $db = Services::database();
        $db->transBegin();
        try {
            // Self-scoped: the family must belong to the caller; a foreign
            // family id updates zero rows and reads as 404.
            $db->table('auth_refresh_tokens')
                ->where('user_id', $userId)
                ->where('family_id', $familyId)
                ->where('revoked_at', null)
                ->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);

            $revoked = $db->affectedRows();
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        if ($revoked === 0) {
            // 404 via the resource vocabulary — an `auth.` prefix would map
            // to 401, and this is "no such session of YOURS", not an auth
            // failure.
            throw ApiException::notFound('resource.not_found');
        }

        Services::auditOutbox()->enqueue('auth.session_revoked', 'auth_sessions', null, $userId, [
            'outcome' => 'success',
            'reason_code' => 'self_service',
            'resource_code' => 'family#' . $familyId,
        ]);

        return $this->ok(['family_id' => $familyId, 'revoked' => true]);
    }

    /**
     * Family of the refresh cookie presented with THIS request, if any.
     * No cookie / unknown cookie → null (e.g. the SPA listing sessions
     * right after an access-token refresh that consumed the cookie).
     */
    private function currentFamilyId(int $userId): ?string
    {
        $cookieName = (string) (getenv('REFRESH_COOKIE_NAME') ?: 'synapse_rt');
        $refresh = trim((string) $this->request->getCookie($cookieName));
        if ($refresh === '') {
            return null;
        }

        $hash = Services::jwt()->hashRefresh($refresh);
        $row = Services::database()->table('auth_refresh_tokens')
            ->select('family_id')
            ->where('user_id', $userId)
            ->where('token_hash', $hash)
            ->get()->getRowArray();

        return $row !== null ? (string) $row['family_id'] : null;
    }
}
