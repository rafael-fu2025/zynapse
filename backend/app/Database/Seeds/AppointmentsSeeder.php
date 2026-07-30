<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * AppointmentsSeeder — DEV/STAGING ONLY.
 *
 * Wipes every `clinic_appointments` row + the matching
 * `clinic.appointment_*` audit-outbox entries, then seeds a canonical
 * 12-appointment demo dataset covering every lifecycle status:
 *
 *   - 4 Scheduled (in the future, today + 1d, +2d, +3d, +5d)
 *   - 2 CheckedIn  (in the future, today, +1d)
 *   - 3 Completed  (in the past, -7d, -3d, -1d)
 *   - 1 Cancelled  (today)
 *   - 1 NoShow     (today)
 *   - 1 historical (yesterday, Completed)
 *
 * Patient ids reference the rows seeded by `PatientRegistrySeeder`
 * (range 6..25); provider_user_id points to the seeded `users`
 * rows (id 1 = admin, id 2 = nurse-jane). Override via environment
 * variables if your seeded ids differ:
 *
 *   APPT_PATIENT_BASE=1 APPT_USER_BASE=1 php spark db:seed AppointmentsSeeder
 *
 * Refuses to run in production. Idempotent.
 */
final class AppointmentsSeeder extends Seeder
{
    /**
     * @return list<array{patient_school_id:string, provider_user_id:int, scheduled_at:string, status:string, reason:string}>
     */
    private function appointments(): array
    {
        $patientBase = (int) (getenv('APPT_PATIENT_BASE') ?: '6');
        $userBase    = (int) (getenv('APPT_USER_BASE')    ?: '1');

        // Build "today" at the timezone used by the demo. We work in
        // UTC throughout because clinic_appointments.scheduled_at is
        // stored as a UTC wall-clock string (the backend converts
        // back to UTC on display).
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $at = static function (int $dayOffset, string $time) use ($today): string {
            $d = $today->modify(($dayOffset >= 0 ? '+' : '') . $dayOffset . ' days');
            return $d->format('Y-m-d') . ' ' . $time;
        };

        return [
            // 4 Scheduled — future, ready to demo the schedule + check-in flow.
            [
                'patient_school_id' => $this->studentNumber($patientBase + 0),
                'provider_user_id'  => $userBase + 1, // nurse-jane
                'scheduled_at'      => $at(1, '08:00:00'),
                'status'            => 'scheduled',
                'reason'            => 'Routine check-up',
            ],
            [
                'patient_school_id' => $this->studentNumber($patientBase + 1),
                'provider_user_id'  => $userBase + 0, // admin
                'scheduled_at'      => $at(1, '09:30:00'),
                'status'            => 'scheduled',
                'reason'            => 'Follow-up consultation',
            ],
            [
                'patient_school_id' => $this->studentNumber($patientBase + 2),
                'provider_user_id'  => $userBase + 1,
                'scheduled_at'      => $at(2, '10:00:00'),
                'status'            => 'scheduled',
                'reason'            => 'Sports physical',
            ],
            [
                'patient_school_id' => $this->studentNumber($patientBase + 3),
                'provider_user_id'  => $userBase + 0,
                'scheduled_at'      => $at(5, '14:00:00'),
                'status'            => 'scheduled',
                'reason'            => 'Allergy consult',
            ],

            // 2 CheckedIn — already arrived, awaiting the clinician.
            [
                'patient_school_id' => $this->studentNumber($patientBase + 4),
                'provider_user_id'  => $userBase + 1,
                'scheduled_at'      => $at(0, '09:00:00'),
                'status'            => 'checked_in',
                'reason'            => 'Walk-in',
            ],
            [
                'patient_school_id' => $this->studentNumber($patientBase + 5),
                'provider_user_id'  => $userBase + 0,
                'scheduled_at'      => $at(1, '11:00:00'),
                'status'            => 'checked_in',
                'reason'            => 'Lab result follow-up',
            ],

            // 3 Completed — past, already closed.
            [
                'patient_school_id' => $this->studentNumber($patientBase + 6),
                'provider_user_id'  => $userBase + 1,
                'scheduled_at'      => $at(-7, '08:30:00'),
                'status'            => 'completed',
                'reason'            => 'First-aid: minor cut',
            ],
            [
                'patient_school_id' => $this->studentNumber($patientBase + 7),
                'provider_user_id'  => $userBase + 0,
                'scheduled_at'      => $at(-3, '10:30:00'),
                'status'            => 'completed',
                'reason'            => 'Vaccination booster',
            ],
            [
                'patient_school_id' => $this->studentNumber($patientBase + 8),
                'provider_user_id'  => $userBase + 1,
                'scheduled_at'      => $at(-1, '13:00:00'),
                'status'            => 'completed',
                'reason'            => 'Medication refill',
            ],

            // 1 Cancelled — same-day cancel.
            [
                'patient_school_id' => $this->studentNumber($patientBase + 9),
                'provider_user_id'  => $userBase + 1,
                'scheduled_at'      => $at(0, '14:30:00'),
                'status'            => 'cancelled',
                'reason'            => 'Patient travelling',
            ],

            // 1 NoShow — same-day missed visit.
            [
                'patient_school_id' => $this->studentNumber($patientBase + 10),
                'provider_user_id'  => $userBase + 1,
                'scheduled_at'      => $at(0, '15:30:00'),
                'status'            => 'no_show',
                'reason'            => 'Health screening',
            ],

            // 1 historical Completed — yesterday.
            [
                'patient_school_id' => $this->studentNumber($patientBase + 11),
                'provider_user_id'  => $userBase + 0,
                'scheduled_at'      => $at(-1, '08:00:00'),
                'status'            => 'completed',
                'reason'            => 'BP monitoring',
            ],
        ];
    }

    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('AppointmentsSeeder must never run in production.');
        }

        $this->wipe();
        $this->seed();

        fwrite(STDOUT, "AppointmentsSeeder: 12 appointments inserted (4 Scheduled, 2 CheckedIn, 4 Completed, 1 Cancelled, 1 NoShow).\n");
    }

    private function wipe(): void
    {
        $db = $this->db;

        // Single DELETE so the FK cascade and CHARSET work the same way
        // across MySQL/MariaDB versions. TRUNCATE is faster but trips
        // the FK check the same way it did in FacilitiesSeeder.
        $db->table('clinic_appointments')->emptyTable();

        // Drop the matching audit_outbox rows so re-seeding doesn't
        // pile up orphan entries. `audit_events` is append-only.
        $db->table('audit_outbox')
            ->groupStart()
                ->like('action_code', 'clinic.appointment_', 'after')
                ->orWhere('entity_type', 'clinic_appointments')
            ->groupEnd()
            ->delete();
    }

    private function seed(): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($this->appointments() as $a) {
            $rows[] = $a + [
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->db->table('clinic_appointments')->insertBatch($rows);
    }

    /**
     * Resolve a student number from the patient registry. We do not
     * hard-fail if the index is out of range — instead we fall back
     * to the first student so a freshly-seeded DB never breaks this
     * seeder. The output still covers every lifecycle status.
     */
    private function studentNumber(int $offset): string
    {
        $row = $this->db->table('patients_students')
            ->select('student_number')
            ->orderBy('id', 'ASC')
            ->limit(1, max(0, $offset - 1))
            ->get()->getRowArray();
        return $row !== null ? (string) $row['student_number'] : '20260001';
    }
}
