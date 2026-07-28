/**
 * Patient registry hooks — students + employees (Phase 11).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  addAllergySchema,
  addContactSchema,
  createDepartmentSchema,
  createEmployeeSchema,
  createStudentSchema,
  departmentSchema,
  employeeSchema,
  studentSchema,
  updateEmployeeSchema,
  updateStudentSchema,
  type AddAllergyInput,
  type AddContactInput,
  type CreateDepartmentInput,
  type CreateEmployeeInput,
  type CreateStudentInput,
  type Department,
  type Employee,
  type Student,
  type UpdateEmployeeInput,
  type UpdateStudentInput,
} from '@/schemas/patients';

interface StudentPage {
  data: Student[];
  next: string | null;
}

interface EmployeePage {
  data: Employee[];
  next: string | null;
}

export function useStudents(cursor: string | null, limit = 25) {
  return useQuery<StudentPage, ApiEnvelopeError>({
    queryKey: ['patients', 'students', { cursor, limit }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/clinic/students?${params.toString()}`,
      );
      const data = z.array(studentSchema).parse(res.data);
      return { data, next: res.data?.next ?? null };
    },
  });
}

export function useStudentSearch(q: string) {
  return useQuery<Student[], ApiEnvelopeError>({
    queryKey: ['patients', 'students', 'search', q],
    enabled: q.trim().length >= 2,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(
        `/clinic/students/search?q=${encodeURIComponent(q.trim())}`,
      );
      return z.array(studentSchema).parse(res.data);
    },
  });
}

export function useStudent(id: number | null) {
  return useQuery<Student, ApiEnvelopeError>({
    queryKey: ['patients', 'students', 'detail', id],
    enabled: id !== null,
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/clinic/students/${id}`);
      return studentSchema.parse(res.data);
    },
  });
}

export function useCreateStudent() {
  const qc = useQueryClient();
  return useMutation<Student, ApiEnvelopeError, CreateStudentInput>({
    mutationFn: async (input) => {
      const valid = createStudentSchema.parse(input);
      const res = await apiClient.post<unknown>('/clinic/students', valid);
      return studentSchema.parse(res.data);
    },
    onSuccess: (s) => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      toast.success(`Student ${s.student_number} registered.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to register student.');
    },
  });
}

/**
 * Update an existing student. Mirrors the legacy
 * `StudentController::update` (PATCH-style via POST). All fields are
 * optional on both sides — only fields the caller actually supplies
 * are sent on the wire, so the dialog can prefetch the current row
 * and diff cleanly.
 */
export function useUpdateStudent() {
  const qc = useQueryClient();
  return useMutation<Student, ApiEnvelopeError, { id: number; input: UpdateStudentInput }>({
    mutationFn: async ({ id, input }) => {
      const valid = updateStudentSchema.parse(input);
      // Strip undefined keys so the backend does not see explicit
      // nulls where the user simply did not edit a field.
      const payload: Record<string, unknown> = {};
      for (const [k, v] of Object.entries(valid)) {
        if (v !== undefined) payload[k] = v;
      }
      const res = await apiClient.post<unknown>(`/clinic/students/${id}`, payload);
      return studentSchema.parse(res.data);
    },
    onSuccess: (s) => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      void qc.invalidateQueries({ queryKey: ['patients', 'students', 'detail', s.id] });
      toast.success(`${s.last_name}, ${s.first_name} updated.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to update student.');
    },
  });
}

export function useSetStudentArchived() {
  const qc = useQueryClient();
  return useMutation<Student, ApiEnvelopeError, { id: number; archived: boolean }>({
    mutationFn: async ({ id, archived }) => {
      const res = await apiClient.post<unknown>(`/clinic/students/${id}/archive`, { archived });
      return studentSchema.parse(res.data);
    },
    onSuccess: (s) => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      toast.success(s.archived ? `${s.student_number} archived.` : `${s.student_number} restored.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Archive change failed.');
    },
  });
}

export function useAddAllergy() {
  const qc = useQueryClient();
  return useMutation<Student, ApiEnvelopeError, { studentId: number; input: AddAllergyInput }>({
    mutationFn: async ({ studentId, input }) => {
      const valid = addAllergySchema.parse(input);
      const res = await apiClient.post<unknown>(`/clinic/students/${studentId}/allergies`, valid);
      return studentSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      toast.success('Allergy recorded.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to record allergy.');
    },
  });
}

