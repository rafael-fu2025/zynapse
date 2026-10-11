<?php

declare(strict_types=1);

namespace Modules\Facilities\Services\Bmg;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\ManilaDay;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Facilities\Policies\BmgPolicy;

/**
 * ScheduleService — the per-unit run schedule the ESP32s pull.
 *
 * One live row per unit (`facilities_bmg_unit_schedules`, UNIQUE
 * unit_id) plus an append-only history ledger. Every published change
 * bumps `version` — that integer is the whole change-distribution
 * channel: the board keeps its copy in flash and re-applies only when
 * the version it polls differs, so nothing on the device depends on
 * the server being reachable at run time.
 *
 * Times are "HH:MM" strings in Asia/Manila (the clinic's calendar,
 * per AGENTS.md), sorted ascending and deduplicated on write.
 */
final class ScheduleService extends BaseService
{
    public const MAX_TIMES          = 8;
    public const MAX_DURATION       = 3600;
    public const DEFAULT_DURATION   = 600;
    public const DEFAULT_PWM        = 200;

    private const TABLE  = 'facilities_bmg_unit_schedules';
    private const HISTORY = 'facilities_bmg_schedule_history';

    private readonly BmgSupport $support;

    public function __construct(
        ?\CodeIgniter\Database\BaseConnection $db,
        private readonly BmgPolicy $policy,
        private readonly AuditOutboxService $audit,
        ?BmgSupport $support = null,
    ) {
        parent::__construct($db);
        // Stateless collaborator — built on demand so thin controllers
        // can construct this service without assembling BmgSupport's
        // own collaborators.
        $this->support = $support ?? new BmgSupport($this->db, new \App\Services\Analytics\BmgAnalytics());
    }

