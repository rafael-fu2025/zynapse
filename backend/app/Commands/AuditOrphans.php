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

        $args = ['--help' => false];
        if (isset($params['out']) && $params['out'] !== '') {
            $args['--out'] = (string) $params['out'];
        }
        if (isset($params['silent'])) {
            $args['--silent'] = true;
        }
        $cmd = 'php ' . escapeshellarg($script);
        foreach ($args as $k => $v) {
            if ($v === false) continue;
            $cmd .= ' ' . escapeshellarg($k . ($v === true ? '' : '=' . $v));
        }

        CLI::write('Running: ' . $cmd, 'yellow');
        passthru($cmd, $rc);
        return $rc === 0 ? 0 : 1;
    }
}