export function useAddContact() {
  const qc = useQueryClient();
  return useMutation<Student, ApiEnvelopeError, { studentId: number; input: AddContactInput }>({
    mutationFn: async ({ studentId, input }) => {
      const valid = addContactSchema.parse(input);
      const res = await apiClient.post<unknown>(`/clinic/students/${studentId}/contacts`, valid);
      return studentSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      toast.success('Emergency contact added.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to add contact.');
    },
  });
}

export function useEmployees(cursor: string | null, limit = 25) {
  return useQuery<EmployeePage, ApiEnvelopeError>({
    queryKey: ['patients', 'employees', { cursor, limit }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/clinic/employees?${params.toString()}`,
      );
      const data = z.array(employeeSchema).parse(res.data);
      return { data, next: res.data?.next ?? null };
    },
  });
}

/**
 * Live employee search (>= 2 chars). Mirrors the legacy
 * `EmployeeController::search` flow — backend matches against number,
 * first, last, middle, department, and position.
 */
export function useEmployeeSearch(q: string) {
  return useQuery<Employee[], ApiEnvelopeError>({
    queryKey: ['patients', 'employees', 'search', q],
    enabled: q.trim().length >= 2,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(
        `/clinic/employees/search?q=${encodeURIComponent(q.trim())}`,
      );
      return z.array(employeeSchema).parse(res.data);
    },
  });
}

/**
 * Single-employee detail (used by the Employees tab View dialog).
 * Mirrors `useStudent` for the students tab.
 */
export function useEmployee(id: number | null) {
  return useQuery<Employee, ApiEnvelopeError>({
    queryKey: ['patients', 'employees', 'detail', id],
    enabled: id !== null,
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/clinic/employees/${id}`);
      return employeeSchema.parse(res.data);
    },
  });
}

export function useCreateEmployee() {
  const qc = useQueryClient();
  return useMutation<Employee, ApiEnvelopeError, CreateEmployeeInput>({
    mutationFn: async (input) => {
      const valid = createEmployeeSchema.parse(input);
      const res = await apiClient.post<unknown>('/clinic/employees', valid);
      return employeeSchema.parse(res.data);
    },
    onSuccess: (e) => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      toast.success(`Employee ${e.employee_number} registered.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to register employee.');
    },
  });
}

export function useUpdateEmployee() {
  const qc = useQueryClient();
  return useMutation<Employee, ApiEnvelopeError, { id: number; input: UpdateEmployeeInput }>({
    mutationFn: async ({ id, input }) => {
      const valid = updateEmployeeSchema.parse(input);
      const res = await apiClient.post<unknown>(`/clinic/employees/${id}`, valid);
      return employeeSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      toast.success('Employee updated.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to update employee.');
    },
  });
}

export function useSetEmployeeArchived() {
  const qc = useQueryClient();
  return useMutation<Employee, ApiEnvelopeError, { id: number; archived: boolean }>({
    mutationFn: async ({ id, archived }) => {
      const res = await apiClient.post<unknown>(`/clinic/employees/${id}/archive`, { archived });
      return employeeSchema.parse(res.data);
    },
    onSuccess: (_e, vars) => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      toast.success(vars.archived ? 'Employee archived.' : 'Employee restored.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to change archive state.');
    },
  });
}

export function useDepartments(activeOnly = false) {
  return useQuery<Department[], ApiEnvelopeError>({
    queryKey: ['patients', 'departments', { activeOnly }],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/clinic/departments${activeOnly ? '?active=1' : ''}`);
      return z.array(departmentSchema).parse(res.data);
    },
  });
}

export function useCreateDepartment() {
  const qc = useQueryClient();
  return useMutation<{ id: number }, ApiEnvelopeError, CreateDepartmentInput>({
    mutationFn: async (input) => {
      const valid = createDepartmentSchema.parse(input);
      const res = await apiClient.post<{ id: number }>('/clinic/departments', valid);
      return z.object({ id: z.number().int().positive() }).parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['patients', 'departments'] });
      toast.success('Department created.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to create department.');
    },
  });
}
