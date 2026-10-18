<?php

declare(strict_types=1);

namespace App\Services\Archive;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\CurrentTenant;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Counselling\Policies\CounsellingPolicy;
use Modules\Facilities\Policies\BmgPolicy;
use Modules\Reports\Services\ReportService;

/** Archive is list hygiene, not authorization to erase retained evidence. */
final class PermanentDeletionService extends BaseService
{
    // Identifiers are server-owned: never derive a table/column from the URL.
    private const RESOURCES = [
        'announcements' => ['guidance_announcements', 'archived_at', 'counselling.announcements.manage'],
        'services' => ['guidance_services', 'archived_at', 'counselling.services.manage'],
        'surveys' => ['surveys', 'archived_at', 'counselling.surveys.manage'],
        'equipment' => ['clinic_equipment', 'archived_at', 'clinic.inventory.delete'],
        'medicines' => ['clinic_medicines', 'archived_at', 'clinic.inventory.delete'],
        'supplies' => ['clinic_inventory_items', 'archived_at', 'clinic.inventory.delete'],
        'staff-schedules' => ['clinic_staff_schedules', 'is_active', 'clinic.schedules.manage'],
        'devices' => ['facilities_bmg_devices', 'archived_at', 'facilities.units.manage'],
        'waste-categories' => ['facilities_waste_categories', 'is_active', 'facilities.categories.manage'],
        'drums' => ['facilities_bmg_units', 'archived_at', 'facilities.units.manage'],
        'report-configs' => ['report_configurations', 'is_active', 'reports.configure'],
        'students' => ['users', 'archived_at', 'clinic.patients.write'],
        'employees' => ['users', 'archived_at', 'clinic.patients.write'],
        'encounters' => ['clinic_encounters', 'archived_at', 'clinic.encounters.write'],
        'counselling-sessions' => ['counselling_sessions', 'archived_at', 'counselling.records.soft_delete'],
    ];

    /** All statuses, including archived, cancelled, voided, expired and failed. */
    private const REFERENCES = [
        'equipment' => [
            ['clinic_equipment_units', 'equipment_id', 'equipment units and their status history'],
        ],
        'medicines' => [
            ['clinic_medicine_batches', 'medicine_id', 'medicine batches'],
            ['clinic_medicine_transactions', 'medicine_id', 'medicine transactions'],
            ['clinic_reorder_requests', 'medicine_id', 'procurement requests'],
            ['clinic_treatments', 'medicine_id', 'clinical treatments'],
            ['clinic_medicine_forecasts', 'medicine_id', 'forecast history'],
        ],
        'supplies' => [
            ['clinic_inventory_movements', 'item_id', 'stock movements'],
            ['clinic_reorder_requests', 'supply_item_id', 'procurement requests'],
        ],
        'devices' => [
            ['facilities_bmg_device_assignments', 'device_id', 'device assignment history'],
            ['facilities_bmg_device_commands', 'device_id', 'device command history'],
            ['facilities_bmg_device_runs', 'device_id', 'device run history'],
        ],
        'waste-categories' => [
            ['facilities_bmg_batches', 'category_id', 'batch history'],
            ['facilities_bmg_composition', 'category_id', 'batch composition history'],
            ['facilities_bmg_inputs', 'category_id', 'waste input history'],
            ['facilities_bmg_units', 'default_category_id', 'drum default categories'],
            ['facilities_bmg_unit_categories', 'category_id', 'drum waste-mix assignments'],
        ],
        'drums' => [
            ['facilities_bmg_batches', 'unit_id', 'batch history'],
            ['facilities_bmg_devices', 'unit_id', 'registered devices'],
            ['facilities_bmg_device_assignments', 'unit_id', 'device assignment history'],
            ['facilities_bmg_device_runs', 'unit_id', 'device run history'],
            ['facilities_bmg_unit_schedules', 'unit_id', 'automation schedules'],
            ['facilities_bmg_schedule_history', 'unit_id', 'schedule history'],
            ['facilities_bmg_unit_categories', 'unit_id', 'drum waste-mix assignments'],
        ],
        'report-configs' => [
            ['generated_reports', 'config_id', 'generated or queued report history'],
        ],
        'surveys' => [
            ['survey_versions', 'survey_id', 'published survey versions'],
            ['survey_responses', 'survey_id', 'survey responses'],
            ['guidance_followups', 'source_survey_id', 'guidance follow-up history'],
            ['survey_link_opens', 'survey_id', 'survey link-open history'],
            ['survey_link_screenshots', 'survey_id', 'survey screenshot evidence, including staged uploads'],
        ],
    ];

