<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * ExternalApps — configuration for the external API surface (D4/D5).
 *
 * API keys are Stripe-style: env-separated (`syn_test_…` / `syn_live_…`),
 * hash-only storage, prefix-indexed lookup, per-key rate limits, 90-day
 * default rotation. Test keys are pinned to the SANDBOX tenant (synthetic
 * data); live keys to the production tenant.
 */
class ExternalApps extends BaseConfig
{
    /** Tenant slug that hosts test-key traffic (migration 2026-09-12-000030 seeds it). */
    public string $sandboxTenantSlug = 'sandbox';

    /** Default per-key request budget (requests/minute, fixed window). */
    public int $defaultRateLimitPerMin = 60;

    /** Default key lifetime — the rotation cadance in docs/OPERATIONS.md matches it. */
    public int $defaultKeyTtlDays = 90;

    /**
     * Sampling rates for `external.request` audit events, per key env.
     * Live traffic is fully audited; sandbox traffic is sampled to keep
     * the outbox meaningful. Override via env EXTERNAL_AUDIT_SAMPLE_TEST
     * / EXTERNAL_AUDIT_SAMPLE_LIVE ("0".."1").
     *
     * @var array<string, float>
     */
    public array $auditSampleRates = [
        'test' => 0.2,
        'live' => 1.0,
    ];

    /**
     * The v1 external scope catalog. Keys may ONLY hold codes from this
     * list — the wildcard is never grantable, and any future PHI-returning
     * scope (RA 10173 lawful-basis gate) must be added here explicitly.
     *
     * @var array<string, string> code => integrator-facing description
     */
    public array $scopes = [
        'reports.read'   => 'De-identified visit and queue aggregates',
        'referrals.read' => 'Referral flow and verification aggregates',
    ];

    public function __construct()
    {
        parent::__construct();

        foreach (array_keys($this->auditSampleRates) as $env) {
            $envKey = 'EXTERNAL_AUDIT_SAMPLE_' . strtoupper($env);
            $raw = getenv($envKey);
            if ($raw !== false && is_numeric($raw)) {
                $this->auditSampleRates[$env] = max(0.0, min(1.0, (float) $raw));
            }
        }
    }
}
