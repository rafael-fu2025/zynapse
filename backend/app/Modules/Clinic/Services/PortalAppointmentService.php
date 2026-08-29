<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
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
            $b = $this->db->table('clinic_appointments')->where('patient_user_id', $userId)->where('archived_at', null);
            if ($status) $b->where('status', $status);
            if ($from) $b->where('scheduled_at >=', $this->localBoundaryUtc($from, false));
            if ($to) $b->where('scheduled_at <=', $this->localBoundaryUtc($to, true));
            foreach ($b->get()->getResultArray() as $row) $out[] = $this->clinicRow($row);
        }
        if ($department === null || in_array($department, ['all', 'counselling'], true)) {
            $b = $this->db->table('counselling_appointments')->where('patient_user_id', $userId);
            if ($status) $b->where('status', $status);
            if ($from) $b->where('appointment_date >=', $from);
            if ($to) $b->where('appointment_date <=', $to);
            foreach ($b->get()->getResultArray() as $row) $out[] = $this->guidanceRow($row);
        }
        $names = $this->providerNames(array_values(array_unique(array_column($out, 'provider_user_id'))));
        foreach ($out as &$row) $row['provider_name'] = $names[$row['provider_user_id']] ?? null;
        unset($row);
        usort($out, static fn(array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function slots(string $department, string $from, string $to, ?int $providerId): array
    {
        $start = new DateTimeImmutable($from, new DateTimeZone(self::LOCAL_TZ));
        $end = new DateTimeImmutable($to, new DateTimeZone(self::LOCAL_TZ));
        $now = new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TZ));
        if ($start > $end || $end > $now->modify('+90 days')) throw ApiException::validationFailure([[
            'code'=>'validation.range','message'=>'Choose a valid range within the 90-day booking horizon.','field'=>'to',
        ]]);
        $slots = $department === 'clinic'
            ? $this->clinicSlots($start, $end, $providerId)
            : $this->guidanceSlots($start, $end, $providerId);
        return array_values(array_filter($slots, static fn(array $slot): bool =>
            new DateTimeImmutable((string) $slot['starts_at']) >= (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 hour')
        ));
    }

    /** @return array<string,mixed> */
    public function book(int $userId, array $input): array
    {
        $department = (string) $input['department'];
        $provider = (int) $input['provider_user_id'];
        $startUtc = $this->parseUtc((string) $input['starts_at']);
        $this->assertPatientFree($userId, $startUtc);
        $startLocal = $startUtc->setTimezone(new DateTimeZone(self::LOCAL_TZ));
        $available = $this->slots($department, $startLocal->format('Y-m-d'), $startLocal->format('Y-m-d'), $provider);
        if (! array_filter($available, static fn(array $s): bool => (int) $s['provider_user_id'] === $provider && $s['starts_at'] === $startUtc->format(DATE_ATOM))) {
            throw new ApiException('statemachine.schedule.slot_full', 409, [[
                'code'=>'statemachine.schedule.slot_full','message'=>'That appointment slot is no longer available.',
            ]]);
        }
        if ($department === 'clinic') {
            return $this->clinicRow($this->clinicAppointments->bookSelf(
                $userId, $provider, $startUtc->format('Y-m-d H:i:s'), $input['reason'] ?? null,
            )->toArray());
        }
        return $this->bookGuidance($userId, $provider, $startLocal, (string) ($input['type'] ?? 'initial'), $input['reason'] ?? null);
    }

    /** @return array<string,mixed> */
    public function cancel(int $userId, string $department, int $id): array
    {
        return $this->txn(function () use ($userId, $department, $id): array {
            $table = $department === 'clinic' ? 'clinic_appointments' : 'counselling_appointments';
            $row = $this->selectForUpdate($table, ['id' => $id, 'patient_user_id' => $userId]);
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
            $this->db->table($table)->where('id', $id)->update($update);
            $provider = (int) ($row[$department === 'clinic' ? 'provider_user_id' : 'counsellor_user_id']);
            foreach (array_unique([$userId, $provider]) as $recipient) $this->notifyAppointment($recipient, $department, $id, $start, 'cancelled');
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

    /** @return list<array<string,mixed>> */
    private function clinicSlots(DateTimeImmutable $from, DateTimeImmutable $to, ?int $provider): array
    {
        $b = $this->db->table('clinic_staff_schedules s')->select('s.*, u.first_name, u.last_name')
            ->join('users u', 'u.id=s.user_id')->where('s.is_active', 1)->whereIn('s.schedule_type', ['regular','on_call']);
        if ($provider) $b->where('s.user_id', $provider);
        $schedules = $b->get()->getResultArray(); $out=[];
        for ($day=$from; $day <= $to; $day=$day->modify('+1 day')) foreach ($schedules as $s) {
            if ((int)$s['day_of_week'] !== (int)$day->format('w')) continue;
            if ($s['effective_from'] && $day->format('Y-m-d') < $s['effective_from']) continue;
            if ($s['effective_to'] && $day->format('Y-m-d') > $s['effective_to']) continue;
            $leaveBuilder = $this->db->table('clinic_staff_schedules')->where('user_id',(int)$s['user_id'])->where('is_active',1)->where('schedule_type','leave')->where('day_of_week',(int)$day->format('w'))
                ->groupStart()->where('effective_from',null)->orWhere('effective_from <=',$day->format('Y-m-d'))->groupEnd()
                ->groupStart()->where('effective_to',null)->orWhere('effective_to >=',$day->format('Y-m-d'))->groupEnd();
            $leave = $leaveBuilder->countAllResults();
            if ($leave > 0) continue;
            $cursor = new DateTimeImmutable($day->format('Y-m-d').' '.$s['shift_start'], new DateTimeZone(self::LOCAL_TZ));
            $finish = new DateTimeImmutable($day->format('Y-m-d').' '.$s['shift_end'], new DateTimeZone(self::LOCAL_TZ));
            while ($cursor->modify('+60 minutes') <= $finish) {
                $utc=$cursor->setTimezone(new DateTimeZone('UTC'));
                $clash=$this->db->table('clinic_appointments')->where('provider_user_id',(int)$s['user_id'])->whereIn('status',['scheduled','checked_in'])->where('scheduled_at >',$utc->modify('-60 minutes')->format('Y-m-d H:i:s'))->where('scheduled_at <',$utc->modify('+60 minutes')->format('Y-m-d H:i:s'))->where('archived_at',null)->countAllResults();
                if (!$clash) $out[]=$this->slot('clinic',(int)$s['user_id'],$utc,trim($s['first_name'].' '.$s['last_name']));
                $cursor=$cursor->modify('+60 minutes');
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function guidanceSlots(DateTimeImmutable $from, DateTimeImmutable $to, ?int $provider): array
    {
        $b=$this->db->table('counselling_availability a')->select('a.*,u.first_name,u.last_name')->join('users u','u.id=a.counsellor_user_id')->where('a.is_active',1);
        if ($provider) $b->where('a.counsellor_user_id',$provider);
        $windows=$b->get()->getResultArray(); $out=[];
        for($day=$from;$day<=$to;$day=$day->modify('+1 day')) foreach($windows as $w){
            if((int)$w['day_of_week']!==(int)$day->format('w'))continue;
            $cursor=new DateTimeImmutable($day->format('Y-m-d').' '.$w['start_time'],new DateTimeZone(self::LOCAL_TZ));
            $finish=new DateTimeImmutable($day->format('Y-m-d').' '.$w['end_time'],new DateTimeZone(self::LOCAL_TZ));
            while($cursor->modify('+60 minutes')<=$finish){
                $end=$cursor->modify('+60 minutes');
                $count=$this->db->table('counselling_appointments')->where('counsellor_user_id',(int)$w['counsellor_user_id'])->where('appointment_date',$day->format('Y-m-d'))->whereIn('status',['scheduled','confirmed'])->where('start_time <',$end->format('H:i:s'))->where('end_time >',$cursor->format('H:i:s'))->countAllResults();
                if($count<(int)$w['max_slots'])$out[]=$this->slot('counselling',(int)$w['counsellor_user_id'],$cursor->setTimezone(new DateTimeZone('UTC')),trim($w['first_name'].' '.$w['last_name']));
                $cursor=$end;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function bookGuidance(int $userId,int $provider,DateTimeImmutable $local,string $type,?string $reason): array
    {
        return $this->txn(function()use($userId,$provider,$local,$type,$reason):array{
            $this->assertPatientFree($userId,$local->setTimezone(new DateTimeZone('UTC')));
            $user=$this->db->table('users')->select('student_number,employee_number')->where('id',$userId)->get()->getRowArray();
            if($user===null)throw new ApiException('resource.not_found',404,[['code'=>'resource.not_found','message'=>'Patient not found.']]);
            $school=(string)($user['student_number']?:$user['employee_number']); $end=$local->modify('+60 minutes'); $now=gmdate('Y-m-d H:i:s');
            $window=$this->db->query('SELECT `id`,`max_slots` FROM `counselling_availability` WHERE `counsellor_user_id`=? AND `day_of_week`=? AND `is_active`=1 AND `start_time`<=? AND `end_time`>=? LIMIT 1 FOR UPDATE',[$provider,(int)$local->format('w'),$local->format('H:i:s'),$end->format('H:i:s')])->getRowArray();
            if($window===null)throw new ApiException('statemachine.schedule.outside_availability',409,[['code'=>'statemachine.schedule.outside_availability','message'=>'The provider is no longer available for that slot.']]);
            $count=$this->db->query('SELECT COUNT(*) AS n FROM `counselling_appointments` WHERE `counsellor_user_id`=? AND `appointment_date`=? AND `status` IN (?,?) AND NOT (?<=`start_time` OR ?>=`end_time`) FOR UPDATE',[$provider,$local->format('Y-m-d'),'scheduled','confirmed',$end->format('H:i:s'),$local->format('H:i:s')])->getRowArray();
            if((int)($count['n']??0)>=(int)$window['max_slots'])throw new ApiException('statemachine.schedule.slot_full',409,[['code'=>'statemachine.schedule.slot_full','message'=>'That slot was just booked. Choose another time.']]);
            $this->db->table('counselling_appointments')->insert(['patient_user_id'=>$userId,'patient_school_id'=>$school,'counsellor_user_id'=>$provider,'appointment_date'=>$local->format('Y-m-d'),'start_time'=>$local->format('H:i:s'),'end_time'=>$end->format('H:i:s'),'type'=>$type,'status'=>'scheduled','reason'=>$reason?:null,'created_by_user_id'=>$userId,'created_at'=>$now,'updated_at'=>$now]);
            $id=(int)$this->db->insertID(); $utc=$local->setTimezone(new DateTimeZone('UTC'));
            foreach(array_unique([$userId,$provider])as$recipient)$this->notifyAppointment($recipient,'counselling',$id,$utc,'scheduled');
            $row=$this->db->table('counselling_appointments')->where('id',$id)->get()->getRowArray(); return $this->guidanceRow($row);
        });
    }

    private function guidanceQueue(): GuidanceQueueService
    { return new GuidanceQueueService(new CounsellingPolicy(), \Config\Services::auditOutbox(), $this->notify); }
    private function parseUtc(string $value): DateTimeImmutable
    { try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC')); } catch(\Throwable){ throw ApiException::validationFailure([['code'=>'validation.field','message'=>'starts_at must be an ISO-8601 timestamp.','field'=>'starts_at']]); } }
    private function localBoundaryUtc(string $date,bool $end):string
    { return (new DateTimeImmutable($date.($end?' 23:59:59':' 00:00:00'),new DateTimeZone(self::LOCAL_TZ)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
    /** @return array<string,mixed> */
    private function slot(string $department,int $provider,DateTimeImmutable $utc,string $name):array
    { return ['department'=>$department,'provider_user_id'=>$provider,'provider_name'=>$name,'starts_at'=>$utc->format(DATE_ATOM),'ends_at'=>$utc->modify('+60 minutes')->format(DATE_ATOM),'duration_minutes'=>60]; }
    /** @return array<string,mixed> */
    private function clinicRow(array $r):array
    { $utc=new DateTimeImmutable((string)$r['scheduled_at'],new DateTimeZone('UTC')); return ['department'=>'clinic','id'=>(int)$r['id'],'provider_user_id'=>(int)$r['provider_user_id'],'starts_at'=>$utc->format(DATE_ATOM),'ends_at'=>$utc->modify('+60 minutes')->format(DATE_ATOM),'status'=>(string)$r['status'],'reason'=>$r['reason']??null,'type'=>null,'queue_entry_id'=>null]; }
    /** @return array<string,mixed> */
    private function guidanceRow(array $r):array
    { $local=new DateTimeImmutable((string)$r['appointment_date'].' '.(string)$r['start_time'],new DateTimeZone(self::LOCAL_TZ)); $end=new DateTimeImmutable((string)$r['appointment_date'].' '.(string)$r['end_time'],new DateTimeZone(self::LOCAL_TZ)); return ['department'=>'counselling','id'=>(int)$r['id'],'provider_user_id'=>(int)$r['counsellor_user_id'],'starts_at'=>$local->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),'ends_at'=>$end->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),'status'=>(string)$r['status'],'reason'=>$r['reason']??null,'type'=>$r['type']??null,'queue_entry_id'=>null]; }
    /** @return array<int,string> */
    private function providerNames(array $ids):array
    { if(!$ids)return[];$out=[];foreach($this->db->table('users')->select('id,first_name,last_name,username')->whereIn('id',$ids)->get()->getResultArray()as$r)$out[(int)$r['id']]=trim($r['first_name'].' '.$r['last_name'])?:$r['username'];return$out; }
    private function notifyAppointment(int $recipient,string $department,int $id,DateTimeImmutable $start,string $status):void
    { $this->notify->enqueue($recipient,'appointment.'.$status,['resource_code'=>'appointment#'.$id,'destination'=>$department,'appointment_at'=>$start->format(DATE_ATOM),'appointment_status'=>$status]); }
    private function assertPatientFree(int $userId,DateTimeImmutable $start):void
    {
        $lower=$start->modify('-59 minutes')->format('Y-m-d H:i:s');$upper=$start->modify('+59 minutes')->format('Y-m-d H:i:s');
        $clinic=$this->db->table('clinic_appointments')->where('patient_user_id',$userId)->whereIn('status',['scheduled','checked_in'])->where('scheduled_at >=',$lower)->where('scheduled_at <=',$upper)->where('archived_at',null)->countAllResults();
        $local=$start->setTimezone(new DateTimeZone(self::LOCAL_TZ));
        $guidance=$this->db->table('counselling_appointments')->where('patient_user_id',$userId)->whereIn('status',['scheduled','confirmed'])->where('appointment_date',$local->format('Y-m-d'))->where('start_time <',$local->modify('+60 minutes')->format('H:i:s'))->where('end_time >',$local->format('H:i:s'))->countAllResults();
        if($clinic+$guidance>0)throw new ApiException('validation.clash',409,[['code'=>'validation.clash','message'=>'You already have an overlapping appointment.','field'=>'starts_at']]);
    }
}
