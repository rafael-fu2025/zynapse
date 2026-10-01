<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Modules\Shared\BaseService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\RawSql;

/**
 * EmployeePersonService — one person, several MIS records.
 *
 * The FU MIS employee directory issues one record (its own employee
 * number) per appointment, so one human can legitimately occupy several
 * `users` rows (kind=employee). Nothing is merged or deleted here: the
 * records are GROUPED at read time and ordered so the newest appointment
 * ("primary record") supplies the position shown on the portal card and
 * registry row, while the remaining records stay reachable through the
 * same payload for the position-history accordion.
 *
 * Two knobs everything else hangs off:
 *
 *  - Grouping key: exact three-part name (first | middle | last,
 *    trimmed + lowercased, missing middle == empty). Deliberately
 *    strict — a variant spelling stays a separate person rather than
 *    risk showing one person's clinic history to another. If MIS ever
 *    exposes a person-level key, replace `nameKey()` and the SQL twin
 *    in `primaryOverrideSql()`; nothing else changes.
 *
 *  - Recency: the MIS issuance year encoded in the 6-digit employee
 *    number (`DYYNNN`, e.g. 225082 → 20|25 → 2025; the first digit marks
 *    the century). The encoding is observed from the synced directory,
 *    not documented by MIS, and MIS supplies no hire date to check it
 *    against — so it is an ORDERING heuristic only. Ties within one
 *    year (concurrent appointments) fall back to the numeric remainder
 *    of the number, then id: deterministic, and the accordion still
 *    shows every record, so an arbitrary pick hides nothing.
 */
final class EmployeePersonService extends BaseService
{
    private const MEMBER_COLS = 'id, employee_number, first_name, middle_name, last_name, department, position, archived_at';

    // ------------------------------------------------------------ pure helpers

    /**
     * Issuance year decoded from the 6-digit `DYYNNN` employee number:
     * first digit marks the century (1 → 1900s, 2 → 2000s), next two are
     * the year (`225082` → 2025, serial 082). Employee-only: student
     * numbers use a different (4-digit year + serial) scheme. Numbers
     * outside the observed MIS format sort last instead of corrupting
     * the primary choice.
     */
    public static function positionYear(?string $employeeNumber): ?int
    {
        if ($employeeNumber === null) {
            return null;
        }
        if (preg_match('/^(\d)(\d{2})\d{3}$/', trim($employeeNumber), $m) !== 1) {
            return null;
        }
        return 1800 + ((int) $m[1]) * 100 + ((int) $m[2]);
    }

    /**
     * Numeric remainder after the `DYY` prefix — the intra-year tiebreak.
     * Returns 0 for numbers that do not parse.
     */
    public static function serialPart(?string $employeeNumber): int
    {
        if ($employeeNumber === null) {
            return 0;
        }
        $n = trim($employeeNumber);
        return preg_match('/^\d{6}$/', $n) === 1 ? (int) substr($n, 3) : 0;
    }

