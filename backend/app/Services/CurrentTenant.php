<?php
/**
 * CurrentTenant — Phase 6 of the patient-registry consolidation.
 *
 * Returns the active tenant id for the current request. Single-tenant
 * deployments always get 1. A future multi-tenant deployment would
 * wire this to a request-scoped binding (header, subdomain, JWT claim,
 * etc.) so every query can be scoped to the active tenant.
 *
 * Usage in services:
 *
 *     $rows = $this->db->table('clinic_encounters')
 *         ->where('tenant_id', CurrentTenant::id())
 *         ->get()->getResultArray();
 */
declare(strict_types=1);

namespace App\Services;

final class CurrentTenant
{
    /** @var int|null Cached tenant id within the request lifecycle. */
    private static ?int $id = null;

    /**
     * Returns the active tenant id. Defaults to 1 (Foundation
     * University) in the single-tenant deployment.
     */
    public static function id(): int
    {
        if (self::$id === null) {
            self::$id = (int) (getenv('SYNAPSE_TENANT_ID') ?: 1);
        }
        return self::$id;
    }

    /**
     * Set the active tenant id (used by the future request-scoped
     * binding to override the default for the current request).
     */
    public static function set(?int $id): void
    {
        self::$id = $id;
    }

    /**
     * Reset the cached value. Useful in tests to ensure a clean state
     * between cases.
     */
    public static function reset(): void
    {
        self::$id = null;
    }
}
