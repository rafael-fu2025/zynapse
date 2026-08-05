<?php

declare(strict_types=1);

namespace App\Services\Audit;

use Config\Services;

/**
 * AuditAutoDrainService — opportunistic in-request drainer.
 *
 * The audit outbox is drained by a CLI worker in production (cron). In
 * dev / demo there is no scheduler, so this service drains opportunistically
 * on the tail of a request (`post_system`), gated by:
 *   - a cooldown (default 10s) so it is not a write per request;
 *   - a cheap pending-row probe so an empty outbox costs one indexed COUNT.
 *
 * `maybeDrain()` must never throw into the request lifecycle — any failure
 * is swallowed (the drainer retries next window).
 */
final class AuditAutoDrainService
{
    private const COOLDOWN_SECONDS = 10;

    private const LOCK_FILE = 'synapse_audit_drain_at';

    private string $writableDir;

    public function __construct(?string $writableDir = null)
    {
        $this->writableDir = $writableDir ?? (string) (WRITEPATH . 'cache');
    }

    public function maybeDrain(int $batch = 500, int $maxBatches = 1): void
    {
        try {
            if (! $this->cooldownElapsed()) {
                return;
            }

            $pending = (int) Services::database()
                ->table(SYNAPSE_AUDIT_OUTBOX)
                ->where('processed_at', null)
                ->where('attempt_count <', AuditDrainService::MAX_ATTEMPTS)
                ->countAllResults();

            if ($pending === 0) {
                return;
            }

            (new AuditDrainService())->drain($batch, $maxBatches);
        } catch (\Throwable $t) {
            // Never let background drain break a request.
        }
    }

    private function cooldownElapsed(): bool
    {
        $now    = time();
        $path   = $this->writableDir . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $last   = is_file($path) ? (int) @file_get_contents($path) : 0;

        if (($now - $last) < self::COOLDOWN_SECONDS) {
            return false;
        }

        // Best-effort stamp; a failed write only delays the next drain.
        @file_put_contents($path, (string) $now);
        return true;
    }
}
