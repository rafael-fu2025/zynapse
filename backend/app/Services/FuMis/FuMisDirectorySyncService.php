<?php

declare(strict_types=1);

namespace App\Services\FuMis;

use App\Exceptions\ApiException;
use App\Exceptions\FuMisUpstreamException;
use App\Modules\Shared\BaseService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\FuMis;
use Config\Services;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Campus-side pull of existing MIS identities. No passwords, login, deletion,
 * privilege import or inference of deactivation from absent directory entries.
 * Page transactions bound lock time; a failed run reports committed progress.
 */
final class FuMisDirectorySyncService extends BaseService
{
    private readonly FuMisAuthService $mis;
    private readonly FuMisIdentityService $identities;
    private readonly FuMis $config;

    public function __construct(?FuMisAuthService $mis = null, ?BaseConnection $db = null, ?FuMis $config = null)
    {
        parent::__construct($db);
        $this->mis = $mis ?? Services::fuMisAuthService();
        $this->identities = new FuMisIdentityService($this->db);
        $this->config = $config ?? new FuMis();
    }

    public function run(string $kind = 'all', bool $dryRun = true, int $pageSize = 100, int $maxPages = 1000): array
    {
        if (! in_array($kind, ['all', 'student', 'employee'], true) || $pageSize < 1 || $pageSize > 100
            || $maxPages < 1 || $maxPages > 10000) {
            throw new InvalidArgumentException('Use kind all/student/employee, page size 1-100 and max pages 1-10000.');
        }
        $result = ['success' => false, 'dry_run' => $dryRun, 'tenant_id' => CurrentTenant::id(), 'kinds' => []];
        if (! $this->config->enabled) {
            return $result + ['error' => ['code' => 'auth.mis_unavailable', 'message' => 'MIS integration is disabled.']];
        }
        $mapper = new FuMisProfileMapper();
        try {
            foreach ($kind === 'all' ? ['student', 'employee'] : [$kind] as $namespace) {
                $result['kinds'][$namespace] = [
                    'pages' => 0, 'fetched' => 0, 'created' => 0, 'updated' => 0,
                    'unchanged' => 0, 'skipped' => 0, 'duplicates' => 0,
                ];
                $seen = [];
                $fingerprints = [];
                $lastPage = null;
                for ($page = 1; $page <= ($lastPage ?? 1); $page++) {
                    $query = ['page' => $page, 'limit' => $pageSize];
                    $raw = $namespace === 'student' ? $this->mis->listStudents($query) : $this->mis->listEmployees($query);
                    $parsed = FuMisDirectoryPage::parse($raw, $page, $maxPages);
                    if ($lastPage !== null && $lastPage !== $parsed['max_page']) {
                        throw new UnexpectedValueException('MIS page count changed during the run; rerun for a stable directory snapshot.');
                    }
                    $lastPage = $parsed['max_page'];
                    $profiles = [];
                    $pageIds = [];
                    foreach ($parsed['records'] as $record) {
                        $profile = $namespace === 'student' ? $mapper->mapStudent($record) : $mapper->mapEmployee($record);
                        $profile['identifier'] = FuMisIdentityService::identifier((string) ($profile['identifier'] ?? ''));
                        $pageIds[] = strtolower($profile['identifier']);
                        $profiles[] = $profile;
                    }
                    // Detect servers which silently ignore `page`, even if they
                    // echo the requested page number in their envelope.
                    $fingerprint = hash('sha256', json_encode($pageIds, JSON_THROW_ON_ERROR));
                    if ($profiles !== [] && isset($fingerprints[$fingerprint])) {
                        throw new UnexpectedValueException('MIS repeated a directory page; sync stopped instead of reporting false completion.');
                    }
                    $fingerprints[$fingerprint] = true;
                    $pageSeen = $seen;
                    $counts = $result['kinds'][$namespace];
                    $writePage = function () use ($namespace, $profiles, $dryRun, &$counts, &$pageSeen): void {
                        foreach ($profiles as $profile) {
                            $key = strtolower($profile['identifier']);
                            $counts['fetched']++;
                            if (isset($pageSeen[$key])) {
                                $counts['duplicates']++;
                                continue;
                            }
                            $outcome = $this->identities->upsert($namespace, $profile, $dryRun);
                            $counts[$outcome['outcome']]++;
                            $pageSeen[$key] = true;
                        }
                        if (! $dryRun) {
                            Services::auditOutbox()->enqueue('mis.directory_page_synced', 'users', null, null, [
                                'reason_code' => 'synapse:mis-sync',
                                'resource_code' => $namespace,
                                'outcome' => 'synced',
                            ]);
                        }
                    };
                    if ($dryRun) {
                        $writePage();
                    } else {
                        $this->txn($writePage);
                    }
                    $counts['pages']++;
                    $result['kinds'][$namespace] = $counts;
                    $seen = $pageSeen;
                }
            }
            $result['success'] = true;
            return $result;
        } catch (\Throwable $e) {
            // Never echo upstream bodies, names, tokens, SQL or exception text.
            $code = match (true) {
                $e instanceof FuMisException, $e instanceof FuMisUpstreamException => 'auth.mis_unavailable',
                $e instanceof ApiException => $e->errorCode,
                $e instanceof UnexpectedValueException => 'mis.directory_contract',
                default => 'mis.sync_failed',
            };
            return $result + ['error' => [
                'code' => $code,
                'message' => 'Sync incomplete. Prior committed pages remain; the failed page was not applied. Check network, pagination, profile fields and identity/index conflicts, then rerun.',
            ]];
        }
    }
}
