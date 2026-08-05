<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Auth\CurrentUser;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;

/**
 * ReorderAutoCheckService — opportunistic low-stock procurement sweep
 * (inventory audit fix).
 *
 * `ReorderService::autoCheck()` only ran when a user clicked "Run
 * auto-check", so a stock-out overnight went undetected. This service
 * runs the same sweep on the tail of a request (`post_system`) gated
 * by a cooldown (default 30 min) so it is not a write per request —
 * mirroring `AuditAutoDrainService`.
 *
 * The sweep has no logged-in user (the hook fires outside the auth
 * filter), so a SYSTEM_USER_ID is bound for the duration; admin holds
 * the wildcard permission the policy check needs, and the audit rows
 * attribute the sweep to that account.
 *
 * `maybeRun()` must never throw into the request lifecycle — any
 * failure is swallowed (the sweep retries next window).
 */
final class ReorderAutoCheckService
{
    private const COOLDOWN_SECONDS = 1800;

    private const LOCK_FILE = 'synapse_reorder_auto_at';

    /** Admin account (id 1) — wildcard `*` covers `clinic.reorders.manage`. */
    private const SYSTEM_USER_ID = 1;

    private string $writableDir;

    public function __construct(?string $writableDir = null)
    {
        $this->writableDir = $writableDir ?? (string) (WRITEPATH . 'cache');
    }

    public function maybeRun(bool $force = false): void
    {
        try {
            if (! $force && ! $this->cooldownElapsed()) {
                return;
            }

            $previous = CurrentUser::id();
            CurrentUser::bind(self::SYSTEM_USER_ID);
            try {
                (new ReorderService(new ClinicPolicy(), Services::auditOutbox(), Services::notificationOutbox()))->autoCheck();
            } finally {
                CurrentUser::forget();
                if ($previous !== null) {
                    CurrentUser::bind($previous);
                }
            }
        } catch (\Throwable $t) {
            // Never let a background sweep break a request.
        }
    }

    private function cooldownElapsed(): bool
    {
        $now  = time();
        $path = $this->writableDir . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $last = is_file($path) ? (int) @file_get_contents($path) : 0;

        if (($now - $last) < self::COOLDOWN_SECONDS) {
            return false;
        }

        // Best-effort stamp; a failed write only delays the next run.
        @file_put_contents($path, (string) $now);
        return true;
    }
}