    /**
     * The device-facing flat payload — the exact shape the firmware
     * parses. Requires the device to be bound to a live unit; the row
     * is lazily seeded for legacy units created before schedules
     * existed (safe defaults: disabled, so nothing spins unexpectedly).
     *
     * @param array<string, mixed> $device bound by DeviceAuthFilter
     * @return array{times: list<string>, duration_seconds: int, pwm: int, enabled: bool, version: int}
     */
    public function forDevice(array $device): array
    {
        $unitId = $device['unit_id'] !== null ? (int) $device['unit_id'] : null;
        if ($unitId === null) {
            throw new ApiException('device.not_bound', 409, [
                ['code' => 'device.not_bound', 'message' => 'This device is not bound to a BMG unit.'],
            ]);
        }

        $unit = $this->db->table('facilities_bmg_units')
            ->where('id', $unitId)
            ->where('tenant_id', CurrentTenant::id())
            ->where('archived_at', null)
            ->get()->getRowArray();
        if ($unit === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => 'The bound BMG unit no longer exists.'],
            ]);
        }

        $row = $this->ensureForUnit($unitId);
        return $this->devicePayload($row);
    }

    /**
     * The admin read — current configuration plus the change history
     * (who published which version, when).
     *
     * @return array<string, mixed>
     */
    public function forUnit(int $unitId): array
    {
        $this->policy->check('list');
        $unit = $this->unitRow($unitId);

        $row = $this->ensureForUnit($unitId);
        $history = $this->db->table(self::HISTORY)
            ->where('tenant_id', CurrentTenant::id())
            ->where('unit_id', $unitId)
            ->orderBy('version', 'DESC')
            ->limit(20)
            ->get()->getResultArray();

        return [
            'unit_id'          => $unitId,
            'unit_code'        => (string) $unit['code'],
            'schedule'         => $this->adminPayload($row),
            'next_run_at'      => $this->nextRunAtUtc($this->timesOf($row), (int) $row['enabled'] === 1),
            'history'          => array_map(static fn (array $h): array => [
                'version'          => (int) $h['version'],
                'times'            => json_decode((string) $h['times'], true, 512, JSON_THROW_ON_ERROR),
                'duration_seconds' => (int) $h['duration_seconds'],
                'pwm'              => (int) $h['pwm'],
                'enabled'          => (int) $h['enabled'] === 1,
                'changed_by_user_id' => (int) $h['changed_by_user_id'],
                'created_at'       => (string) $h['created_at'],
            ], $history),
        ];
    }

    /**
     * Publish a new schedule configuration. Atomic: the version bump
     * reads the row under lock, so two editors can never mint the same
     * version (the board would silently skip one). The history row is
     * the human audit; the audit outbox carries the machine trail.
     *
     * @param array{times: list<string>, duration_seconds: int, pwm: int, enabled: bool} $input
     * @return array<string, mixed>
     */
    public function update(int $unitId, array $input): array
    {
        $this->policy->check('schedule_manage');
        $userId = \App\Auth\CurrentUser::assert();
        $unit   = $this->unitRow($unitId);

        $times     = $this->normalizeTimes($input['times'] ?? []);
        $duration  = (int) ($input['duration_seconds'] ?? 0);
        $pwm       = (int) ($input['pwm'] ?? -1);
        $enabled   = (bool) ($input['enabled'] ?? false);
        $this->assertScalarRanges($duration, $pwm);

        $row = $this->txn(function () use ($unitId, $unit, $times, $duration, $pwm, $enabled, $userId): array {
            $row = $this->selectForUpdate(self::TABLE, ['unit_id' => $unitId, 'tenant_id' => CurrentTenant::id()]);
            if ($row === null) {
                $row = $this->insertDefault($unitId);
                // Re-lock: the plain insert above is visible to this txn,
                // but the FOR UPDATE read keeps the version arithmetic
                // serialized against any concurrent publisher.
                $row = $this->selectForUpdate(self::TABLE, ['unit_id' => $unitId, 'tenant_id' => CurrentTenant::id()]);
            }

            $now      = $this->support->utcNow();
            $version  = (int) $row['version'] + 1;
            $timesJson = json_encode($times, JSON_THROW_ON_ERROR);

            $this->db->table(self::TABLE)
                ->where('tenant_id', CurrentTenant::id())
                ->where('unit_id', $unitId)
                ->update([
                    'times'            => $timesJson,
                    'duration_seconds' => $duration,
                    'pwm'              => $pwm,
                    'enabled'          => $enabled ? 1 : 0,
                    'version'          => $version,
                    'updated_at'       => $now,
                ]);

            $this->db->table(self::HISTORY)->insert([
                'tenant_id'          => CurrentTenant::id(),
                'unit_id'            => $unitId,
                'version'            => $version,
                'times'              => $timesJson,
                'duration_seconds'   => $duration,
                'pwm'                => $pwm,
                'enabled'            => $enabled ? 1 : 0,
                'changed_by_user_id' => $userId,
                'created_at'         => $now,
            ]);

            $this->audit->enqueue(
                'bmg.schedule_updated',
                self::TABLE,
                (int) $row['id'],
                $userId,
                ['resource_code' => (string) $unit['code'], 'next_status' => (string) $version],
            );

            $row['times']            = $timesJson;
            $row['duration_seconds'] = $duration;
            $row['pwm']              = $pwm;
            $row['enabled']          = $enabled ? 1 : 0;
            $row['version']          = $version;
            return $row;
        });

        $payload                 = $this->adminPayload($row);
        $payload['next_run_at']  = $this->nextRunAtUtc($this->timesOf($row), (int) $row['enabled'] === 1);
        return $payload;
    }

    /**
     * Next scheduled run in UTC SQL form — the dashboard's "next run"
     * readout. Pure function over the schedule's Manila times; `$now`
     * is injectable for tests.
     */
    public static function nextRunAtUtc(array $times, bool $enabled, ?DateTimeImmutable $now = null): ?string
    {
        if (! $enabled || $times === []) {
            return null;
        }
        $now       = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowManila = $now->setTimezone(new DateTimeZone(ManilaDay::TZ));

        // Both candidate days are MANILA days: gmdate() here would name a
        // UTC date that can differ from the Manila one the schedule runs
        // on (e.g. 23:30 Manila), silently skipping tomorrow's slot.
        $today    = $nowManila->format('Y-m-d');
        $tomorrow = $nowManila->modify('+1 day')->format('Y-m-d');
        $upcoming = [];
        foreach ($times as $hhmm) {
            if (! preg_match('/^\d{2}:\d{2}$/', (string) $hhmm)) {
                continue;
            }
            foreach ([$today, $tomorrow] as $day) {
                $candidate = new DateTimeImmutable($day . ' ' . $hhmm, new DateTimeZone(ManilaDay::TZ));
                if ($candidate > $nowManila) {
                    $upcoming[] = $candidate;
                    break;
                }
            }
        }
        if ($upcoming === []) {
            return null;
        }
        usort($upcoming, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);

        return $upcoming[0]->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Validate + normalize the times list: strings "HH:MM" (24 h),
     * deduplicated, ascending, at most 8. Validation rules on the
     * controller cover scalar fields; the array's shape is semantic,
     * so it is enforced here where the device contract lives.
     *
     * @return list<string>
     */
    public static function normalizeTimes(mixed $raw): array
    {
        if (! is_array($raw)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'times must be an array of "HH:MM" strings.', 'field' => 'times'],
            ]);
        }
        if (count($raw) > self::MAX_TIMES) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'At most ' . self::MAX_TIMES . ' run times per day.', 'field' => 'times'],
            ]);
        }

        $seen = [];
        foreach ($raw as $t) {
            $t = trim((string) $t);
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => "Invalid run time \"{$t}\" — use 24-hour HH:MM.", 'field' => 'times'],
                ]);
            }
            if (isset($seen[$t])) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => "Duplicate run time \"{$t}\".", 'field' => 'times'],
                ]);
            }
            $seen[$t] = true;
        }

        $times = array_keys($seen);
        sort($times);
        return $times;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{times: list<string>, duration_seconds: int, pwm: int, enabled: bool, version: int}
     */
    private function devicePayload(array $row): array
    {
        return [
            'times'            => $this->timesOf($row),
            'duration_seconds' => (int) $row['duration_seconds'],
            'pwm'              => (int) $row['pwm'],
            'enabled'          => (int) $row['enabled'] === 1,
            'version'          => (int) $row['version'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function adminPayload(array $row): array
    {
        return $this->devicePayload($row) + [
            'unit_id'   => (int) $row['unit_id'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @return list<string>
     */
    private function timesOf(array $row): array
    {
        $times = json_decode((string) $row['times'], true, 512, JSON_THROW_ON_ERROR);
        return is_array($times) ? array_values(array_map('strval', $times)) : [];
    }

    private function unitRow(int $unitId): array
    {
        $unit = $this->db->table('facilities_bmg_units')
            ->where('id', $unitId)
            ->where('tenant_id', CurrentTenant::id())
            ->where('archived_at', null)
            ->get()->getRowArray();
        if ($unit === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG unit #{$unitId} not found."],
            ]);
        }
        return $unit;
    }

    /**
     * Fetch the unit's schedule row, inserting safe defaults when the
     * unit predates the schedule table. Idempotent; also called from
     * `BmgService::createUnit` so every drum answers the device pull
     * from the moment it exists.
     *
     * @return array<string, mixed>
     */
    public function ensureForUnit(int $unitId): array
    {
        $row = $this->db->table(self::TABLE)
            ->where('tenant_id', CurrentTenant::id())
            ->where('unit_id', $unitId)
            ->get()->getRowArray();
        if ($row !== null) {
            return $row;
        }
        return $this->insertDefault($unitId);
    }

    /**
     * @return array<string, mixed>
     */
    private function insertDefault(int $unitId): array
    {
        $now = $this->support->utcNow();
        $this->db->table(self::TABLE)->insert([
            'tenant_id'        => CurrentTenant::id(),
            'unit_id'          => $unitId,
            'times'            => json_encode([], JSON_THROW_ON_ERROR),
            'duration_seconds' => self::DEFAULT_DURATION,
            'pwm'              => self::DEFAULT_PWM,
            'enabled'          => 0,
            'version'          => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $row = $this->db->table(self::TABLE)
            ->where('tenant_id', CurrentTenant::id())
            ->where('unit_id', $unitId)
            ->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('transaction.rolled_back', 409, [
                ['code' => 'transaction.rolled_back', 'message' => 'Schedule seeding failed.'],
            ]);
        }
        return $row;
    }

    private function assertScalarRanges(int $duration, int $pwm): void
    {
        $errors = [];
        if ($duration < 1 || $duration > self::MAX_DURATION) {
            $errors[] = ['code' => 'validation.field', 'message' => 'duration_seconds must be 1–' . self::MAX_DURATION . '.', 'field' => 'duration_seconds'];
        }
        if ($pwm < 0 || $pwm > 255) {
            $errors[] = ['code' => 'validation.field', 'message' => 'pwm must be 0–255.', 'field' => 'pwm'];
        }
        if ($errors !== []) {
            throw ApiException::validationFailure($errors);
        }
    }
}
