<?php

declare(strict_types=1);

namespace Modules\Counselling\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * GuidanceAudience — shared audience targeting for guidance content
 * (announcements Phase A, surveys Phase B).
 *
 * Audiences key on `users.kind` + `users.created_at` against the
 * academic-year start (Aug 1, mirroring
 * Modules\Reports\Services\ReportRange::ACADEMIC_YEAR_START_MONTH):
 *   - new_students       — student account created inside the current AY;
 *   - continuing_students — created earlier;
 *   - graduating_students — currently matches ALL students (no
 *     year-level data yet; Phase B/C refines when cohort data lands).
 *
 * Staff (non-student) callers preview everything.
 */
final class GuidanceAudience
{
    /** @return list<string> */
    public static function audiencesFor(BaseConnection $db, int $userId, int $tenantId): array
    {
        $student = $db->table('users')
            ->select('created_at, kind')
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->get()->getRowArray();

        if ($student === null || (string) $student['kind'] !== 'student') {
            return ['all', 'new_students', 'continuing_students', 'graduating_students'];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $startYear = (int) $now->format('n') >= 8 ? (int) $now->format('Y') : (int) $now->format('Y') - 1;
        $ayStart = new \DateTimeImmutable($startYear . '-08-01 00:00:00', new \DateTimeZone('UTC'));

        $isNew = $student['created_at'] !== null
            && strtotime((string) $student['created_at']) >= $ayStart->getTimestamp();

        return $isNew
            ? ['all', 'new_students', 'graduating_students']
            : ['all', 'continuing_students', 'graduating_students'];
    }
}
