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
  studentFacetsSchema,
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
  type StudentFacets,
  type UpdateEmployeeInput,
  type UpdateStudentInput,
} from '@/schemas/patients';
import {
  employeePortalClinicVisitSchema,
  type EmployeePortalClinicVisit,
} from '@/schemas/employeePortal';

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
 * Which records a registry list shows, by archive state.
 *
 *   active   — live records only (the default; nothing is sent)
 *   archived — ONLY archived records
 *   all      — live and archived together
 *
 * This replaced a boolean "include archived" switch, which could only mean
 * "live" or "live + archived" — so reviewing archived records meant scrolling
 * past every live one to find them.
 */
export type ArchiveStatus = 'active' | 'archived' | 'all';

export const ARCHIVE_STATUSES: ReadonlyArray<ArchiveStatus> = ['active', 'archived', 'all'];

/** Coerce an arbitrary URL value to a status; anything unknown is `active`. */
export function parseArchiveStatus(value: string | null | undefined): ArchiveStatus {
  return value === 'archived' || value === 'all' ? value : 'active';
}

/** `active` is the server default, so it stays off the wire. */
function setArchiveParam(params: URLSearchParams, status: ArchiveStatus | undefined): void {
  if (status !== undefined && status !== 'active') params.set('archived', status);
}

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
  status?: ArchiveStatus;
  teaching?: string;
  department?: string;
  position?: string;
}): URLSearchParams {
  const params = new URLSearchParams();

  if (input.cursor !== null && input.cursor !== '') params.set('cursor', input.cursor);
  params.set('limit', String(input.limit));
  setArchiveParam(params, input.status);

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
  status?: ArchiveStatus;
  department?: string;
  position?: string;
}): URLSearchParams {
  const params = new URLSearchParams({ q: input.q });
  setArchiveParam(params, input.status);

  if (input.department !== undefined && input.department !== FACET_ALL) {
    params.set('department', input.department);
  }
  if (input.position !== undefined && input.position !== FACET_ALL) {
    params.set('position', input.position);
  }

  return params;
}

/**
 * Query string for the student list.
 *
 * Mirrors `employeeListParams`: the `'all'` sentinel must never reach the
 * wire (the backend treats blank as "no filter", but the literal string
 * `all` would filter on a department actually named "all"), and the first
 * page omits `cursor` rather than sending an empty one.
 *
 * `year_level` travels as its numeric string; the backend binds it as an
 * integer, so there is no string comparison against a TINYINT column.
 */
export function studentListParams(input: {
  cursor: string | null;
  limit: number;
  status?: ArchiveStatus;
  department?: string;
  course?: string;
  yearLevel?: string;
}): URLSearchParams {
  const params = new URLSearchParams();

  if (input.cursor !== null && input.cursor !== '') params.set('cursor', input.cursor);
  params.set('limit', String(input.limit));
  setArchiveParam(params, input.status);

  if (input.department !== undefined && input.department !== FACET_ALL) {
    params.set('department', input.department);
  }
  if (input.course !== undefined && input.course !== FACET_ALL) {
    params.set('course', input.course);
  }
  if (input.yearLevel !== undefined && input.yearLevel !== FACET_ALL) {
    params.set('year_level', input.yearLevel);
  }

  return params;
}

/**
 * Query string for the student search. The active facets ride along so a
 * filter is not silently dropped while the user types — the same defect
 * that made the employee teaching filter look broken.
 */
export function studentSearchParams(input: {
  q: string;
  status?: ArchiveStatus;
  department?: string;
  course?: string;
  yearLevel?: string;
}): URLSearchParams {
  const params = new URLSearchParams({ q: input.q });
  setArchiveParam(params, input.status);

  if (input.department !== undefined && input.department !== FACET_ALL) {
    params.set('department', input.department);
  }
  if (input.course !== undefined && input.course !== FACET_ALL) {
    params.set('course', input.course);
  }
  if (input.yearLevel !== undefined && input.yearLevel !== FACET_ALL) {
    params.set('year_level', input.yearLevel);
  }

  return params;
}

