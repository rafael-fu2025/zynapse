/**
 * PatientsPage — patient registry (Phase 11, recycled from synapse_ag).
 *
 * Students tab: keyset-paginated list with live search (>= 2 chars),
 * registration dialog (RHF + Zod), and a detail dialog that manages
 * allergies + emergency contacts. Employees tab: list + registration.
 * Archive is soft — registry rows are never deleted.
 */
import { zodResolver } from '@hookform/resolvers/zod';
import {
  Archive,
  ArchiveRestore,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Eye,
  HeartPulse,
  Loader2,
  Pencil,
  Phone,
  Plus,
  Search,
  UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { QueryErrorRow } from '@/components/QueryErrorState';
import { MobileCardList, MobileCard, MobileCardField, MobileCardActions } from '@/components/MobileCardList';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useTabParam } from '@/hooks/useTabParam';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  useAddAllergy,
  useAddContact,
  useCreateDepartment,
  useCreateEmployee,
  useCreateStudent,
  useDepartments,
  useEmployee,
  useEmployeeSearch,
  useEmployees,
  useSetEmployeeArchived,
  useSetStudentArchived,
  useStudent,
  useStudentSearch,
  useStudents,
  useUpdateEmployee,
  useUpdateStudent,
} from '@/hooks/usePatients';
import {
  addAllergySchema,
  addContactSchema,
  createDepartmentSchema,
  createEmployeeSchema,
  createStudentSchema,
  updateEmployeeSchema,
  updateStudentSchema,
  type AddAllergyInput,
  type AddContactInput,
  type CreateDepartmentInput,
  type CreateEmployeeInput,
  type CreateStudentInput,
  type Employee,
  type Student,
  type UpdateEmployeeInput,
  type UpdateStudentInput,
} from '@/schemas/patients';

const SEVERITY_VARIANT = { mild: 'info', moderate: 'warning', severe: 'destructive' } as const;

function CreateStudentDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateStudent();
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<CreateStudentInput>({ resolver: zodResolver(createStudentSchema) });

  const gender = watch('gender');

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Register student</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="student_number">Student number</Label>
          <Input id="student_number" aria-invalid={errors.student_number !== undefined} {...register('student_number')} />
          {errors.student_number !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.student_number.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="first_name">First name</Label>
          <Input id="first_name" aria-invalid={errors.first_name !== undefined} {...register('first_name')} />
          {errors.first_name !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.first_name.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="last_name">Last name</Label>
          <Input id="last_name" aria-invalid={errors.last_name !== undefined} {...register('last_name')} />
          {errors.last_name !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.last_name.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="course">Course</Label>
          <Input id="course" {...register('course')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="year_level">Year level (1–6)</Label>
          <Input
            id="year_level"
            type="number"
            min={1}
            max={6}
            aria-invalid={errors.year_level !== undefined}
            {...register('year_level', { setValueAs: (v: string) => (v === '' ? undefined : Number(v)) })}
          />
          {errors.year_level !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.year_level.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label id="gender-label">Gender</Label>
          <Select value={gender ?? ''} onValueChange={(v) => setValue('gender', v as CreateStudentInput['gender'])}>
            <SelectTrigger aria-labelledby="gender-label"><SelectValue placeholder="Select…" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="male">Male</SelectItem>
              <SelectItem value="female">Female</SelectItem>
              <SelectItem value="other">Other</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="blood_type">Blood type</Label>
          <Input id="blood_type" placeholder="O+" {...register('blood_type')} />
        </div>
        <DialogFooter className="col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Register
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

/**
 * EditStudentDialog — mirrors the legacy `StudentController::edit`
 * form (Phase 11). All fields are optional on the backend, so we
 * only PATCH what the user actually changed: cleared inputs become
 * the empty string, which the hook strips out of the payload.
 */
function EditStudentDialog({ student, onClose }: { student: Student; onClose: () => void }) {
  const update = useUpdateStudent();
  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    formState: { errors },
  } = useForm<UpdateStudentInput>({
    resolver: zodResolver(updateStudentSchema),
    defaultValues: {
      first_name:  student.first_name,
      last_name:   student.last_name,
      middle_name: student.middle_name ?? '',
      course:      student.course ?? '',
      year_level:  student.year_level ?? undefined,
      section:     student.section ?? '',
      gender:      student.gender ?? undefined,
      blood_type:  student.blood_type ?? '',
    },
  });
  const gender = watch('gender');

  const onSubmit = handleSubmit((values) => {
    update.mutate({ id: student.id, input: values }, { onSuccess: () => { reset(); onClose(); } });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Edit student — {student.student_number}</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="es-first">First name</Label>
          <Input id="es-first" {...register('first_name')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="es-last">Last name</Label>
          <Input id="es-last" {...register('last_name')} />
        </div>
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="es-middle">Middle name</Label>
          <Input id="es-middle" {...register('middle_name')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="es-course">Course</Label>
          <Input id="es-course" {...register('course')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="es-year">Year level (1–6)</Label>
          <Input
            id="es-year"
            type="number"
            min={1}
            max={6}
            aria-invalid={errors.year_level !== undefined}
            {...register('year_level', { setValueAs: (v: string) => (v === '' ? undefined : Number(v)) })}
          />
        </div>
        <div className="space-y-1.5">
          <Label id="es-gender-label">Gender</Label>
          <Select
            value={gender ?? ''}
            onValueChange={(v) => setValue('gender', v as UpdateStudentInput['gender'])}
          >
            <SelectTrigger aria-labelledby="es-gender-label"><SelectValue placeholder="Select…" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="male">Male</SelectItem>
              <SelectItem value="female">Female</SelectItem>
              <SelectItem value="other">Other</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="es-blood">Blood type</Label>
          <Input id="es-blood" placeholder="O+" {...register('blood_type')} />
        </div>
        <DialogFooter className="col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={update.isPending}>
            {update.isPending && <Loader2 className="animate-spin" />} Save
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function StudentDetailDialog({ studentId, onClose }: { studentId: number; onClose: () => void }) {
  const detail = useStudent(studentId);
  const addAllergy = useAddAllergy();
  const addContact = useAddContact();

  const allergyForm = useForm<AddAllergyInput>({
    resolver: zodResolver(addAllergySchema),
    defaultValues: { severity: 'mild' },
  });
  const contactForm = useForm<AddContactInput>({
    resolver: zodResolver(addContactSchema),
    defaultValues: { is_primary: false },
  });

  const s = detail.data;
  const severity = allergyForm.watch('severity');
  const isPrimary = contactForm.watch('is_primary');

  const submitAllergy = allergyForm.handleSubmit((values) => {
    addAllergy.mutate(
      { studentId, input: values },
      { onSuccess: () => allergyForm.reset({ allergen: '', severity: 'mild', reaction: '' }) },
    );
  });
  const submitContact = contactForm.handleSubmit((values) => {
    addContact.mutate(
      { studentId, input: values },
      { onSuccess: () => contactForm.reset({ contact_name: '', relationship: '', phone: '', is_primary: false }) },
    );
  });

  return (
    <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-xl">
      <DialogHeader>
        <DialogTitle>
          {s !== undefined ? `${s.last_name}, ${s.first_name} — ${s.student_number}` : 'Student'}
        </DialogTitle>
      </DialogHeader>

      {detail.isLoading && <Loader2 className="mx-auto size-5 animate-spin text-muted-foreground" />}

      {s !== undefined && (
        <div className="space-y-4">
          <div className="grid grid-cols-3 gap-2 text-sm">
            <p><span className="text-muted-foreground">Course:</span> {s.course ?? '—'}</p>
            <p><span className="text-muted-foreground">Year:</span> {s.year_level ?? '—'}</p>
            <p><span className="text-muted-foreground">Blood:</span> {s.blood_type ?? '—'}</p>
          </div>

          <section className="space-y-2">
            <h3 className="flex items-center gap-1.5 text-sm font-semibold">
              <HeartPulse className="size-4 text-destructive" /> Allergies
            </h3>
            {(s.allergies ?? []).length === 0 && (
              <p className="text-xs text-muted-foreground">None recorded.</p>
            )}
            <ul className="space-y-1">
              {(s.allergies ?? []).map((a) => (
                <li key={a.id} className="flex items-center gap-2 text-sm">
                  <Badge variant={SEVERITY_VARIANT[a.severity]}>{a.severity}</Badge>
                  <span className="font-medium">{a.allergen}</span>
                  {a.reaction !== null && <span className="text-xs text-muted-foreground">— {a.reaction}</span>}
                </li>
              ))}
            </ul>
            <form noValidate onSubmit={(e) => void submitAllergy(e)} className="flex flex-wrap items-end gap-2">
              <div className="min-w-32 flex-1 space-y-1">
                <Label htmlFor="allergen" className="text-xs">Allergen</Label>
                <Input id="allergen" aria-invalid={allergyForm.formState.errors.allergen !== undefined} {...allergyForm.register('allergen')} />
              </div>
              <div className="w-32 space-y-1">
                <Label id="severity-label" className="text-xs">Severity</Label>
                <Select value={severity} onValueChange={(v) => allergyForm.setValue('severity', v as AddAllergyInput['severity'])}>
                  <SelectTrigger aria-labelledby="severity-label"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="mild">Mild</SelectItem>
                    <SelectItem value="moderate">Moderate</SelectItem>
                    <SelectItem value="severe">Severe</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <Button type="submit" size="sm" disabled={addAllergy.isPending}>
                {addAllergy.isPending ? <Loader2 className="animate-spin" /> : <Plus />} Add
              </Button>
            </form>
          </section>

          <section className="space-y-2">
            <h3 className="flex items-center gap-1.5 text-sm font-semibold">
              <Phone className="size-4" /> Emergency contacts
            </h3>
            {(s.contacts ?? []).length === 0 && (
              <p className="text-xs text-muted-foreground">None recorded.</p>
            )}
            <ul className="space-y-1">
              {(s.contacts ?? []).map((c) => (
                <li key={c.id} className="flex items-center gap-2 text-sm">
                  {c.is_primary && <Badge variant="info">primary</Badge>}
                  <span className="font-medium">{c.contact_name}</span>
                  <span className="text-xs text-muted-foreground">({c.relationship})</span>
                  <span className="font-mono text-xs">{c.phone}</span>
                </li>
              ))}
            </ul>
            <form noValidate onSubmit={(e) => void submitContact(e)} className="flex flex-wrap items-end gap-2">
              <div className="min-w-28 flex-1 space-y-1">
                <Label htmlFor="contact_name" className="text-xs">Name</Label>
                <Input id="contact_name" aria-invalid={contactForm.formState.errors.contact_name !== undefined} {...contactForm.register('contact_name')} />
              </div>
              <div className="w-28 space-y-1">
                <Label htmlFor="relationship" className="text-xs">Relation</Label>
                <Input id="relationship" aria-invalid={contactForm.formState.errors.relationship !== undefined} {...contactForm.register('relationship')} />
              </div>
              <div className="w-32 space-y-1">
                <Label htmlFor="phone" className="text-xs">Phone</Label>
                <Input id="phone" aria-invalid={contactForm.formState.errors.phone !== undefined} {...contactForm.register('phone')} />
              </div>
              <div className="flex items-center gap-1.5 pb-2">
                <Checkbox
                  id="is_primary"
                  checked={isPrimary}
                  onCheckedChange={(v) => contactForm.setValue('is_primary', v === true)}
                />
                <Label htmlFor="is_primary" className="text-xs font-normal">Primary</Label>
              </div>
              <Button type="submit" size="sm" disabled={addContact.isPending}>
                {addContact.isPending ? <Loader2 className="animate-spin" /> : <Plus />} Add
              </Button>
            </form>
          </section>
        </div>
      )}

      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
      </DialogFooter>
    </DialogContent>
  );
}

function CreateEmployeeDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateEmployee();
  const departments = useDepartments(true);
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<CreateEmployeeInput>({
      resolver: zodResolver(createEmployeeSchema),
      defaultValues: { employment_status: 'active' },
    });
  const department = watch('department');

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Register employee</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="employee_number">Employee number</Label>
          <Input id="employee_number" aria-invalid={errors.employee_number !== undefined} {...register('employee_number')} />
          {errors.employee_number !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.employee_number.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="e_first_name">First name</Label>
          <Input id="e_first_name" aria-invalid={errors.first_name !== undefined} {...register('first_name')} />
          {errors.first_name !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.first_name.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="e_last_name">Last name</Label>
          <Input id="e_last_name" aria-invalid={errors.last_name !== undefined} {...register('last_name')} />
          {errors.last_name !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.last_name.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label id="create-emp-dept-label">Department</Label>
          {/*
            Departments come from the clinic_departments registry (active
            rows only) — same source as the edit dialog, so a typo can't
            mint a phantom department. New entries are added from the
            Departments panel below the employee table.
          */}
          <Select
            {...(department !== undefined && department !== '' ? { value: department } : {})}
            onValueChange={(v) => setValue('department', v, { shouldValidate: true })}
          >
            <SelectTrigger aria-labelledby="create-emp-dept-label"><SelectValue placeholder="Select…" /></SelectTrigger>
            <SelectContent>
              {(departments.data ?? []).map((d) => (
                <SelectItem key={d.id} value={d.name}>{d.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="position">Position</Label>
          <Input id="position" {...register('position')} />
        </div>
        <DialogFooter className="col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Register
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function EditEmployeeDialog({ employee, onClose }: { employee: Employee; onClose: () => void }) {
  const update = useUpdateEmployee();
  const departments = useDepartments(true);
  const { register, handleSubmit, reset, setValue, watch } =
    useForm<UpdateEmployeeInput>({
      resolver: zodResolver(updateEmployeeSchema),
      defaultValues: {
        first_name: employee.first_name,
        last_name: employee.last_name,
        department: employee.department ?? '',
        position: employee.position ?? '',
        employment_status: employee.employment_status,
        is_teaching: employee.is_teaching,
      },
    });
  const status = watch('employment_status');
  const department = watch('department');
  const isTeaching = watch('is_teaching');

  const onSubmit = handleSubmit((values) => {
    update.mutate({ id: employee.id, input: values }, { onSuccess: () => { reset(); onClose(); } });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Edit employee — {employee.employee_number}</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="emp-first">First name</Label>
            <Input id="emp-first" {...register('first_name')} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="emp-last">Last name</Label>
            <Input id="emp-last" {...register('last_name')} />
          </div>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label id="emp-dept-label">Department</Label>
            <Select
              {...(department !== undefined && department !== '' ? { value: department } : {})}
              onValueChange={(v) => setValue('department', v, { shouldValidate: true })}
            >
              <SelectTrigger aria-labelledby="emp-dept-label"><SelectValue placeholder="Select…" /></SelectTrigger>
              <SelectContent>
                {(departments.data ?? []).map((d) => (
                  <SelectItem key={d.id} value={d.name}>{d.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="emp-position">Position</Label>
            <Input id="emp-position" {...register('position')} />
          </div>
        </div>
        <div className="space-y-1.5">
          <Label id="emp-status-label">Employment status</Label>
          <Select {...(status !== undefined ? { value: status } : {})} onValueChange={(v) => setValue('employment_status', v as UpdateEmployeeInput['employment_status'])}>
            <SelectTrigger aria-labelledby="emp-status-label"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="active">active</SelectItem>
              <SelectItem value="on_leave">on_leave</SelectItem>
              <SelectItem value="inactive">inactive</SelectItem>
            </SelectContent>
          </Select>
        </div>
        {/*
          Teaching flag. Faculty (teaching=1) can refer students to
          counselling; non-teaching staff cannot. The backend gates
          the referral policy on this flag — see the migration
          `EmployeeIsTeaching` for the column.
        */}
        <label className="flex items-center gap-2 rounded-md border bg-background px-3 py-2 text-sm">
          <input
            type="checkbox"
            checked={isTeaching === true}
            onChange={(e) => setValue('is_teaching', e.target.checked, { shouldDirty: true })}
            className="size-4"
            aria-label="Teaching employee"
          />
          <span>Teaching employee (faculty — can refer students to counselling)</span>
        </label>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={update.isPending}>
            {update.isPending && <Loader2 className="animate-spin" />} Save
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

/**
 * EmployeeDetailDialog — read-only detail for the Employees tab. Mirrors
 * `StudentDetailDialog` for the students side. The schema already
 * carries the emergency contact + date-hired fields, so the dialog
 * can show them without an extra round trip.
 */
function EmployeeDetailDialog({ employeeId, onClose }: { employeeId: number; onClose: () => void }) {
  const detail = useEmployee(employeeId);
  const e = detail.data;

  return (
    <DialogContent className="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>
          {e !== undefined
            ? `${e.last_name}, ${e.first_name} — ${e.employee_number}`
            : 'Employee'}
        </DialogTitle>
      </DialogHeader>
      {detail.isLoading && <Loader2 className="mx-auto size-5 animate-spin text-muted-foreground" />}
      {e !== undefined && (
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          <div>
            <dt className="text-xs text-muted-foreground">Department</dt>
            <dd>{e.department ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Position</dt>
            <dd>{e.position ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Status</dt>
            <dd>
              <Badge variant={e.employment_status === 'active' ? 'success' : e.employment_status === 'on_leave' ? 'warning' : 'secondary'}>
                {e.employment_status}
              </Badge>
            </dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Hired</dt>
            <dd className="font-mono text-xs">{e.date_hired ?? '—'}</dd>
          </div>
          <div className="col-span-2">
            <dt className="text-xs text-muted-foreground">Emergency contact</dt>
            <dd>
              {e.emergency_contact_name !== null || e.emergency_contact_phone !== null
                ? (
                  <span>
                    {e.emergency_contact_name ?? '—'}
                    {e.emergency_contact_phone !== null && (
                      <span className="ml-2 font-mono text-xs">{e.emergency_contact_phone}</span>
                    )}
                  </span>
                )
                : '—'}
            </dd>
          </div>
          <div className="col-span-2">
            <dt className="text-xs text-muted-foreground">Handles</dt>
            <dd className="flex flex-wrap gap-1.5">
              {e.has_qr
                ? <Badge variant="info">QR</Badge>
                : <Badge variant="secondary">no QR</Badge>}
              {e.has_rfid
                ? <Badge variant="info">RFID</Badge>
                : <Badge variant="secondary">no RFID</Badge>}
              {e.is_teaching
                ? <Badge variant="warning">teaching</Badge>
                : <Badge variant="secondary">non-teaching</Badge>}
            </dd>
          </div>
          {e.archived && (
            <div className="col-span-2">
              <Badge variant="secondary">archived</Badge>
            </div>
          )}
        </dl>
      )}
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
      </DialogFooter>
    </DialogContent>
  );
}

function DepartmentsPanel() {
  const departments = useDepartments();
  const create = useCreateDepartment();
  const { register, handleSubmit, formState: { errors }, reset } =
    useForm<CreateDepartmentInput>({ resolver: zodResolver(createDepartmentSchema) });
  const onSubmit = handleSubmit((values) => create.mutate(values, { onSuccess: () => reset() }));

  return (
    <section className="overflow-hidden rounded-xl border bg-card">
      <header className="border-b px-3 py-2 text-sm font-semibold text-foreground">Departments</header>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="flex flex-wrap items-end gap-2 border-b p-3">
        <div className="space-y-1">
          <Label htmlFor="dept-name" className="text-xs">Name</Label>
          <Input id="dept-name" className="h-8 w-44" aria-invalid={errors.name !== undefined} {...register('name')} />
        </div>
        <div className="space-y-1">
          <Label htmlFor="dept-code" className="text-xs">Code</Label>
          <Input id="dept-code" className="h-8 w-28" aria-invalid={errors.code !== undefined} {...register('code')} />
        </div>
        <Button type="submit" size="sm" disabled={create.isPending}>
          {create.isPending ? <Loader2 className="animate-spin" /> : <Plus />} Add
        </Button>
      </form>
      <ul className="max-h-40 divide-y overflow-auto text-sm">
        {(departments.data ?? []).map((d) => (
          <li key={d.id} className="flex items-center justify-between px-3 py-1.5">
            <span>{d.name} <span className="font-mono text-xs text-muted-foreground">({d.code})</span></span>
            <Badge variant={d.is_active ? 'success' : 'secondary'}>{d.is_active ? 'active' : 'inactive'}</Badge>
          </li>
        ))}
        {(departments.data?.length ?? 0) === 0 && (
          <li className="px-3 py-3 text-center text-muted-foreground">No departments yet.</li>
        )}
      </ul>
    </section>
  );
}

export default function PatientsPage() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [empCursor, setEmpCursor] = useState<string | null>(null);
  const [empHistory, setEmpHistory] = useState<Array<string | null>>([null]);
  const [tab, setTab] = useTabParam('students');
  const [query, setQuery] = useState('');
  const [empQuery, setEmpQuery] = useState('');
  const [openCreate, setOpenCreate] = useState(false);
  const [openCreateEmp, setOpenCreateEmp] = useState(false);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [empDetailId, setEmpDetailId] = useState<number | null>(null);
  const [editStudent, setEditStudent] = useState<Student | null>(null);
  const [editEmp, setEditEmp] = useState<Employee | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const [showArchived, setShowArchived] = useState(false);
  const [showArchivedEmp, setShowArchivedEmp] = useState(false);
  const archiveEmp = useSetEmployeeArchived();

  // Debounce the search text so a request fires only after the user
  // pauses — previously every keystroke created a fresh query key.
  const debouncedQuery = useDebouncedValue(query, 300);
  const debouncedEmpQuery = useDebouncedValue(empQuery, 300);
  const searching = debouncedQuery.trim().length >= 2;
  const empSearching = debouncedEmpQuery.trim().length >= 2;
  const list = useStudents(cursor, 25, showArchived);
  const search = useStudentSearch(debouncedQuery);
  const employees = useEmployees(empCursor, 25, showArchivedEmp);
  const empSearch = useEmployeeSearch(debouncedEmpQuery);
  const setArchived = useSetStudentArchived();

  function nextPage() {
    if (list.data?.next !== null && list.data?.next !== undefined) {
      const n = list.data.next;
      setHistory((h) => [...h, n]);
      setCursor(n);
    }
  }
  function prevPage() {
    if (history.length < 2) return;
    const next = history.slice(0, -1);
    setHistory(next);
    setCursor(next[next.length - 1] ?? null);
  }

  function empNextPage() {
    if (employees.data?.next !== null && employees.data?.next !== undefined) {
      const n = employees.data.next;
      setEmpHistory((h) => [...h, n]);
      setEmpCursor(n);
    }
  }
  function empPrevPage() {
    if (empHistory.length < 2) return;
    const next = empHistory.slice(0, -1);
    setEmpHistory(next);
    setEmpCursor(next[next.length - 1] ?? null);
  }

  const rows: Student[] = searching ? (search.data ?? []) : (list.data?.data ?? []);
  const loading = searching ? search.isLoading : list.isLoading;
  const errored = searching ? search.isError : list.isError;
  const retrying = searching ? search.isFetching : list.isFetching;
  const retry = () => void (searching ? search.refetch() : list.refetch());

  const studentActions = (student: Student) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button className="min-h-11" size="sm" variant="outline" aria-label={`Actions for ${student.student_number}`}>
          Actions <ChevronDown className="size-3.5" aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-48">
        <DropdownMenuItem className="min-h-11" onSelect={() => setDetailId(student.id)}>
          <Eye /> View record
        </DropdownMenuItem>
        <DropdownMenuItem className="min-h-11" onSelect={() => setEditStudent(student)}>
          <Pencil /> Edit record
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          className="min-h-11"
          disabled={setArchived.isPending}
          onSelect={() => {
            if (student.archived) {
              setArchived.mutate({ id: student.id, archived: false });
            } else {
              setConfirm({
                title: `Archive ${student.student_number}?`,
                description: 'The student is soft-archived (never deleted) and removed from active workflows. You can restore them later.',
                confirmLabel: 'Archive',
                run: () => setArchived.mutate({ id: student.id, archived: true }),
              });
            }
          }}
        >
          {student.archived ? <ArchiveRestore /> : <Archive />}
          {student.archived ? 'Restore record' : 'Archive record'}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );

  const employeeActions = (employee: Employee) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button className="min-h-11" size="sm" variant="outline" aria-label={`Actions for ${employee.employee_number}`}>
          Actions <ChevronDown className="size-3.5" aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-48">
        <DropdownMenuItem className="min-h-11" onSelect={() => setEmpDetailId(employee.id)}>
          <Eye /> View record
        </DropdownMenuItem>
        <DropdownMenuItem className="min-h-11" onSelect={() => setEditEmp(employee)}>
          <Pencil /> Edit record
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          className="min-h-11"
          disabled={archiveEmp.isPending}
          onSelect={() => {
            if (employee.archived) {
              archiveEmp.mutate({ id: employee.id, archived: false });
            } else {
              setConfirm({
                title: `Archive ${employee.employee_number}?`,
                description: 'The employee is soft-archived (never deleted) and removed from active workflows. You can restore them later.',
                confirmLabel: 'Archive',
                run: () => archiveEmp.mutate({ id: employee.id, archived: true }),
              });
            }
          }}
        >
          {employee.archived ? <ArchiveRestore /> : <Archive />}
          {employee.archived ? 'Restore record' : 'Archive record'}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Patients</h1>
          <p className="text-sm text-muted-foreground">
            Registry recycled from the legacy system — records are archived, never deleted.
          </p>
        </div>
      </header>

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="students">Students</TabsTrigger>
          <TabsTrigger value="employees">Employees</TabsTrigger>
        </TabsList>

        <TabsContent value="students" className="space-y-4">
          <section className="flex flex-wrap items-end justify-between gap-3 rounded-xl border bg-card p-3">
            <div className="relative w-full sm:w-72">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                aria-label="Search students"
                placeholder="Search number or name (min 2 chars)…"
                className="pl-9"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
              />
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Button
                variant={showArchived ? 'secondary' : 'outline'}
                aria-pressed={showArchived}
                onClick={() => { setShowArchived((v) => !v); setCursor(null); setHistory([null]); }}
              >
                <Archive /> {showArchived ? 'Hide archived' : 'Show archived'}
              </Button>
              <Dialog open={openCreate} onOpenChange={setOpenCreate}>
                <Button onClick={() => setOpenCreate(true)}>
                  <UserPlus /> Register student
                </Button>
                {openCreate && <CreateStudentDialog onClose={() => setOpenCreate(false)} />}
              </Dialog>
            </div>
          </section>

          <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
            <Table>
              <TableHeader className="bg-muted/50">
                <TableRow>
                  <TableHead className="px-3">Number</TableHead>
                  <TableHead className="px-3">Name</TableHead>
                  <TableHead className="px-3">Course / Yr</TableHead>
                  <TableHead className="px-3">Blood</TableHead>
                  <TableHead className="px-3">No-shows</TableHead>
                  <TableHead className="px-3">Status</TableHead>
                  <TableHead className="px-3 text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {loading && (
                  <TableRow>
                    <TableCell colSpan={7} className="px-3 py-6 text-center text-muted-foreground">
                      <Loader2 className="mx-auto size-4 animate-spin" />
                    </TableCell>
                  </TableRow>
                )}
                {!loading && rows.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={7} className="px-3 py-6 text-center text-muted-foreground">
                      {searching ? 'No matches.' : 'No students registered.'}
                    </TableCell>
                  </TableRow>
                )}
                {errored && !loading && (
                  <QueryErrorRow colSpan={7} message="Failed to load students." onRetry={retry} pending={retrying} />
                )}
                {rows.map((s) => (
                  <TableRow key={s.id}>
                    <TableCell className="px-3 font-mono text-xs">{s.student_number}</TableCell>
                    <TableCell className="px-3">{s.last_name}, {s.first_name}</TableCell>
                    <TableCell className="px-3 text-xs">
                      {s.course ?? '—'}{s.year_level !== null ? ` · Y${s.year_level}` : ''}
                    </TableCell>
                    <TableCell className="px-3 font-mono text-xs">{s.blood_type ?? '—'}</TableCell>
                    <TableCell className="px-3">
                      {s.consecutive_no_shows >= 3
                        ? <Badge variant="destructive">{s.consecutive_no_shows}</Badge>
                        : <span className="text-xs">{s.consecutive_no_shows}</span>}
                    </TableCell>
                    <TableCell className="px-3">
                      {s.archived ? <Badge variant="secondary">Archived</Badge> : <Badge variant="success">Active</Badge>}
                    </TableCell>
                    <TableCell className="px-3 text-right">
                      {studentActions(s)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </section>

          {/* Mobile: student cards from the same rows. */}
          {loading && (
            <p className="py-6 text-center text-sm text-muted-foreground md:hidden" role="status">
              <Loader2 className="mx-auto size-4 animate-spin" />
            </p>
          )}
          {errored && !loading && (
            <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-center text-sm text-destructive md:hidden">
              <p>Failed to load students.</p>
              <Button variant="outline" size="sm" className="mt-2" onClick={retry} disabled={retrying}>Retry</Button>
            </div>
          )}
          {!loading && !errored && rows.length === 0 && (
            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground md:hidden">
              {searching ? 'No matches.' : 'No students registered.'}
            </p>
          )}
          <MobileCardList>
            {rows.map((s) => (
              <MobileCard key={s.id} aria-label={`Student ${s.student_number}`}>
                <div className="mb-1 flex items-center justify-between gap-2">
                  <span className="text-sm font-medium text-foreground">{s.last_name}, {s.first_name}</span>
                  {s.archived ? <Badge variant="secondary">Archived</Badge> : <Badge variant="success">Active</Badge>}
                </div>
                <MobileCardField label="Number"><span className="font-mono text-xs">{s.student_number}</span></MobileCardField>
                <MobileCardField label="Course / Yr"><span className="text-xs">{s.course ?? '—'}{s.year_level !== null ? ` · Y${s.year_level}` : ''}</span></MobileCardField>
                <MobileCardField label="Blood"><span className="font-mono text-xs">{s.blood_type ?? '—'}</span></MobileCardField>
                <MobileCardField label="No-shows">
                  {s.consecutive_no_shows >= 3
                    ? <Badge variant="destructive">{s.consecutive_no_shows}</Badge>
                    : <span className="text-xs">{s.consecutive_no_shows}</span>}
                </MobileCardField>
                <MobileCardActions>{studentActions(s)}</MobileCardActions>
              </MobileCard>
            ))}
          </MobileCardList>

          {!searching && (
            <nav className="flex items-center justify-between" aria-label="pagination">
              <p className="text-xs text-muted-foreground">Page {history.length}</p>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" onClick={prevPage} disabled={history.length < 2}>
                  <ChevronLeft /> Prev
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={nextPage}
                  disabled={list.data?.next === null || list.data?.next === undefined}
                >
                  Next <ChevronRight />
                </Button>
              </div>
            </nav>
          )}
        </TabsContent>

        <TabsContent value="employees" className="space-y-4">
          <section className="flex flex-wrap items-end justify-between gap-3 rounded-xl border bg-card p-3">
            <div className="relative w-full sm:w-72">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                aria-label="Search employees"
                placeholder="Search number, name, department (min 2 chars)…"
                className="pl-9"
                value={empQuery}
                onChange={(e) => setEmpQuery(e.target.value)}
              />
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Button
                variant={showArchivedEmp ? 'secondary' : 'outline'}
                aria-pressed={showArchivedEmp}
                onClick={() => { setShowArchivedEmp((v) => !v); setEmpCursor(null); setEmpHistory([null]); }}
              >
                <Archive /> {showArchivedEmp ? 'Hide archived' : 'Show archived'}
              </Button>
              <Dialog open={openCreateEmp} onOpenChange={setOpenCreateEmp}>
                <Button onClick={() => setOpenCreateEmp(true)}>
                  <UserPlus /> Register employee
                </Button>
                {openCreateEmp && <CreateEmployeeDialog onClose={() => setOpenCreateEmp(false)} />}
              </Dialog>
            </div>
          </section>

          <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
            <Table>
              <TableHeader className="bg-muted/50">
                <TableRow>
                  <TableHead className="px-3">Number</TableHead>
                  <TableHead className="px-3">Name</TableHead>
                  <TableHead className="px-3">Department</TableHead>
                  <TableHead className="px-3">Position</TableHead>
                  <TableHead className="px-3">Status</TableHead>
                  <TableHead className="px-3 text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(() => {
                  const empLoading = empSearching ? empSearch.isLoading : employees.isLoading;
                  const empRows: Employee[] = empSearching ? (empSearch.data ?? []) : (employees.data?.data ?? []);
                  const empErrored = empSearching ? empSearch.isError : employees.isError;
                  if (empLoading) {
                    return (
                      <TableRow>
                        <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                          <Loader2 className="mx-auto size-4 animate-spin" />
                        </TableCell>
                      </TableRow>
                    );
                  }
                  if (empErrored) {
                    return (
                      <QueryErrorRow
                        colSpan={6}
                        message="Failed to load employees."
                        onRetry={() => void (empSearching ? empSearch.refetch() : employees.refetch())}
                        pending={empSearching ? empSearch.isFetching : employees.isFetching}
                      />
                    );
                  }
                  if (empRows.length === 0) {
                    return (
                      <TableRow>
                        <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                          {empSearching ? 'No matches.' : 'No employees registered.'}
                        </TableCell>
                      </TableRow>
                    );
                  }
                  return empRows.map((e) => (
                    <TableRow key={e.id}>
                      <TableCell className="px-3 font-mono text-xs">{e.employee_number}</TableCell>
                      <TableCell className="px-3">{e.last_name}, {e.first_name}</TableCell>
                      <TableCell className="px-3 text-xs">{e.department ?? '—'}</TableCell>
                      <TableCell className="px-3 text-xs">{e.position ?? '—'}</TableCell>
                      <TableCell className="px-3">
                        <div className="flex flex-wrap items-center gap-1">
                          <Badge variant={e.employment_status === 'active' ? 'success' : e.employment_status === 'on_leave' ? 'warning' : 'secondary'}>
                            {e.employment_status}
                          </Badge>
                          {e.is_teaching && <Badge variant="warning">teaching</Badge>}
                          {e.archived && <Badge variant="secondary">archived</Badge>}
                        </div>
                      </TableCell>
                      <TableCell className="px-3 text-right">
                        {employeeActions(e)}
                      </TableCell>
                    </TableRow>
                  ));
                })()}
              </TableBody>
            </Table>
          </section>

          {/* Mobile: employee cards from the same rows. */}
          {(() => {
            const empLoading = empSearching ? empSearch.isLoading : employees.isLoading;
            const empRows: Employee[] = empSearching ? (empSearch.data ?? []) : (employees.data?.data ?? []);
            const empErrored = empSearching ? empSearch.isError : employees.isError;
            return (
              <div className="md:hidden">
                {empLoading && (
                  <p className="py-6 text-center text-sm text-muted-foreground" role="status">
                    <Loader2 className="mx-auto size-4 animate-spin" />
                  </p>
                )}
                {empErrored && !empLoading && (
                  <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-center text-sm text-destructive">
                    <p>Failed to load employees.</p>
                    <Button variant="outline" size="sm" className="mt-2" onClick={() => void (empSearching ? empSearch.refetch() : employees.refetch())} disabled={empSearching ? empSearch.isFetching : employees.isFetching}>Retry</Button>
                  </div>
                )}
                {!empLoading && !empErrored && empRows.length === 0 && (
                  <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                    {empSearching ? 'No matches.' : 'No employees registered.'}
                  </p>
                )}
                <MobileCardList>
                  {empRows.map((e) => (
                    <MobileCard key={e.id} aria-label={`Employee ${e.employee_number}`}>
                      <div className="mb-1 flex items-center justify-between gap-2">
                        <span className="text-sm font-medium text-foreground">{e.last_name}, {e.first_name}</span>
                        <Badge variant={e.employment_status === 'active' ? 'success' : e.employment_status === 'on_leave' ? 'warning' : 'secondary'}>
                          {e.employment_status}
                        </Badge>
                      </div>
                      <div className="mb-1 flex flex-wrap gap-1.5">
                        {e.is_teaching && <Badge variant="warning">teaching</Badge>}
                        {e.archived && <Badge variant="secondary">archived</Badge>}
                      </div>
                      <MobileCardField label="Number"><span className="font-mono text-xs">{e.employee_number}</span></MobileCardField>
                      <MobileCardField label="Department"><span className="text-xs">{e.department ?? '—'}</span></MobileCardField>
                      <MobileCardField label="Position"><span className="text-xs">{e.position ?? '—'}</span></MobileCardField>
                      <MobileCardActions>{employeeActions(e)}</MobileCardActions>
                    </MobileCard>
                  ))}
                </MobileCardList>
              </div>
            );
          })()}

          {!empSearching && (
            <nav className="flex items-center justify-between" aria-label="pagination">
              <p className="text-xs text-muted-foreground">Page {empHistory.length}</p>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" onClick={empPrevPage} disabled={empHistory.length < 2}>
                  <ChevronLeft /> Prev
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={empNextPage}
                  disabled={employees.data?.next === null || employees.data?.next === undefined}
                >
                  Next <ChevronRight />
                </Button>
              </div>
            </nav>
          )}

          <DepartmentsPanel />
        </TabsContent>
      </Tabs>

      {detailId !== null && (
        <Dialog open onOpenChange={(o) => !o && setDetailId(null)}>
          <StudentDetailDialog studentId={detailId} onClose={() => setDetailId(null)} />
        </Dialog>
      )}

      {editStudent !== null && (
        <Dialog open onOpenChange={(o) => !o && setEditStudent(null)}>
          <EditStudentDialog student={editStudent} onClose={() => setEditStudent(null)} />
        </Dialog>
      )}

      {editEmp !== null && (
        <Dialog open onOpenChange={(o) => !o && setEditEmp(null)}>
          <EditEmployeeDialog employee={editEmp} onClose={() => setEditEmp(null)} />
        </Dialog>
      )}

      {empDetailId !== null && (
        <Dialog open onOpenChange={(o) => !o && setEmpDetailId(null)}>
          <EmployeeDetailDialog employeeId={empDetailId} onClose={() => setEmpDetailId(null)} />
        </Dialog>
      )}

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        pending={setArchived.isPending || archiveEmp.isPending}
        onConfirm={() => {
          confirm?.run();
          setConfirm(null);
        }}
        onCancel={() => setConfirm(null)}
      />
    </main>
  );
}
