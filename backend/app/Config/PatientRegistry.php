<?php
/**
 * PatientRegistry — feature flag for the patient-registry consolidation.
 *
 * Read at request time by every service that resolves a patient by
 * identifier. When `legacyMode` is true (default during the transition),
 * services fall through to the legacy patients_students / patients_employees
 * tables on a miss. When false (after Phase 5 production rollout), the new
 * patient_identifiers table is the only source of truth.
 *
 * Services should branch on this single config value rather than carry
 * their own copies of the flag.
 */
declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

final class PatientRegistry extends BaseConfig
{
    /**
     * When true, services that look up a patient by identifier fall back
     * to the legacy patients_students / patients_employees tables on a
     * patient_identifiers miss. Set to false after Phase 5 to retire the
     * legacy tables in production.
     *
     * @var bool
     */
    public bool $legacyMode = true;

    /**
     * When true, the patient_identifiers table is treated as the canonical
     * source of truth. Services that look up a patient by identifier try
     * the new table first and (if `legacyMode`) fall back to the legacy
     * tables. When false, services ONLY consult the new table.
     *
     * @var bool
     */
    public bool $newMode = true;
}
