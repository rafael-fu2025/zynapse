<?php
/**
 * EmployeePersonServiceTest — grouping of duplicate MIS employee records.
 *
 * Pure halves pin the two heuristics everything else hangs off:
 *   1. positionYear() — the `DYY` employee-number prefix decode (225 →
 *      2025), including non-parsable fallbacks.
 *   2. sortRecords()/pickPrimary() — year desc → serial desc → id desc,
 *      including the same-year concurrent-appointment tie.
 *   3. nameKey() — strict three-part matching (middle-name variants stay
 *      separate people).
 *
 * DB-backed halves run against the local dev MariaDB (skip when
 * unreachable) and verify that the SQL twin of nameKey() used by the
 * primary-only listing filter hides exactly the rows nameKey() groups,
 * and that attachRecords() assembles a consistent record group.
 */
declare(strict_types=1);

namespace Tests\Unit;

use Config\Services;
use Modules\Clinic\Services\EmployeePersonService;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class EmployeePersonServiceTest extends TestCase
{
    public function testPositionYearDecodesCenturyAndYear(): void
    {
        $this->assertSame(2025, EmployeePersonService::positionYear('225082'));
        $this->assertSame(2000, EmployeePersonService::positionYear('200151'));
        $this->assertSame(2024, EmployeePersonService::positionYear('224007'));
        $this->assertSame(1995, EmployeePersonService::positionYear('195123'), 'First digit is the century marker.');
    }

    public function testPositionYearRejectsNonParsableNumbers(): void
    {
        $this->assertNull(EmployeePersonService::positionYear(null));
        $this->assertNull(EmployeePersonService::positionYear(''));
        $this->assertNull(EmployeePersonService::positionYear('20260928'), '8-digit system-style number has no DYY prefix.');
        $this->assertNull(EmployeePersonService::positionYear('22A082'));
        $this->assertNull(EmployeePersonService::positionYear('225'), 'Prefix alone is not a record number.');
    }

    public function testSerialPartTiebreaksSameYear(): void
    {
        $this->assertSame(82, EmployeePersonService::serialPart('225082'));
        $this->assertSame(149, EmployeePersonService::serialPart('225149'));
        $this->assertSame(0, EmployeePersonService::serialPart('garbage'));
    }

    public function testSortRecordsPutsNewestIssuanceFirst(): void
    {
        $sorted = EmployeePersonService::sortRecords([
            ['id' => 1, 'employee_number' => '210453'],
            ['id' => 2, 'employee_number' => '223076'],
            ['id' => 3, 'employee_number' => '200151'],
        ]);
        $this->assertSame([2, 1, 3], array_column($sorted, 'id'), '223076 (2023) > 210453 (2010) > 200151 (2000).');
    }

    public function testSortRecordsTiebreaksSameYearBySerialThenId(): void
    {
        $sorted = EmployeePersonService::sortRecords([
            ['id' => 41, 'employee_number' => '224044'],
            ['id' => 42, 'employee_number' => '224075'],
        ]);
        $this->assertSame('224075', $sorted[0]['employee_number'], 'Higher serial inside one year wins.');

        $sameNumber = EmployeePersonService::sortRecords([
            ['id' => 7, 'employee_number' => '225082'],
            ['id' => 9, 'employee_number' => '225082'],
        ]);
        $this->assertSame(9, $sameNumber[0]['id'], 'Identical numbers fall back to id desc.');
    }

    public function testSortRecordsPutsUnparsableNumbersLast(): void
    {
        $sorted = EmployeePersonService::sortRecords([
            ['id' => 1, 'employee_number' => null],
            ['id' => 2, 'employee_number' => '210453'],
        ]);
        $this->assertSame(2, $sorted[0]['id']);
    }

    public function testPickPrimary(): void
    {
        $this->assertNull(EmployeePersonService::pickPrimary([]));
        $primary = EmployeePersonService::pickPrimary([
            ['id' => 1, 'employee_number' => '224044'],
            ['id' => 2, 'employee_number' => '224075'],
        ]);
        $this->assertSame('224075', $primary['employee_number']);
    }

    public function testNameKeyIsStrictThreePartMatch(): void
    {
        $base = EmployeePersonService::nameKey('Cyril', 'Macasilhig', 'Mapula');
        $this->assertSame($base, EmployeePersonService::nameKey(' CYRIL ', 'macasilhig', 'Mapula '), 'Case/whitespace insensitive.');
        $this->assertNotSame($base, EmployeePersonService::nameKey('Cyril', 'M', 'Mapula'), 'Middle-initial variant is a different key.');
        $this->assertSame(
            EmployeePersonService::nameKey('Juan', null, 'Dela Cruz'),
            EmployeePersonService::nameKey('Juan', '', 'Dela Cruz'),
            'NULL and empty middle names are the same person shape.',
        );
        $this->assertNotSame(
            EmployeePersonService::nameKey('Juan', null, 'Dela Cruz'),
            EmployeePersonService::nameKey('Juan', 'Reyes', 'Dela Cruz'),
            'A record without a middle name never matches one with it.',
        );
    }

    /**
     * @return list<array<string, mixed>> live tenant-1 employee rows
     */
    private static function liveEmployees(\mysqli $m): array
    {
        $res = $m->query(
            "SELECT id, employee_number, first_name, middle_name, last_name
             FROM users
             WHERE tenant_id = 1 AND kind = 'employee' AND deleted_at IS NULL AND archived_at IS NULL",
        );
        $rows = $res !== false ? $res->fetch_all(MYSQLI_ASSOC) : [];
        if ($rows === []) {
            self::markTestSkipped('No employees in dev DB');
        }
        return $rows;
    }

    public function testPrimaryOnlyFilterHidesExactlyTheNonPrimaryDuplicates(): void
    {
        $m = @mysqli_connect('127.0.0.1', 'root', '', 'synapse_zcode', 3306);
        if ($m === false) {
            self::markTestSkipped('synapse_zcode not reachable on 127.0.0.1:3306');
        }

        $rows = self::liveEmployees($m);
        $groups = [];
        foreach ($rows as $r) {
            $groups[EmployeePersonService::nameKeyFromRow($r)][] = $r;
        }
        if (count($groups) === count($rows)) {
            self::markTestSkipped('No duplicate employee persons in dev DB to exercise the filter');
        }

        $db = Services::database();
        $builder = $db->table('users')
            ->select('id')
            ->where('tenant_id', 1)
            ->where('kind', 'employee')
            ->where('deleted_at', null)
            ->where('archived_at', null);
        (new EmployeePersonService($db))->applyPrimaryOnlyFilter($builder, false);

        $this->assertSame(
            count($groups),
            $builder->countAllResults(),
            'The SQL twin of nameKey() must hide exactly the non-primary rows.',
        );
        $m->close();
    }

    public function testAttachRecordsGroupsSiblingsEndToEnd(): void
    {
        $m = @mysqli_connect('127.0.0.1', 'root', '', 'synapse_zcode', 3306);
        if ($m === false) {
            self::markTestSkipped('synapse_zcode not reachable on 127.0.0.1:3306');
        }

        $rows = self::liveEmployees($m);
        $groups = [];
        foreach ($rows as $r) {
            $groups[EmployeePersonService::nameKeyFromRow($r)][] = $r;
        }
        $dupKey = null;
        foreach ($groups as $key => $members) {
            if (count($members) > 1) {
                $dupKey = $key;
                break;
            }
        }
        if ($dupKey === null) {
            self::markTestSkipped('No duplicate employee persons in dev DB');
        }
        $members = $groups[$dupKey];

        $svc = new EmployeePersonService(Services::database());
        $out = $svc->attachRecords(array_map(static fn (array $r): array => $r, $members));

        $this->assertCount(count($members), $out);
        foreach ($out as $row) {
            $this->assertSame(count($members), count($row['records']), 'Every member sees the whole group.');
            $primaries = array_values(array_filter($row['records'], static fn (array $r) => $r['is_primary']));
            $this->assertCount(1, $primaries, 'Exactly one primary record per group.');
            $this->assertSame(
                (int) EmployeePersonService::pickPrimary($members)['id'],
                $primaries[0]['id'],
                'Primary follows the year/serial/id precedence.',
            );
            $this->assertSame(
                EmployeePersonService::positionYear($row['employee_number']),
                $row['position_year'],
            );
        }
        $m->close();
    }
}
