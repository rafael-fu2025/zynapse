<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Audit\AuditChainVerifier;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * AuditChainVerify — recomputes the `audit_events` hash chain (Phase 6).
 *
 *   php spark synapse:audit-verify [--to=N]
 *
 * Walks id-ascending from genesis, recomputing
 * SHA-256(prev_hash || payload_json) and checking `prev_id` linkage.
 * Exit code 0 = chain intact, 1 = divergence found.
 */
final class AuditChainVerify extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:audit-verify';
    protected $description = 'Verify the audit_events hash chain; report the first divergence.';
    protected $usage       = 'synapse:audit-verify [--to=N]';
    protected $arguments   = [];
    protected $options     = [
        '--to' => 'Verify up to this event id (inclusive). Default: entire chain.',
    ];

    public function run(array $params): int
    {
        $to = isset($params['to']) ? (int) $params['to'] : null;

        $result = (new AuditChainVerifier())->verify($to);

        CLI::write(sprintf('Checked %d event(s).', $result['checked']));

        if ($result['ok']) {
            CLI::write(sprintf(
                'Chain intact%s.',
                $result['verified_up_to'] !== null ? ' up to id ' . $result['verified_up_to'] : ' (no events)',
            ), 'green');
            return 0;
        }

        $d = $result['first_divergence'];
        CLI::write('Chain DIVERGENT:', 'red');
        CLI::write(sprintf('  event id : %d', $d['id']), 'red');
        CLI::write(sprintf('  reason   : %s', $d['reason']), 'red');
        CLI::write(sprintf('  expected : %s', $d['expected']), 'red');
        CLI::write(sprintf('  actual   : %s', $d['actual']), 'red');
        CLI::write(sprintf(
            '  last verified id: %s',
            $result['verified_up_to'] !== null ? (string) $result['verified_up_to'] : '(none)',
        ), 'yellow');

        return 1;
    }
}
