<?php

declare(strict_types=1);

namespace App\Services\Rbac;

/**
 * PrivilegedRoles — the role-name constants behind the 2026-09 RBAC
 * rework (Platform Owner / per-unit administrator split).
 *
 * Role definitions themselves stay code-defined in Config\AuthGroups;
 * only ASSIGNMENTS are DB-managed. These constants exist so the
 * server-side governance rules (D2) reference one source of truth
 * instead of scattering role-name literals across services:
 *
 *   - Granting or revoking any role in SET_P requires
 *     `rbac.privileged.manage` (superadmin only).
 *   - No user can revoke their own last privileged role.
 *   - Kiosk machine accounts are created/reset only by clinic_admin
 *     or superadmin.
 *
 * These are the ONLY sanctioned role-name references in server logic —
 * every authorization CHECK still goes through permission codes.
 */
final class PrivilegedRoles
{
    /**
     * Privileged role set P — grant/revoke requires
     * `rbac.privileged.manage`. Sorted for stable diffs/audits.
     *
     * @var list<string>
     */
    public const SET_P = ['bmg_admin', 'clinic_admin', 'guidance_admin', 'superadmin'];

    /** The wildcard holder ('*') — the Platform Owner. */
    public const WILDCARD_GROUP = 'superadmin';

    /** Kiosk machine accounts (created/reset only by clinic_admin or superadmin). */
    public const KIOSK_GROUP = 'kiosk';

    /** Clinic unit administrator — the kiosk machine-account owner. */
    public const CLINIC_ADMIN_GROUP = 'clinic_admin';
}
