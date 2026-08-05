<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\Policies\ClinicPolicy;

/**
 * StaffScheduleService — recurring staff shift roster (Phase P5b,
 * recycled from legacy synapse_ag staff_schedules).
 *
 * Admin-managed CRUD gated by `clinic.schedules.manage`. Rows are a
 * weekly shift template per (user, weekday) with an optional effective
 * date range; removal is a soft archive (`is_active = 0`). `user_id`
 * references the auth users table only — no cross-module links.
 */
final class StaffScheduleService extends BaseService
{
    private const TYPES = ['regular', 'on_call', 'leave'];

    public function __construct(
        private readonly ClinicPolicy $policy,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(?int $userId, bool $includeArchived = false): array
    {
        $this->policy->check('schedulesManage');

        $builder = $this->db->table('clinic_staff_schedules s')
            ->select("s.id, s.user_id, s.day_of_week, s.shift_start, s.shift_end, s.schedule_type, s.effective_from, s.effective_to, s.is_active, u.first_name, u.last_name")
            // Staff display name for the roster — the schedule UI shows
            // the person's name (id tooltip), not just a bare user id.
            ->join('users u', 'u.id = s.user_id', 'left')
            ->orderBy('s.user_id', 'ASC')
            ->orderBy('s.day_of_week', 'ASC')
            ->orderBy('s.shift_start', 'ASC');
        if (! $includeArchived) {
            $builder->where('s.is_active', 1);
        }
        if ($userId !== null) {
            $builder->where('s.user_id', $userId);
        }

        return array_map(fn (array $r): array => $this->row($r), $builder->get()->getResultArray());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $this->policy->check('schedulesManage');
        $actor = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($input, $actor): array {
            $start   = (string) $input['shift_start'];
            $end     = (string) $input['shift_end'];
            $userId  = (int) $input['user_id'];
            $dow     = (int) $input['day_of_week'];
            $this->assertShiftOrder($start, $end);
            $this->assertUserExists($userId);
            $this->assertNoOverlap($userId, $dow, $start, $end);
            $this->assertEffectiveOrder($this->dateOrNull($input, 'effective_from'), $this->dateOrNull($input, 'effective_to'));

            $now = $this->utcNow();
            $this->db->table('clinic_staff_schedules')->insert([
                'user_id'        => (int) $input['user_id'],
                'day_of_week'    => (int) $input['day_of_week'],
                'shift_start'    => $start,
                'shift_end'      => $end,
                'schedule_type'  => $this->normalizeType($input['schedule_type'] ?? null),
                'effective_from' => $this->dateOrNull($input, 'effective_from'),
                'effective_to'   => $this->dateOrNull($input, 'effective_to'),
                'is_active'      => 1,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('clinic.staff_schedule_created', 'clinic_staff_schedules', $id, $actor, [
                'resource_code' => 'user#' . (string) ((int) $input['user_id']),
            ]);

            return $this->getRow($id);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input): array
    {
        $this->policy->check('schedulesManage');
        $actor = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $input, $actor): array {
            $row = $this->selectForUpdate('clinic_staff_schedules', ['id' => $id, 'is_active' => 1]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Staff schedule #{$id} not found."],
                ]);
            }

            $start   = isset($input['shift_start']) ? (string) $input['shift_start'] : (string) $row['shift_start'];
            $end     = isset($input['shift_end'])   ? (string) $input['shift_end']   : (string) $row['shift_end'];
            $userId  = isset($input['user_id'])     ? (int) $input['user_id']        : (int) $row['user_id'];
            $dow     = isset($input['day_of_week']) ? (int) $input['day_of_week']    : (int) $row['day_of_week'];
            $this->assertShiftOrder($start, $end);
            $this->assertUserExists($userId);
            // Exclude this row when scanning so an update that keeps the
            // same slot (or only touches type/dates) doesn't self-conflict.
            $this->assertNoOverlap($userId, $dow, $start, $end, $id);

            $effFrom = array_key_exists('effective_from', $input)
                ? $this->dateOrNull($input, 'effective_from')
                : ($row['effective_from'] !== null ? (string) $row['effective_from'] : null);
            $effTo   = array_key_exists('effective_to', $input)
                ? $this->dateOrNull($input, 'effective_to')
                : ($row['effective_to'] !== null ? (string) $row['effective_to'] : null);
            $this->assertEffectiveOrder($effFrom, $effTo);

            $this->db->table('clinic_staff_schedules')->where('id', $id)->update([
                'user_id'        => $userId,
                'day_of_week'    => $dow,
                'shift_start'    => $start,
                'shift_end'      => $end,
                'schedule_type'  => isset($input['schedule_type']) ? $this->normalizeType($input['schedule_type']) : (string) $row['schedule_type'],
                'effective_from' => $effFrom,
                'effective_to'   => $effTo,
                'updated_at'     => $this->utcNow(),
            ]);

            $this->audit->enqueue('clinic.staff_schedule_updated', 'clinic_staff_schedules', $id, $actor, []);

            return $this->getRow($id);
        });
    }

