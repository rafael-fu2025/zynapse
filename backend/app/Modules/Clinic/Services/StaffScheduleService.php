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
    public function list(?int $userId): array
    {
        $this->policy->check('schedulesManage');

        $builder = $this->db->table('clinic_staff_schedules')
            ->select('id, user_id, day_of_week, shift_start, shift_end, schedule_type, effective_from, effective_to, is_active')
            ->where('is_active', 1)
            ->orderBy('user_id', 'ASC')
            ->orderBy('day_of_week', 'ASC')
            ->orderBy('shift_start', 'ASC');
        if ($userId !== null) {
            $builder->where('user_id', $userId);
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
            $start = (string) $input['shift_start'];
            $end   = (string) $input['shift_end'];
            $this->assertShiftOrder($start, $end);

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

            $start = isset($input['shift_start']) ? (string) $input['shift_start'] : (string) $row['shift_start'];
            $end   = isset($input['shift_end'])   ? (string) $input['shift_end']   : (string) $row['shift_end'];
            $this->assertShiftOrder($start, $end);

            $this->db->table('clinic_staff_schedules')->where('id', $id)->update([
                'day_of_week'    => isset($input['day_of_week']) ? (int) $input['day_of_week'] : (int) $row['day_of_week'],
                'shift_start'    => $start,
                'shift_end'      => $end,
                'schedule_type'  => isset($input['schedule_type']) ? $this->normalizeType($input['schedule_type']) : (string) $row['schedule_type'],
                'effective_from' => array_key_exists('effective_from', $input) ? $this->dateOrNull($input, 'effective_from') : $row['effective_from'],
                'effective_to'   => array_key_exists('effective_to', $input) ? $this->dateOrNull($input, 'effective_to') : $row['effective_to'],
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

    // ------------------------------------------------------------ helpers

    private function assertShiftOrder(string $start, string $end): void
    {
        if ($start >= $end) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'shift_start must precede shift_end.', 'field' => 'shift_start'],
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
        $r = $this->db->table('clinic_staff_schedules')
            ->select('id, user_id, day_of_week, shift_start, shift_end, schedule_type, effective_from, effective_to, is_active')
            ->where('id', $id)->get()->getRowArray();
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
            'day_of_week'    => (int) $r['day_of_week'],
            'shift_start'    => (string) $r['shift_start'],
            'shift_end'      => (string) $r['shift_end'],
            'schedule_type'  => (string) $r['schedule_type'],
            'effective_from' => $r['effective_from'] !== null ? (string) $r['effective_from'] : null,
            'effective_to'   => $r['effective_to'] !== null ? (string) $r['effective_to'] : null,
            'is_active'      => (bool) $r['is_active'],
        ];
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