    /**
     * Grouping key — MUST stay in lockstep with the SQL expression in
     * `primaryOverrideSql()`.
     */
    public static function nameKey(?string $first, ?string $middle, ?string $last): string
    {
        return mb_strtolower(trim((string) $first))
            . '|' . mb_strtolower(trim((string) ($middle ?? '')))
            . '|' . mb_strtolower(trim((string) $last));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nameKeyFromRow(array $row): string
    {
        return self::nameKey(
            isset($row['first_name']) ? (string) $row['first_name'] : '',
            isset($row['middle_name']) && $row['middle_name'] !== null ? (string) $row['middle_name'] : null,
            isset($row['last_name']) ? (string) $row['last_name'] : '',
        );
    }

    /**
     * Order a person's records newest-first: issuance year desc, then
     * serial desc, then id desc. Numbers that fail to decode sort last
     * (still deterministic) instead of corrupting the primary choice.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function sortRecords(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $ya = self::positionYear(isset($a['employee_number']) ? (string) $a['employee_number'] : null);
            $yb = self::positionYear(isset($b['employee_number']) ? (string) $b['employee_number'] : null);
            if ($ya !== $yb) {
                if ($ya === null) {
                    return 1;
                }
                if ($yb === null) {
                    return -1;
                }
                return $yb <=> $ya;
            }
            $sa = self::serialPart(isset($a['employee_number']) ? (string) $a['employee_number'] : null);
            $sb = self::serialPart(isset($b['employee_number']) ? (string) $b['employee_number'] : null);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
        });
        return $rows;
    }

    /**
     * The record whose position the UI displays.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    public static function pickPrimary(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }
        return self::sortRecords($rows)[0];
    }

    // ------------------------------------------------------------ DB-backed

    /**
     * Attach `records` (the person's employee records, newest first) and
     * `position_year` to every row of a list page, in one batched round
     * trip per page.
     *
     * `primaryAmongLive` keeps `is_primary` consistent with
     * `applyPrimaryOnlyFilter()`: when the page hides archived rows, the
     * primary is picked among live siblings only. Archived siblings still
     * appear in `records`, flagged.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function attachRecords(array $rows, bool $primaryAmongLive = true): array
    {
        if ($rows === []) {
            return $rows;
        }

        $keys = [];
        foreach ($rows as $r) {
            $keys[self::nameKeyFromRow($r)] = true;
        }
        $members = $this->groupMembers(array_keys($keys));

        $byKey = [];
        foreach ($members as $m) {
            $byKey[self::nameKeyFromRow($m)][] = $m;
        }

        $counts = $this->visitCounts(array_map(
            static fn (array $m) => (int) $m['id'],
            $members,
        ));

        $recordsByKey = [];
        foreach ($byKey as $key => $list) {
            $candidates = $primaryAmongLive
                ? array_values(array_filter($list, static fn (array $m) => $m['archived_at'] === null))
                : $list;
            $primary = self::pickPrimary($candidates !== [] ? $candidates : $list);
            $recordsByKey[$key] = array_map(
                fn (array $m) => $this->recordSummary($m, $primary, $counts),
                self::sortRecords($list),
            );
        }

        foreach ($rows as &$r) {
            $key = self::nameKeyFromRow($r);
            $r['records'] = $recordsByKey[$key] ?? [$this->recordSummary($r, $r, $counts)];
            $r['position_year'] = self::positionYear(isset($r['employee_number']) ? (string) $r['employee_number'] : null);
        }
        unset($r);

        return $rows;
    }

    /**
     * Exclude non-primary records from a list builder so each person
     * appears exactly once (their primary record). Self-contained SQL —
     * the correlated predicate references only columns of the outer
     * `users` table, so nothing user-supplied ever enters it. The name
     * comparison MUST stay in lockstep with `nameKey()`.
     *
     * `includeArchived` must match the listing query: a hidden sibling
     * must not strip the only visible row of a person.
     */
    public function applyPrimaryOnlyFilter(BaseBuilder $builder, bool $includeArchived): void
    {
        $archivedSql = $includeArchived ? '' : ' AND s.archived_at IS NULL';
        $builder->where(new RawSql('NOT EXISTS (' . $this->primaryOverrideSql($archivedSql) . ')'));
    }

    /**
     * TRUE when some same-person sibling outranks `users` under the same
     * precedence `sortRecords()` uses: year, then serial, then id.
     */
    private function primaryOverrideSql(string $archivedSql): string
    {
        $num = static fn (string $t, int $from, ?int $len): string
            => sprintf(
                "CAST(SUBSTRING(COALESCE(%s.employee_number, ''), %d%s) AS UNSIGNED)",
                $t,
                $from,
                $len !== null ? ", {$len}" : '',
            );

        return sprintf(
            "SELECT 1 FROM users s
                WHERE s.tenant_id = users.tenant_id
                  AND s.kind = 'employee'%s
                  AND LOWER(TRIM(COALESCE(s.first_name, ''))) = LOWER(TRIM(COALESCE(users.first_name, '')))
                  AND COALESCE(TRIM(s.middle_name), '') = COALESCE(TRIM(users.middle_name), '')
                  AND LOWER(TRIM(COALESCE(s.last_name, ''))) = LOWER(TRIM(COALESCE(users.last_name, '')))
                  AND s.employee_number REGEXP '^[0-9]{6}$'
                  AND (%s, %s, %s, s.id) > (%s, %s, %s, users.id)
                  LIMIT 1",
            $archivedSql,
            $num('s', 1, 1),
            $num('s', 2, 2),
            $num('s', 4, null),
            $num('users', 1, 1),
            $num('users', 2, 2),
            $num('users', 4, null),
        );
    }

    /**
     * Every employee row sharing one of the given name keys.
     *
     * @param list<string> $keys
     * @return list<array<string, mixed>>
     */
    private function groupMembers(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $tuples = [];
        $binds = [CurrentTenant::id(), 'employee'];
        foreach ($keys as $key) {
            [$f, $m, $l] = explode('|', $key, 3);
            $tuples[] = '(LOWER(TRIM(COALESCE(first_name, \'\'))) = ?'
                . ' AND LOWER(TRIM(COALESCE(middle_name, \'\'))) = ?'
                . ' AND LOWER(TRIM(COALESCE(last_name, \'\'))) = ?)';
            $binds[] = $f;
            $binds[] = $m;
            $binds[] = $l;
        }

        $sql = 'SELECT ' . self::MEMBER_COLS
            . ' FROM users'
            . ' WHERE users.tenant_id = ? AND kind = ? AND (' . implode(' OR ', $tuples) . ')';

        $rows = $this->db->query($sql, $binds)->getResultArray();
        return array_map(static fn (array $r): array => $r, $rows);
    }

    /**
     * Clinic encounter counts for the accordion headers, batched.
     *
     * @param list<int> $userIds
     * @return array<int, int>
     */
    private function visitCounts(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = $this->db->table('clinic_encounters')
            ->select('patient_user_id, COUNT(*) AS n')
            ->where('clinic_encounters.tenant_id', CurrentTenant::id())
            ->whereIn('patient_user_id', $userIds)
            ->where('archived_at', null)
            ->groupBy('patient_user_id')
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['patient_user_id']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $primary
     * @param array<int, int> $counts
     * @return array<string, mixed>
     */
    private function recordSummary(array $row, array $primary, array $counts): array
    {
        $nullable = static function (mixed $v): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            return (string) $v;
        };

        return [
            'id'              => (int) $row['id'],
            'employee_number' => (string) ($row['employee_number'] ?? ''),
            'department'      => $nullable($row['department'] ?? null),
            'position'        => $nullable($row['position'] ?? null),
            'position_year'   => self::positionYear(isset($row['employee_number']) ? (string) $row['employee_number'] : null),
            'archived'        => ($row['archived_at'] ?? null) !== null,
            'is_primary'      => (int) $row['id'] === (int) ($primary['id'] ?? 0),
            'visit_count'     => $counts[(int) $row['id']] ?? 0,
        ];
    }
}
