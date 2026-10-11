<?php

declare(strict_types=1);

namespace Modules\Facilities\Services\Bmg;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\ManilaDay;
use App\Services\CurrentTenant;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Facilities\Policies\BmgPolicy;

/**
 * MonitorService — the per-unit monitoring read behind the Facilities
 * dashboard (`GET facilities/units/{id}/monitor`).
 *
 * One aggregate payload: device liveness (heartbeat age against the
 * offline threshold), RTC health + measured drift, the unit's current
 * schedule with the next scheduled run, the last 30 reported runs with
 * failed/missed breakdowns, the active batch, void-aware manual I/O
 * totals, and open alerts. Each piece is cheap; the whole thing stays
 * a handful of indexed queries — this is the endpoint a dashboard can
 * poll every 30 s.
 *
 * The slot math (online, missed runs) is exposed as pure static
 * helpers for unit tests — Manila-day aware per AGENTS.md.
 */
final class MonitorService extends BaseService
{
    private const RUNS_LIMIT = 30;

    /**
     * How long after a schedule is published before the board can be
     * expected to act on it: it pulls `/schedule` about every 10 minutes
     * and, on applying a version, deliberately skips slots already behind
     * it. A slot inside this window was never the board's to run.
     */
    public const SCHEDULE_SYNC_ALLOWANCE_MIN = 10;

    public function __construct(
        ?\CodeIgniter\Database\BaseConnection $db,
        private readonly BmgPolicy $policy,
        private readonly ScheduleService $schedules,
    ) {
        parent::__construct($db);
    }

