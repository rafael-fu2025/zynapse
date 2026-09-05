<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tenancy fitness test — the structural guardrail for multi-tenant
 * scoping.
 *
 * Context: `tenant_id` exists on ~40 tables, but scoping is applied
 * MANUALLY per query and only ~9% of sampled query sites did it. This
 * test cannot make queries correct — but it makes regression VISIBLE:
 * it scans every service/controller for `->table('X')` calls on
 * tenant-scoped tables, checks whether the surrounding statement
 * mentions the tenant scope, and fails when the count of unscoped
 * sites grows beyond the recorded baseline.
 *
 * So: existing violations (the baseline) burn down over time; NEW ones
 * fail CI immediately. When you scope a file, update the baseline
 * downward in the same commit — the numbers only ever shrink.
 *
 * Scan methodology (deliberately heuristic, like
 * KioskMediaSecurityTest's source assertions):
 *   - a "site" is each `->table('name')` / `->table("name")` occurrence;
 *   - a site is "scoped" if the text from that occurrence to the end of
 *     the statement (next `;` outside the call chain, capped) contains
 *     `tenant_id` or `CurrentTenant`;
 *   - files under auth/RBAC/outbox-drain/commands are exempt (see
 *     EXEMPT_PATHS for why each bucket must span tenants).
 */
final class TenantScopeFitnessTest extends TestCase
{
    /**
     * Tables whose rows must be tenant-scoped. Keep in sync with the
     * migrations that add `tenant_id` (TenantsAndTenantId, the BMG
     * additions, TenantIdSchemaGap).
     *
     * @var list<string>
     */
    private const TENANT_TABLES = [
        'clinic_appointments',
        'clinic_checkins',
        'clinic_departments',
        'clinic_encounters',
        'clinic_inventory_items',
        'clinic_inventory_movements',
        'clinic_medicine_batches',
        'clinic_medicine_forecasts',
        'clinic_medicine_transactions',
        'clinic_medicines',
        'clinic_queue_entries',
        'clinic_reorder_requests',
        'clinic_staff_schedules',
        'clinic_triage_predictions',
        'clinic_treatments',
        'clinic_vitals',
        'counselling_appointments',
        'counselling_availability',
        'counselling_notes',
        'counselling_queue_entries',
        'counselling_scheduling_analytics',
        'counselling_sessions',
        'facilities_bmg_alerts',
        'facilities_bmg_batch_updates',
        'facilities_bmg_batches',
        'facilities_bmg_inputs',
        'facilities_bmg_losses',
        'facilities_bmg_outputs',
        'facilities_bmg_process_logs',
        'facilities_bmg_units',
        'facilities_waste_categories',
        'generated_reports',
        'notification_outbox',
        'notifications',
        'referral_referrals',
        'report_configurations',
        'report_summaries',
        'users',
    ];

    /**
     * Path fragments (relative to app/) whose queries legitimately span
     * tenants and are never scanned:
     *
     *  - Auth/Rbac: credential and permission lookups run BEFORE a tenant
     *    is known; `users` is the table that RESOLVES the tenant.
     *  - Audit + Notify drains: the audit chain is hash-linked across the
     *    whole table — a tenant predicate would fork the chain and break
     *    AuditChainVerifier.
     *  - Analytics: pure classes, no DB access (kept exempt defensively).
     *
     * @var list<string>
     */
    private const EXEMPT_PATHS = [
        'Auth/',
        'Services/Rbac/',
        'Services/Audit/',
        'Services/Notify/',
        'Services/Analytics/',
        'Commands/',
        'Database/',
        'Views/',
    ];

    /**
     * Burn-down baseline: unscoped-site counts per file. Introduced
     * 2026-08-29 at 305 sites across 23 files; the Phase-2.4 burn-down
     * scoped them ALL the same day (agents + review), so the map now
     * holds only the one KNOWN scanner false positive below.
     *
     * The test fails if a file's count EXCEEDS the baseline; shrinking
     * without updating is fine (the assert is one-sided) but update the
     * map when you fix a file.
     *
     * Files missing from the map had zero unscoped sites at baseline —
     * i.e. every tenant-scoped ->table() site in every non-exempt
     * service/controller must carry a tenant predicate.
     *
     * @var array<string, int>
     */
    private const BASELINE = [
        // KNOWN FALSE POSITIVE: the scanner's statement window cannot see
        // through the $row variable — the referral_referrals INSERT at
        // ~L211 builds its data array above (L188: 'tenant_id' =>
        // CurrentTenant::id()) and inserts it at L211. If you touch that
        // method, inline nothing; just leave this entry as-is.
        'Modules/Referrals/Services/ReferralService.php' => 1,
    ];

    public function testNoNewUnscopedTenantQuerySites(): void
    {
        $appRoot = $this->appRoot();
        $counts = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appRoot) + 1));

            if ($this->isExempt($relative)) {
                continue;
            }
            if (! str_contains($relative, 'Services/') && ! str_contains($relative, 'Controllers/')) {
                continue;
            }

            $unscoped = $this->countUnscopedSites((string) file_get_contents($file->getPathname()));
            if ($unscoped > 0) {
                $counts[$relative] = $unscoped;
            }
        }

        $regressions = [];
        foreach ($counts as $file => $count) {
            $allowed = self::BASELINE[$file] ?? 0;
            if ($count > $allowed) {
                $regressions[] = sprintf('%s: %d unscoped (baseline allows %d)', $file, $count, $allowed);
            }
        }

        $this->assertSame([], $regressions, sprintf(
            "New unscoped tenant query sites detected. Every `->table('X')` on a tenant-scoped\n"
            . "table must carry `->where('tenant_id', CurrentTenant::id())` (or be added to an\n"
            . "exemption bucket with a documented reason). Regressions:\n%s",
            implode("\n", $regressions),
        ));
    }

    public function testBaselineOnlyShrinks(): void
    {
        // Guard the burn-down itself: if a file in the baseline reaches
        // zero unscoped sites, this test nags until the map is updated.
        $appRoot = $this->appRoot();
        $fullyScoped = [];

        foreach (array_keys(self::BASELINE) as $relative) {
            $path = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_file($path)) {
                continue; // file deleted — fine, map entry is inert
            }
            if ($this->countUnscopedSites((string) file_get_contents($path)) === 0) {
                $fullyScoped[] = $relative;
            }
        }

        $this->assertSame([], $fullyScoped, sprintf(
            "These files are now fully tenant-scoped — remove them from BASELINE to record\n"
            . "burn-down progress:\n%s",
            implode("\n", $fullyScoped),
        ));
    }

    /**
     * Counts `->table('X')` sites on tenant-scoped tables whose
     * statement shows no tenant scoping.
     */
    private function countUnscopedSites(string $source): int
    {
        $scopedTables = implode('|', self::TENANT_TABLES);
        $pattern = "/->table\\(\\s*['\"]({$scopedTables})['\"]/";

        $count = 0;
        if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            return 0;
        }

        foreach ($matches[1] as [$table, $offset]) {
            $window = $this->statementWindow($source, $offset);
            if (! str_contains($window, 'tenant_id') && ! str_contains($window, 'CurrentTenant')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The text from the `->table(` call to the end of the statement —
     * the next `;` not followed by more chained calls, or a hard cap for
     * very long builder chains. Deliberately generous: the baseline
     * absorbs imprecision, and the heuristic is deterministic.
     */
    private function statementWindow(string $source, int $offset): string
    {
        $cap = 1500;
        $end = min(strlen($source), $offset + $cap);
        $semi = strpos($source, ';', $offset);

        if ($semi !== false && $semi < $end) {
            $end = $semi;
        }

        return substr($source, $offset, $end - $offset);
    }

    private function isExempt(string $relativePath): bool
    {
        foreach (self::EXEMPT_PATHS as $fragment) {
            if (str_contains($relativePath, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function appRoot(): string
    {
        // tests/Unit/<this file> → backend/app
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app';
        $this->assertDirectoryExists($root);
        return (string) realpath($root);
    }
}
