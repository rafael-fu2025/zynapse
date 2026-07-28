/**
 * Zod schemas — Patient Registry (mirrors backend PatientController rules).
 */
import { z } from 'zod';

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
  id: z.number().int().positive(),
  student_number: z.string(),
  first_name: z.string(),
  last_name: z.string(),
  middle_name: z.string().nullable(),
  course: z.string().nullable(),
  year_level: z.number().int().nullable(),
  section: z.string().nullable(),
  date_of_birth: z.string().nullable(),
  gender: z.enum(['male', 'female', 'other']).nullable(),
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
});
export type CreateStudentInput = z.infer<typeof createStudentSchema>;

/**
 * Update-student fields. All optional — the backend treats missing
 * keys as "no change". The backend enforces the same field set as
 * `studentRules(false)` in PatientController.
 */
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
  description: z.string().nullable(),
  is_active: z.boolean(),
});
export type Department = z.infer<typeof departmentSchema>;

export const createDepartmentSchema = z.object({
  name: z.string().min(1, 'Required').max(100),
  code: z.string().min(1, 'Required').max(20),
  description: z.string().max(1000).optional(),
});
export type CreateDepartmentInput = z.infer<typeof createDepartmentSchema>;

export const addAllergySchema = z.object({
  allergen: z.string().min(1, 'Required').max(200),
  severity: z.enum(['mild', 'moderate', 'severe']).default('mild'),
  reaction: z.string().max(2000).optional(),
});
export type AddAllergyInput = z.infer<typeof addAllergySchema>;

export const addContactSchema = z.object({
  contact_name: z.string().min(1, 'Required').max(150),
  relationship: z.string().min(1, 'Required').max(50),
  phone: z.string().min(1, 'Required').max(20),
  is_primary: z.boolean().default(false),
});
export type AddContactInput = z.infer<typeof addContactSchema>;