    public function archive(int $id): void
    {
        $this->policy->check('schedulesManage');
        $actor = \App\Auth\CurrentUser::assert();

        $this->txn(function () use ($id, $actor): void {
            $row = $this->selectForUpdate('clinic_staff_schedules', ['id' => $id, 'is_active' => 1]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Staff schedule #{$id} not found."],
                ]);
            }
            $this->db->table('clinic_staff_schedules')->where('id', $id)->update([
                'is_active'  => 0,
                'updated_at' => $this->utcNow(),
            ]);
            $this->audit->enqueue('clinic.staff_schedule_archived', 'clinic_staff_schedules', $id, $actor, []);
        });
    }

    /**
     * Restore an archived shift template (`is_active = 1`). Idempotent
     * — restoring an active row is a no-op. Returns the fresh row so
     * the SPA can splice it back into the roster.
     *
     * @return array<string, mixed>
     */
    public function unarchive(int $id): array
    {
        $this->policy->check('schedulesManage');
        $actor = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $actor): array {
            $row = $this->selectForUpdate('clinic_staff_schedules', ['id' => $id]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Staff schedule #{$id} not found."],
                ]);
            }
            if ((int) $row['is_active'] === 1) {
                // Idempotent: already active.
                return $this->getRow($id);
            }
            $this->db->table('clinic_staff_schedules')->where('id', $id)->update([
                'is_active'  => 1,
                'updated_at' => $this->utcNow(),
            ]);
            $this->audit->enqueue('clinic.staff_schedule_restored', 'clinic_staff_schedules', $id, $actor, []);

            return $this->getRow($id);
        });
    }

    // ------------------------------------------------------------ helpers

    private function assertShiftOrder(string $start, string $end): void
    {
        if ($start >= $end) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'shift_start must precede shift_end.', 'field' => 'shift_start'],
            ]);
        }
    }

    /**
     * The roster only references registered auth users; a bogus id
     * would otherwise surface as a raw DB FK error (500).
     */
    private function assertUserExists(int $userId): void
    {
        $exists = $this->db->table('users')
            ->where('id', $userId)
            ->countAllResults() > 0;
        if (! $exists) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'User #' . $userId . ' does not exist.', 'field' => 'user_id'],
            ]);
        }
    }

    /**
     * No two ACTIVE shifts may overlap for the same staff member on the
     * same weekday. `$excludeId` skips the row being updated (the
     * overlap scan is `start < other_end AND end > other_start`).
     */
    private function assertNoOverlap(int $userId, int $dow, string $start, string $end, ?int $excludeId = null): void
    {
        $builder = $this->db->table('clinic_staff_schedules')
            ->where('user_id', $userId)
            ->where('day_of_week', $dow)
            ->where('is_active', 1)
            ->where('shift_start <', $end)
            ->where('shift_end >', $start);
        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }
        if ($builder->countAllResults() > 0) {
            throw ApiException::validationFailure([
                ['code' => 'validation.conflict', 'message' => 'Shift overlaps an existing active shift for this staff member on that day.', 'field' => 'shift_start'],
            ]);
        }
    }

    /**
     * When both effective dates are set, `effective_to` must not
     * precede `effective_from` (ISO dates compare correctly as strings).
     */
    private function assertEffectiveOrder(?string $from, ?string $to): void
    {
        if ($from !== null && $to !== null && $to < $from) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'effective_to must not precede effective_from.', 'field' => 'effective_to'],
            ]);
        }
    }

    private function normalizeType(mixed $type): string
    {
        $t = is_string($type) ? $type : '';
        return in_array($t, self::TYPES, true) ? $t : 'regular';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function dateOrNull(array $input, string $key): ?string
    {
        $v = $input[$key] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    private function getRow(int $id): array
    {
        $r = $this->db->table('clinic_staff_schedules s')
            ->select("s.id, s.user_id, s.day_of_week, s.shift_start, s.shift_end, s.schedule_type, s.effective_from, s.effective_to, s.is_active, u.first_name, u.last_name")
            ->join('users u', 'u.id = s.user_id', 'left')
            ->where('s.id', $id)->get()->getRowArray();
        return $this->row($r);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function row(array $r): array
    {
        return [
            'id'             => (int) $r['id'],
            'user_id'        => (int) $r['user_id'],
            // Staff display name from the unified registry; null when
            // the referenced user no longer exists.
            'user_name'      => $this->userFullName($r),
            'day_of_week'    => (int) $r['day_of_week'],
            'shift_start'    => (string) $r['shift_start'],
            'shift_end'      => (string) $r['shift_end'],
            'schedule_type'  => (string) $r['schedule_type'],
            'effective_from' => $r['effective_from'] !== null ? (string) $r['effective_from'] : null,
            'effective_to'   => $r['effective_to'] !== null ? (string) $r['effective_to'] : null,
            'is_active'      => (bool) $r['is_active'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     */
    private function userFullName(array $r): ?string
    {
        $first = isset($r['first_name']) && $r['first_name'] !== null ? trim((string) $r['first_name']) : '';
        $last  = isset($r['last_name'])  && $r['last_name']  !== null ? trim((string) $r['last_name'])  : '';
        if ($first === '' && $last === '') {
            return null;
        }
        return trim($first . ' ' . $last);
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
