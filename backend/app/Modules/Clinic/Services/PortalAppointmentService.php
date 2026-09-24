<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\CurrentTenant;
use App\Services\Notify\NotificationOutboxService;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Counselling\Policies\CounsellingPolicy;
use Modules\Counselling\Services\QueueService as GuidanceQueueService;

/** Patient-owned appointment and multi-destination queue facade. */
final class PortalAppointmentService extends BaseService
{
    private const LOCAL_TZ = 'Asia/Manila';

    public function __construct(
        private readonly AppointmentService $clinicAppointments,
        private readonly QueueService $clinicQueue,
        private readonly NotificationOutboxService $notify,
    ) { parent::__construct(); }

    /** @return list<array<string,mixed>> */
    public function appointments(int $userId, ?string $department, ?string $status, ?string $from, ?string $to): array
    {
        $out = [];
        if ($department === null || in_array($department, ['all', 'clinic'], true)) {
            $b = $this->db->table('clinic_appointments')->where('clinic_appointments.tenant_id', CurrentTenant::id())->where('patient_user_id', $userId)->where('archived_at', null);
            if ($status) $b->where('status', $status);
            if ($from) $b->where('scheduled_at >=', $this->localBoundaryUtc($from, false));
            if ($to) $b->where('scheduled_at <=', $this->localBoundaryUtc($to, true));
            foreach ($b->get()->getResultArray() as $row) $out[] = $this->clinicRow($row);
        }
        if ($department === null || in_array($department, ['all', 'counselling'], true)) {
            $b = $this->db->table('counselling_appointments')->where('counselling_appointments.tenant_id', CurrentTenant::id())->where('patient_user_id', $userId);
            if ($status) $b->where('status', $status);
            if ($from) $b->where('appointment_date >=', $from);
            if ($to) $b->where('appointment_date <=', $to);
            foreach ($b->get()->getResultArray() as $row) $out[] = $this->guidanceRow($row);
        }
        // Unassigned appointments have nobody to name yet, and a null in the
        // id list would reach `whereIn` — filter before the lookup, then leave
        // provider_name null rather than inventing "Provider #0".
        $ids = array_values(array_filter(
            array_unique(array_column($out, 'provider_user_id')),
            static fn($id): bool => $id !== null,
        ));
        $names = $this->providerNames($ids);
        foreach ($out as &$row) $row['provider_name'] = $row['provider_user_id'] !== null ? ($names[$row['provider_user_id']] ?? null) : null;
        unset($row);
        usort($out, static fn(array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
        return $out;
    }

    /**
     * Available times for a department, pooled across every staff member.
     *
     * The patient books a TIME, not a person (2026-09-23). Slots used to be
     * one row per free staff member ("9:00 AM · Nurse Reyes"), which asked the
     * patient to choose staff they have no basis to choose, and forced the desk
     * to honour a pairing that made no operational sense. A slot's `remaining`
     * is now the department's spare capacity at that instant, and the provider
     * is assigned later by whoever approves the appointment.
     *
     * @return list<array<string,mixed>>
     */
    public function slots(string $department, string $from, string $to): array
    {
        $start = new DateTimeImmutable($from, new DateTimeZone(self::LOCAL_TZ));
        $end = new DateTimeImmutable($to, new DateTimeZone(self::LOCAL_TZ));
        $now = new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TZ));
        if ($start > $end || $end > $now->modify('+90 days')) throw ApiException::validationFailure([[
            'code'=>'validation.range','message'=>'Choose a valid range within the 90-day booking horizon.','field'=>'to',
        ]]);
        $slots = $department === 'clinic'
            ? $this->clinicSlots($start, $end)
            : $this->guidanceSlots($start, $end);
        return array_values(array_filter($slots, static fn(array $slot): bool =>
            new DateTimeImmutable((string) $slot['starts_at']) >= (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 hour')
        ));
    }

    /**
     * Book a department and a time. No provider is named or reserved.
     *
     * @return array<string,mixed>
     */
    public function book(int $userId, array $input): array
    {
        $department = (string) $input['department'];
        $startUtc = $this->parseUtc((string) $input['starts_at']);
        $this->assertPatientFree($userId, $startUtc);
        $startLocal = $startUtc->setTimezone(new DateTimeZone(self::LOCAL_TZ));
        // Re-derive availability at commit time: the time must still exist and
        // still have a place left. The check is repeated inside the write
        // transaction below, because this read is not locking.
        $available = $this->slots($department, $startLocal->format('Y-m-d'), $startLocal->format('Y-m-d'));
        if (! array_filter($available, static fn(array $s): bool => $s['starts_at'] === $startUtc->format(DATE_ATOM))) {
            throw new ApiException('statemachine.schedule.slot_full', 409, [[
                'code'=>'statemachine.schedule.slot_full','message'=>'That appointment slot is no longer available.',
            ]]);
        }
        if ($department === 'clinic') {
            return $this->txn(function () use ($userId, $startUtc, $input): array {
                $this->assertClinicPlace($startUtc);
                $dto = $this->clinicAppointments->bookSelf(
                    $userId, null, $startUtc->format('Y-m-d H:i:s'), $input['reason'] ?? null,
                )->toArray();
                $row = $this->clinicRow($dto);
                // Pass the proof-of-booking QR through — bookSelf mints it,
                // but clinicRow used to drop the field, so students booked
                // from the portal could never render their QR (2026-09 audit).
                $row['qr_token'] = $dto['qr_token'] ?? null;
                return $row;
            });
        }
        return $this->bookGuidance($userId, $startLocal, (string) ($input['type'] ?? 'initial'), $input['reason'] ?? null);
    }

    /** @return array<string,mixed> */
    public function cancel(int $userId, string $department, int $id): array
    {
        return $this->txn(function () use ($userId, $department, $id): array {
            $table = $department === 'clinic' ? 'clinic_appointments' : 'counselling_appointments';
            $row = $this->selectForUpdate($table, ['tenant_id' => CurrentTenant::id(), 'id' => $id, 'patient_user_id' => $userId]);
            if ($row === null) throw new ApiException('resource.not_found', 404, [['code'=>'resource.not_found','message'=>'Appointment not found.']]);
            if ((string) $row['status'] !== 'scheduled') throw new ApiException('statemachine.appointment.not_cancellable', 409, [[
                'code'=>'statemachine.appointment.not_cancellable','message'=>'Only scheduled appointments can be cancelled online.',
            ]]);
            $start = $department === 'clinic'
                ? new DateTimeImmutable((string) $row['scheduled_at'], new DateTimeZone('UTC'))
                : (new DateTimeImmutable((string) $row['appointment_date'].' '.(string) $row['start_time'], new DateTimeZone(self::LOCAL_TZ)))->setTimezone(new DateTimeZone('UTC'));
            if ($start < (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 hour')) throw new ApiException('validation.cancellation_cutoff', 409, [[
                'code'=>'validation.cancellation_cutoff','message'=>'Online cancellation closes one hour before the appointment.',
            ]]);
            $now = gmdate('Y-m-d H:i:s');
            $update = ['status'=>'cancelled','updated_at'=>$now];
            if ($department === 'counselling') $update['cancellation_reason'] = 'Cancelled by patient';
            $this->db->table($table)->where($table . '.tenant_id', CurrentTenant::id())->where('id', $id)->update($update);
            $owner = $row[$department === 'clinic' ? 'provider_user_id' : 'counsellor_user_id'];
            // An unapproved appointment has no owner, so only the patient is
            // told. Coercing null here would enqueue to user 0.
            $recipients = $owner !== null ? array_unique([$userId, (int) $owner]) : [$userId];
            foreach ($recipients as $recipient) $this->notifyAppointment($recipient, $department, $id, $start, 'cancelled');
            return $department === 'clinic' ? $this->clinicRow(array_merge($row, $update)) : $this->guidanceRow(array_merge($row, $update));
        });
    }

