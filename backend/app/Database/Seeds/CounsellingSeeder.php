<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Services\Crypto\EncryptionService;
use CodeIgniter\Database\Seeder;

/**
 * CounsellingSeeder — DEV/STAGING ONLY.
 *
 * Wipes + seeds the counselling module so every page has demo data:
 *   - 5 availability windows (one per weekday) for the seeded
 *     counsellor user, max 2 slots each.
 *   - 8 counselling appointments spread across this week + next,
 *     covering every status (scheduled, confirmed, completed,
 *     cancelled, no_show).
 *   - 2 sessions — 1 closed (with encrypted notes) + 1 open.
 *   - 2 scheduling_analytics rows showing realistic no-show rates
 *     so the analytics view renders without a recompute round-trip.
 *
 * Notes are written through `EncryptionService::encryptField` so the
 * seeder depends on `COUNSELLING_KEY` being present in the env. The
 * demo key is set in `.env` (`COUNSELLING_KEY=…`).
 *
 * Refuses to run in production. Idempotent.
 */
final class CounsellingSeeder extends Seeder
{
    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('CounsellingSeeder must never run in production.');
        }

        $counsellorId = $this->findCounsellorUserId();
        if ($counsellorId === null) {
            throw new \RuntimeException('CounsellingSeeder: no user with group "counsellor" found. Run PermissionsAndGroupsSeeder + DevUserSeeder first.');
        }

        $studentIds = $this->collectStudentIds();
        if ($studentIds === []) {
            throw new \RuntimeException('CounsellingSeeder: no students found. Run PatientRegistrySeeder first.');
        }

        $adminId = $this->findAdminUserId() ?? $counsellorId;

        $this->wipe();
        $this->seedAvailability($counsellorId);
        $this->seedAppointments($counsellorId, $studentIds, $adminId);
        $this->seedSessions($counsellorId, $studentIds, $adminId);
        $this->seedAnalytics($counsellorId);

        fwrite(STDOUT, "CounsellingSeeder: 5 availability windows + 8 appointments + 2 sessions (1 closed, 1 open) + 2 analytics rows inserted.\n");
    }

    private function findCounsellorUserId(): ?int
    {
        $row = $this->db->table('auth_groups_users')
            ->select('user_id')
            ->join('auth_groups g', 'g.id = auth_groups_users.group_id AND g.name = "counsellor"', 'inner', false)
            ->limit(1)
            ->get()->getRowArray();
        return $row !== null ? (int) $row['user_id'] : null;
    }

    private function findAdminUserId(): ?int
    {
        $row = $this->db->table('auth_groups_users')
            ->select('user_id')
            ->join('auth_groups g', 'g.id = auth_groups_users.group_id AND g.name = "admin"', 'inner', false)
            ->limit(1)
            ->get()->getRowArray();
        return $row !== null ? (int) $row['user_id'] : null;
    }

    /**
     * @return list<string>
     */
    private function collectStudentIds(): array
    {
        $rows = $this->db->table('users')
            ->select('student_number')
            ->where('kind', 'student')
            ->where('student_number IS NOT NULL', null, false)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
        return array_map(static fn (array $r) => (string) $r['student_number'], $rows);
    }

    private function wipe(): void
    {
        $this->db->table('counselling_notes')->emptyTable();
        $this->db->table('counselling_sessions')->emptyTable();
        $this->db->table('counselling_appointments')->emptyTable();
        $this->db->table('counselling_availability')->emptyTable();
        $this->db->table('counselling_scheduling_analytics')->emptyTable();
        $this->db->table('audit_outbox')
            ->groupStart()
                ->like('action_code', 'counselling.', 'after')
                ->orWhere('entity_type', 'counselling_sessions')
                ->orWhere('entity_type', 'counselling_appointments')
                ->orWhere('entity_type', 'counselling_availability')
            ->groupEnd()
            ->delete();
    }

    private function seedAvailability(int $counsellorId): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $rows = [];
        // Mon-Fri 09:00-12:00 and 13:00-16:00 — 2 slots each = 10 windows.
        foreach (range(1, 5) as $dow) {
            $rows[] = [
                'counsellor_user_id' => $counsellorId,
                'day_of_week'        => $dow,
                'start_time'         => '09:00:00',
                'end_time'           => '12:00:00',
                'max_slots'          => 2,
                'is_active'          => 1,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
            $rows[] = [
                'counsellor_user_id' => $counsellorId,
                'day_of_week'        => $dow,
                'start_time'         => '13:00:00',
                'end_time'           => '16:00:00',
                'max_slots'          => 2,
                'is_active'          => 1,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];
        }
        $this->db->table('counselling_availability')->insertBatch($rows);
    }

    /**
     * @param list<string> $studentIds
     */
    private function seedAppointments(int $counsellorId, array $studentIds, int $createdBy): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $nowStr = $now->format('Y-m-d H:i:s');

        // Helper: take the Nth student id, cycling if needed.
        $sid = static function (int $i) use ($studentIds): string {
            return $studentIds[$i % count($studentIds)];
        };

        // Helper: modify() needs an explicit unit; bare '0' is invalid.
        $offset = static function (string $expr): string {
            $expr = trim($expr);
            if ($expr === '0' || $expr === '-0' || $expr === '+0') {
                return '0 days';
            }
            return str_contains($expr, ' ') ? $expr : $expr . ' days';
        };

        // 8 appointments, dates spread across the previous 7 days,
        // today, and the next 4 days. Status mix covers all five
        // enum values exactly once (plus extras for upcoming slots).
        $appointments = [
            // 2 completed (past week)
            ['date' => '-5', 'start' => '10:00:00', 'end' => '11:00:00', 'type' => 'initial',     'status' => 'completed', 'reason' => 'Initial intake — adjustment concerns',  'student' => 0, 'cr' => null],
            ['date' => '-2', 'start' => '14:00:00', 'end' => '15:00:00', 'type' => 'follow_up',   'status' => 'completed', 'reason' => 'Follow-up: coping strategies',         'student' => 1, 'cr' => null],
            // 1 cancelled
            ['date' => '-1', 'start' => '11:00:00', 'end' => '12:00:00', 'type' => 'crisis',      'status' => 'cancelled', 'reason' => 'Crisis debrief',                      'student' => 2, 'cr' => 'Patient rescheduling.'],
            // 1 no_show
            ['date' => '0',  'start' => '09:00:00', 'end' => '10:00:00', 'type' => 'follow_up',   'status' => 'no_show',   'reason' => 'Follow-up: anxiety coping',           'student' => 3, 'cr' => null],
            // 1 confirmed (today afternoon)
            ['date' => '0',  'start' => '15:00:00', 'end' => '16:00:00', 'type' => 'referral_based', 'status' => 'confirmed', 'reason' => 'Referral from faculty',           'student' => 4, 'cr' => null],
            // 1 scheduled (tomorrow)
            ['date' => '+1', 'start' => '10:00:00', 'end' => '11:00:00', 'type' => 'initial',     'status' => 'scheduled', 'reason' => 'Initial intake',                     'student' => 5, 'cr' => null],
            // 1 scheduled (next week)
            ['date' => '+4', 'start' => '14:00:00', 'end' => '15:00:00', 'type' => 'follow_up',   'status' => 'scheduled', 'reason' => 'Follow-up',                          'student' => 6, 'cr' => null],
            // 1 scheduled (next week)
            ['date' => '+4', 'start' => '15:00:00', 'end' => '16:00:00', 'type' => 'initial',     'status' => 'scheduled', 'reason' => 'New student intake',                 'student' => 7, 'cr' => null],
        ];

        $rows = [];
        foreach ($appointments as $a) {
            $date = $now->modify($offset($a['date']))->format('Y-m-d');
            $rows[] = [
                'patient_school_id'   => $sid((int) $a['student']),
                'counsellor_user_id'  => $counsellorId,
                'appointment_date'    => $date,
                'start_time'          => $a['start'],
                'end_time'            => $a['end'],
                'type'                => $a['type'],
                'status'              => $a['status'],
                'reason'              => $a['reason'],
                'cancellation_reason' => $a['cr'],
                'created_by_user_id'  => $createdBy,
                'created_at'          => $nowStr,
                'updated_at'          => $nowStr,
            ];
        }
        $this->db->table('counselling_appointments')->insertBatch($rows);
    }

    /**
     * @param list<string> $studentIds
     */
    private function seedSessions(int $counsellorId, array $studentIds, int $createdBy): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $nowStr = $now->format('Y-m-d H:i:s');
        $crypto = new EncryptionService();

        // Session 1: closed, with a short encrypted note.
        $noteText1 = "Session summary: patient reports improved sleep after 2 weeks of CBT-based sleep hygiene. No SI/HI. Will continue weekly follow-ups and re-evaluate in 4 weeks.";
        $env1 = $crypto->encryptField($noteText1);
        $closedStart = $now->modify('-2 days')->setTime(14, 0, 0);
        $closedRow = [
            'patient_school_id'  => $studentIds[0],
            'counsellor_user_id' => $counsellorId,
            'started_at'         => $closedStart->format('Y-m-d H:i:s'),
            'ended_at'           => $now->modify('-2 days')->setTime(15, 0, 0)->format('Y-m-d H:i:s'),
            'archived_at'        => null,
            'created_at'         => $nowStr,
            'updated_at'         => $nowStr,
        ];
        $this->db->table('counselling_sessions')->insert($closedRow);
        $closedId = (int) $this->db->insertID();
        $this->db->table('counselling_notes')->insert([
            'session_id'         => $closedId,
            'notes_cipher'       => $env1['ciphertext'],
            'notes_nonce'        => $env1['nonce'],
            'notes_key_version'  => $env1['key_version'],
            'created_by_user_id' => $createdBy,
            'created_at'         => $nowStr,
            'updated_at'         => $nowStr,
        ]);

        // Session 2: still open, no notes yet.
        $openRow = [
            'patient_school_id'  => $studentIds[1],
            'counsellor_user_id' => $counsellorId,
            'started_at'         => $now->modify('-15 minutes')->format('Y-m-d H:i:s'),
            'ended_at'           => null,
            'archived_at'        => null,
            'created_at'         => $nowStr,
            'updated_at'         => $nowStr,
        ];
        $this->db->table('counselling_sessions')->insert($openRow);
    }

    private function seedAnalytics(int $counsellorId): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $nowStr = $now->format('Y-m-d H:i:s');

        $rows = [
            [
                'counsellor_user_id'  => $counsellorId,
                'day_of_week'         => 1, // Mon
                'time_slot'           => '10:00:00',
                'total_appointments'  => 24,
                'total_no_shows'      => 5,
                'no_show_rate'        => 0.2083,
                'avg_utilization'     => 0.7500,
                'recommended_overbooking' => 1,
                'last_calculated_at'  => $now->format('Y-m-d H:i:s'),
                'created_at'          => $nowStr,
                'updated_at'          => $nowStr,
            ],
            [
                'counsellor_user_id'  => $counsellorId,
                'day_of_week'         => 2, // Tue
                'time_slot'           => '14:00:00',
                'total_appointments'  => 18,
                'total_no_shows'      => 2,
                'no_show_rate'        => 0.1111,
                'avg_utilization'     => 0.9000,
                'recommended_overbooking' => 0,
                'last_calculated_at'  => $now->format('Y-m-d H:i:s'),
                'created_at'          => $nowStr,
                'updated_at'          => $nowStr,
            ],
        ];
        $this->db->table('counselling_scheduling_analytics')->insertBatch($rows);
    }
}
