<?php

declare(strict_types=1);

namespace Modules\Reports\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * ReportConfigService — saved report configurations + on-demand
 * generation (Phase P6).
 *
 * A configuration names a module + parameters. `run()` reuses the
 * existing {@see ReportService::exportRows()} (which deliberately omits
 * patient identifiers) to write a CSV file under WRITEPATH/reports, then
 * records a `generated_reports` row with the metadata + an optional
 * deterministic P2c narrative. Downloads are served by basename only so a
 * stored path can never traverse outside the reports directory.
 */
final class ReportConfigService extends BaseService
{
    public const MODULES = ['clinic', 'counselling', 'inventory'];

    public function __construct(
        private readonly ReportService $reports,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listConfigs(): array
    {
        $rows = $this->db->table('report_configurations')
            ->select('id, name, module, report_type, parameters, schedule_cron, is_active, created_at')
            ->where('is_active', 1)
            ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')
            ->get()->getResultArray();

        return array_map(fn (array $r): array => $this->configRow($r), $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createConfig(array $input): array
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($input, $userId): array {
            $params = is_array($input['parameters'] ?? null) ? $input['parameters'] : [];
            $now    = $this->utcNow();

            $this->db->table('report_configurations')->insert([
                'name'               => (string) $input['name'],
                'module'             => (string) $input['module'],
                'report_type'        => (string) ($input['report_type'] ?? 'export'),
                'parameters'         => json_encode($params, JSON_UNESCAPED_SLASHES),
                'schedule_cron'      => isset($input['schedule_cron']) && $input['schedule_cron'] !== '' ? (string) $input['schedule_cron'] : null,
                'is_active'          => 1,
                'created_by_user_id' => $userId,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('reports.config_created', 'report_configurations', $id, $userId, [
                'resource_code' => (string) $input['module'],
            ]);

            return $this->getConfig($id);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateConfig(int $id, array $input): array
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $input, $userId): array {
            $cfg = $this->selectForUpdate('report_configurations', ['id' => $id, 'is_active' => 1]);
            if ($cfg === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Report configuration #{$id} not found."],
                ]);
            }

            $update = ['updated_at' => $this->utcNow()];
            if (isset($input['name'])) {
                $update['name'] = (string) $input['name'];
            }
            if (isset($input['module'])) {
                $update['module'] = (string) $input['module'];
            }
            if (isset($input['report_type'])) {
                $update['report_type'] = (string) $input['report_type'];
            }
            if (array_key_exists('parameters', $input) && is_array($input['parameters'])) {
                $update['parameters'] = json_encode($input['parameters'], JSON_UNESCAPED_SLASHES);
            }
            if (array_key_exists('schedule_cron', $input)) {
                $update['schedule_cron'] = $input['schedule_cron'] !== null && $input['schedule_cron'] !== '' ? (string) $input['schedule_cron'] : null;
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
            $cfg = $this->selectForUpdate('report_configurations', ['id' => $id, 'is_active' => 1]);
            if ($cfg === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Report configuration #{$id} not found."],
                ]);
            }
            $this->db->table('report_configurations')->where('id', $id)->update([
                'is_active'  => 0,
                'updated_at' => $this->utcNow(),
            ]);
            $this->audit->enqueue('reports.config_archived', 'report_configurations', $id, $userId, []);
        });
    }

