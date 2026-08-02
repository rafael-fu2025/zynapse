<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Services;
use Modules\Reports\Services\ReportConfigService;
use Modules\Reports\Services\ReportService;

/** Process queued generated reports and expire retained files. */
final class ReportGenerationDrain extends BaseCommand
{
    protected $group = 'SYNAPSE';
    protected $name = 'synapse:reports-drain';
    protected $description = 'Generate queued reports and remove expired report files.';
    protected $usage = 'synapse:reports-drain [--limit=10]';
    protected $arguments = [];
    protected $options = ['--limit' => 'Maximum queued reports to process'];

    public function run(array $params): int
    {
        $limit = max(1, min(100, (int) ($params['limit'] ?? 10)));
        $service = new ReportConfigService(new ReportService(), Services::auditOutbox());
        $processed = $service->processQueued($limit);
        $expired = $service->cleanupExpired();
        CLI::write("Processed {$processed} queued report(s); expired {$expired} retained file(s).", 'green');
        return 0;
    }
}
