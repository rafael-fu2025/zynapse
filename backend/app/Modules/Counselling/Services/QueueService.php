<?php

declare(strict_types=1);

namespace Modules\Counselling\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\StateMachineException;
use App\Services\Audit\AuditOutboxService;
use App\Services\Notify\NotificationOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Counselling\Policies\CounsellingPolicy;
use Modules\Counselling\DTOs\SessionDto;

/** Independent FIFO queue owned by the existing Counselling module. */
final class QueueService extends BaseService
{
    private const TRANSITIONS = [
        'start' => ['called'],
        'skip' => ['called'],
        'complete' => ['in_session'],
    ];

    private const RESULT = [
        'start' => 'in_session',
        'skip' => 'skipped',
        'complete' => 'done',
    ];

    public function __construct(
        private readonly CounsellingPolicy $policy,
        private readonly AuditOutboxService $audit,
        private readonly NotificationOutboxService $notify,
    ) {
        parent::__construct();
    }

    /** @return list<array<string, mixed>> */
    public function today(): array
    {
        $this->policy->check('queueRead');
        $this->enqueueDueAppointments();
        return array_map(fn (array $row): array => $this->serialize($row), $this->todayRows());
    }

    /** Idempotently enqueue Guidance appointments once they reach T-15. */
    public function enqueueDueAppointments(): int
    {
        $localNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $cutoff = $localNow->modify('+15 minutes');
        $dates = [$localNow->format('Y-m-d'), $localNow->modify('+1 day')->format('Y-m-d')];
        $rows = $this->db->table('counselling_appointments')
            ->select('id, patient_user_id, patient_school_id, counsellor_user_id, appointment_date, start_time, reason')
            ->whereIn('appointment_date', $dates)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->orderBy('appointment_date', 'ASC')->orderBy('start_time', 'ASC')
            ->get()->getResultArray();
        $count = 0;
        foreach ($rows as $row) {
            if ((int) ($row['patient_user_id'] ?? 0) <= 0) continue;
            $start = new DateTimeImmutable((string) $row['appointment_date'] . ' ' . (string) $row['start_time'], new DateTimeZone('Asia/Manila'));
            if ($start > $cutoff) continue;
            try {
                $count += $this->txn(function () use ($row): int {
                    $locked = $this->selectForUpdate('counselling_appointments', ['id' => (int) $row['id']]);
                    if ($locked === null || ! in_array((string) $locked['status'], ['scheduled', 'confirmed'], true)) return 0;
                    $existing = $this->db->table('counselling_queue_entries')->where('counselling_appointment_id', (int) $row['id'])->countAllResults();
                    if ($existing > 0) return 0;
                    $this->enqueue(
                        (int) $row['patient_user_id'],
                        (string) $row['patient_school_id'],
                        (int) $row['id'], null, null,
                        (string) ($row['reason'] ?: 'Scheduled Guidance appointment'),
                        (int) $row['counsellor_user_id'],
                    );
                    return 1;
                });
            } catch (\Throwable $e) {
                log_message('warning', 'Guidance due enqueue skipped #' . (int) $row['id'] . ': ' . $e->getMessage());
            }
        }
        return $count;
    }

    /** @return array<string,mixed>|null */
    public function myStatus(int $patientUserId): ?array
    {
        $row = $this->db->table('counselling_queue_entries')
            ->where('queue_date', $this->businessToday())
            ->where('patient_user_id', $patientUserId)
            ->whereIn('status', ['waiting', 'called', 'in_session'])
            ->orderBy('position', 'ASC')->get()->getRowArray();
        if ($row === null) return null;
        $ahead = (string) $row['status'] === 'waiting' ? $this->db->table('counselling_queue_entries')
            ->where('queue_date', $this->businessToday())->whereIn('status', ['waiting', 'called', 'in_session'])
            ->where('position <', (int) $row['position'])->countAllResults() : 0;
        return [
            'destination' => 'counselling', 'queue_entry_id' => (int) $row['id'],
            'queue_number' => sprintf('G-%03d', (int) $row['position']), 'position' => (int) $row['position'],
            'status' => (string) $row['status'], 'people_ahead' => (int) $ahead,
            'estimated_wait_minutes' => (string) $row['status'] === 'waiting' ? (int) round($ahead * $this->averageServiceMinutes()) : null,
            'called_at' => $row['called_at'] !== null ? (string) $row['called_at'] : null,
            'started_at' => $row['started_at'] !== null ? (string) $row['started_at'] : null,
            'appointment_id' => $row['counselling_appointment_id'] !== null ? (int) $row['counselling_appointment_id'] : null,
            'session_id' => $row['counselling_session_id'] !== null ? (int) $row['counselling_session_id'] : null,
        ];
    }

