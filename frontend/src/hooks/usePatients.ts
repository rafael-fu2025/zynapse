/**
 * Patient registry hooks — students + employees (Phase 11).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient, getNextCursor } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  addAllergySchema,
  addContactSchema,
  createEmployeeSchema,
  createStudentSchema,
  employeeFacetsSchema,
  employeeSchema,
  portalAccountSchema,
  studentSchema,
  updateEmployeeSchema,
  updateStudentSchema,
  type AddAllergyInput,
  type AddContactInput,
  type CreateEmployeeInput,
  type CreateStudentInput,
  type Employee,
  type PortalAccount,
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

/** UI sentinel meaning "no facet filter". */
export const FACET_ALL = 'all';

/**
 * Query string for the employee list.
 *
 * Pure so it can be tested directly: the `'all'` sentinel must never reach
 * the wire (the backend treats a blank value as "no filter", but sending the
 * literal string `all` would filter on a department named "all"), and the
 * first page must omit `cursor` rather than send an empty one.
 */
export function employeeListParams(input: {
  cursor: string | null;
  limit: number;
  includeArchived: boolean;
  teaching?: string;
  department?: string;
  position?: string;
}): URLSearchParams {
  const params = new URLSearchParams();

  if (input.cursor !== null && input.cursor !== '') params.set('cursor', input.cursor);
  params.set('limit', String(input.limit));
  if (input.includeArchived) params.set('include_archived', '1');

  if (input.teaching !== undefined && input.teaching !== FACET_ALL) {
    params.set('teaching', input.teaching);
  }
  if (input.department !== undefined && input.department !== FACET_ALL) {
    params.set('department', input.department);
  }
  if (input.position !== undefined && input.position !== FACET_ALL) {
    params.set('position', input.position);
  }

  return params;
}

/**
 * Query string for the employee search. The active facets ride along so a
 * filter is not silently dropped while the user types.
 */
export function employeeSearchParams(input: {
  q: string;
  department?: string;
  position?: string;
}): URLSearchParams {
  const params = new URLSearchParams({ q: input.q });

  if (input.department !== undefined && input.department !== FACET_ALL) {
    params.set('department', input.department);
  }
  if (input.position !== undefined && input.position !== FACET_ALL) {
    params.set('position', input.position);
  }

  return params;
}

export function useStudents(cursor: string | null, limit = 25, includeArchived = false) {
  return useQuery<StudentPage, ApiEnvelopeError>({
    queryKey: ['patients', 'students', { cursor, limit, includeArchived }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      if (includeArchived) params.set('include_archived', '1');
      const res = await apiClient.get<unknown[]>(
        `/clinic/students?${params.toString()}`,
      );
      const data = z.array(studentSchema).parse(res.data);
      // Pagination lives on `synapseMeta` after the response normalizer
      // unwraps the envelope, so `res.data.next` is always undefined and
      // left the Next button permanently disabled.
      return { data, next: getNextCursor(res) };
    },
  });
}

export function useStudentSearch(
  q: string,
  opts: { enabled?: boolean } = {},
) {
  return useQuery<Student[], ApiEnvelopeError>({
    queryKey: ['patients', 'students', 'search', q],
    // `enabled` must include the CALLER's permission gate, not just the
    // query length — surfaces like the command palette render the search
    // only for `clinic.patients.read` holders, and without this gate
    // every keystroke still fired the request and collected 403s
    // (2026-09 audit).
    enabled: (opts.enabled ?? true) && q.trim().length >= 2,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(
        `/clinic/students/search?q=${encodeURIComponent(q.trim())}`,
      );
      return z.array(studentSchema).parse(res.data);
    },
  });
}

