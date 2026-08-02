<?php

declare(strict_types=1);

namespace App\Auth;

use CodeIgniter\Database\BaseConnection;
use Config\Services;

/** Resolve mutable account state that must not be trusted from a signed JWT. */
final class AccountStateService
{
    public const ACCESS_GRANTED = 'granted';
    public const ACCESS_UNAUTHORIZED = 'unauthorized';
    public const ACCESS_PASSWORD_CHANGE_REQUIRED = 'password_change_required';

    private const FORCE_RESET_ALLOWED_PATHS = [
        'api/v1/auth/me',
        'api/v1/auth/change-password',
        'api/v1/auth/logout',
    ];

    public function __construct(private readonly ?BaseConnection $connection = null)
    {
    }

    /** @return array{active:bool, force_reset:bool}|null */
    public function forUser(int $userId): ?array
    {
        $db = $this->connection ?? Services::database();
        $row = $db->table('users u')
            ->select('u.active, u.deleted_at, COALESCE(i.force_reset, 0) AS force_reset', false)
            ->join('auth_identities i', "i.user_id = u.id AND i.type = 'email_password'", 'left')
            ->where('u.id', $userId)
            ->get()
            ->getRowArray();

        if ($row === null || $row['deleted_at'] !== null) {
            return null;
        }

        return [
            'active'      => (bool) $row['active'],
            'force_reset' => (bool) $row['force_reset'],
        ];
    }

    /**
     * Pure policy decision used by the request filter and unit tests.
     *
     * @param array{active:bool, force_reset:bool}|null $state
     */
    public static function accessDecision(?array $state, string $path): string
    {
        if ($state === null || ! $state['active']) {
            return self::ACCESS_UNAUTHORIZED;
        }
        if ($state['force_reset'] && ! in_array(trim($path, '/'), self::FORCE_RESET_ALLOWED_PATHS, true)) {
            return self::ACCESS_PASSWORD_CHANGE_REQUIRED;
        }
        return self::ACCESS_GRANTED;
    }
}