    /** @return list<array<string,mixed>> */
    public function queues(int $userId): array
    {
        $this->clinicAppointments->autoCheckInTodaysPending();
        $guidance = $this->guidanceQueue();
        $guidance->enqueueDueAppointments();
        $rows = [];
        $clinic = $this->clinicQueue->myStatus($userId);
        if ($clinic !== null) $rows[] = array_merge(['destination'=>'clinic','appointment_id'=>null,'session_id'=>null], $clinic);
        $counselling = $guidance->myStatus($userId);
        if ($counselling !== null) $rows[] = $counselling;
        return $rows;
    }

    /**
     * Clinic capacity is one appointment per staff member per 60-minute slot,
     * so a time's capacity is simply the number of scheduled staff covering
     * it. Leave removes that staff member's contribution, not the whole slot —
     * the difference matters when three nurses are rostered and one is away.
     *
     * Coverage is keyed by staff id so a person holding both a `regular` and
     * an `on_call` schedule over the same hour counts once; the old code
     * emitted a duplicate slot per schedule.
     *
     * @return list<array<string,mixed>>
     */
    private function clinicSlots(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $schedules = $this->db->table('clinic_staff_schedules')
            ->select('user_id, day_of_week, shift_start, shift_end, effective_from, effective_to')
            ->where('tenant_id', CurrentTenant::id())
            ->where('is_active', 1)->whereIn('schedule_type', ['regular','on_call'])
            ->get()->getResultArray();
        $leave = $this->db->table('clinic_staff_schedules')
            ->select('user_id, day_of_week, effective_from, effective_to')
            ->where('tenant_id', CurrentTenant::id())->where('is_active', 1)
            ->where('schedule_type', 'leave')->get()->getResultArray();

        $places = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $dow = (int) $day->format('w');
            $date = $day->format('Y-m-d');
            foreach ($schedules as $s) {
                if ((int) $s['day_of_week'] !== $dow) continue;
                if ($s['effective_from'] && $date < $s['effective_from']) continue;
                if ($s['effective_to'] && $date > $s['effective_to']) continue;
                if ($this->onLeave($leave, (int) $s['user_id'], $dow, $date)) continue;
                $cursor = new DateTimeImmutable($date.' '.$s['shift_start'], new DateTimeZone(self::LOCAL_TZ));
                $finish = new DateTimeImmutable($date.' '.$s['shift_end'], new DateTimeZone(self::LOCAL_TZ));
                while ($cursor->modify('+60 minutes') <= $finish) {
                    $places[$date][$cursor->format('H:i')][(int) $s['user_id']] = 1;
                    $cursor = $cursor->modify('+60 minutes');
                }
            }
        }