export function useStudents(
  cursor: string | null,
  limit = 25,
  status: ArchiveStatus = 'active',
  department = FACET_ALL,
  course = FACET_ALL,
  yearLevel = FACET_ALL,
) {
  return useQuery<StudentPage, ApiEnvelopeError>({
    queryKey: [
      'patients', 'students',
      { cursor, limit, status, department, course, yearLevel },
    ],
    queryFn: async () => {
      const params = studentListParams({
        cursor,
        limit,
        status,
        department,
        course,
        yearLevel,
      });
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
  opts: {
    enabled?: boolean;
    status?: ArchiveStatus;
    department?: string;
    course?: string;
    yearLevel?: string;
  } = {},
) {
  const status = opts.status ?? 'active';
  const department = opts.department ?? FACET_ALL;
  const course = opts.course ?? FACET_ALL;
  const yearLevel = opts.yearLevel ?? FACET_ALL;
  return useQuery<Student[], ApiEnvelopeError>({
    queryKey: ['patients', 'students', 'search', q, { status, department, course, yearLevel }],
    // `enabled` must include the CALLER's permission gate, not just the
    // query length — surfaces like the command palette render the search
    // only for `clinic.patients.read` holders, and without this gate
    // every keystroke still fired the request and collected 403s
    // (2026-09 audit).
    enabled: (opts.enabled ?? true) && q.trim().length >= 2,
    queryFn: async () => {
      const params = studentSearchParams({ q: q.trim(), status, department, course, yearLevel });
      const res = await apiClient.get<unknown[]>(
        `/clinic/students/search?${params.toString()}`,
      );
      return z.array(studentSchema).parse(res.data);
    },
  });
}

/**
 * Facet options for the Students tab filters — distinct MIS-supplied
 * department / course / year level values present in the registry, within
 * the archive `status` being viewed (live by default). In the archived view
 * the selects must offer what archived students actually have.
 *
 * Unlike `useEmployeeFacets`' source, these come from our own synced rows
 * rather than a MIS lookup call, so the selects still populate when the
 * campus network (and therefore the MIS API) is unreachable.
 */
export function useStudentFacets(status: ArchiveStatus = 'active') {
  return useQuery<StudentFacets, ApiEnvelopeError>({
    queryKey: ['patients', 'students', 'facets', status],
    queryFn: async () => {
      const params = new URLSearchParams();
      setArchiveParam(params, status);
      const qs = params.toString();
      const res = await apiClient.get<unknown>(`/clinic/students/facets${qs === '' ? '' : `?${qs}`}`);
      return studentFacetsSchema.parse(res.data);
    },
    // Facets change only when the registry is re-synced.
    staleTime: 5 * 60 * 1000,
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

/** URL segment for the medical-record endpoints of a patient kind. */
export function patientMedicalSegment(kind: 'student' | 'employee'): 'students' | 'employees' {
  return kind === 'employee' ? 'employees' : 'students';
}

/**
 * Detail for either registry kind — students and employees share one
 * person shape (allergies + emergency contacts included on both).
 */
export function usePatientDetail(kind: 'student' | 'employee', idOrIdentifier: number | string | null) {
  return useQuery<Student, ApiEnvelopeError>({
    queryKey: ['patients', kind, 'detail', idOrIdentifier],
    enabled: idOrIdentifier !== null && idOrIdentifier !== '',
    queryFn: async () => {
      const res = await apiClient.get<unknown>(
        `/clinic/${patientMedicalSegment(kind)}/${encodeURIComponent(String(idOrIdentifier))}`,
      );
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
  return useMutation<Student, ApiEnvelopeError, { kind: 'student' | 'employee'; patientId: number; input: AddAllergyInput }>({
    mutationFn: async ({ kind, patientId, input }) => {
      const valid = addAllergySchema.parse(input);
      const res = await apiClient.post<unknown>(`/clinic/${patientMedicalSegment(kind)}/${patientId}/allergies`, valid);
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
  return useMutation<Student, ApiEnvelopeError, { kind: 'student' | 'employee'; patientId: number; input: AddContactInput }>({
    mutationFn: async ({ kind, patientId, input }) => {
      const valid = addContactSchema.parse(input);
      const res = await apiClient.post<unknown>(`/clinic/${patientMedicalSegment(kind)}/${patientId}/contacts`, valid);
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
  return useMutation<Student, ApiEnvelopeError, { kind: 'student' | 'employee'; patientId: number; allergyId: number; input: AddAllergyInput }>({
    mutationFn: async ({ kind, patientId, allergyId, input }) => {
      const valid = addAllergySchema.parse(input);
      const res = await apiClient.post<unknown>(
        `/clinic/${patientMedicalSegment(kind)}/${patientId}/allergies/${allergyId}`,
        valid,
      );
      return studentSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      toast.success('Allergy updated.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to update allergy.');
    },
  });
}

export function useDeleteAllergy() {
  const qc = useQueryClient();
  return useMutation<Student, ApiEnvelopeError, { kind: 'student' | 'employee'; patientId: number; allergyId: number }>({
    mutationFn: async ({ kind, patientId, allergyId }) => {
      const res = await apiClient.post<unknown>(
        `/clinic/${patientMedicalSegment(kind)}/${patientId}/allergies/${allergyId}/delete`,
        {},
      );
      return studentSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      toast.success('Allergy removed.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to remove allergy.');
    },
  });
}

export function useUpdateContact() {
  const qc = useQueryClient();
  return useMutation<Student, ApiEnvelopeError, { kind: 'student' | 'employee'; patientId: number; contactId: number; input: AddContactInput }>({
    mutationFn: async ({ kind, patientId, contactId, input }) => {
      const valid = addContactSchema.parse(input);
      const res = await apiClient.post<unknown>(
        `/clinic/${patientMedicalSegment(kind)}/${patientId}/contacts/${contactId}`,
        valid,
      );
      return studentSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
      toast.success('Emergency contact updated.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to update contact.');
    },
  });
}

export function useDeleteContact() {
  const qc = useQueryClient();
  return useMutation<Student, ApiEnvelopeError, { kind: 'student' | 'employee'; patientId: number; contactId: number }>({
    mutationFn: async ({ kind, patientId, contactId }) => {
      const res = await apiClient.post<unknown>(
        `/clinic/${patientMedicalSegment(kind)}/${patientId}/contacts/${contactId}/delete`,
        {},
      );
      return studentSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['patients'] });
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
  status: ArchiveStatus = 'active',
  teaching: 'all' | 'teaching' | 'non_teaching' = 'all',
  department = 'all',
  position = 'all',
) {
  return useQuery<EmployeePage, ApiEnvelopeError>({
    queryKey: [
      'patients', 'employees',
      { cursor, limit, status, teaching, department, position },
    ],
    queryFn: async () => {
      const params = employeeListParams({
        cursor,
        limit,
        status,
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
 * department / position values present in the directory within the archive
 * `status` being viewed (live by default). Served from
 * the synced rows so every option is guaranteed to match at least one
 * employee (unlike the dropped `clinic_departments` picker, whose
 * hand-entered rows never lined up with MIS values).
 */
export function useEmployeeFacets(status: ArchiveStatus = 'active') {
  return useQuery<{ departments: string[]; positions: string[] }, ApiEnvelopeError>({
    queryKey: ['patients', 'employees', 'facets', status],
    queryFn: async () => {
      const params = new URLSearchParams();
      setArchiveParam(params, status);
      const qs = params.toString();
      const res = await apiClient.get<unknown>(`/clinic/employees/facets${qs === '' ? '' : `?${qs}`}`);
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
export function useEmployeeSearch(
  q: string,
  department = 'all',
  position = 'all',
  status: ArchiveStatus = 'active',
) {
  return useQuery<Employee[], ApiEnvelopeError>({
    queryKey: ['patients', 'employees', 'search', q, { status, department, position }],
    enabled: q.trim().length >= 2,
    queryFn: async () => {
      const params = employeeSearchParams({ q: q.trim(), status, department, position });
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

/**
 * One employee record's clinic encounters — the registry row accordion's
 * per-record history. Lazily fetched when a record's section expands
 * (`enabled` is the caller's choice), 0-visit records resolve to [].
 * Same wire shape as the portal's clinic visits, so one schema parses both.
 */
export function useEmployeeEncounters(employeeId: number | null, enabled: boolean) {
  return useQuery<EmployeePortalClinicVisit[], ApiEnvelopeError>({
    queryKey: ['patients', 'employees', 'encounters', employeeId],
    enabled: enabled && employeeId !== null,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(
        `/clinic/employees/${employeeId}/encounters?limit=50`,
      );
      return res.data.map((v) => employeePortalClinicVisitSchema.parse(v));
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