    /**
     * Enqueue from the already-authorized kiosk transaction.
     *
     * @return array<string, mixed>
     */
    public function enqueueFromKiosk(
        int $patientUserId,
        string $patientSchoolId,
        ?int $appointmentId,
        ?int $checkinId,
        string $purpose,
        ?int $assignedCounsellorUserId = null,
    ): array {
        return $this->enqueue($patientUserId, $patientSchoolId, $appointmentId, null, $checkinId, $purpose, $assignedCounsellorUserId);
    }

    /** @return array<string, mixed> */
    public function enqueueFromReferral(int $patientUserId, string $patientSchoolId, int $referralId): array
    {
        $this->policy->check('queueManage');
        return $this->txn(fn (): array => $this->enqueue(
            $patientUserId,
            $patientSchoolId,
            null,
            $referralId,
            null,
            'Referral Follow-up',
            null,
        ));
    }

    /** Fetch an already-associated handoff without creating or mutating a queue row. */
    public function getForHandoff(int $id): array
    {
        $this->policy->check('queueManage');
        return $this->getRow($id);
    }

    /** @return array<string, mixed> */
    public function callNext(): array
    {
        $this->policy->check('queueManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($userId): array {
            $rows = $this->db->query(
                'SELECT `id`, `status`, `assigned_counsellor_user_id` FROM `counselling_queue_entries`'
                . ' WHERE `queue_date` = ? AND ((`status` IN (?, ?) AND `assigned_counsellor_user_id` = ?)'
                . ' OR (`status` = ? AND (`assigned_counsellor_user_id` = ? OR `assigned_counsellor_user_id` IS NULL)))'
                . ' ORDER BY `position` ASC FOR UPDATE',
                [$this->businessToday(), 'called', 'in_session', $userId, 'waiting', $userId],
            )->getResultArray();

            foreach ($rows as $row) {
                if (in_array((string) $row['status'], ['called', 'in_session'], true)) {
                    throw new ApiException('statemachine.queue.already_active', 409, [
                        ['code' => 'statemachine.queue.already_active', 'message' => 'You already have a Guidance patient called or in session.'],
                    ]);
                }
            }
            $next = $rows[0] ?? null;
            if ($next === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => 'No patients waiting for Guidance.'],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('counselling_queue_entries')->where('id', (int) $next['id'])->update([
                'status' => 'called',
                'called_at' => $now,
                'called_by_user_id' => $userId,
                'assigned_counsellor_user_id' => $userId,
                'updated_at' => $now,
            ]);
            $this->audit->enqueue('counselling.queue_called', 'counselling_queue_entries', (int) $next['id'], $userId, []);
            $this->notifyCalled((int) $next['id'], $userId);
            return $this->getRow((int) $next['id']);
        });
    }

    /** @return array<string, mixed> */
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
            $row = $this->selectForUpdate('counselling_queue_entries', ['id' => $id]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Guidance queue entry #{$id} not found."],
                ]);
            }
            $current = (string) $row['status'];
            if ($row['assigned_counsellor_user_id'] !== null && (int) $row['assigned_counsellor_user_id'] !== $userId) {
                throw ApiException::forbidden('rbac.record.forbidden');
            }
            if (! in_array($current, self::TRANSITIONS[$action], true)) {
                throw StateMachineException::invalidTransition($current, self::RESULT[$action], 'counselling.queue');
            }

            $now = $this->utcNow();
            $update = ['status' => self::RESULT[$action], 'updated_at' => $now];
            if ($action === 'start') {
                $sessionId = $row['counselling_session_id'] !== null
                    ? (int) $row['counselling_session_id']
                    : $this->openSessionForQueue($row, $userId, $now);
                $update['counselling_session_id'] = $sessionId;
                $update['started_at'] = $now;
            } elseif ($action === 'complete') {
                $update['finished_at'] = $now;
                $this->completeLinkedRecords($row, $userId, $now);
            } elseif ($action === 'skip') {
                $update['finished_at'] = $now;
            }

            $this->db->table('counselling_queue_entries')->where('id', $id)->update($update);
            $this->audit->enqueue(
                'counselling.queue_' . self::RESULT[$action],
                'counselling_queue_entries',
                $id,
                $userId,
                ['previous_status' => $current, 'next_status' => self::RESULT[$action]],
            );
            return $this->getRow($id);
        });
    }

    /**
     * Complete a queue-linked session from the Sessions workspace. Returning
     * null means this is a manually opened session and should use the normal
     * direct-close path. Linked completion is transactional and idempotent.
     */
    public function completeLinkedSession(int $sessionId): ?SessionDto
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($sessionId, $userId): ?SessionDto {
            $session = $this->selectForUpdate('counselling_sessions', ['id' => $sessionId, 'archived_at' => null]);
            if ($session === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Session #{$sessionId} not found."],
                ]);
            }
            $this->policy->check('close', $session);

            $queue = $this->db->query(
                'SELECT * FROM `counselling_queue_entries` WHERE `counselling_session_id` = ? LIMIT 1 FOR UPDATE',
                [$sessionId],
            )->getRowArray();
            if ($queue === null) {
                return null;
            }
            if ((string) $queue['status'] === 'done' && $session['ended_at'] !== null) {
                return SessionDto::fromRow($session);
            }
            if ((string) $queue['status'] !== 'in_session') {
                throw new ApiException('statemachine.counselling.session_not_active', 409, [[
                    'code' => 'statemachine.counselling.session_not_active',
                    'message' => 'This Guidance session is no longer active. Refresh before trying again.',
                ]]);
            }

            $now = $this->utcNow();
            $this->completeLinkedRecords($queue, $userId, $now);
            $this->db->table('counselling_queue_entries')->where('id', (int) $queue['id'])->update([
                'status' => 'done',
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
            $this->audit->enqueue('counselling.queue_done', 'counselling_queue_entries', (int) $queue['id'], $userId, [
                'previous_status' => 'in_session',
                'next_status' => 'done',
                'reason_code' => 'session_workspace_complete',
            ]);

            $fresh = $this->db->table('counselling_sessions')->where('id', $sessionId)->get()->getRowArray();
            return SessionDto::fromRow($fresh);
        });
    }

    /** @return array<string, mixed> */
    public function repairSession(int $id): array
    {
        $this->policy->check('queueManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $userId): array {
            $queue = $this->selectForUpdate('counselling_queue_entries', ['id' => $id]);
            if ($queue === null) {
                throw new ApiException('resource.not_found', 404, [[
                    'code' => 'resource.not_found', 'message' => "Guidance queue entry #{$id} not found.",
                ]]);
            }
            if ((string) $queue['status'] !== 'in_session') {
                throw new ApiException('statemachine.counselling.queue_not_active', 409, [[
                    'code' => 'statemachine.counselling.queue_not_active',
                    'message' => 'Only an active Guidance queue entry can repair its session link.',
                ]]);
            }
            if ($queue['counselling_session_id'] !== null) {
                $existing = $this->db->table('counselling_sessions')
                    ->where('id', (int) $queue['counselling_session_id'])
                    ->where('archived_at', null)->get()->getRowArray();
                if ($existing !== null) return $this->getRow($id);
            }

            $now = $this->utcNow();
            $sessionId = $this->openSessionForQueue($queue, $userId, $now);
            $this->db->table('counselling_queue_entries')->where('id', $id)->update([
                'counselling_session_id' => $sessionId,
                'updated_at' => $now,
            ]);
            $this->audit->enqueue('counselling.queue_session_repaired', 'counselling_queue_entries', $id, $userId, [
                'resource_code' => 'session#' . $sessionId,
            ]);
            return $this->getRow($id);
        });
    }

    /** @return array{active:list<array<string,mixed>>,now_serving:?array<string,mixed>,waiting:list<array<string,mixed>>} */
    public function publicState(): array
    {
        $active = [];
        $waiting = [];
        foreach ($this->todayRows() as $row) {
            $item = $this->publicRow($row);
            if (in_array((string) $row['status'], ['called', 'in_session'], true)) {
                $active[] = $item;
            } elseif ((string) $row['status'] === 'waiting') {
                $waiting[] = $item;
            }
        }
        $average = $this->averageServiceMinutes();
        foreach ($waiting as $index => &$item) {
            $ahead = $index + count($active);
            $item['est_wait_minutes'] = (int) round($ahead * $average);
        }
        unset($item);
        return ['active' => $active, 'now_serving' => $active[0] ?? null, 'waiting' => $waiting];
    }

    /** @return array<string, mixed> */
    private function enqueue(
        int $patientUserId,
        string $patientSchoolId,
        ?int $appointmentId,
        ?int $referralId,
        ?int $checkinId,
        string $purpose,
        ?int $assignedCounsellorUserId,
    ): array {
        $date = $this->businessToday();
        $active = $this->db->query(
            'SELECT `id` FROM `counselling_queue_entries`'
            . ' WHERE `queue_date` = ? AND `patient_school_id` = ? AND `status` IN (?, ?, ?) LIMIT 1 FOR UPDATE',
            [$date, $patientSchoolId, 'waiting', 'called', 'in_session'],
        )->getRowArray();
        if ($active !== null) {
            return $this->getRow((int) $active['id']);
        }

        // Lock a stable row even when today's queue is empty so two first
        // arrivals cannot both calculate position 1.
        $this->db->query('SELECT `id` FROM `tenants` WHERE `id` = ? FOR UPDATE', [1]);
        $last = $this->db->query(
            'SELECT `position` FROM `counselling_queue_entries`'
            . ' WHERE `queue_date` = ? ORDER BY `position` DESC LIMIT 1 FOR UPDATE',
            [$date],
        )->getRowArray();
        $position = ($last !== null ? (int) $last['position'] : 0) + 1;
        $now = $this->utcNow();
        $this->db->table('counselling_queue_entries')->insert([
            'patient_user_id' => $patientUserId,
            'patient_school_id' => $patientSchoolId,
            'counselling_appointment_id' => $appointmentId,
            'assigned_counsellor_user_id' => $assignedCounsellorUserId,
            'referral_id' => $referralId,
            'checkin_id' => $checkinId,
            'purpose' => $purpose,
            'queue_date' => $date,
            'position' => $position,
            'status' => 'waiting',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $this->db->insertID();
        return $this->getRow($id);
    }

    private function openSessionForQueue(array $queue, int $userId, string $now): int
    {
        $owner = $queue['assigned_counsellor_user_id'] !== null
            ? (int) $queue['assigned_counsellor_user_id']
            : $userId;
        $this->db->table('counselling_sessions')->insert([
            'patient_user_id' => (int) $queue['patient_user_id'],
            'patient_school_id' => (string) $queue['patient_school_id'],
            'counsellor_user_id' => $owner,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sessionId = (int) $this->db->insertID();
        $this->audit->enqueue('counselling.session_opened', 'counselling_sessions', $sessionId, $userId, [
            'next_status' => 'open',
            'reason_code' => 'guidance_queue_start',
        ]);
        return $sessionId;
    }

    private function completeLinkedRecords(array $queue, int $userId, string $now): void
    {
        if ($queue['counselling_session_id'] !== null) {
            $sessionId = (int) $queue['counselling_session_id'];
            $this->db->table('counselling_sessions')->where('id', $sessionId)->where('ended_at', null)->update([
                'ended_at' => $now,
                'updated_at' => $now,
            ]);
            if ($this->db->affectedRows() > 0) {
                $this->audit->enqueue('counselling.session_closed', 'counselling_sessions', $sessionId, $userId, [
                    'reason_code' => 'guidance_queue_complete',
                ]);
            }
        }
        if ($queue['counselling_appointment_id'] !== null) {
            $appointmentId = (int) $queue['counselling_appointment_id'];
            $this->db->table('counselling_appointments')->where('id', $appointmentId)->where('status', 'confirmed')->update([
                'status' => 'completed',
                'updated_at' => $now,
            ]);
        }
    }

    private function notifyCalled(int $id, int $actor): void
    {
        $row = $this->db->table('counselling_queue_entries')->select('patient_user_id, position')->where('id', $id)->get()->getRowArray();
        $patientId = (int) ($row['patient_user_id'] ?? 0);
        if ($patientId > 0 && $patientId !== $actor) {
            $this->notify->enqueue($patientId, 'counselling.queue_called', [
                'queue_number' => sprintf('G-%03d', (int) $row['position']),
                'destination' => 'counselling',
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function todayRows(): array
    {
        return $this->db->table('counselling_queue_entries q')
            ->select('q.*, u.first_name, u.last_name')
            ->join('users u', 'u.id = q.patient_user_id', 'left')
            ->where('q.queue_date', $this->businessToday())
            ->orderBy('q.position', 'ASC')
            ->get()->getResultArray();
    }

    /** @return array<string, mixed> */
    private function getRow(int $id): array
    {
        $row = $this->db->table('counselling_queue_entries q')
            ->select('q.*, u.first_name, u.last_name')
            ->join('users u', 'u.id = q.patient_user_id', 'left')
            ->where('q.id', $id)
            ->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Guidance queue entry #{$id} not found."],
            ]);
        }
        return $this->serialize($row);
    }

    /** @return array<string, mixed> */
    private function serialize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'destination' => 'counselling',
            'queue_number' => sprintf('G-%03d', (int) $row['position']),
            'position' => (int) $row['position'],
            'status' => (string) $row['status'],
            'display_name' => $this->displayName($row),
            'patient_school_id' => (string) $row['patient_school_id'],
            'purpose' => $row['purpose'] !== null ? (string) $row['purpose'] : null,
            'counselling_appointment_id' => $row['counselling_appointment_id'] !== null ? (int) $row['counselling_appointment_id'] : null,
            'counselling_session_id' => $row['counselling_session_id'] !== null ? (int) $row['counselling_session_id'] : null,
            'assigned_counsellor_user_id' => $row['assigned_counsellor_user_id'] !== null ? (int) $row['assigned_counsellor_user_id'] : null,
            'referral_id' => $row['referral_id'] !== null ? (int) $row['referral_id'] : null,
            'called_at' => $row['called_at'] !== null ? (string) $row['called_at'] : null,
            'started_at' => $row['started_at'] !== null ? (string) $row['started_at'] : null,
            'finished_at' => $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function publicRow(array $row): array
    {
        return [
            'position' => (int) $row['position'],
            'queue_number' => sprintf('G-%03d', (int) $row['position']),
            'display_name' => $this->displayName($row, true),
            'patient_school_id' => (string) $row['patient_school_id'],
        ];
    }

    private function displayName(array $row, bool $full = false): string
    {
        $first = trim((string) ($row['first_name'] ?? ''));
        $last = trim((string) ($row['last_name'] ?? ''));
        if ($full && ($first !== '' || $last !== '')) {
            return trim($last . ($first !== '' ? ', ' . $first : ''));
        }
        return $first !== '' ? $first : mb_substr((string) $row['patient_school_id'], 0, 3) . '…';
    }

    private function averageServiceMinutes(): float
    {
        $row = $this->db->query(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, `started_at`, `finished_at`)) AS avg_min'
            . ' FROM `counselling_queue_entries` WHERE `queue_date` = ?'
            . ' AND `started_at` IS NOT NULL AND `finished_at` IS NOT NULL',
            [$this->businessToday()],
        )->getRowArray();
        return $row !== null && $row['avg_min'] !== null ? max(1.0, (float) $row['avg_min']) : 10.0;
    }

    private function businessToday(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