    /**
     * @return array<string, mixed>
     */
    public function unitMonitor(int $unitId): array
    {
        $this->policy->check('list');

        $unit = $this->db->table('facilities_bmg_units')
            ->select('id, code, display_name, status, archived_at')
            ->where('id', $unitId)
            ->where('tenant_id', CurrentTenant::id())
            ->get()->getRowArray();
        if ($unit === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG unit #{$unitId} not found."],
            ]);
        }

        $device = $this->db->table('facilities_bmg_devices')
            ->select('id, code, display_name, status, firmware, last_seen_at, last_heartbeat_at, last_uptime_seconds, last_rssi_dbm, rtc_valid, rtc_offset_seconds, last_run_result, motor_state')
            ->where('tenant_id', CurrentTenant::id())
            ->where('unit_id', $unitId)
            ->where('archived_at', null)
            ->get()->getRowArray();

        $offlineAfterMin = max(1, (int) (getenv('BMG_OFFLINE_AFTER_MIN') ?: 20));
        // A drum with no bound board is a normal state (idle drum, board
        // pulled for replacement) — never dereference a null row here.
        $lastSeen = $device !== null
            ? ($device['last_heartbeat_at'] ?? $device['last_seen_at'])
            : null;
        $lastSeen = $lastSeen !== null ? (string) $lastSeen : null;
        $ageMin   = $lastSeen !== null ? self::ageMinutes($lastSeen) : null;
        $online   = $device !== null && self::isOnline($lastSeen, $offlineAfterMin);

        $devicePayload = null;
        if ($device !== null) {
            $devicePayload = [
                'id'                    => (int) $device['id'],
                'code'                  => (string) $device['code'],
                'display_name'          => (string) $device['display_name'],
                'status'                => (string) $device['status'],
                'firmware'              => $device['firmware'] !== null ? (string) $device['firmware'] : null,
                'online'                => $online,
                'offline_after_minutes' => $offlineAfterMin,
                'last_seen_at'          => $lastSeen,
                'last_heartbeat_at'     => $device['last_heartbeat_at'] !== null ? (string) $device['last_heartbeat_at'] : null,
                'heartbeat_age_minutes' => $ageMin,
                'last_rssi_dbm'         => $device['last_rssi_dbm'] !== null ? (int) $device['last_rssi_dbm'] : null,
                'last_uptime_seconds'   => $device['last_uptime_seconds'] !== null ? (int) $device['last_uptime_seconds'] : null,
                'rtc_valid'             => $device['rtc_valid'] === null ? null : (int) $device['rtc_valid'] === 1,
                'rtc_offset_seconds'    => $device['rtc_offset_seconds'] !== null ? (int) $device['rtc_offset_seconds'] : null,
                'last_run_result'       => $device['last_run_result'] !== null ? (string) $device['last_run_result'] : null,
                'motor_state'           => $device['motor_state'] !== null ? (string) $device['motor_state'] : null,
            ];
        }

        $schedule = $this->schedules->forUnit($unitId);

        $runs = $this->db->table('facilities_bmg_device_runs')
            ->where('tenant_id', CurrentTenant::id())
            ->where('unit_id', $unitId)
            ->orderBy('started_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(self::RUNS_LIMIT)
            ->get()->getResultArray();
        $recentRuns = array_map(static fn (array $r): array => [
            'id'               => (int) $r['id'],
            'run_uid'          => (string) $r['run_uid'],
            'origin'           => (string) $r['origin'],
            'result'           => (string) $r['result'],
            'started_at'       => $r['started_at'] !== null ? (string) $r['started_at'] : null,
            'ended_at'         => $r['ended_at'] !== null ? (string) $r['ended_at'] : null,
            'duration_seconds' => (int) $r['duration_seconds'],
            'turns_count'      => $r['turns_count'] !== null ? (int) $r['turns_count'] : null,
            'batch_id'         => $r['batch_id'] !== null ? (int) $r['batch_id'] : null,
            'created_at'       => (string) $r['created_at'],
        ], $runs);

        $scheduleRow = $this->scheduleTimes($unitId);
        $missedTimes = self::missedSlots(
            $scheduleRow['times'],
            array_map(static fn (array $r): ?string => $r['started_at'], $runs),
            (int) (getenv('BMG_MISSED_RUN_GRACE_MIN') ?: 30),
            null,
            self::slotsOwedFrom($scheduleRow['published_at']),
        );

        $batch = $this->db->table('facilities_bmg_batches')
            ->select('id, reference_code, status, started_at, total_input_weight_kg, output_weight_kg, started_by_user_id, tenant_id, archived_at')
            ->where('unit_id', $unitId)
            ->where('tenant_id', CurrentTenant::id())
            ->where('archived_at', null)
            ->whereIn('status', [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT])
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        $activeBatch = null;
        if ($batch !== null) {
            $activeBatch = [
                'id'                     => (int) $batch['id'],
                'reference_code'         => (string) $batch['reference_code'],
                'status'                 => (string) $batch['status'],
                'started_at'             => (string) $batch['started_at'],
                'total_input_weight_kg'  => (float) $batch['total_input_weight_kg'],
                'output_weight_kg'       => $batch['output_weight_kg'] !== null ? (float) $batch['output_weight_kg'] : null,
            ];
        }

        // Void-aware manual I/O totals for the unit's whole history.
        $totalsIn = $this->db->table('facilities_bmg_inputs AS i')
            ->selectSum('i.weight_kg', 't')
            ->join('facilities_bmg_batches AS b', 'b.id = i.batch_id', 'inner')
            ->where('i.tenant_id', CurrentTenant::id())
            ->where('b.unit_id', $unitId)
            ->where('b.archived_at', null)
            ->where('i.voided_at', null)
            ->get()->getRowArray();
        $totalsOut = $this->db->table('facilities_bmg_outputs AS o')
            ->selectSum('o.output_weight_kg', 't')
            ->join('facilities_bmg_batches AS b', 'b.id = o.batch_id', 'inner')
            ->where('o.tenant_id', CurrentTenant::id())
            ->where('b.unit_id', $unitId)
            ->where('b.archived_at', null)
            ->where('o.voided_at', null)
            ->get()->getRowArray();

        $openAlerts = [];
        if ($batch !== null) {
            $openAlerts = array_map(static fn (array $r): array => [
                'id'           => (int) $r['id'],
                'code'         => (string) $r['code'],
                'severity'     => (string) $r['severity'],
                'message'      => (string) $r['message'],
                'triggered_at' => (string) $r['triggered_at'],
            ], $this->db->table('facilities_bmg_alerts')
                ->where('tenant_id', CurrentTenant::id())
                ->where('batch_id', (int) $batch['id'])
                ->where('acknowledged_at', null)
                ->orderBy('triggered_at', 'DESC')
                ->limit(20)
                ->get()->getResultArray());
        }

        return [
            'unit' => [
                'id'           => (int) $unit['id'],
                'code'         => (string) $unit['code'],
                'display_name' => (string) $unit['display_name'],
                'status'       => (string) $unit['status'],
                'archived_at'  => $unit['archived_at'] !== null ? (string) $unit['archived_at'] : null,
            ],
            'device'       => $devicePayload,
            'schedule'     => [
                'times'            => $scheduleRow['times'],
                'duration_seconds' => $schedule['schedule']['duration_seconds'],
                'pwm'              => $schedule['schedule']['pwm'],
                'enabled'          => $schedule['schedule']['enabled'],
                'version'          => $schedule['schedule']['version'],
                'next_run_at'      => $schedule['next_run_at'],
            ],
            'runs' => [
                'recent'       => $recentRuns,
                'failed_count' => count(array_filter($recentRuns, static fn (array $r): bool => $r['result'] !== 'completed')),
                'missed_times' => $missedTimes,
                'missed_count' => count($missedTimes),
            ],
            'active_batch' => $activeBatch,
            'totals' => [
                'input_kg'  => round((float) ($totalsIn['t'] ?? 0), 2),
                'output_kg' => round((float) ($totalsOut['t'] ?? 0), 2),
            ],
            'open_alerts' => $openAlerts,
        ];
    }

    /**
     * The unit's schedule times (empty list when none / disabled or
     * missing row) — read directly so missed-slot math doesn't force a
     * lazy seed through the admin payload.
     *
     * @return array{times: list<string>, published_at: ?string}
     */
    private function scheduleTimes(int $unitId): array
    {
        $row = $this->db->table('facilities_bmg_unit_schedules')
            ->where('tenant_id', CurrentTenant::id())
            ->where('unit_id', $unitId)
            ->get()->getRowArray();
        if ($row === null || (int) $row['enabled'] !== 1) {
            return ['times' => [], 'published_at' => null];
        }
        $times = json_decode((string) $row['times'], true, 512, JSON_THROW_ON_ERROR);
        return [
            'times'        => is_array($times) ? array_values(array_map('strval', $times)) : [],
            // `updated_at` is stamped on every publish (and on creation), so
            // it is when the CURRENT version went live.
            'published_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * The earliest slot the board can fairly be held to, given when its
     * current schedule was published (UTC SQL). Null = no restriction.
     * Pure helper.
     */
    public static function slotsOwedFrom(?string $publishedUtcSql): ?DateTimeImmutable
    {
        if ($publishedUtcSql === null) {
            return null;
        }
        return (new DateTimeImmutable($publishedUtcSql, new DateTimeZone('UTC')))
            ->modify('+' . self::SCHEDULE_SYNC_ALLOWANCE_MIN . ' minutes');
    }

    /**
     * Minutes since a UTC SQL timestamp — null-safe. Pure helper.
     */
    public static function ageMinutes(string $utcSql, ?DateTimeImmutable $now = null): int
    {
        $now  = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $then = new DateTimeImmutable($utcSql, new DateTimeZone('UTC'));
        return (int) floor(($now->getTimestamp() - $then->getTimestamp()) / 60);
    }

    /**
     * A device is online when it checked in within the threshold.
     * Null last-seen (never checked in) is offline, not unknown — the
     * dashboard would never surface a dead board otherwise.
     */
    public static function isOnline(?string $lastSeenUtcSql, int $thresholdMinutes, ?DateTimeImmutable $now = null): bool
    {
        if ($lastSeenUtcSql === null) {
            return false;
        }
        return self::ageMinutes($lastSeenUtcSql, $now) <= $thresholdMinutes;
    }

    /**
     * Scheduled slots whose grace window has fully elapsed with no run
     * recorded near them. Considers the last two Manila days so a
     * shortly-after-midnight read still sees yesterday evening's
     * misses. `$runStarts` are UTC SQL `started_at` values; returns
     * missed slots as Manila "Y-m-d H:i" strings. Pure helper.
     *
     * `$owedFrom` is the earliest instant a slot can count as missed: a
     * slot that fell BEFORE the schedule was published (or before the
     * batch existed) was never due, so reporting it would blame the
     * board for a run nobody asked for. Null keeps the whole look-back.
     *
     * @param list<string> $times
     * @param list<?string> $runStarts
     * @return list<string>
     */
    public static function missedSlots(array $times, array $runStarts, int $graceMinutes, ?DateTimeImmutable $now = null, ?DateTimeImmutable $owedFrom = null): array
    {
        if ($times === []) {
            return [];
        }
        $now       = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowManila = $now->setTimezone(new DateTimeZone(ManilaDay::TZ));

        $starts = [];
        foreach ($runStarts as $s) {
            if ($s === null) {
                continue;
            }
            $starts[] = new DateTimeImmutable($s, new DateTimeZone('UTC'));
        }

        $missed = [];
        $days   = [
            $nowManila->format('Y-m-d'),
            $nowManila->modify('-1 day')->format('Y-m-d'),
        ];
        foreach ($days as $day) {
            foreach ($times as $hhmm) {
                if (! preg_match('/^\d{2}:\d{2}$/', (string) $hhmm)) {
                    continue;
                }
                $slot = new DateTimeImmutable($day . ' ' . $hhmm, new DateTimeZone(ManilaDay::TZ));
                if ($owedFrom !== null && $slot < $owedFrom) {
                    continue; // fell before the schedule/batch existed: not the board's miss
                }
                $slotEnd = $slot->modify('+' . $graceMinutes . ' minutes');
                if ($slotEnd >= $nowManila) {
                    continue; // grace window not yet elapsed
                }
                $matched = false;
                foreach ($starts as $start) {
                    $startManila = $start->setTimezone(new DateTimeZone(ManilaDay::TZ));
                    $windowStart = $slot->modify('-5 minutes');
                    if ($startManila >= $windowStart && $startManila <= $slotEnd) {
                        $matched = true;
                        break;
                    }
                }
                if (! $matched) {
                    $missed[] = $slot->format('Y-m-d H:i');
                }
            }
        }
        sort($missed);
        return $missed;
    }
}