    /** @return array{resource:string,id:int,deleted:bool} */
    public function delete(string $resource, int $id): array
    {
        $definition = self::RESOURCES[$resource] ?? null;
        if ($definition === null) {
            throw new ApiException('resource.not_found', 404, null, 'This archived resource is not supported.');
        }
        [$table, $archiveColumn, $permission] = $definition;
        $this->authorize($permission);
        $this->checkPolicy($resource);

        return $this->txn(function () use ($resource, $id, $table, $archiveColumn): array {
            $where = ['tenant_id' => CurrentTenant::id(), 'id' => $id];
            if (in_array($resource, ['students', 'employees'], true)) {
                $where['kind'] = $resource === 'students' ? 'student' : 'employee';
            }
            $row = $this->selectForUpdate($table, $where);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, null, 'Archived record not found.');
            }
            $this->checkPolicy($resource, $row);
            if ($resource === 'report-configs') {
                $extra = ReportService::MODULE_EXTRA_PERMISSIONS[(string) $row['module']] ?? null;
                if ($extra !== null) {
                    $this->authorize($extra);
                }
            }
            $archived = $archiveColumn === 'is_active'
                ? (int) $row[$archiveColumn] === 0
                : $row[$archiveColumn] !== null;
            if (! $archived) {
                $this->refuse('archive.required', 'Archive this record before requesting permanent deletion.');
            }
            if (in_array($resource, ['students', 'employees', 'encounters', 'counselling-sessions'], true)) {
                $label = match ($resource) {
                    'students', 'employees' => 'Patient registry records',
                    'encounters' => 'Clinical encounters',
                    default => 'Counselling sessions',
                };
                $this->refuse('archive.retention_protected', $label . ' must be retained. Retention-based erasure is not authorized through generic HTTP deletion; leave this record archived.');
            }
            foreach (self::REFERENCES[$resource] ?? [] as [$childTable, $column, $reason]) {
                $this->guardReference($childTable, $column, $id, $reason);
            }
            if ($resource === 'supplies' && (float) $row['quantity_on_hand'] !== 0.0) {
                $this->refuse('archive.stock_protected', 'Cannot permanently delete a supply with stock on hand. Keep it archived to preserve its stock balance.');
            }
            if ($resource === 'devices') {
                // Telemetry uses the device CODE (VARCHAR), not its numeric FK.
                $this->guardReference('facilities_bmg_process_logs', 'device_id', (string) $row['code'], 'device telemetry history');
                $this->guardReference('facilities_bmg_process_logs', 'device_id', (string) $id, 'device telemetry history');
                if ($row['unit_id'] !== null) {
                    $this->refuse('archive.references_protected', 'This device is still bound to a drum. Its operational binding must be retained.');
                }
            }
            if ($resource === 'surveys') {
                $this->deleteUnusedSurveyDraft($id);
            }
            $this->db->table($table)->where($table . '.tenant_id', CurrentTenant::id())->where('id', $id)->delete();
            // Use a stable catalogue code or resource discriminator, never survey
            // titles, patient names, notes, credentials or the deleted payload.
            Services::auditOutbox()->enqueue('archive.permanently_deleted', $table, $id, CurrentUser::assert(), [
                'resource_code' => (string) ($row['code'] ?? $resource),
                'reason_code' => 'archived_unused',
            ]);
            return ['resource' => $resource, 'id' => $id, 'deleted' => true];
        });
    }

    private function authorize(string $permission): void
    {
        if (! Services::permissionService()->userHas(CurrentUser::assert(), $permission)) {
            throw ApiException::forbidden('rbac.permission_denied:' . $permission);
        }
    }

    private function checkPolicy(string $resource, ?array $row = null): void
    {
        $clinicAction = match ($resource) {
            'equipment' => 'equipmentDelete',
            'medicines', 'supplies' => 'inventoryDelete',
            'staff-schedules' => 'schedulesManage',
            'students', 'employees' => 'patientsWrite',
            'encounters' => 'archive',
            default => null,
        };
        if ($clinicAction !== null) {
            (new ClinicPolicy())->check($clinicAction, $resource === 'encounters' ? $row : null);
        }
        if ($resource === 'counselling-sessions') {
            (new CounsellingPolicy())->check('archive', $row);
        }
        $bmgAction = match ($resource) {
            'devices' => 'devices_manage',
            'drums' => 'manage_units',
            'waste-categories' => 'categories_manage',
            default => null,
        };
        if ($bmgAction !== null) {
            (new BmgPolicy())->check($bmgAction);
        }
    }

    private function guardReference(string $table, string $column, int|string $value, string $reason): void
    {
        // Locking reads see current committed data rather than a stale snapshot.
        // The parent lock also serializes FK writers against the final delete.
        if ($this->selectForUpdate($table, ['tenant_id' => CurrentTenant::id(), $column => $value], 'id') !== null) {
            $this->refuse('archive.references_protected', 'Cannot permanently delete: this record is referenced by ' . $reason . '. Keep it archived to preserve history.');
        }
    }

    private function deleteUnusedSurveyDraft(int $surveyId): void
    {
        foreach (['survey_questions', 'survey_links'] as $table) {
            $builder = $this->db->table($table)->where($table . '.tenant_id', CurrentTenant::id())->where('survey_id', $surveyId)->orderBy('id', 'ASC');
            $rows = $this->db->query($builder->getCompiledSelect() . ' FOR UPDATE')->getResultArray();
            foreach ($rows as $row) {
                if ($row['version_id'] !== null) {
                    $this->refuse('archive.retention_protected', 'Published survey content must be retained, even when no responses remain.');
                }
                $childId = (int) $row['id'];
                if ($table === 'survey_questions') {
                    // question_id has no FK in survey_answers.
                    $this->guardReference('survey_answers', 'question_id', $childId, 'survey answer history');
                    $this->db->table('survey_answer_options')->where('survey_answer_options.tenant_id', CurrentTenant::id())->where('question_id', $childId)->delete();
                } else {
                    $this->guardReference('survey_link_opens', 'link_id', $childId, 'survey link-open history');
                    $this->guardReference('survey_link_screenshots', 'link_id', $childId, 'survey screenshot evidence');
                }
                $this->db->table($table)->where($table . '.tenant_id', CurrentTenant::id())->where('id', $childId)->delete();
            }
        }
    }

    private function refuse(string $code, string $message): never
    {
        throw new ApiException($code, 409, null, $message);
    }
}
