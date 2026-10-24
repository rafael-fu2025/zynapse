<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Audit\AuditChainVerifier;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * AuditChainVerify — recomputes the `audit_events` hash chains.
 *
 *   php spark synapse:audit-verify [--to=N] [--tenant=N]
 *
 * Default verifies everything: the frozen v1 global chain (hash_version = 1)
 * plus every tenant's v2 per-tenant chain (AuditHashV2 canonical JSON).
 * --tenant=N narrows to that tenant's v2 chain. Exit 0 = intact, 1 =
 * divergence found (or bad arguments).
 */
final class AuditChainVerify extends BaseCommand
{
    protected $group       = 'SYNAPSE';
    protected $name        = 'synapse:audit-verify';
    protected $description = 'Verify the audit_events hash chains (v1 global + per-tenant v2); report the first divergence.';
    protected $usage       = 'synapse:audit-verify [--to=N] [--tenant=N]';
    protected $arguments   = [];
    protected $options     = [
        '--to'     => 'Verify up to this event id (inclusive). Default: entire chain.',
        '--tenant' => 'Verify only this tenant\'s v2 chain (integer tenant id).',
    ];

    public function run(array $params): int
    {
        $to = isset($params['to']) ? (int) $params['to'] : null;
        $verifier = new AuditChainVerifier();

        if (isset($params['tenant'])) {
            $tenantId = filter_var($params['tenant'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($tenantId === false) {
                CLI::error('--tenant must be a positive integer tenant id.');
                return 1;
            }
            $result = $verifier->verifyTenant($tenantId, $to);
            CLI::write(sprintf('Checked %d event(s) for tenant %d.', $result['checked'], $tenantId));
        } else {
            $result = $verifier->verify($to);
            CLI::write(sprintf('Checked %d event(s).', $result['checked']));
            foreach ($result['tenants'] ?? [] as $t) {
                CLI::write(sprintf(
                    '  tenant %-4d %s (%d event(s)%s)',
                    $t['tenant_id'],
                    $t['ok'] ? 'OK' : 'DIVERGENT',
                    $t['checked'],
                    $t['verified_up_to'] !== null ? ', up to id ' . $t['verified_up_to'] : '',
                ), $t['ok'] ? 'green' : 'red');
            }
        }

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
