<?php

declare(strict_types=1);

namespace App\Services\FuMis;

use Config\FuMis;
use Config\Services;

/**
 * MisAutoSyncService — opportunistic scheduled batch sync runner.
 *
 * Runs background directory synchronization on campus-network deployments
 * without requiring manual spark invocations or external crontabs.
 *
 * Invariants:
 *   - Completely decoupled from individual login events: login NEVER calls this.
 *   - Bounded by cooldown ($config->autoSyncCooldownSeconds, default 24h) and
 *     file-based lock timestamp so it never executes redundantly.
 *   - Only runs when $config->enabled AND $config->autoSyncEnabled are true.
 *   - Exception-swallowing: failures never leak into user-facing web requests.
 */
final class MisAutoSyncService
{
    private const LOCK_FILE = 'synapse_mis_sync_at';

    private readonly FuMis $config;
    private string $writableDir;

    public function __construct(?FuMis $config = null, ?string $writableDir = null)
    {
        $this->config = $config ?? new FuMis();
        $this->writableDir = $writableDir ?? (string) (WRITEPATH . 'cache');
    }

    /**
     * Run opportunistic batch sync if enabled and cooldown has elapsed.
     *
     * @return array<string, mixed>|null Sync summary if run, null if skipped.
     */
    public function maybeRun(bool $force = false): ?array
    {
        if (! $this->config->enabled || (! $this->config->autoSyncEnabled && ! $force)) {
            return null;
        }

        try {
            if (! $force && ! $this->cooldownElapsed()) {
                return null;
            }

            $syncService = new FuMisDirectorySyncService(null, null, $this->config);
            $summary = $syncService->run(
                'all',
                false,
                $this->config->autoSyncBatchSize,
                $this->config->autoSyncMaxPages,
            );

            if ($summary['success'] ?? false) {
                $this->touchLock();
            }

            return $summary;
        } catch (\Throwable $t) {
            log_message('warning', sprintf('MisAutoSyncService failed: %s', $t->getMessage()));
            return null;
        }
    }

    private function cooldownElapsed(): bool
    {
        $now = time();
        $path = $this->writableDir . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $last = is_file($path) ? (int) @file_get_contents($path) : 0;

        if (($now - $last) < $this->config->autoSyncCooldownSeconds) {
            return false;
        }

        // Pre-stamp to prevent concurrent web requests from running overlapping syncs.
        $this->touchLock();
        return true;
    }

    private function touchLock(): void
    {
        $path = $this->writableDir . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        @file_put_contents($path, (string) time());
    }
}
