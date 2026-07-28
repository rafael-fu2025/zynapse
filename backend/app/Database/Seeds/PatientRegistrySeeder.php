<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * PatientRegistrySeeder — DEV/STAGING ONLY.
 *
 * Resets the patient registry (students + employees + departments) to
 * a known, demoable state and inserts a canonical reference dataset.
 *
 *   1. Wipes every registry-related table in FK-safe order:
 *        - patient_allergies       (child of patients_students)
 *        - patient_contacts        (child of patients_students)
 *        - patients_students
 *        - patients_employees
 *        - clinic_departments
 *      Also drops the matching rows from audit_outbox so the seeder
 *      is re-runnable without piling up orphan audit entries.
 *
 *   2. Seeds the canonical departments (Registrar, Facilities, …).
 *
 *   3. Seeds students with the **8-digit YYYYNNNN ID format**:
 *      first 4 digits = current year (e.g. 2026), last 4 = random.
 *      Same format for employees.
 *
 * Refuses to run in production. Idempotent — re-running yields the
 * same end state. Invoke with:
 *
 *   php spark db:seed PatientRegistrySeeder
 */
final class PatientRegistrySeeder extends Seeder
{
    /** @var list<array{code:string, name:string, description:?string}> */
    private const DEPARTMENTS = [
        ['code' => 'REG',     'name' => 'Registrar',            'description' => 'Student records, IDs, enrollment.'],
        ['code' => 'FAC',     'name' => 'Facilities',           'description' => 'Bio-medical generators + waste management.'],
        ['code' => 'ADM',     'name' => 'Admin',                'description' => 'General administration.'],
        ['code' => 'HEA',     'name' => 'Health Services',      'description' => 'Clinic + counselling operations.'],
        ['code' => 'ACA',     'name' => 'Academic Affairs',     'description' => 'Curriculum + faculty load.'],
        ['code' => 'FAC-EDU', 'name' => 'Faculty',              'description' => 'Teaching faculty (BSIT, BSCS, BSN, BSEd).'],
        ['code' => 'ITS',     'name' => 'IT Services',          'description' => 'Systems + network + help desk.'],
        ['code' => 'CAF',     'name' => 'Cafeteria Services',   'description' => 'Food service + meal programs.'],
        ['code' => 'MNT',     'name' => 'Maintenance',          'description' => 'Buildings, grounds, repairs.'],
        ['code' => 'SEC',     'name' => 'Security',             'description' => 'Campus security + ID checks.'],
    ];

    /**
     * Each tuple: student_number, first_name, last_name, middle_name,
     * gender, blood_type, course, year_level, section.
     *
     * @var list<array{0:string,1:string,2:string,3:?string,4:'male'|'female'|'other',5:?string,6:string,7:int,8:string}>
     */
    private const STUDENTS = [
        // 1st year (year_level = 1)
        ['20266239', 'Andrei',  'Santos',    'M.',     'male',   'O+',  'BSIT',          1, 'Block A'],
        ['20267759', 'Bianca',  'Reyes',     'L.',     'female', 'A+',  'BSIT',          1, 'Block A'],
        ['20263381', 'Carlo',   'Garcia',    'D.',     'male',   'B+',  'BSCS',          1, 'Block A'],
        ['20267847', 'Diana',   'Mendoza',   'C.',     'female', 'AB+', 'BSCS',          1, 'Block A'],
        ['20269876', 'Eduardo', 'Cruz',      null,     'male',   'O+',  'BSN',           1, 'Block A'],

        // 2nd year (year_level = 2)
        ['20265122', 'Frances', 'Bautista',  'R.',     'female', 'O-',  'BSIT',          2, 'Block B'],
        ['20261970', 'Gabriel', 'Lopez',     'S.',     'male',   'A+',  'BSIT',          2, 'Block B'],
        ['20269617', 'Hannah',  'Rivera',    'P.',     'female', 'B+',  'BSCS',          2, 'Block B'],
        ['20267688', 'Isabel',  'Torres',    'M.',     'female', 'O+',  'BSEd',          2, 'Block B'],
        ['20264466', 'Javier',  'Gonzales',  'V.',     'male',   'A-',  'BSEd',          2, 'Block B'],

        // 3rd year (year_level = 3)
        ['20265442', 'Katrina', 'Ramos',     'D.',     'female', 'B-',  'BSIT',          3, 'Block C'],
        ['20264130', 'Luis',    'Aquino',    'B.',     'male',   'O+',  'BSCS',          3, 'Block C'],
        ['20264116', 'Maria',   'Dela Cruz', 'S.',     'female', 'O+',  'BSN',           3, 'Block C'],
        ['20268514', 'Nathan',  'Villanueva','P.',     'male',   'A+',  'BSEd',          3, 'Block C'],

        // 4th year (year_level = 4)
        ['20266041', 'Olivia',  'Marquez',   'G.',     'female', 'B+',  'BSIT',          4, 'Block D'],
        ['20267764', 'Paolo',   'Castro',    'R.',     'male',   'O+',  'BSCS',          4, 'Block D'],
        ['20263896', 'Quinn',   'Aguilar',   'T.',     'female', 'AB+', 'BSN',           4, 'Block D'],

        // 5th & 6th year (BSN has extended program years)
        ['20263151', 'Rafael',  'Santiago',  'M.',     'male',   'O+',  'BSN',           5, 'Block E'],
        ['20263139', 'Sofia',   'Mercado',   'L.',     'female', 'A+',  'BSN',           5, 'Block E'],
        ['20265406', 'Tomas',   'Flores',    'C.',     'male',   'B+',  'BSIT',          6, 'Block F'],
    ];

