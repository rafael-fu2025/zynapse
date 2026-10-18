<?php

declare(strict_types=1);

namespace App\Services\FuMis;

use Config\FuMis;

/** Opportunistic DB-only admission. Never performs directory IO in a request. */
final class MisAutoSyncService
{
    private readonly FuMis $config;

    // Retain the legacy second argument for callers; filesystem locks are obsolete.
    public function __construct(?FuMis $config = null, ?string $writableDir = null)
    {
        $this->config = $config ?? new FuMis();
    }

    public function maybeRun(bool $force = false): ?array
    {
        if (! $this->config->enabled || (! $this->config->autoSyncEnabled && ! $force)) {
            return null;
        }
        try {
            return (new MisSyncJobService())->enqueue('all', false, $this->config->autoSyncBatchSize,
                $this->config->autoSyncMaxPages, 'auto', $force ? 0 : $this->config->autoSyncCooldownSeconds);
        } catch (\Throwable) {
            log_message('warning', 'MIS automatic enqueue unavailable; check database/configuration.');
            return null;
        }
    }
}
