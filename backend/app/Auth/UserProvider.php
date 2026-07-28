<?php

declare(strict_types=1);

namespace App\Auth;

use CodeIgniter\Database\BaseConnection;
use Config\Services;

/**
 * UserProvider — minimal credential/user lookup for the stateless API.
 *
 * SYNAPSE keeps a Shield-shaped schema (`users` + `auth_identities`)
 * but does NOT route auth through Shield's authenticator stack (JWTs
 * are issued by `JwtService`). This provider is the only reader the
 * API needs:
 *
 *   - `email_password` identity rows: `secret` = email,
 *     `secret2` = password_hash (never selected unless needed).
 */
final class UserProvider
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Services::database();
    }

    /**
     * Look up an ACTIVE user by email. Returns an object exposing
     * `id`, `email`, `username`, `active`, `password_hash` — or null.
     */
    public function findByCredentials(array $credentials): ?object
    {
        $email = strtolower(trim((string) ($credentials['email'] ?? '')));
        if ($email === '') {
            return null;
        }

        $row = $this->db->table('users u')
            ->select('u.id, u.username, u.active, i.secret AS email, i.secret2 AS password_hash')
            ->join('auth_identities i', "i.user_id = u.id AND i.type = 'email_password'")
            ->where('LOWER(i.secret)', $email)
            ->where('u.deleted_at', null)
            ->where('u.active', 1)
            ->get()->getRow();

        return $row !== null ? $this->cast($row) : null;
    }

    /**
     * Look up an active user by id. Password hash is NOT exposed here.
     */
    public function findById(int $userId): ?object
    {
        $row = $this->db->table('users u')
            ->select('u.id, u.username, u.active, i.secret AS email, i.force_reset')
            ->join('auth_identities i', "i.user_id = u.id AND i.type = 'email_password'", 'left')
            ->where('u.id', $userId)
            ->where('u.deleted_at', null)
            ->get()->getRow();

        return $row !== null ? $this->cast($row) : null;
    }

    private function cast(object $row): object
    {
        $row->id     = (int) $row->id;
        $row->active = (bool) $row->active;
        return $row;
    }
}