    /**
     * Each tuple: employee_number, first_name, last_name, middle_name,
     * gender, department_code, position, employment_status,
     * emergency_contact_name, emergency_contact_phone, is_teaching.
     *
     * `is_teaching` flips the row into the "faculty" cohort — teaching
     * staff can refer students to counselling via the
     * `ReferralController::create` policy gate (see the
     * EmployeeIsTeaching migration).
     *
     * @var list<array{0:string,1:string,2:string,3:?string,4:'male'|'female'|'other',5:string,6:string,7:'active'|'inactive'|'on_leave',8:string,9:string,10:bool}>
     */
    private const EMPLOYEES = [
        // Health Services — clinic staff
        ['20266839', 'Althea',  'Navarro',   'B.',  'female', 'HEA', 'School Nurse',         'active',   'Jose Navarro',  '09171234501', false],
        ['20263047', 'Brando',  'Del Rosario','V.','male',   'HEA', 'Medical Officer',      'active',   'Liza Del Rosario','09171234502', false],

        // Counselling
        ['20261982', 'Carla',   'Jimenez',   'M.',  'female', 'HEA', 'Guidance Counselor',   'active',   'Mark Jimenez',  '09171234503', false],
        ['20265459', 'Diego',   'Salazar',   'P.',  'male',   'HEA', 'Psychometrician',      'on_leave', 'Anna Salazar',  '09171234504', false],

        // Facilities — BMG operators (non-teaching)
        ['20266743', 'Erica',   'Tan',       'G.',  'female', 'FAC', 'BMG Lead Operator',    'active',   'Ben Tan',       '09171234505', false],
        ['20266256', 'Francis', 'Lim',       'C.',  'male',   'FAC', 'BMG Operator',         'active',   'Nora Lim',      '09171234506', false],

        // Admin
        ['20267245', 'Gina',    'Ong',       'S.',  'female', 'ADM', 'School Principal',     'active',   'Cris Ong',      '09171234507', false],
        ['20264233', 'Harold',  'Co',        'R.',  'male',   'ADM', 'Vice Principal',       'active',   'Joy Co',        '09171234508', false],

        // IT Services
        ['20261359', 'Ivy',     'Chua',      'T.',  'female', 'ITS', 'Systems Administrator','active',   'Ben Chua',      '09171234509', false],
        ['20268995', 'Jerome',  'Yap',       'D.',  'male',   'ITS', 'Network Engineer',     'inactive', 'Trish Yap',     '09171234510', false],

        // Cafeteria
        ['20267100', 'Karen',   'Sy',        'L.',  'female', 'CAF', 'Head Cook',            'active',   'Ramon Sy',      '09171234511', false],

        // Maintenance
        ['20267598', 'Leo',     'Tan',       'M.',  'male',   'MNT', 'Lead Maintenance',     'active',   'Nida Tan',      '09171234512', false],

        // Security
        ['20261376', 'Maria',   'Cruz',      'S.',  'female', 'SEC', 'Security Guard',       'active',   'Ben Cruz',      '09171234513', false],
        ['20262994', 'Noel',    'Reyes',     'V.',  'male',   'SEC', 'Security Guard',       'active',   'Lita Reyes',    '09171234514', false],

        // Registrar
        ['20261234', 'Olivia',  'Bautista',  'P.',  'female', 'REG', 'Registrar Officer',    'active',   'Mark Bautista', '09171234515', false],

        // Faculty (teaching) — can refer students to counselling.
        // Demo: 6 faculty covering BSIT, BSCS, BSN, BSEd, Junior High,
        // Senior High. Their position names show in the appointments
        // and referrals flows.
        ['20269001', 'Patricia','Cruz',      'D.',  'female', 'FAC-EDU', 'BSIT Professor',  'active',   'Liza Cruz',  '09171234601', true],
        ['20269002', 'Roberto', 'Ramos',     'M.',  'male',   'FAC-EDU', 'BSCS Professor',  'active',   'Nora Ramos', '09171234602', true],
        ['20269003', 'Stephanie','Tan',      'A.',  'female', 'FAC-EDU', 'BSN Professor',   'active',   'Ben Tan',    '09171234603', true],
        ['20269004', 'Tomas',   'Dela Cruz', 'R.',  'male',   'FAC-EDU', 'BSEd Professor',  'active',   'Joy Cruz',   '09171234604', true],
        ['20269005', 'Ursula',  'Yap',       'P.',  'female', 'FAC-EDU', 'Junior High Adviser','active', 'Mark Yap',   '09171234605', true],
        ['20269006', 'Victor',  'Lim',       'C.',  'male',   'FAC-EDU', 'Senior High Adviser','active', 'Anna Lim',   '09171234606', true],
    ];

    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('PatientRegistrySeeder must never run in production.');
        }

        $this->wipe();
        $this->seedDepartments();
        $this->seedStudents();
        $this->seedEmployees();

        fwrite(STDOUT, "PatientRegistrySeeder: 10 departments + 20 students + 21 employees inserted.\n");
        fwrite(STDOUT, "  6 teaching employees (faculty) — can refer students to counselling.\n");
        fwrite(STDOUT, "  ID format: 8 digits, YYYY#### (year + 4 random).\n");
    }

    private function wipe(): void
    {
        $db = $this->db;

        // Children first (FKs to patients_students).
        $db->table('patient_allergies')->emptyTable();
        $db->table('patient_contacts')->emptyTable();

        // Then students, then employees, then departments.
        $db->table('patients_students')->emptyTable();
        $db->table('patients_employees')->emptyTable();
        $db->table('clinic_departments')->emptyTable();

        // Drop the matching audit_outbox rows so re-seeding doesn't
        // pile up orphan entries. `audit_events` is append-only by
        // design — we never touch it.
        $db->table('audit_outbox')
            ->groupStart()
                ->like('action_code', 'clinic.patient_', 'after')
                ->orWhereIn('entity_type', [
                    'patients_students',
                    'patients_employees',
                    'patient_allergies',
                    'patient_contacts',
                    'clinic_departments',
                ])
            ->groupEnd()
            ->delete();
    }

    private function seedDepartments(): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = array_map(
            static fn (array $d) => $d + ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            self::DEPARTMENTS,
        );
        $this->db->table('clinic_departments')->insertBatch($rows);
    }

    private function seedStudents(): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach (self::STUDENTS as [$num, $first, $last, $middle, $gender, $blood, $course, $year, $section]) {
            $rows[] = [
                'student_number'         => $num,
                'first_name'             => $first,
                'last_name'              => $last,
                'middle_name'            => $middle,
                'gender'                 => $gender,
                'blood_type'             => $blood,
                'course'                 => $course,
                'year_level'             => $year,
                'section'                => $section,
                'consecutive_no_shows'   => 0,
                'created_at'             => $now,
                'updated_at'             => $now,
            ];
        }
        $this->db->table('patients_students')->insertBatch($rows);
    }

    private function seedEmployees(): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach (self::EMPLOYEES as [$num, $first, $last, $middle, $gender, $deptCode, $position, $status, $ecName, $ecPhone, $isTeaching]) {
            $rows[] = [
                'employee_number'         => $num,
                'first_name'              => $first,
                'last_name'               => $last,
                'middle_name'             => $middle,
                'gender'                  => $gender,
                'department'              => $this->deptName($deptCode),
                'position'                => $position,
                'employment_status'       => $status,
                'emergency_contact_name'  => $ecName,
                'emergency_contact_phone' => $ecPhone,
                'is_teaching'             => $isTeaching ? 1 : 0,
                'created_at'              => $now,
                'updated_at'              => $now,
            ];
        }
        $this->db->table('patients_employees')->insertBatch($rows);
    }

    /**
     * Resolve a department code to its display name (denormalized into
     * `patients_employees.department`). Keeps the employee seed
     * readable: `deptCode='HEA'` → `'Health Services'`.
     */
    private function deptName(string $code): string
    {
        foreach (self::DEPARTMENTS as $d) {
            if ($d['code'] === $code) {
                return $d['name'];
            }
        }
        return $code;
    }
}
