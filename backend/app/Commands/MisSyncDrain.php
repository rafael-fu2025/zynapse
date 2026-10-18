<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\FuMis\MisSyncJobService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class MisSyncDrain extends BaseCommand
{
    protected $group = 'SYNAPSE';
    protected $name = 'synapse:mis-sync-drain';
    protected $description = 'Drain queued MIS syncs; never replay interrupted jobs.';
    protected $usage = 'synapse:mis-sync-drain [--limit=1] [--tenant=N]';
    protected $options = ['--limit' => 'Maximum jobs, 1-100 (default 1).', '--tenant' => 'Optional tenant id; otherwise discover queued tenants.'];

    public function run(array $params): int
    {
        $limit = filter_var($params['limit'] ?? CLI::getOption('limit') ?? 1, FILTER_VALIDATE_INT);
        $rawTenant = $params['tenant'] ?? CLI::getOption('tenant');
        $tenant = $rawTenant === null ? null : filter_var($rawTenant, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100 || ($tenant !== null && ($tenant === false || $tenant < 1))) {
            CLI::error('Use --limit=1..100 and --tenant=positive-id.');
            return 1;
        }
        try {
            $jobs = (new MisSyncJobService())->drain($limit, $tenant);
            CLI::write(json_encode($jobs, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            foreach ($jobs as $job) {
                if ($job['status'] !== 'completed') {
                    return 1;
                }
            }
            return 0;
        } catch (\Throwable) {
            CLI::error('MIS queue unavailable. Check database/configuration.');
            return 1;
        }
    }
}
