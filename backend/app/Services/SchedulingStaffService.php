<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use Config\SchedulingStaff;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\StaffScheduleService;
use Modules\Counselling\Policies\CounsellingPolicy;
use Modules\Counselling\Services\ScheduleService;

/** Scheduling enrollment never provisions login access or consults MIS. */
final class SchedulingStaffService extends BaseService
{
    private function config(): SchedulingStaff
    {
        return config(SchedulingStaff::class);
    }

    private function unit(string $unit): void
    {
        if (! in_array($unit, ['clinic', 'guidance'], true)) {
            throw new ApiException('resource.not_found', 404);
        }
    }

    public function manage(string $unit): void
    {
        $this->unit($unit);
        if ($unit === 'clinic') {
            (new ClinicPolicy())->check('schedulesManage');
        } else {
            (new CounsellingPolicy())->check('scheduleTeamManage');
        }
    }

    /** Lock the directory row before the assignment, including for first enrollment. */
    private function identity(string $unit, int $userId, bool $lock): array
    {
        $where = ['tenant_id' => CurrentTenant::id(), 'id' => $userId];
        $user = $lock ? $this->selectForUpdate('users', $where)
            : $this->db->table('users')->where('users.tenant_id', CurrentTenant::id())->where('id', $userId)->get()->getRowArray();
        $where = ['tenant_id' => CurrentTenant::id(), 'unit' => $unit, 'user_id' => $userId];
        $assignment = $lock ? $this->selectForUpdate('scheduling_staff_assignments', $where)
            : $this->db->table('scheduling_staff_assignments')->where('tenant_id', CurrentTenant::id())->where('unit', $unit)->where('user_id', $userId)->get()->getRowArray();
        return [$user, $assignment];
    }

