/**
 * Zod schemas — Patient Registry (mirrors backend PatientController rules).
 * Phase 2.2: createStudentSchema and createEmployeeSchema now accept
 * optional `create_account` + `account_email` so admins can create
 * a portal login at the same time as the patient record.
 */
import { z } from 'zod';

// Phase 3.5: portal-account envelope returned by the backend when
// create_account=true on createStudent/createEmployee.
export const portalAccountSchema = z.object({
  email: z.string().email(),
  temporary_password: z.string().min(12),
  user_id: z.number().int().positive(),
  persons_id: z.number().int().positive(),
});
export type PortalAccount = z.infer<typeof portalAccountSchema>;

export const allergySchema = z.object({
  id: z.number().int().positive(),
  allergen: z.string(),
  severity: z.enum(['mild', 'moderate', 'severe']),
  reaction: z.string().nullable(),
});
export type Allergy = z.infer<typeof allergySchema>;

export const contactSchema = z.object({
  id: z.number().int().positive(),
  contact_name: z.string(),
  relationship: z.string(),
  phone: z.string(),
  is_primary: z.boolean(),
});
export type EmergencyContact = z.infer<typeof contactSchema>;

export const studentSchema = z.object({
  // Phase 2.2: new top-level fields exposed by the unified PersonDto.
  // Optional so the schema accepts legacy patient rows during the
  // transition window.
  kind: z.enum(['student', 'employee', 'contractor', 'alumni']).optional(),
  persons_id: z.number().int().positive().optional(),
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
export type Student = z.infer<typeof studentSchema>;

export const employeeSchema = z.object({
  // Phase 2.2: see note in studentSchema above.
  kind: z.enum(['student', 'employee', 'contractor', 'alumni']).optional(),
  persons_id: z.number().int().positive().optional(),
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
export type Employee = z.infer<typeof employeeSchema>;

export const createStudentSchema = z.object({
  student_number: z.string().min(1, 'Required').max(50),
  first_name: z.string().min(1, 'Required').max(100),
  last_name: z.string().min(1, 'Required').max(100),
  middle_name: z.string().max(100).optional(),
  course: z.string().max(100).optional(),
  year_level: z.number().int().min(1).max(6).optional(),
  section: z.string().max(20).optional(),
  gender: z.enum(['male', 'female', 'other']).optional(),
  blood_type: z.string().max(5).optional(),
  // Phase 2.2: optional portal-login creation (admin-only, requires
  // rbac.manage on the backend). When true, account_email must be set.
  create_account: z.boolean().default(false),
  account_email: z.string().email().optional(),
});
export type CreateStudentInput = z.infer<typeof createStudentSchema>;

export const updateStudentSchema = z.object({
  first_name:    z.string().max(100).optional(),
  last_name:     z.string().max(100).optional(),
  middle_name:   z.string().max(100).optional().or(z.literal('')),
  course:        z.string().max(100).optional().or(z.literal('')),
  year_level:    z.number().int().min(1).max(6).optional(),
  section:       z.string().max(20).optional().or(z.literal('')),
  date_of_birth: z.string().max(10).optional().or(z.literal('')),
  gender:        z.enum(['male', 'female', 'other']).optional(),
  blood_type:    z.string().max(5).optional().or(z.literal('')),
});
export type UpdateStudentInput = z.infer<typeof updateStudentSchema>;

export const createEmployeeSchema = z.object({
  employee_number: z.string().min(1, 'Required').max(50),
  first_name: z.string().min(1, 'Required').max(100),
  last_name: z.string().min(1, 'Required').max(100),
  department: z.string().max(100).optional(),
  position: z.string().max(100).optional(),
  employment_status: z.enum(['active', 'inactive', 'on_leave']).default('active'),
  // Phase 2.2: optional portal-login creation.
  create_account: z.boolean().default(false),
  account_email: z.string().email().optional(),
});
export type CreateEmployeeInput = z.infer<typeof createEmployeeSchema>;

export const updateEmployeeSchema = z.object({
  first_name: z.string().max(100).optional(),
  last_name: z.string().max(100).optional(),
  department: z.string().max(100).optional().or(z.literal('')),
  position: z.string().max(100).optional().or(z.literal('')),
  employment_status: z.enum(['active', 'inactive', 'on_leave']).optional(),
  is_teaching: z.boolean().optional(),
});
export type UpdateEmployeeInput = z.infer<typeof updateEmployeeSchema>;

export const departmentSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  code: z.string(),
  is_active: z.boolean(),
});
export type Department = z.infer<typeof departmentSchema>;

export const allergyCreateSchema = z.object({
  allergen: z.string().min(1, 'Required').max(200),
  severity: z.enum(['mild', 'moderate', 'severe']),
  reaction: z.string().max(2000).optional(),
});
export type AllergyCreateInput = z.infer<typeof allergyCreateSchema>;

export const contactCreateSchema = z.object({
  contact_name: z.string().min(1, 'Required').max(150),
  relationship: z.string().min(1, 'Required').max(50),
  phone: z.string().min(1, 'Required').max(20),
  is_primary: z.boolean().default(false),
});
export type ContactCreateInput = z.infer<typeof contactCreateSchema>;

// Phase 4 cleanup: aliases for the legacy add* schema names so
// usePatients/PatientsPage continue to compile. The canonical names
// are allergyCreateSchema / contactCreateSchema / departmentSchema.
export const addAllergySchema = allergyCreateSchema;
export type AddAllergyInput = AllergyCreateInput;
export const addContactSchema = contactCreateSchema;
export type AddContactInput = ContactCreateInput;
export const createDepartmentSchema = departmentSchema.pick({ name: true, code: true });
export type CreateDepartmentInput = { name: string; code: string };

export const studentsListQuerySchema = z.object({
  q: z.string().optional(),
  archived: z.enum(['true', 'false', 'all']).optional(),
  limit: z.number().int().min(1).max(100).optional(),
  cursor: z.string().optional(),
});
export type StudentsListQuery = z.infer<typeof studentsListQuerySchema>;