export function useStudent(idOrIdentifier: number | string | null) {
  return useQuery<Student, ApiEnvelopeError>({
    queryKey: ['patients', 'students', 'detail', idOrIdentifier],
    enabled: idOrIdentifier !== null && idOrIdentifier !== '',
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/clinic/students/${encodeURIComponent(String(idOrIdentifier))}`);
      return studentSchema.parse(res.data);
    },
  });
}

export function useCreateStudent() {
  const qc = useQueryClient();
  // Phase 3.5: the response may also include a portal_account envelope
  // (when create_account=true on the payload). We merge it into the
  // Student type so callers can read result.portal_account.
  type CreateStudentResult = Student & { portal_account?: PortalAccount };
  return useMutation<CreateStudentResult, ApiEnvelopeError, CreateStudentInput>({
    mutationFn: async (input) => {
      const valid = createStudentSchema.parse(input);
      const res = await apiClient.post<unknown>('/clinic/students', valid);
      // Parse the known patient fields first, then attach the optional
      // portal_account envelope untouched.
      const parsed = studentSchema.parse(res.data);
      const envelope = (res.data as Record<string, unknown>).portal_account;
      const merged: CreateStudentResult = { ...parsed };
      if (envelope !== undefined && envelope !== null && typeof envelope === 'object') {
        merged.portal_account = portalAccountSchema.parse(envelope);
      }
      return merged;
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

// Edit / remove for allergies and emergency contacts (2026-08-12).

export function useUpdateAllergy() {
  const qc = useQueryClient();
  return useMutation<Student, ApiEnvelopeError, { studentId: number; allergyId: number; input: AddAllergyInput }>({
    mutationFn: async ({ studentId, allergyId, input }) => {
      const valid = addAllergySchema.parse(input);
      const res = await apiClient.post<unknown>(
        `/clinic/students/${studentId}/allergies/${allergyId}`,
        valid,
      );
      return studentSchema.parse(res.data);
    },
    onSuccess: (s) => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      void qc.invalidateQueries({ queryKey: ['patients', 'students', 'detail', s.id] });
      toast.success('Allergy updated.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to update allergy.');
    },
  });
}

export function useDeleteAllergy() {
  const qc = useQueryClient();
  return useMutation<Student, ApiEnvelopeError, { studentId: number; allergyId: number }>({
    mutationFn: async ({ studentId, allergyId }) => {
      const res = await apiClient.post<unknown>(
        `/clinic/students/${studentId}/allergies/${allergyId}/delete`,
        {},
      );
      return studentSchema.parse(res.data);
    },
    onSuccess: (s) => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      void qc.invalidateQueries({ queryKey: ['patients', 'students', 'detail', s.id] });
      toast.success('Allergy removed.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to remove allergy.');
    },
  });
}

export function useUpdateContact() {
  const qc = useQueryClient();
  return useMutation<Student, ApiEnvelopeError, { studentId: number; contactId: number; input: AddContactInput }>({
    mutationFn: async ({ studentId, contactId, input }) => {
      const valid = addContactSchema.parse(input);
      const res = await apiClient.post<unknown>(
        `/clinic/students/${studentId}/contacts/${contactId}`,
        valid,
      );
      return studentSchema.parse(res.data);
    },
    onSuccess: (s) => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      void qc.invalidateQueries({ queryKey: ['patients', 'students', 'detail', s.id] });
      toast.success('Emergency contact updated.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to update contact.');
    },
  });
}

export function useDeleteContact() {
  const qc = useQueryClient();
  return useMutation<Student, ApiEnvelopeError, { studentId: number; contactId: number }>({
    mutationFn: async ({ studentId, contactId }) => {
      const res = await apiClient.post<unknown>(
        `/clinic/students/${studentId}/contacts/${contactId}/delete`,
        {},
      );
      return studentSchema.parse(res.data);
    },
    onSuccess: (s) => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      void qc.invalidateQueries({ queryKey: ['patients', 'students', 'detail', s.id] });
      toast.success('Emergency contact removed.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to remove contact.');
    },
  });
}

/**
 * Employee list with the MIS-backed facet filters.
 *
 * `department` and `position` are the only two categorical fields the FU
 * MIS employee payload actually carries (`department_name` + `position`),
 * so they are the only facet filters the UI offers. The legacy `teaching`
 * argument is retained because the mobile client still sends it, but the
 * web UI no longer surfaces it — MIS supplies no teaching flag.
 *
 * `'all'` is the UI sentinel and is never sent on the wire.
 */
export function useEmployees(
  cursor: string | null,
  limit = 25,
  includeArchived = false,
  teaching: 'all' | 'teaching' | 'non_teaching' = 'all',
  department = 'all',
  position = 'all',
) {
  return useQuery<EmployeePage, ApiEnvelopeError>({
    queryKey: [
      'patients', 'employees',
      { cursor, limit, includeArchived, teaching, department, position },
    ],
    queryFn: async () => {
      const params = employeeListParams({
        cursor,
        limit,
        includeArchived,
        teaching,
        department,
        position,
      });
      const res = await apiClient.get<unknown[]>(
        `/clinic/employees?${params.toString()}`,
      );
      const data = z.array(employeeSchema).parse(res.data);
      // See useStudents — the cursor comes from `synapseMeta`, not `data`.
      return { data, next: getNextCursor(res) };
    },
  });
}

/**
 * Facet options for the Employees tab filters — distinct MIS-supplied
 * department / position values present in the live directory. Served from
 * the synced rows so every option is guaranteed to match at least one
 * employee (unlike the dropped `clinic_departments` picker, whose
 * hand-entered rows never lined up with MIS values).
 */
export function useEmployeeFacets() {
  return useQuery<{ departments: string[]; positions: string[] }, ApiEnvelopeError>({
    queryKey: ['patients', 'employees', 'facets'],
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/clinic/employees/facets');
      return employeeFacetsSchema.parse(res.data);
    },
    // Facets change only when the directory is re-synced.
    staleTime: 5 * 60 * 1000,
  });
}

/**
 * Live employee search (>= 2 chars). Mirrors the legacy
 * `EmployeeController::search` flow — backend matches against number,
 * first, last, middle, department, and position. The facet filters stay in
 * force during search so narrowing does not silently reset while typing.
 */
export function useEmployeeSearch(q: string, department = 'all', position = 'all') {
  return useQuery<Employee[], ApiEnvelopeError>({
    queryKey: ['patients', 'employees', 'search', q, { department, position }],
    enabled: q.trim().length >= 2,
    queryFn: async () => {
      const params = employeeSearchParams({ q: q.trim(), department, position });
      const res = await apiClient.get<unknown[]>(
        `/clinic/employees/search?${params.toString()}`,
      );
      return z.array(employeeSchema).parse(res.data);
    },
  });
}

/**
 * Single-employee detail (used by the Employees tab View dialog).
 * Mirrors `useStudent` for the students tab.
 */
export function useEmployee(idOrIdentifier: number | string | null) {
  return useQuery<Employee, ApiEnvelopeError>({
    queryKey: ['patients', 'employees', 'detail', idOrIdentifier],
    enabled: idOrIdentifier !== null && idOrIdentifier !== '',
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/clinic/employees/${encodeURIComponent(String(idOrIdentifier))}`);
      return employeeSchema.parse(res.data);
    },
  });
}

export function useCreateEmployee() {
  const qc = useQueryClient();
  type CreateEmployeeResult = Employee & { portal_account?: PortalAccount };
  return useMutation<CreateEmployeeResult, ApiEnvelopeError, CreateEmployeeInput>({
    mutationFn: async (input) => {
      const valid = createEmployeeSchema.parse(input);
      const res = await apiClient.post<unknown>('/clinic/employees', valid);
      const parsed = employeeSchema.parse(res.data);
      const envelope = (res.data as Record<string, unknown>).portal_account;
      const merged: CreateEmployeeResult = { ...parsed };
      if (envelope !== undefined && envelope !== null && typeof envelope === 'object') {
        merged.portal_account = portalAccountSchema.parse(envelope);
      }
      return merged;
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
