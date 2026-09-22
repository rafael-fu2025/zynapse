<?php
/**
 * AuditOrphans — Phase 0 CLI wrapper for the patient-school-id orphan audit.
 *
 *   php spark synapse:audit-orphans [--out=path/to.csv] [--silent]
 *
 * Emits a CSV with one row per clinical-row reference, classifies it as
 * linked-active, linked-archived, or orphan, and prints a human summary.
 */
declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class AuditOrphans extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:audit-orphans';
    protected $description = 'Audit every patient_school_id reference in clinical tables and classify it as linked or orphan.';
    protected $usage       = 'synapse:audit-orphans [--out=path/to.csv] [--silent]';
    protected $arguments   = [];
    protected $options     = [
        '--out'    => 'Output CSV path (default: writable/reports/orphan-audit-YYYY-MM-DD.csv)',
        '--silent' => 'Do not print the human summary to stdout',
    ];

    public function run(array $params): int
    {
        $script = APPPATH . '..' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'audit-orphans.php';
        if (! is_file($script)) {
            CLI::error("Audit script not found: {$script}");
            return 1;
        }

        // Build argv array for the script
        $argv = [$script];
        if (isset($params['out']) && $params['out'] !== '') {
            $argv[] = '--out=' . $params['out'];
        }
        if (isset($params['silent'])) {
            $argv[] = '--silent';
        }

        // Save current argv and replace with our constructed one
        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = $argv;
        $_SERVER['argc'] = count($argv);

        CLI::write('Running audit-orphans script...', 'yellow');
        
        // Capture output
        ob_start();
        try {
            $returnValue = require $script;
            $output = ob_get_clean();
            
            // Write captured output
            if ($output !== false && $output !== '') {
                CLI::write($output);
            }
            
            // Restore original argv
            if ($originalArgv !== null) {
                $_SERVER['argv'] = $originalArgv;
                $_SERVER['argc'] = count($originalArgv);
            }
            
            // Handle return value: if script returns int, use it; otherwise success
            return is_int($returnValue) ? $returnValue : 0;
        } catch (\Throwable $e) {
            ob_end_clean();
            
            // Restore original argv
            if ($originalArgv !== null) {
                $_SERVER['argv'] = $originalArgv;
                $_SERVER['argc'] = count($originalArgv);
            }
            
            CLI::error('Script failed: ' . $e->getMessage());
            return 1;
        }
    }
}
