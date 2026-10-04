<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Auth\CurrentUser;
use Config\Services;

/**
 * QueueSkipSweepService — resolves expired skip windows to no-show.
 *
 * The queue's 60-minute recall window (October 2026 panel revision) is
 * enforced server-side so the transition happens even when no staff
 * member has a page open:
 *
 *   - production: cron runs `php spark synapse:queue-skip-sweep`
 *     every minute (see backend/README.md);
 *   - dev / demo: the `post_system` hook calls `maybeRun()` on a
 *     cooldown so a laptop with no scheduler still resolves deadlines.
 *
 * The sweep itself (`QueueService::sweepExpiredSkips()`) is idempotent —
 * every candidate is re-validated under a row lock — so overlapping
 * runs can never double-write. The cooldown here exists purely so the
 * in-request path is not a write per request.
 *
 * There is no logged-in user on the hook path, so the sweep runs with
 * the seeded system account bound for the duration (same pattern as
 * `ReorderAutoCheckService`): any audit rows it writes attribute the
 * automatic transition to that account, and `CurrentUser` is restored
 * afterwards so the rest of the request is unaffected.
 *
 * `maybeRun()` must never throw into the request lifecycle — any
 * failure is swallowed and the next window retries.
 */
final class QueueSkipSweepService
{
    /**
     * Sweep cadence for the opportunistic (post_system) path. Shorter
     * than the other auto-runners: a countdown that expires must flip
     * within about a minute of its deadline, not thirty.
     */
    private const COOLDOWN_SECONDS = 30;

    private const LOCK_FILE = 'synapse_queue_skip_sweep_at';

    /** Admin account — wildcard `*` covers the queue policy checks. */
    private readonly int $systemUserId;

    private string $writableDir;

    public function __construct(?string $writableDir = null)
    {
        $this->writableDir = $writableDir ?? (string) (WRITEPATH . 'cache');

        // Mirrors ReorderAutoCheckService: deployments that remove the
        // seeded admin point SYNAPSE_SYSTEM_USER_ID at another wildcard
        // holder.
        $env = getenv('SYNAPSE_SYSTEM_USER_ID');
        $this->systemUserId = ($env === false || $env === '') ? 1 : max(1, (int) $env);
    }

    /**
     * Run the sweep now (CLI) or on the next elapsed cooldown (hook).
     *
     * @return int entries transitioned to no-show
     */
    public function maybeRun(bool $force = false): int
    {
        try {
            if (! $force && ! $this->cooldownElapsed()) {
                return 0;
            }

            $previous = CurrentUser::id();
            CurrentUser::bind($this->systemUserId);
            try {
                return Services::queueService()->sweepExpiredSkips();
            } finally {
                CurrentUser::forget();
                if ($previous !== null) {
                    CurrentUser::bind($previous);
                }
            }
        } catch (\Throwable $t) {
            // Never let a background sweep break a request.
            return 0;
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
