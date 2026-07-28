<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\StateMachineException;
use App\Services\Audit\AuditOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\Policies\ClinicPolicy;

/**
 * QueueService — walk-in queue (Phase 14, recycled from synapse_ag
 * ConsultationController queue actions).
 *
 * Rules ported from the legacy module:
 *   - Positions are stable 1-based per day, assigned under a row lock
 *     so concurrent check-ins cannot collide.
 *   - Only ONE entry may be `called` at a time (the "now serving"
 *     slot); it must be started or skipped before the next call.
 *   - The public feed discloses ONLY position + display name; the
 *     school id is never exposed unauthenticated (masked fallback).
 */
final class QueueService extends BaseService
{
    /** @var array<string, array<int, string>> action => allowed current statuses */
    private const TRANSITIONS = [
        'start'    => ['called'],
        'skip'     => ['called'],
        'complete' => ['in_session'],
    ];

    /** @var array<string, string> action => resulting status */
    private const RESULT = [
        'start'    => 'in_session',
        'skip'     => 'skipped',
        'complete' => 'done',
    ];

    public function __construct(
        private readonly ClinicPolicy $policy,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    /**
     * Today's queue — staff view (full school ids).
     *
     * @return array<int, array<string, mixed>>
     */
    public function today(): array
    {
        $this->policy->check('queueRead');

        return array_map(
            fn (array $r): array => $this->row($r, false),
            $this->todayRows(),
        );
    }

    /** Enqueue an OPEN encounter into today's queue. */
    public function enqueue(int $encounterId): array
    {
        $this->policy->check('queueManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($encounterId, $userId): array {
            $encounter = $this->selectForUpdate('clinic_encounters', ['id' => $encounterId, 'archived_at' => null]);
            if ($encounter === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Encounter #{$encounterId} not found."],
                ]);
            }
            if ((string) $encounter['status'] !== 'Open') {
                throw new ApiException('statemachine.queue.encounter_not_open', 409, [
                    ['code' => 'statemachine.queue.encounter_not_open', 'message' => 'Only Open encounters can be queued.'],
                ]);
            }

            $existing = $this->db->table('clinic_queue_entries')
                ->where('encounter_id', $encounterId)
                ->get()->getRowArray();
            if ($existing !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => 'Encounter is already queued.'],
                ]);
            }

            $today = $this->utcToday();

            // Lock today's entries so the MAX(position) read is stable.
            $rows = $this->db->query(
                'SELECT `position` FROM `clinic_queue_entries` WHERE `queue_date` = ? ORDER BY `position` DESC LIMIT 1 FOR UPDATE',
                [$today],
            )->getRowArray();
            $position = ($rows !== null ? (int) $rows['position'] : 0) + 1;

            $now = $this->utcNow();
            $this->db->table('clinic_queue_entries')->insert([
                'encounter_id' => $encounterId,
                'queue_date'   => $today,
                'position'     => $position,
                'status'       => 'waiting',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('clinic.queue_joined', 'clinic_queue_entries', $id, $userId, [
                'resource_code' => 'position#' . (string) $position,
            ]);

            return $this->getRow($id);
        });
    }

    /** Call the next waiting patient (single "now serving" slot). */
    public function callNext(): array
    {
        $this->policy->check('queueManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($userId): array {
            $today = $this->utcToday();

            // Lock today's open entries (called + waiting) in one pass.
            $rows = $this->db->query(
                'SELECT `id`, `status` FROM `clinic_queue_entries`'
                . ' WHERE `queue_date` = ? AND `status` IN (?, ?)'
                . ' ORDER BY `position` ASC FOR UPDATE',
                [$today, 'called', 'waiting'],
            )->getResultArray();

            foreach ($rows as $r) {
                if ((string) $r['status'] === 'called') {
                    throw new ApiException('statemachine.queue.already_called', 409, [
                        ['code' => 'statemachine.queue.already_called', 'message' => 'A patient is already called — start or skip them first.'],
                    ]);
                }
            }

            $next = $rows[0] ?? null;
            if ($next === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => 'No patients waiting.'],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('clinic_queue_entries')->where('id', (int) $next['id'])->update([
                'status'            => 'called',
                'called_at'         => $now,
                'called_by_user_id' => $userId,
                'updated_at'        => $now,
            ]);

            $this->audit->enqueue('clinic.queue_called', 'clinic_queue_entries', (int) $next['id'], $userId, []);

            return $this->getRow((int) $next['id']);
        });
    }

    /** start / skip / complete on a called or in-session entry. */
    public function transition(int $id, string $action): array
    {
        $this->policy->check('queueManage');
        $userId = \App\Auth\CurrentUser::assert();

        if (! isset(self::TRANSITIONS[$action])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => "Unknown action '{$action}'.", 'field' => 'action'],
            ]);
        }

        return $this->txn(function () use ($id, $action, $userId): array {
            $row = $this->selectForUpdate('clinic_queue_entries', ['id' => $id]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Queue entry #{$id} not found."],
                ]);
            }

            $current = (string) $row['status'];
            if (! in_array($current, self::TRANSITIONS[$action], true)) {
                throw StateMachineException::invalidTransition($current, self::RESULT[$action], 'queue');
            }

            $now    = $this->utcNow();
            $update = ['status' => self::RESULT[$action], 'updated_at' => $now];
            if ($action === 'start') {
                $update['started_at'] = $now;
            }
            if ($action === 'complete') {
                $update['finished_at'] = $now;
            }

            $this->db->table('clinic_queue_entries')->where('id', $id)->update($update);

            $this->audit->enqueue(
                'clinic.queue_' . self::RESULT[$action],
                'clinic_queue_entries',
                $id,
                $userId,
                ['outcome' => self::RESULT[$action]],
            );

            return $this->getRow($id);
        });
    }

    /**
     * PUBLIC waiting-room feed — minimum disclosure: position + display
     * name only (legacy TV/kiosk contract). No policy check by design;
     * the route is unauthenticated.
     *
     * @return array{now_serving: ?array{position: int, display_name: string}, waiting: array<int, array{position: int, display_name: string}>, updated_at: string}
     */
    public function publicState(): array
    {
        $nowServing = null;
        $waiting    = [];

        foreach ($this->todayRows() as $r) {
            $status = (string) $r['status'];
            $item   = [
                'position'     => (int) $r['position'],
                'display_name' => $this->displayName($r),
            ];
            if ($status === 'called' || $status === 'in_session') {
                // Highest-progress entry wins the "now serving" board.
                $nowServing = $item;
            } elseif ($status === 'waiting') {
                $waiting[] = $item;
            }
        }

        return [
            'now_serving' => $nowServing,
            'waiting'     => $waiting,
            'updated_at'  => $this->utcNow(),
        ];
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array<int, array<string, mixed>>
     */
    private function todayRows(): array
    {
        return $this->db->table('clinic_queue_entries q')
            ->select('q.id, q.encounter_id, q.position, q.status, q.called_at, q.started_at, q.finished_at, q.created_at, e.patient_school_id, e.chief_complaint, s.first_name, s.last_name')
            ->join('clinic_encounters e', 'e.id = q.encounter_id')
            ->join('patients_students s', 's.student_number = e.patient_school_id', 'left')
            ->where('q.queue_date', $this->utcToday())
            ->orderBy('q.position', 'ASC')
            ->get()->getResultArray();
    }

    private function getRow(int $id): array
    {
        $row = $this->db->table('clinic_queue_entries q')
            ->select('q.id, q.encounter_id, q.position, q.status, q.called_at, q.started_at, q.finished_at, q.created_at, e.patient_school_id, e.chief_complaint, s.first_name, s.last_name')
            ->join('clinic_encounters e', 'e.id = q.encounter_id')
            ->join('patients_students s', 's.student_number = e.patient_school_id', 'left')
            ->where('q.id', $id)
            ->get()->getRowArray();
        return $this->row($row, false);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function row(array $r, bool $public): array
    {
        $out = [
            'id'              => (int) $r['id'],
            'encounter_id'    => (int) $r['encounter_id'],
            'position'        => (int) $r['position'],
            'status'          => (string) $r['status'],
            'display_name'    => $this->displayName($r),
            'called_at'       => $r['called_at'] !== null ? (string) $r['called_at'] : null,
            'started_at'      => $r['started_at'] !== null ? (string) $r['started_at'] : null,
            'finished_at'     => $r['finished_at'] !== null ? (string) $r['finished_at'] : null,
        ];
        if (! $public) {
            $out['patient_school_id'] = (string) $r['patient_school_id'];
            $out['chief_complaint']   = (string) $r['chief_complaint'];
        }
        return $out;
    }

    /**
     * First name from the patient registry; masked school id fallback.
     *
     * @param array<string, mixed> $r
     */
    private function displayName(array $r): string
    {
        if (isset($r['first_name']) && $r['first_name'] !== null && $r['first_name'] !== '') {
            return (string) $r['first_name'];
        }
        $sid = (string) $r['patient_school_id'];
        return mb_substr($sid, 0, 3) . '…';
    }

    private function utcToday(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
