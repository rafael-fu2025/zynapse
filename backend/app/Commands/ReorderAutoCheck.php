<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Clinic\Services\ReorderAutoCheckService;

/**
 * ReorderAutoCheck — run the low-stock procurement sweep on demand
 * (inventory audit fix; the post_system hook runs it on a cooldown).
 *
 *   php spark synapse:reorder-auto-check
 *
 * Creates `pending` reorder requests for every non-archived medicine /
 * supply item at or below its threshold with no open request. Safe to
 * run repeatedly (one open request per item invariant).
 */
final class ReorderAutoCheck extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:reorder-auto-check';
    protected $description = 'Create reorder requests for stock at/below its threshold.';
    protected $usage       = 'synapse:reorder-auto-check';

    public function run(array $params): int
    {
        $before = $this->openRequestCount();

        (new ReorderAutoCheckService())->maybeRun(true);

        $after = $this->openRequestCount();
        CLI::write(sprintf('Auto-check complete. Open reorders: %d → %d.', $before, $after), 'green');
        return 0;
    }

    private function openRequestCount(): int
    {
        return (int) db_connect()->table('clinic_reorder_requests')
            ->whereIn('status', ['pending', 'approved', 'ordered', 'received'])
            ->countAllResults();
    }
}
