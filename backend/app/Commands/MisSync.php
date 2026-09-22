<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\FuMis\FuMisDirectorySyncService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Host-authorized, campus-side directory sync. Never an HTTP/request-thread job. */
final class MisSync extends BaseCommand
{
    protected $group = 'SYNAPSE';
    protected $name = 'synapse:mis-sync';
    protected $description = 'Pull MIS students/employees into local directories without login. Dry-run by default.';
    protected $usage = 'synapse:mis-sync [--kind all|student|employee] [--apply] [--confirm] [--page-size 100] [--max-pages 1000]';
    protected $options = [
        '--kind' => 'all (default), student or employee.',
        '--apply' => 'Apply profile/base-role changes. Without this flag, only preview counts.',
        '--confirm' => 'Required for --apply in production.',
        '--page-size' => '1-100 (default 100). Upstream may cap this; max_page determines completion.',
        '--max-pages' => 'Safety limit per namespace, 1-10000 (default 1000).',
    ];

    public function run(array $params): int
    {
        $option = static fn (string $name, mixed $default = null): mixed => $params[$name] ?? CLI::getOption($name) ?? $default;
        $apply = array_key_exists('apply', $params)
            ? true
            : (CLI::getOption('apply') !== null);
        if (ENVIRONMENT === 'production' && $apply && $option('confirm', false) === false) {
            CLI::error('Refusing to apply in production without --confirm. Run a dry-run first.');
            return 1;
        }
        $kind = $option('kind', 'all');
        $pageSize = filter_var($option('page-size', 100), FILTER_VALIDATE_INT);
        $maxPages = filter_var($option('max-pages', 1000), FILTER_VALIDATE_INT);
        if (! is_string($kind) || ! in_array($kind, ['all', 'student', 'employee'], true)
            || $pageSize === false || $pageSize < 1 || $pageSize > 100
            || $maxPages === false || $maxPages < 1 || $maxPages > 10000) {
            CLI::error('Invalid options. Use kind all/student/employee, page-size 1-100, max-pages 1-10000.');
            return 1;
        }
        try {
            $summary = (new FuMisDirectorySyncService())->run($kind, ! $apply, $pageSize, $maxPages);
            CLI::write(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            if (! $summary['success']) {
                CLI::error('MIS sync did not complete. No deletions or role removals were performed.');
                return 1;
            }
            CLI::write($apply ? 'MIS directory sync complete.' : 'Dry-run complete: no local users or memberships were changed.');
            return 0;
        } catch (\Throwable) {
            CLI::error('MIS sync could not start. Check local database/configuration; no upstream details are printed.');
            return 1;
        }
    }
}
