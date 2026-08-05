// Verify the live API response parses cleanly through the frontend Zod schema.
// Plain JS to avoid needing a TS runner.
const { z } = require('zod');

const allergySchema = z.object({
  id: z.number().int().positive(),
  allergen: z.string(),
  severity: z.enum(['mild', 'moderate', 'severe']),
  reaction: z.string().nullable(),
});

const contactSchema = z.object({
  id: z.number().int().positive(),
  contact_name: z.string(),
  relationship: z.string(),
  phone: z.string(),
  is_primary: z.boolean(),
});

const studentSchema = z.object({
  kind: z.enum(['student', 'employee', 'contractor', 'alumni']).optional(),
  persons_id: z.number().int().positive().nullable().optional(),
  patient_identifier_id: z.number().int().positive().nullable(),
  identifier: z.string().optional(),
  user_id: z.number().int().positive().nullable(),
  id: z.number().int().positive(),
  student_number: z.string(),
  first_name: z.string(),
  last_name: z.string(),
  middle_name: z.string().nullable(),
  course: z.string().nullable(),
  year_level: z.number().int().nullable(),
  section: z.string().nullable(),
  date_of_birth: z.string().nullable(),
  gender: z.string().nullable(),
  blood_type: z.string().nullable(),
  has_qr: z.boolean(),
  has_rfid: z.boolean(),
  consecutive_no_shows: z.number().int().min(0),
  archived: z.boolean(),
  created_at: z.string(),
  allergies: z.array(allergySchema).optional(),
  contacts: z.array(contactSchema).optional(),
});

const employeeSchema = z.object({
  kind: z.enum(['student', 'employee', 'contractor', 'alumni']).optional(),
  persons_id: z.number().int().positive().nullable().optional(),
  patient_identifier_id: z.number().int().positive().nullable(),
  identifier: z.string().optional(),
  user_id: z.number().int().positive().nullable(),
  id: z.number().int().positive(),
  employee_number: z.string(),
  first_name: z.string(),
  last_name: z.string(),
  middle_name: z.string().nullable(),
  department: z.string().nullable(),
  position: z.string().nullable(),
  date_hired: z.string().nullable(),
  employment_status: z.enum(['active', 'inactive', 'on_leave']),
  hr_synced_at: z.string().nullable(),
  emergency_contact_name: z.string().nullable(),
  emergency_contact_phone: z.string().nullable(),
  has_qr: z.boolean(),
  has_rfid: z.boolean(),
  is_teaching: z.boolean(),
  archived: z.boolean(),
  created_at: z.string(),
});

async function login() {
  const r = await fetch('http://127.0.0.1:8090/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email: 'admin@synapse.dev', password: 'DevPassw0rd!' }),
  });
  const env = await r.json();
  if (!env.success) throw new Error('login failed: ' + JSON.stringify(env));
  return env.data.access_token;
}

async function getPage(token, path) {
  const r = await fetch('http://127.0.0.1:8090' + path, {
    headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
  });
  const env = await r.json();
  if (!env.success) throw new Error(path + ' failed: ' + JSON.stringify(env.errors));
  return env.data;
}

(async () => {
  const token = await login();

  const students = await getPage(token, '/api/v1/clinic/students?limit=2');
  console.log('--- /clinic/students row 0 ---');
  console.log(JSON.stringify(students[0], null, 2));

  const sParse = z.array(studentSchema).safeParse(students);
  console.log('\nstudents parse ok?', sParse.success);
  if (!sParse.success) {
    console.log('Issue count:', sParse.error.issues.length);
    console.log('First 3 issues:');
    for (const issue of sParse.error.issues.slice(0, 3)) {
      console.log(JSON.stringify(issue, null, 2));
    }
  }

  const employees = await getPage(token, '/api/v1/clinic/employees?limit=2');
  console.log('\n--- /clinic/employees row 0 ---');
  console.log(JSON.stringify(employees[0], null, 2));

  const eParse = z.array(employeeSchema).safeParse(employees);
  console.log('\nemployees parse ok?', eParse.success);
  if (!eParse.success) {
    console.log('Issue count:', eParse.error.issues.length);
    console.log('First 3 issues:');
    for (const issue of eParse.error.issues.slice(0, 3)) {
      console.log(JSON.stringify(issue, null, 2));
    }
  }
})().catch((e) => {
  console.error('FAILED:', e);
  process.exit(1);
});