        return $this->pooledSlots('clinic', $places, $this->clinicBookedInstants($from, $to));
    }

    /**
     * Guidance capacity is **one appointment per counsellor covering the
     * instant** — the rule the clinic path already used. Coverage is keyed by
     * counsellor id, so a counsellor holding two overlapping windows over the
     * same hour counts once rather than twice: capacity cannot be inflated by
     * declaring the same cover more than once (2026-09-24).
     *
     * @return list<array<string,mixed>>
     */
    private function guidanceSlots(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $windows = $this->db->table('counselling_availability')
            ->select('counsellor_user_id, day_of_week, start_time, end_time')
            ->where('tenant_id', CurrentTenant::id())->where('is_active', 1)
            ->get()->getResultArray();

        $places = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $dow = (int) $day->format('w');
            $date = $day->format('Y-m-d');
            foreach ($windows as $w) {
                if ((int) $w['day_of_week'] !== $dow) continue;
                $counsellor = (int) $w['counsellor_user_id'];
                $cursor = new DateTimeImmutable($date.' '.$w['start_time'], new DateTimeZone(self::LOCAL_TZ));
                $finish = new DateTimeImmutable($date.' '.$w['end_time'], new DateTimeZone(self::LOCAL_TZ));
                while ($cursor->modify('+60 minutes') <= $finish) {
                    // One place per counsellor, not one per window.
                    $places[$date][$cursor->format('H:i')][$counsellor] = 1;
                    $cursor = $cursor->modify('+60 minutes');
                }
            }
        }

        return $this->pooledSlots('counselling', $places, $this->guidanceBookedInstants($from, $to));
    }

    /**
     * Collapse per-staff coverage into one row per time, carrying the places
     * still free. `remaining` is capacity minus what the department already
     * holds, and only times with at least one place left are emitted.
     *
     * @param array<string,array<string,array<int,int>>> $places date => 'H:i' => staffId => places
     * @param list<int> $booked UTC start instants already held in the department
     * @return list<array<string,mixed>>
     */
    private function pooledSlots(string $department, array $places, array $booked): array
    {
        $out = [];
        foreach ($places as $date => $times) {
            foreach ($times as $time => $perStaff) {
                $utc = (new DateTimeImmutable($date.' '.$time.':00', new DateTimeZone(self::LOCAL_TZ)))
                    ->setTimezone(new DateTimeZone('UTC'));
                $remaining = array_sum($perStaff) - $this->overlapping($booked, $utc->getTimestamp());
                if ($remaining > 0) $out[] = $this->slot($department, $utc, $remaining);
            }
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));

        return $out;
    }

    /**
     * Appointments whose 60-minute window overlaps the one starting at $at.
     *
     * Unassigned bookings count. The patient holds that time even though no
     * staff member owns it yet, so it must not be offered to anyone else —
     * counting only assigned appointments would double-sell every slot that
     * is still waiting for approval.
     *
     * @param list<int> $booked
     */
    private function overlapping(array $booked, int $at): int
    {
        $n = 0;
        foreach ($booked as $ts) if (abs($ts - $at) < 3600) $n++;
        return $n;
    }

    /**
     * A staff member is off when any active `leave` schedule for that weekday
     * covers the date.
     *
     * @param list<array<string,mixed>> $leave
     */
    private function onLeave(array $leave, int $userId, int $dow, string $date): bool
    {
        foreach ($leave as $l) {
            if ((int) $l['user_id'] !== $userId || (int) $l['day_of_week'] !== $dow) continue;
            if ($l['effective_from'] && $date < $l['effective_from']) continue;
            if ($l['effective_to'] && $date > $l['effective_to']) continue;
            return true;
        }
        return false;
    }

    /** @return list<int> */
    private function clinicBookedInstants(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // Padded by a day either side: a late appointment on the previous day
        // still overlaps the first slot of the range.
        $rows = $this->db->table('clinic_appointments')
            ->select('scheduled_at')
            ->where('tenant_id', CurrentTenant::id())
            ->whereIn('status', ['scheduled','confirmed','checked_in'])
            ->where('archived_at', null)
            ->where('scheduled_at >=', $this->localBoundaryUtc($from->modify('-1 day')->format('Y-m-d'), false))
            ->where('scheduled_at <=', $this->localBoundaryUtc($to->modify('+1 day')->format('Y-m-d'), true))
            ->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) $out[] = (new DateTimeImmutable((string) $r['scheduled_at'], new DateTimeZone('UTC')))->getTimestamp();
        return $out;
    }

    /** @return list<int> */
    private function guidanceBookedInstants(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->db->table('counselling_appointments')
            ->select('appointment_date, start_time')
            ->where('tenant_id', CurrentTenant::id())
            ->whereIn('status', ['scheduled','confirmed'])
            ->where('appointment_date >=', $from->modify('-1 day')->format('Y-m-d'))
            ->where('appointment_date <=', $to->modify('+1 day')->format('Y-m-d'))
            ->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $out[] = (new DateTimeImmutable((string) $r['appointment_date'].' '.(string) $r['start_time'], new DateTimeZone(self::LOCAL_TZ)))
                ->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        }
        return $out;
    }

    /**
     * The read-only check in book() cannot guard the race on its own now that
     * capacity is pooled: with no named provider there is no per-provider
     * clash to reject a second booking. Lock the schedules that define the
     * capacity, then re-derive the day inside the transaction, so the last
     * place cannot be sold twice.
     */
    private function assertClinicPlace(DateTimeImmutable $startUtc): void
    {
        $this->db->query(
            'SELECT `id` FROM `clinic_staff_schedules` WHERE `tenant_id` = ? AND `is_active` = 1 FOR UPDATE',
            [CurrentTenant::id()],
        )->getResultArray();
        $local = $startUtc->setTimezone(new DateTimeZone(self::LOCAL_TZ));
        $available = $this->clinicSlots($local, $local);
        if (! array_filter($available, static fn(array $s): bool => $s['starts_at'] === $startUtc->format(DATE_ATOM))) {
            throw new ApiException('statemachine.schedule.slot_full', 409, [[
                'code'=>'statemachine.schedule.slot_full','message'=>'That slot was just taken. Choose another time.',
            ]]);
        }
    }

    /**
     * Guidance capacity is the number of **distinct counsellors** covering the
     * instant, not a sum of per-window figures. Locking those windows and
     * counting the department's live appointments is what stops the last place
     * being sold twice now that no counsellor is named at booking.
     *
     * Counting distinct counsellors rather than rows is also what makes this
     * agree with the slot list: one counsellor holding two overlapping windows
     * is one place in both places, where the two used to disagree.
     *
     * @return array<string,mixed>
     */
    private function bookGuidance(int $userId, DateTimeImmutable $local, string $type, ?string $reason): array
    {
        return $this->txn(function () use ($userId, $local, $type, $reason): array {
            $utc = $local->setTimezone(new DateTimeZone('UTC'));
            $this->assertPatientFree($userId, $utc);
            $user = $this->db->table('users')->select('student_number,employee_number')->where('users.tenant_id', CurrentTenant::id())->where('id',$userId)->get()->getRowArray();
            if($user===null)throw new ApiException('resource.not_found',404,[['code'=>'resource.not_found','message'=>'Patient not found.']]);
            $school=(string)($user['student_number']?:$user['employee_number']); $end=$local->modify('+60 minutes'); $now=gmdate('Y-m-d H:i:s');
            $windows=$this->db->query('SELECT `counsellor_user_id` FROM `counselling_availability` WHERE `tenant_id`=? AND `day_of_week`=? AND `is_active`=1 AND `start_time`<=? AND `end_time`>=? FOR UPDATE',[CurrentTenant::id(),(int)$local->format('w'),$local->format('H:i:s'),$end->format('H:i:s')])->getResultArray();
            // Distinct counsellors: two overlapping windows held by one person
            // are one place, matching what the portal offered.
            $capacity=count(array_unique(array_map(static fn(array $w):int=>(int)$w['counsellor_user_id'],$windows)));
            if($capacity===0)throw new ApiException('statemachine.schedule.outside_availability',409,[['code'=>'statemachine.schedule.outside_availability','message'=>'No provider is available for that slot.']]);
            $count=$this->db->query('SELECT COUNT(*) AS n FROM `counselling_appointments` WHERE `tenant_id`=? AND `appointment_date`=? AND `status` IN (?,?) AND NOT (?<=`start_time` OR ?>=`end_time`) FOR UPDATE',[CurrentTenant::id(),$local->format('Y-m-d'),'scheduled','confirmed',$end->format('H:i:s'),$local->format('H:i:s')])->getRowArray();
            if((int)($count['n']??0)>=$capacity)throw new ApiException('statemachine.schedule.slot_full',409,[['code'=>'statemachine.schedule.slot_full','message'=>'That slot was just booked. Choose another time.']]);
            $this->db->table('counselling_appointments')->insert(['tenant_id'=>CurrentTenant::id(),'patient_user_id'=>$userId,'patient_school_id'=>$school,'counsellor_user_id'=>null,'appointment_date'=>$local->format('Y-m-d'),'start_time'=>$local->format('H:i:s'),'end_time'=>$end->format('H:i:s'),'type'=>$type,'status'=>'scheduled','reason'=>$reason?:null,'created_by_user_id'=>$userId,'created_at'=>$now,'updated_at'=>$now]);
            $id=(int)$this->db->insertID(); $utc=$local->setTimezone(new DateTimeZone('UTC'));
            // Only the patient is notified: nobody owns this appointment yet.
            // Approval notifies whoever picks it up.
            $this->notifyAppointment($userId,'counselling',$id,$utc,'scheduled');
            $row=$this->db->table('counselling_appointments')->where('counselling_appointments.tenant_id', CurrentTenant::id())->where('id',$id)->get()->getRowArray(); return $this->guidanceRow($row);
        });
    }

    private function guidanceQueue(): GuidanceQueueService
    { return new GuidanceQueueService(new CounsellingPolicy(), \Config\Services::auditOutbox(), $this->notify); }
    private function parseUtc(string $value): DateTimeImmutable
    { try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC')); } catch(\Throwable){ throw ApiException::validationFailure([['code'=>'validation.field','message'=>'starts_at must be an ISO-8601 timestamp.','field'=>'starts_at']]); } }
    private function localBoundaryUtc(string $date,bool $end):string
    { return (new DateTimeImmutable($date.($end?' 23:59:59':' 00:00:00'),new DateTimeZone(self::LOCAL_TZ)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    /**
     * A bookable time. It names no provider: `remaining` is how many places
     * the department still has at that instant, pooled across all staff.
     *
     * @return array<string,mixed>
     */
    private function slot(string $department, DateTimeImmutable $utc, int $remaining): array
    {
        return [
            'department'       => $department,
            'starts_at'        => $utc->format(DATE_ATOM),
            'ends_at'          => $utc->modify('+60 minutes')->format(DATE_ATOM),
            'duration_minutes' => 60,
            'remaining'        => $remaining,
        ];
    }

    /**
     * `provider_user_id` is null until someone approves the appointment. It is
     * passed through as null rather than cast, because `(int) null` is 0 and 0
     * is a user id that does not exist — a client reading it would render
     * "Provider #0" instead of "not yet assigned".
     *
     * @return array<string,mixed>
     */
    private function clinicRow(array $r): array
    {
        $utc = new DateTimeImmutable((string) $r['scheduled_at'], new DateTimeZone('UTC'));
        return ['department'=>'clinic','id'=>(int)$r['id'],'provider_user_id'=>($r['provider_user_id'] ?? null) !== null ? (int) $r['provider_user_id'] : null,'starts_at'=>$utc->format(DATE_ATOM),'ends_at'=>$utc->modify('+60 minutes')->format(DATE_ATOM),'status'=>(string)$r['status'],'reason'=>$r['reason']??null,'type'=>null,'queue_entry_id'=>null];
    }

    /** @return array<string,mixed> */
    private function guidanceRow(array $r): array
    {
        $local = new DateTimeImmutable((string) $r['appointment_date'].' '.(string) $r['start_time'], new DateTimeZone(self::LOCAL_TZ));
        $end = new DateTimeImmutable((string) $r['appointment_date'].' '.(string) $r['end_time'], new DateTimeZone(self::LOCAL_TZ));
        return ['department'=>'counselling','id'=>(int)$r['id'],'provider_user_id'=>($r['counsellor_user_id'] ?? null) !== null ? (int) $r['counsellor_user_id'] : null,'starts_at'=>$local->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),'ends_at'=>$end->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),'status'=>(string)$r['status'],'reason'=>$r['reason']??null,'type'=>$r['type']??null,'queue_entry_id'=>null];
    }
    /** @return array<int,string> */
    private function providerNames(array $ids):array
    { if(!$ids)return[];$out=[];foreach($this->db->table('users')->select('id,first_name,last_name,username')->where('users.tenant_id', CurrentTenant::id())->whereIn('id',$ids)->get()->getResultArray()as$r)$out[(int)$r['id']]=trim($r['first_name'].' '.$r['last_name'])?:$r['username'];return$out; }
    private function notifyAppointment(int $recipient,string $department,int $id,DateTimeImmutable $start,string $status):void
    { $this->notify->enqueue($recipient,'appointment.'.$status,['resource_code'=>'appointment#'.$id,'destination'=>$department,'appointment_at'=>$start->format(DATE_ATOM),'appointment_status'=>$status]); }
    private function assertPatientFree(int $userId,DateTimeImmutable $start):void
    {
        $lower=$start->modify('-59 minutes')->format('Y-m-d H:i:s');$upper=$start->modify('+59 minutes')->format('Y-m-d H:i:s');
        $clinic=$this->db->table('clinic_appointments')->where('clinic_appointments.tenant_id', CurrentTenant::id())->where('patient_user_id',$userId)->whereIn('status',['scheduled','checked_in'])->where('scheduled_at >=',$lower)->where('scheduled_at <=',$upper)->where('archived_at',null)->countAllResults();
        $local=$start->setTimezone(new DateTimeZone(self::LOCAL_TZ));
        $guidance=$this->db->table('counselling_appointments')->where('counselling_appointments.tenant_id', CurrentTenant::id())->where('patient_user_id',$userId)->whereIn('status',['scheduled','confirmed'])->where('appointment_date',$local->format('Y-m-d'))->where('start_time <',$local->modify('+60 minutes')->format('H:i:s'))->where('end_time >',$local->format('H:i:s'))->countAllResults();
        if($clinic+$guidance>0)throw new ApiException('validation.clash',409,[['code'=>'validation.clash','message'=>'You already have an overlapping appointment.','field'=>'starts_at']]);
    }
}