    /**
     * Lock directory identities before an existing schedule/appointment row.
     * The initial read is only a routing hint; ownership is rechecked under lock.
     * Call inside the caller's write transaction. Ineligible former owners may
     * still have their history cancelled, archived or reassigned.
     */
    public function lockResource(string $unit, string $table, int $id, string $ownerColumn, array $additionalIds = []): ?array
    {
        $this->unit($unit);
        $where = ['tenant_id' => CurrentTenant::id(), 'id' => $id];
        $hint = $this->db->table($table)->where($where)->get()->getRowArray();
        if ($hint === null) return null;
        $ids = array_values(array_unique(array_filter([
            ...$additionalIds, $hint[$ownerColumn] !== null ? (int) $hint[$ownerColumn] : null,
        ], static fn ($id): bool => $id !== null)));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $userId) $this->identity($unit, $userId, true);
        $row = $this->selectForUpdate($table, $where);
        if ($row !== null && $row[$ownerColumn] !== $hint[$ownerColumn]) {
            throw ApiException::conflict('scheduling.owner_changed', 'Scheduling ownership changed. Reload and try again.');
        }
        return $row;
    }

    /** Call inside the caller's write transaction. */
    public function assertAssignable(string $unit, int $userId): void
    {
        $this->unit($unit);
        [$user, $assignment] = $this->identity($unit, $userId, true);
        $reason = $this->config()->eligibility($unit, $user);
        if ($reason !== null || $assignment === null || ! (bool) $assignment['is_active']) {
            throw ApiException::validationFailure([[
                'code' => 'scheduling.staff.ineligible', 'field' => 'user_id',
                'message' => 'Staff #' . $userId . ' requires an active eligible enrollment (' . ($reason ?? ($assignment === null ? 'not_enrolled' : 'inactive_enrollment')) . ').',
            ]]);
        }
    }

    /** Internal capacity lookup; lock=true must run inside a transaction. @return list<int> */
    public function eligibleIds(string $unit, bool $lock = false): array
    {
        $this->unit($unit);
        $rows = $this->db->table('scheduling_staff_assignments')
            ->where('tenant_id', CurrentTenant::id())->where('unit', $unit)
            ->orderBy('user_id', 'ASC')->get()->getResultArray();
        $ids = [];
        foreach ($rows as $row) {
            [$user, $assignment] = $this->identity($unit, (int) $row['user_id'], $lock);
            if ($assignment !== null && (bool) $assignment['is_active'] && $this->config()->eligibility($unit, $user) === null) {
                $ids[] = (int) $row['user_id'];
            }
        }
        return $ids;
    }

    /** Only team managers see other Guidance providers; enrollment grants no record permission. */
    public function providers(string $unit): array
    {
        $this->unit($unit);
        $self = null;
        if ($unit === 'clinic') {
            $this->manage($unit);
        } else {
            (new CounsellingPolicy())->check('scheduleRead');
            if (! Services::permissionService()->userHas(CurrentUser::assert(), 'counselling.schedule.team_manage')) {
                $self = CurrentUser::assert();
            }
        }
        $ids = $this->eligibleIds($unit);
        if ($self !== null) $ids = array_values(array_intersect($ids, [$self]));
        if ($ids === []) return [];
        $rows = $this->db->table('users')->where('tenant_id', CurrentTenant::id())->whereIn('id', $ids)
            ->orderBy('last_name', 'ASC')->orderBy('id', 'ASC')->get()->getResultArray();
        return array_map(fn (array $u): array => [
            'id' => (int) $u['id'], 'name' => $this->name($u),
            'position' => $u['position'], 'employee_number' => $u['employee_number'],
        ], $rows);
    }

    public function discover(string $unit, string $q = '', ?string $cursor = null, int $limit = 25): array
    {
        $this->manage($unit);
        $after = 0;
        if ($cursor !== null && $cursor !== '') {
            $decoded = json_decode(base64_decode($cursor, true) ?: '', true);
            if (! is_array($decoded) || ($decoded[0] ?? null) !== CurrentTenant::id()
                || ($decoded[1] ?? null) !== $unit || ! is_int($decoded[2] ?? null) || $decoded[2] < 1) {
                throw ApiException::validationFailure([['code' => 'validation.cursor', 'field' => 'cursor', 'message' => 'Invalid scheduling cursor.']]);
            }
            $after = $decoded[2];
        }
        $keys = $this->config()->departmentKeys($unit);
        $limit = max(1, min(100, $limit));
        $aliases = $keys === [] ? 'NULL' : implode(',', array_fill(0, count($keys), '?'));
        // Both halves are tenant scoped; include orphaned legacy assignments for review.
        $sql = "SELECT d.user_id, u.*, a.id AS assignment_id, a.is_active AS enrollment_active
            FROM (SELECT id AS user_id FROM users WHERE tenant_id = ?
                  UNION SELECT user_id FROM scheduling_staff_assignments WHERE tenant_id = ? AND unit = ?) d
            LEFT JOIN users u ON u.id = d.user_id AND u.tenant_id = ?
            LEFT JOIN scheduling_staff_assignments a ON a.user_id = d.user_id AND a.tenant_id = ? AND a.unit = ?
            WHERE d.user_id > ? AND (a.id IS NOT NULL OR (u.kind = 'employee' AND BINARY LOWER(TRIM(u.department)) IN ({$aliases})))";
        $tenant = CurrentTenant::id();
        $binds = [$tenant, $tenant, $unit, $tenant, $tenant, $unit, $after, ...$keys];
        if (trim($q) !== '') {
            $sql .= " AND (CONCAT_WS(' ',u.first_name,u.last_name,u.employee_number,u.department,u.position) LIKE ? ESCAPE '!')";
            $binds[] = '%' . $this->db->escapeLikeString(trim($q)) . '%';
        }
        $sql .= ' ORDER BY d.user_id ASC LIMIT ' . ($limit + 1);
        $rows = $this->db->query($sql, $binds)->getResultArray();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $items = [];
        foreach ($rows as $u) {
            $id = (int) $u['user_id'];
            $reason = $this->config()->eligibility($unit, $u['id'] === null ? null : $u);
            $enrolled = $u['assignment_id'] !== null;
            $active = $enrolled && (bool) $u['enrollment_active'];
            $configured = $this->configured($unit, $id);
            $items[] = [
                'user_id' => $id, 'employee_number' => $u['employee_number'], 'name' => $this->name($u),
                'department' => $u['department'], 'position' => $u['position'], 'employment_status' => $u['employment_status'],
                'assignment_id' => $enrolled ? (int) $u['assignment_id'] : null,
                'enrollment_status' => $enrolled ? ($active ? 'active' : 'inactive') : null,
                'eligible' => $reason === null, 'can_enroll' => $reason === null && ! $enrolled,
                'status' => $reason ?? (! $enrolled ? 'not_enrolled' : (! $active ? 'inactive_enrollment' : ($configured ? 'configured' : 'ready'))),
                'future_appointments' => $this->futureCount($unit, $id),
            ];
        }
        return ['items' => $items, 'next_cursor' => $more ? base64_encode(json_encode([$tenant, $unit, (int) end($rows)['user_id']])) : null, 'department_keys' => $keys];
    }

    private function name(array $u): string
    {
        return trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['username'] ?? ('User #' . $u['user_id']));
    }

    private function configured(string $unit, int $id): bool
    {
        [$table, $column] = $this->scheduleTable($unit);
        return $this->db->table($table)->where('tenant_id', CurrentTenant::id())->where($column, $id)->countAllResults() > 0;
    }

    private function scheduleTable(string $unit): array
    {
        return $unit === 'clinic' ? ['clinic_staff_schedules', 'user_id'] : ['counselling_availability', 'counsellor_user_id'];
    }

    private function futureCount(string $unit, int $id): int
    {
        if ($unit === 'clinic') {
            // Clinic enrollment is a recurring duty roster, not an appointment-provider assignment.
            return $this->db->table('clinic_staff_schedules')->where('tenant_id', CurrentTenant::id())
                ->where('user_id', $id)->where('is_active', 1)->groupStart()->where('effective_to', null)
                ->orWhere('effective_to >=', \App\Modules\Shared\ManilaDay::today())->groupEnd()->countAllResults();
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila'));
        return $this->db->table('counselling_appointments')->where('tenant_id', CurrentTenant::id())
            ->where('counsellor_user_id', $id)->whereIn('status', ['scheduled', 'confirmed'])
            ->groupStart()->where('appointment_date >', $now->format('Y-m-d'))->orGroupStart()
            ->where('appointment_date', $now->format('Y-m-d'))->where('start_time >=', $now->format('H:i:s'))
            ->groupEnd()->groupEnd()->countAllResults();
    }

    /** Strict sorted IDs give every batch the same lock order. */
    private function ids(mixed $raw, string $field = 'user_ids', bool $days = false): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === [] || count($raw) > 100) {
            throw ApiException::validationFailure([['code' => 'validation.field', 'field' => $field, 'message' => 'Provide a nonempty list of at most 100 integers.']]);
        }
        foreach ($raw as $id) {
            if (! is_int($id) || $id < ($days ? 0 : 1) || ($days && $id > 6)) {
                throw ApiException::validationFailure([['code' => 'validation.field', 'field' => $field, 'message' => 'Invalid integer value.']]);
            }
        }
        $ids = array_values(array_unique($raw));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public function enroll(string $unit, array $input): array
    {
        $this->manage($unit);
        $ids = $this->ids($input['user_ids'] ?? null);
        return $this->txn(fn (): array => $this->enrollIds($unit, $ids));
    }

    private function enrollIds(string $unit, array $ids): array
    {
        $created = $skipped = 0;
        foreach ($ids as $id) {
            [$user, $assignment] = $this->identity($unit, $id, true);
            if ($assignment !== null && ! (bool) $assignment['is_active']) { ++$skipped; continue; }
            $reason = $this->config()->eligibility($unit, $user);
            if ($reason !== null) {
                throw ApiException::validationFailure([['code' => 'scheduling.staff.ineligible', 'field' => 'user_ids', 'message' => "Staff #{$id}: {$reason}."]]);
            }
            if ($assignment !== null) { ++$skipped; continue; }
            $now = gmdate('Y-m-d H:i:s');
            $actor = CurrentUser::assert();
            $this->db->table('scheduling_staff_assignments')->insert([
                'tenant_id' => CurrentTenant::id(), 'unit' => $unit, 'user_id' => $id, 'is_active' => 1,
                'enrolled_by_user_id' => $actor, 'updated_by_user_id' => $actor, 'created_at' => $now, 'updated_at' => $now,
            ]);
            Services::auditOutbox()->enqueue('scheduling.staff_enrolled', 'scheduling_staff_assignments', (int) $this->db->insertID(), $actor, ['resource_code' => $unit . ':staff#' . $id]);
            ++$created;
        }
        return compact('created', 'skipped');
    }

    public function status(string $unit, int $assignmentId, array $input): array
    {
        $this->manage($unit);
        if (! is_bool($input['active'] ?? null)) {
            throw ApiException::validationFailure([['code' => 'validation.field', 'field' => 'active', 'message' => 'active must be boolean.']]);
        }
        return $this->txn(function () use ($unit, $assignmentId, $input): array {
            $row = $this->db->table('scheduling_staff_assignments')->where('tenant_id', CurrentTenant::id())
                ->where('unit', $unit)->where('id', $assignmentId)->get()->getRowArray();
            if ($row === null) throw new ApiException('resource.not_found', 404);
            [$user, $assignment] = $this->identity($unit, (int) $row['user_id'], true);
            if ($input['active'] && ($reason = $this->config()->eligibility($unit, $user)) !== null) {
                throw ApiException::validationFailure([['code' => 'scheduling.staff.ineligible', 'field' => 'active', 'message' => $reason]]);
            }
            $update = ['is_active' => (int) $input['active'], 'updated_at' => gmdate('Y-m-d H:i:s'), 'updated_by_user_id' => CurrentUser::assert()];
            $this->db->table('scheduling_staff_assignments')->where('tenant_id', CurrentTenant::id())->where('unit', $unit)->where('id', $assignmentId)->update($update);
            Services::auditOutbox()->enqueue('scheduling.staff_status_changed', 'scheduling_staff_assignments', $assignmentId, CurrentUser::assert(), [
                'resource_code' => $unit . ':staff#' . $assignment['user_id'],
                'previous_status' => (bool) $assignment['is_active'] ? 'active' : 'inactive',
                'next_status' => $input['active'] ? 'active' : 'inactive',
            ]);
            return [...$assignment, ...$update, 'id' => $assignmentId, 'user_id' => (int) $assignment['user_id'], 'active' => $input['active']];
        });
    }

    public function template(string $unit, array $input): array
    {
        $this->manage($unit);
        $ids = $this->ids($input['user_ids'] ?? null);
        $days = $this->ids($input['days_of_week'] ?? null, 'days_of_week', true);
        foreach (['start_time', 'end_time'] as $field) {
            if (! is_string($input[$field] ?? null) || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $input[$field])) {
                throw ApiException::validationFailure([['code' => 'validation.field', 'field' => $field, 'message' => 'Use a valid HH:mm time.']]);
            }
        }
        $type = $input['schedule_type'] ?? 'regular';
        if ($input['start_time'] >= $input['end_time'] || ! in_array($type, ['regular', 'on_call', 'leave'], true)
            || ($unit === 'guidance' && $type !== 'regular')) {
            throw ApiException::validationFailure([['code' => 'validation.field', 'field' => 'start_time', 'message' => 'Invalid hours or schedule type for this unit.']]);
        }
        return $this->txn(function () use ($unit, $input, $ids, $days, $type): array {
            // Template application never enrolls staff implicitly. Validate every
            // selected member, even if all their days will be skipped.
            foreach ($ids as $id) $this->assertAssignable($unit, $id);
            [$table, $column] = $this->scheduleTable($unit);
            $created = $skipped = 0;
            $service = $unit === 'clinic' ? new StaffScheduleService(new ClinicPolicy(), Services::auditOutbox())
                : new ScheduleService(new CounsellingPolicy(), Services::auditOutbox());
            foreach ($ids as $id) {
                foreach ($days as $day) {
                    // Any existing user/day wins, including archived, split, dated and leave rows.
                    if ($this->db->table($table)->where('tenant_id', CurrentTenant::id())->where($column, $id)->where('day_of_week', $day)->countAllResults() > 0) {
                        ++$skipped; continue;
                    }
                    if ($unit === 'clinic') {
                        $service->create(['user_id' => $id, 'day_of_week' => $day, 'shift_start' => $input['start_time'], 'shift_end' => $input['end_time'], 'schedule_type' => $type]);
                    } else {
                        $service->addSlot(['counsellor_user_id' => $id, 'day_of_week' => $day, 'start_time' => $input['start_time'], 'end_time' => $input['end_time']]);
                    }
                    ++$created;
                }
            }
            return compact('created', 'skipped');
        });
    }
}
