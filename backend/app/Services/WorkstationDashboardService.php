<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\ManilaDay;
use App\Services\Rbac\PermissionService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/** Read-only, request-scoped workstation projections. Never selects clinical notes. */
final class WorkstationDashboardService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly PermissionService $permissions,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function dashboard(string $unit, int $actorId): array
    {
        $permission = match ($unit) {
            'clinic' => 'clinic.encounters.read',
            'guidance' => 'counselling.records.read',
            'facilities' => 'facilities.units.read',
            default => throw new \InvalidArgumentException('Unknown workstation'),
        };
        if (! $this->permissions->userHas($actorId, $permission)) {
            throw ApiException::forbidden('rbac.permission_denied:' . $permission);
        }
        $now = $this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $day = $now->setTimezone(new DateTimeZone(ManilaDay::TZ))->format('Y-m-d');
        $own = $unit === 'guidance' && ! $this->permissions->userHas($actorId, 'counselling.records.read_any');
        $data = match ($unit) {
            'clinic' => $this->clinic($actorId, $day),
            'guidance' => $this->guidance($actorId, $day, $own),
            'facilities' => $this->facilities($actorId, $day),
        };
        return ['unit' => $unit, 'day' => $day,
            'generated_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'scope' => $own ? 'own' : 'tenant', ...$data];
    }

    private function live(string $table, string $alias): BaseBuilder
    {
        return $this->db->table($table . ' ' . $alias)
            ->where($alias . '.tenant_id', CurrentTenant::id())
            ->where($alias . '.archived_at', null);
    }

    private function encounters(): BaseBuilder
    {
        return $this->live('clinic_encounters', 'e');
    }

    private function sessions(int $actorId, bool $own): BaseBuilder
    {
        $q = $this->live('counselling_sessions', 's');
        return $own ? $q->where('s.counsellor_user_id', $actorId) : $q;
    }

    private function batches(): BaseBuilder
    {
        return $this->live('facilities_bmg_batches', 'b')
            ->join('facilities_bmg_units u', 'u.id = b.unit_id AND u.tenant_id = b.tenant_id AND u.archived_at IS NULL')
            ->where('u.tenant_id', CurrentTenant::id());
    }

    private function within(BaseBuilder $q, string $column, string $day): BaseBuilder
    {
        $bounds = ManilaDay::dayBoundsUtcSql($day);
        return $q->where($column . ' >=', $bounds['start'])->where($column . ' <', $bounds['end']);
    }

    private function metric(string $key, string $label, int|float $value, string $description, ?string $unit = null): array
    {
        return compact('key', 'label', 'value', 'unit', 'description');
    }

    private function clinic(int $actorId, string $day): array
    {
        $metrics = [];
        if ($this->permissions->userHas($actorId, 'clinic.queue.read')) {
            $n = $this->db->table('clinic_queue_entries q')->where('q.tenant_id', CurrentTenant::id())
                ->join('clinic_encounters e', 'e.id = q.encounter_id AND e.tenant_id = q.tenant_id AND e.archived_at IS NULL')
                ->where('e.tenant_id', CurrentTenant::id())->where('q.queue_date', $day)
                ->whereIn('q.status', ['waiting', 'called'])->countAllResults();
            $metrics[] = $this->metric('waiting_queue', 'Waiting / called today', $n, 'Today’s Manila queue; waiting and called entries only.');
        }
        $metrics[] = $this->metric('completed_consultations', 'Completed consultations today',
            $this->within($this->encounters(), 'e.closed_at', $day)->where('e.status', 'closed')->where('e.outcome', null)->countAllResults(),
            'Staff-completed encounters today; excludes no-shows and automatic closures.');
        if ($this->permissions->userHas($actorId, 'clinic.appointments.read')) {
            $metrics[] = $this->metric('appointments_today', 'Appointments today',
                $this->within($this->live('clinic_appointments', 'a'), 'a.scheduled_at', $day)->countAllResults(),
                'Nonarchived appointments scheduled for today, in all persisted states.');
        }
        if ($this->permissions->userHas($actorId, 'clinic.inventory.read')) {
            $metrics[] = $this->metric('active_medicines', 'Active medicine catalog',
                $this->live('clinic_medicines', 'm')->countAllResults(), 'Nonarchived medicine catalog entries, not stock quantities.');
        }
        $items = $this->encounters()->select('e.triage_priority AS category, COUNT(*) AS total', false)
            ->where('e.triage_priority !=', null)->groupBy('e.triage_priority')->orderBy('e.triage_priority')->get()->getResultArray();
        return ['metrics' => $metrics,
            'trend' => $this->trend($this->encounters(), 'e.started_at', $day, 'Encounter starts'),
            'breakdown' => $this->breakdown('Triage priorities', 'Nonarchived encounters with a recorded triage priority; unassessed encounters excluded.', $items),
            'recent' => $this->clinicalRecent($this->encounters(), 'e', 'encounters', 'attending_user_id', 'started_at')];
    }

    private function guidance(int $actorId, string $day, bool $own): array
    {
        $metrics = [
            $this->metric('open_sessions', 'Open sessions', $this->sessions($actorId, $own)->where('s.ended_at', null)->countAllResults(), 'Nonarchived sessions without an end time, in the session scope.'),
            $this->metric('completed_sessions', 'Completed sessions today', $this->within($this->sessions($actorId, $own), 's.ended_at', $day)->countAllResults(), 'Sessions ended today in Manila, in the session scope.'),
        ];
        if ($this->permissions->userHas($actorId, 'counselling.queue.read')) {
            // The existing guidance day board is tenant-wide, including unassigned walk-ins.
            $n = $this->db->table('counselling_queue_entries q')->where('q.tenant_id', CurrentTenant::id())
                ->where('q.queue_date', $day)->where('q.status', 'waiting')->countAllResults();
            $metrics[] = $this->metric('waiting_queue', 'Waiting today', $n, 'Tenant day board: today’s waiting entries, matching guidance queue visibility.');
        }
        if ($this->permissions->userHas($actorId, 'referrals.read')) {
            $n = $this->live('referral_referrals', 'r')->where('r.target_module', 'counselling')
                ->whereIn('r.status', ['submitted', 'acknowledged', 'under_review'])->countAllResults();
            $metrics[] = $this->metric('incoming_referrals', 'Pending incoming referrals', $n, 'Nonclosed incoming referrals to counselling; excludes outgoing referrals.');
        }
        $rows = $this->sessions($actorId, $own)->select("CASE WHEN s.ended_at IS NULL THEN 'open' ELSE 'closed' END AS category, COUNT(*) AS total", false)
            ->groupBy('category')->orderBy('category')->get()->getResultArray();
        return ['metrics' => $metrics,
            'trend' => $this->trend($this->sessions($actorId, $own), 's.started_at', $day, 'Session starts'),
            'breakdown' => $this->breakdown('Session states', 'Nonarchived open and closed sessions in the session scope.', $rows),
            'recent' => $this->clinicalRecent($this->sessions($actorId, $own), 's', 'sessions', 'counsellor_user_id', 'started_at')];
    }

    private function facilities(int $actorId, string $day): array
    {
        // Unit list / analytics / device list all use facilities.units.read in BmgPolicy.
        $metrics = [
            $this->metric('total_units', 'Drums', $this->live('facilities_bmg_units', 'u')->countAllResults(), 'Nonarchived BMG units.'),
            $this->metric('active_batches', 'Active batches', $this->batches()->whereIn('b.status', ['processing', 'awaiting_output'])->countAllResults(), 'Nonarchived processing / awaiting-output batches on nonarchived units.'),
        ];
        $devices = $this->live('facilities_bmg_devices', 'd')
            ->join('facilities_bmg_units u', 'u.id = d.unit_id AND u.tenant_id = d.tenant_id AND u.archived_at IS NULL')
            ->where('u.tenant_id', CurrentTenant::id())->countAllResults();
        $metrics[] = $this->metric('bound_devices', 'Bound devices', $devices, 'Nonarchived devices bound to nonarchived units; not an online or motor-state claim.');
        if ($this->permissions->userHas($actorId, 'facilities.bmg.io.record')) {
            $kg = $this->batches()->selectSum('b.total_input_weight_kg', 'kg')
                ->groupStart()->where('b.status', 'released')->orGroupStart()->where('b.status', 'idle')->where('b.finished_at !=', null)->groupEnd()->groupEnd()
                ->get()->getRowArray();
            $metrics[] = $this->metric('treated_kg', 'Treated input', (float) ($kg['kg'] ?? 0), 'All-time input weight of completed batches (released, or legacy finished idle), counted once per batch; not compost yield.', 'kg');
        }
        $items = $this->live('facilities_bmg_units', 'u')->select('u.status AS category, COUNT(*) AS total', false)
            ->groupBy('u.status')->orderBy('u.status')->get()->getResultArray();
        $rows = $this->live('facilities_bmg_units', 'u')->select('u.id, u.code, u.display_name, u.status, d.code AS device_code, d.display_name AS device_name, d.last_seen_at')
            ->join('facilities_bmg_devices d', 'd.unit_id = u.id AND d.tenant_id = u.tenant_id AND d.archived_at IS NULL', 'left')
            ->orderBy('u.updated_at', 'DESC')->orderBy('u.id', 'DESC')->limit(6)->get()->getResultArray();
        return ['metrics' => $metrics,
            'trend' => $this->trend($this->batches(), 'b.started_at', $day, 'Batch starts'),
            'breakdown' => $this->breakdown('Drum lifecycle states', 'Persisted unit states; not live motor telemetry.', $items),
            'recent' => ['kind' => 'drums', 'title' => 'Recently updated drums', 'rows' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'code' => (string) $r['code'], 'title' => (string) ($r['display_name'] ?: $r['code']),
                'subtitle' => $r['device_code'], 'status' => (string) $r['status'],
                'occurred_at' => $r['last_seen_at'], 'owner' => $r['device_name'],
            ], $rows)]];
    }

    private function clinicalRecent(BaseBuilder $q, string $alias, string $kind, string $ownerColumn, string $timeColumn): array
    {
        $status = $kind === 'sessions' ? "CASE WHEN {$alias}.ended_at IS NULL THEN 'open' ELSE 'closed' END" : $alias . '.status';
        $rows = $q->select("{$alias}.id, {$alias}.{$timeColumn} AS occurred_at, {$status} AS status, p.first_name, p.last_name, o.username AS owner", false)
            ->join('users p', "p.id = {$alias}.patient_user_id AND p.tenant_id = {$alias}.tenant_id AND p.archived_at IS NULL", 'left')
            ->join('users o', "o.id = {$alias}.{$ownerColumn} AND o.tenant_id = {$alias}.tenant_id AND o.archived_at IS NULL", 'left')
            ->orderBy($alias . '.' . $timeColumn, 'DESC')->orderBy($alias . '.id', 'DESC')->limit(6)->get()->getResultArray();
        return ['kind' => $kind, 'title' => 'Recent ' . $kind, 'rows' => array_map(static function (array $r): array {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            return ['id' => (int) $r['id'], 'code' => '#' . $r['id'], 'title' => $name !== '' ? $name : 'Patient',
                'subtitle' => null, 'status' => (string) $r['status'], 'occurred_at' => $r['occurred_at'], 'owner' => $r['owner']];
        }, $rows)];
    }

    private function trend(BaseBuilder $q, string $column, string $day, string $title): array
    {
        $last = new DateTimeImmutable($day, new DateTimeZone(ManilaDay::TZ));
        $first = $last->modify('-6 days');
        $start = ManilaDay::dayBoundsUtcSql($first->format('Y-m-d'))['start'];
        $end = ManilaDay::dayBoundsUtcSql($day)['end'];
        // Filter the indexed UTC column first; group into Manila days without DB timezone tables.
        $rows = $q->select("DATE(DATE_ADD({$column}, INTERVAL 8 HOUR)) AS day, COUNT(*) AS total", false)
            ->where($column . ' >=', $start)->where($column . ' <', $end)
            ->groupBy('day')->orderBy('day')->get()->getResultArray();
        $counts = array_column($rows, 'total', 'day');
        $points = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $first->modify('+' . $i . ' days')->format('Y-m-d');
            $points[] = ['day' => $date, 'value' => (int) ($counts[$date] ?? 0)];
        }
        return ['title' => $title, 'description' => 'Last seven Manila calendar days, including today; nonarchived records in scope.', 'points' => $points];
    }

    private function breakdown(string $title, string $description, array $rows): array
    {
        return ['title' => $title, 'description' => $description, 'items' => array_map(static fn (array $r): array => [
            'key' => (string) $r['category'], 'label' => ucfirst(str_replace('_', ' ', (string) $r['category'])), 'value' => (int) $r['total'],
        ], $rows)];
    }
}