    /**
     * Generate the CSV for a configuration + archive a generated_reports
     * row. Returns the generated-report metadata.
     *
     * @return array<string, mixed>
     */
    public function run(int $configId): array
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($configId, $userId): array {
            $cfg = $this->selectForUpdate('report_configurations', ['id' => $configId, 'is_active' => 1]);
            if ($cfg === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Report configuration #{$configId} not found."],
                ]);
            }

            $module = (string) $cfg['module'];
            if (! in_array($module, self::MODULES, true)) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => "Unknown report module '{$module}'.", 'field' => 'module'],
                ]);
            }

            $params = [];
            if (is_string($cfg['parameters']) && $cfg['parameters'] !== '') {
                $decoded = json_decode($cfg['parameters'], true);
                $params  = is_array($decoded) ? $decoded : [];
            }
            $range = $this->reports->range($params['start'] ?? null, $params['end'] ?? null);

            [$headers, $rows] = $this->reports->exportRows($module, $range);

            // Write the CSV to disk (reused export rows already exclude PII).
            $dir = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'reports';
            if (! is_dir($dir) && ! @mkdir($dir, 0770, true) && ! is_dir($dir)) {
                throw ApiException::conflict('export.unavailable', 'Unable to create the reports directory.');
            }
            $filename = 'report-' . $module . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.csv';
            $full     = $dir . DIRECTORY_SEPARATOR . $filename;

            $handle = fopen($full, 'w');
            if ($handle === false) {
                throw ApiException::conflict('export.unavailable', 'Unable to open the report file for writing.');
            }
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);

            // Optional deterministic narrative (P2c).
            $aiSummary = null;
            if (! empty($params['summarize'])) {
                $report    = match ($module) {
                    'clinic'      => $this->reports->clinic($range),
                    'counselling' => $this->reports->counselling($range),
                    'inventory'   => $this->reports->inventory($range),
                };
                $aiSummary = $this->reports->summarize($module, $range, $report)['narrative'];
            }

            $now = $this->utcNow();
            $this->db->table('generated_reports')->insert([
                'config_id'            => $configId,
                'module'               => $module,
                'file_path'            => $filename,
                'format'               => 'csv',
                'row_count'            => count($rows),
                'parameters_used'      => json_encode(['range' => $range] + $params, JSON_UNESCAPED_SLASHES),
                'ai_summary'           => $aiSummary,
                'generated_by_user_id' => $userId,
                'generated_at'         => $now,
                'created_at'           => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('reports.generated', 'generated_reports', $id, $userId, [
                'resource_code' => $module . ':' . $range['start'] . '..' . $range['end'],
            ]);

            return $this->getGenerated($id);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listGenerated(): array
    {
        $rows = $this->db->table('generated_reports')
            ->select('id, config_id, module, file_path, format, row_count, parameters_used, ai_summary, generated_at')
            ->orderBy('generated_at', 'DESC')->orderBy('id', 'DESC')
            ->limit(100)
            ->get()->getResultArray();

        return array_map(fn (array $r): array => $this->generatedRow($r), $rows);
    }

    /**
     * Resolve a stored generated report to an on-disk file, guarding
     * against path traversal (basename only).
     *
     * @return array{path: string, name: string}
     */
    public function fileForDownload(int $id): array
    {
        $row = $this->db->table('generated_reports')->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Generated report #{$id} not found."],
            ]);
        }
        $filename = basename((string) $row['file_path']);
        $full     = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . $filename;
        if ($filename === '' || ! is_file($full)) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => 'The report file is no longer available.'],
            ]);
        }
        return ['path' => $full, 'name' => $filename];
    }

    // ------------------------------------------------------------ helpers

    private function getConfig(int $id): array
    {
        $r = $this->db->table('report_configurations')
            ->select('id, name, module, report_type, parameters, schedule_cron, is_active, created_at')
            ->where('id', $id)->get()->getRowArray();
        return $this->configRow($r);
    }

    private function getGenerated(int $id): array
    {
        $r = $this->db->table('generated_reports')
            ->select('id, config_id, module, file_path, format, row_count, parameters_used, ai_summary, generated_at')
            ->where('id', $id)->get()->getRowArray();
        return $this->generatedRow($r);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function configRow(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'name'          => (string) $r['name'],
            'module'        => (string) $r['module'],
            'report_type'   => (string) $r['report_type'],
            'parameters'    => $this->decodeJson($r['parameters'] ?? null),
            'schedule_cron' => $r['schedule_cron'] !== null ? (string) $r['schedule_cron'] : null,
            'is_active'     => (bool) $r['is_active'],
            'created_at'    => (string) $r['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function generatedRow(array $r): array
    {
        return [
            'id'              => (int) $r['id'],
            'config_id'       => $r['config_id'] !== null ? (int) $r['config_id'] : null,
            'module'          => (string) $r['module'],
            'file_path'       => (string) $r['file_path'],
            'format'          => (string) $r['format'],
            'row_count'       => $r['row_count'] !== null ? (int) $r['row_count'] : null,
            'parameters_used' => $this->decodeJson($r['parameters_used'] ?? null),
            'ai_summary'      => $r['ai_summary'] !== null ? (string) $r['ai_summary'] : null,
            'generated_at'    => (string) $r['generated_at'],
        ];
    }

    private function decodeJson(mixed $raw): mixed
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return $decoded === null ? null : $decoded;
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
