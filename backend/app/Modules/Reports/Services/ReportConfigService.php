<?php

declare(strict_types=1);

namespace Modules\Reports\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Saved configurations and queued generated-report lifecycle.
 *
 * HTTP requests only enqueue generation. The CLI worker streams result rows
 * to disk outside a transaction and applies a 30-day retention window.
 */
final class ReportConfigService extends BaseService
{
    public const MODULES = ReportService::MODULES;
    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE = 50;
    public const RETENTION_DAYS = 30;
    public const GENERATED_STATUSES = ['queued', 'processing', 'completed', 'failed', 'expired'];

    public function __construct(
        private readonly ReportService $reports,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    /** @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>} */
    public function listConfigs(
        bool $includeArchived = false,
        int $page = 1,
        int $limit = self::DEFAULT_PAGE_SIZE,
        ?string $module = null,
    ): array
    {
        [$page, $limit] = $this->normalizePage($page, $limit);
        if ($module !== null) {
            $this->assertModule($module);
        }
        $countBuilder = $this->db->table('report_configurations');
        $builder = $this->db->table('report_configurations')
            ->select('id, name, module, report_type, parameters, is_active, created_at, updated_at')
            ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC');
        if (! $includeArchived) {
            $countBuilder->where('is_active', 1);
            $builder->where('is_active', 1);
        }
        if ($module !== null) {
            $countBuilder->where('module', $module);
            $builder->where('module', $module);
        }
        $total = (int) $countBuilder->countAllResults();
        $rows = $builder->limit($limit, ($page - 1) * $limit)->get()->getResultArray();

        return $this->pageResult(
            array_map(fn (array $row): array => $this->configRow($row), $rows),
            $page,
            $limit,
            $total,
        );
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createConfig(array $input): array
    {
        $userId = \App\Auth\CurrentUser::assert();
        $params = $this->normalizeParameters($input['parameters'] ?? null);

        return $this->txn(function () use ($input, $params, $userId): array {
            $now = $this->utcNow();
            $this->db->table('report_configurations')->insert([
                'name' => trim((string) $input['name']),
                'module' => (string) $input['module'],
                'report_type' => 'export',
                'parameters' => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'schedule_cron' => null,
                'is_active' => 1,
                'created_by_user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->insertID();
            $this->audit->enqueue('reports.config_created', 'report_configurations', $id, $userId, [
                'resource_code' => (string) $input['module'],
            ]);
            return $this->getConfig($id);
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function updateConfig(int $id, array $input): array
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $input, $userId): array {
            $config = $this->selectForUpdate('report_configurations', ['id' => $id, 'is_active' => 1]);
            if ($config === null) {
                throw $this->notFound('Report configuration', $id);
            }

            $update = ['updated_at' => $this->utcNow()];
            if (array_key_exists('name', $input)) {
                $update['name'] = trim((string) $input['name']);
            }
            if (array_key_exists('module', $input)) {
                $update['module'] = (string) $input['module'];
            }
            if (array_key_exists('parameters', $input)) {
                $update['parameters'] = json_encode(
                    $this->normalizeParameters($input['parameters']),
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            }

            $this->db->table('report_configurations')->where('id', $id)->update($update);
            $this->audit->enqueue('reports.config_updated', 'report_configurations', $id, $userId, []);
            return $this->getConfig($id);
        });
    }

    public function archiveConfig(int $id): void
    {
        $userId = \App\Auth\CurrentUser::assert();
        $this->txn(function () use ($id, $userId): void {
            $config = $this->selectForUpdate('report_configurations', ['id' => $id, 'is_active' => 1]);
            if ($config === null) {
                throw $this->notFound('Report configuration', $id);
            }
            $this->db->table('report_configurations')->where('id', $id)->update([
                'is_active' => 0,
                'updated_at' => $this->utcNow(),
            ]);
            $this->audit->enqueue('reports.config_archived', 'report_configurations', $id, $userId, []);
        });
    }

    /** @return array<string, mixed> */
    public function unarchiveConfig(int $id): array
    {
        $userId = \App\Auth\CurrentUser::assert();
        return $this->txn(function () use ($id, $userId): array {
            $config = $this->selectForUpdate('report_configurations', ['id' => $id]);
            if ($config === null) {
                throw $this->notFound('Report configuration', $id);
            }
            if ((int) $config['is_active'] !== 1) {
                $this->db->table('report_configurations')->where('id', $id)->update([
                    'is_active' => 1,
                    'updated_at' => $this->utcNow(),
                ]);
                $this->audit->enqueue('reports.config_restored', 'report_configurations', $id, $userId, []);
            }
            return $this->getConfig($id);
        });
    }

    /** Queue generation and return immediately. @return array<string, mixed> */
    public function run(int $configId): array
    {
        $userId = \App\Auth\CurrentUser::assert();
        $config = $this->db->table('report_configurations')
            ->where(['id' => $configId, 'is_active' => 1])->get()->getRowArray();
        if ($config === null) {
            throw $this->notFound('Report configuration', $configId);
        }

        $module = (string) $config['module'];
        $this->assertModule($module);
        $params = $this->normalizeParameters($this->decodeJson($config['parameters'] ?? null));
        $range = $this->reports->range((string) $params['start'], (string) $params['end']);
        $filename = 'report-' . $module . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.csv';

        return $this->txn(function () use ($configId, $userId, $module, $params, $range, $filename): array {
            $now = $this->utcNow();
            $this->db->table('generated_reports')->insert([
                'config_id' => $configId,
                'module' => $module,
                'file_path' => $filename,
                'format' => 'csv',
                'status' => 'queued',
                'row_count' => null,
                'parameters_used' => json_encode(['range' => $range] + $params, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'ai_summary' => null,
                'error_message' => null,
                'generated_by_user_id' => $userId,
                'generated_at' => $now,
                'started_at' => null,
                'completed_at' => null,
                'expires_at' => null,
                'created_at' => $now,
            ]);
            $id = (int) $this->db->insertID();
            $this->audit->enqueue('reports.generation_queued', 'generated_reports', $id, $userId, [
                'resource_code' => $module . ':' . $range['start'] . '..' . $range['end'],
            ]);
            return $this->getGenerated($id);
        });
    }

    /** Process queued jobs. Intended for the reports drain CLI command. */
    public function processQueued(int $limit = 10): int
    {
        $processed = 0;
        for ($i = 0; $i < max(1, $limit); $i++) {
            $job = $this->claimNext();
            if ($job === null) {
                break;
            }
            $this->processJob($job);
            $processed++;
        }
        return $processed;
    }

    public function cleanupExpired(): int
    {
        $now = $this->utcNow();
        $rows = $this->db->table('generated_reports')
            ->select('id, file_path')->where('status', 'completed')
            ->where('expires_at IS NOT NULL', null, false)->where('expires_at <=', $now)
            ->get()->getResultArray();
        foreach ($rows as $row) {
            $this->deleteReportFile((string) $row['file_path']);
            $this->db->table('generated_reports')->where('id', $row['id'])->update(['status' => 'expired']);
        }
        return count($rows);
    }

    /** @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>} */
    public function listGenerated(
        int $page = 1,
        int $limit = self::DEFAULT_PAGE_SIZE,
        ?string $module = null,
        ?string $status = null,
    ): array
    {
        [$page, $limit] = $this->normalizePage($page, $limit);
        if ($module !== null) {
            $this->assertModule($module);
        }
        if ($status !== null && ! in_array($status, self::GENERATED_STATUSES, true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Unknown generated-report status.', 'field' => 'status'],
            ]);
        }
        $countBuilder = $this->db->table('generated_reports');
        $builder = $this->db->table('generated_reports')
            ->select('id, config_id, module, file_path, format, status, row_count, parameters_used, ai_summary, error_message, generated_at, started_at, completed_at, expires_at')
            ->orderBy('generated_at', 'DESC')->orderBy('id', 'DESC');
        foreach (['module' => $module, 'status' => $status] as $column => $value) {
            if ($value !== null) {
                $countBuilder->where($column, $value);
                $builder->where($column, $value);
            }
        }
        $total = (int) $countBuilder->countAllResults();
        $rows = $builder->limit($limit, ($page - 1) * $limit)->get()->getResultArray();
        return $this->pageResult(
            array_map(fn (array $row): array => $this->generatedRow($row), $rows),
            $page,
            $limit,
            $total,
        );
    }

    /** @return array{path: string, name: string} */
    public function fileForDownload(int $id): array
    {
        $row = $this->db->table('generated_reports')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            throw $this->notFound('Generated report', $id);
        }
        if ((string) ($row['status'] ?? '') !== 'completed') {
            throw ApiException::conflict('reports.file_unavailable', 'This report is not ready for download.');
        }
        if ($row['expires_at'] !== null && (string) $row['expires_at'] <= $this->utcNow()) {
            $this->deleteReportFile((string) $row['file_path']);
            $this->db->table('generated_reports')->where('id', $id)->update(['status' => 'expired']);
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => 'The report file has expired.'],
            ]);
        }
        $filename = basename((string) $row['file_path']);
        $full = $this->reportsDirectory() . DIRECTORY_SEPARATOR . $filename;
        if ($filename === '' || ! is_file($full)) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => 'The report file is no longer available.'],
            ]);
        }
        return ['path' => $full, 'name' => $filename];
    }

    /** @return array<string, mixed>|null */
    private function claimNext(): ?array
    {
        return $this->txn(function (): ?array {
            $row = $this->db->query(
                "SELECT * FROM generated_reports WHERE status = 'queued' ORDER BY id ASC LIMIT 1 FOR UPDATE",
            )->getRowArray();
            if ($row === null) {
                return null;
            }
            $this->db->table('generated_reports')->where('id', $row['id'])->update([
                'status' => 'processing',
                'started_at' => $this->utcNow(),
                'error_message' => null,
            ]);
            $row['status'] = 'processing';
            return $row;
        });
    }

    /** @param array<string, mixed> $job */
    private function processJob(array $job): void
    {
        $id = (int) $job['id'];
        $filename = basename((string) $job['file_path']);
        $path = $this->reportsDirectory() . DIRECTORY_SEPARATOR . $filename;
        $partPath = $path . '.part';
        try {
            $this->ensureReportsDirectory();
            $params = $this->decodeJson($job['parameters_used'] ?? null);
            $range = is_array($params) && is_array($params['range'] ?? null)
                ? $this->reports->range($params['range']['start'] ?? null, $params['range']['end'] ?? null)
                : $this->reports->range(null, null);
            $module = (string) $job['module'];
            $this->assertModule($module);
            [$headers, $rows] = $this->reports->exportStream($module, $range);

            $handle = fopen($partPath, 'wb');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open the report file.');
            }
            $rowCount = 0;
            try {
                foreach ($rows as $row) {
                    fputcsv($handle, $row, ',', '"', '');
                    $rowCount++;
                }
            } finally {
                fclose($handle);
            }

            $completed = $this->utcNow();
            GeneratedReportCsvAssembler::assemble(
                $path,
                $partPath,
                $headers,
                [
                    'Module' => $module,
                    'Range start' => $range['start'],
                    'Range end' => $range['end'],
                    'Calendar timezone' => ReportRange::APP_TIMEZONE,
                    'Requested at (UTC)' => (string) $job['generated_at'],
                    'Completed at (UTC)' => $completed,
                    'Aggregate row count' => $rowCount,
                    'Retention' => self::RETENTION_DAYS . ' days',
                ],
            );

            $summary = null;
            if (is_array($params) && ! empty($params['summarize'])) {
                $report = match ($module) {
                    'clinic' => $this->reports->clinic($range),
                    'counselling' => $this->reports->counselling($range),
                    'inventory' => $this->reports->inventory($range),
                    'referrals' => $this->reports->referrals($range),
                    'facilities' => $this->reports->facilities($range),
                };
                $summary = $this->reports->summarize(
                    $module, $range, $report, (int) $job['generated_by_user_id'],
                )['narrative'];
            }

            $expires = (new DateTimeImmutable($completed, new DateTimeZone('UTC')))
                ->modify('+' . self::RETENTION_DAYS . ' days')->format('Y-m-d H:i:s');
            $this->db->table('generated_reports')->where('id', $id)->update([
                'status' => 'completed',
                'row_count' => $rowCount,
                'ai_summary' => $summary,
                'completed_at' => $completed,
                'expires_at' => $expires,
                'error_message' => null,
            ]);
            $this->audit->enqueue('reports.generated', 'generated_reports', $id, (int) $job['generated_by_user_id'], [
                'resource_code' => $module . ':' . $range['start'] . '..' . $range['end'],
            ]);
        } catch (\Throwable $throwable) {
            $this->deleteReportFile($filename);
            $this->deleteReportFile($filename . '.part');
            log_message('error', 'Generated report {id} failed: {message}', [
                'id' => $id,
                'message' => $throwable->getMessage(),
            ]);
            $this->db->table('generated_reports')->where('id', $id)->update([
                'status' => 'failed',
                'error_message' => 'Report generation failed. Retry the configuration or contact an administrator.',
                'completed_at' => $this->utcNow(),
            ]);
        }
    }

    /** @param mixed $raw @return array<string, mixed> */
    private function normalizeParameters(mixed $raw): array
    {
        $params = is_array($raw) ? $raw : [];
        $range = $this->reports->range(
            is_string($params['start'] ?? null) ? $params['start'] : null,
            is_string($params['end'] ?? null) ? $params['end'] : null,
        );
        return [
            'range_mode' => 'fixed',
            'start' => $range['start'],
            'end' => $range['end'],
            'summarize' => (bool) ($params['summarize'] ?? false),
        ];
    }

    private function assertModule(string $module): void
    {
        if (! in_array($module, self::MODULES, true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Unknown report module.', 'field' => 'module'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function getConfig(int $id): array
    {
        $row = $this->db->table('report_configurations')
            ->select('id, name, module, report_type, parameters, is_active, created_at, updated_at')
            ->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            throw $this->notFound('Report configuration', $id);
        }
        return $this->configRow($row);
    }

    /** @return array<string, mixed> */
    private function getGenerated(int $id): array
    {
        $row = $this->db->table('generated_reports')
            ->select('id, config_id, module, file_path, format, status, row_count, parameters_used, ai_summary, error_message, generated_at, started_at, completed_at, expires_at')
            ->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            throw $this->notFound('Generated report', $id);
        }
        return $this->generatedRow($row);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function configRow(array $row): array
    {
        $parameters = $this->normalizeParameters($this->decodeJson($row['parameters'] ?? null));
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'module' => (string) $row['module'],
            'report_type' => (string) $row['report_type'],
            'parameters' => $parameters,
            'is_active' => (bool) $row['is_active'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function generatedRow(array $row): array
    {
        $rawParameters = $this->decodeJson($row['parameters_used'] ?? null);
        $rawParameters = is_array($rawParameters) ? $rawParameters : [];
        $rawRange = is_array($rawParameters['range'] ?? null) ? $rawParameters['range'] : [];
        $normalized = $this->normalizeParameters([
            'start' => $rawParameters['start'] ?? $rawRange['start'] ?? null,
            'end' => $rawParameters['end'] ?? $rawRange['end'] ?? null,
            'summarize' => $rawParameters['summarize'] ?? false,
        ]);
        $parametersUsed = ['range' => ['start' => $normalized['start'], 'end' => $normalized['end']]] + $normalized;
        return [
            'id' => (int) $row['id'],
            'config_id' => $row['config_id'] !== null ? (int) $row['config_id'] : null,
            'module' => (string) $row['module'],
            'format' => (string) $row['format'],
            'status' => (string) $row['status'],
            'row_count' => $row['row_count'] !== null ? (int) $row['row_count'] : null,
            'parameters_used' => $parametersUsed,
            'ai_summary' => $row['ai_summary'] !== null ? (string) $row['ai_summary'] : null,
            'error_message' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
            'generated_at' => (string) $row['generated_at'],
            'started_at' => $row['started_at'] !== null ? (string) $row['started_at'] : null,
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            'expires_at' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
        ];
    }

    private function decodeJson(mixed $raw): mixed
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return array{0: int, 1: int} */
    private function normalizePage(int $page, int $limit): array
    {
        return [max(1, $page), max(1, min(self::MAX_PAGE_SIZE, $limit))];
    }

    /** @param array<int, array<string, mixed>> $items @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>} */
    private function pageResult(array $items, int $page, int $limit, int $total): array
    {
        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $limit)),
            ],
        ];
    }

    private function ensureReportsDirectory(): void
    {
        $dir = $this->reportsDirectory();
        if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create the reports directory.');
        }
    }

    private function reportsDirectory(): string
    {
        return rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'reports';
    }

    private function deleteReportFile(string $filename): void
    {
        $basename = basename($filename);
        if ($basename === '') {
            return;
        }
        $path = $this->reportsDirectory() . DIRECTORY_SEPARATOR . $basename;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function notFound(string $entity, int $id): ApiException
    {
        return new ApiException('resource.not_found', 404, [
            ['code' => 'resource.not_found', 'message' => $entity . ' #' . $id . ' not found.'],
        ]);
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
