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
  KeyRound,
  Loader2,
  Mail,
  Pencil,
  Phone,
  Plus,
  Search,
  Trash2,
  UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Badge } from '@/components/ui/badge';
import { PageHeader, PageToolbar } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { QueryErrorRow } from '@/components/QueryErrorState';
import { MobileCardList, MobileCard, MobileCardField, MobileCardActions } from '@/components/MobileCardList';
import { useUrlFilter } from '@/hooks/useUrlFilter';
import { useCan } from '@/hooks/useCan';
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
  useDeleteAllergy,
  useDeleteContact,
  useDepartments,
  useEmployee,
  useEmployeeSearch,
  useEmployees,
  useSetEmployeeArchived,
  useSetStudentArchived,
  useStudent,
  useStudentSearch,
  useStudents,
  useUpdateAllergy,
  useUpdateContact,
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
  type PortalAccount,
  type Student,
  type UpdateEmployeeInput,
  type UpdateStudentInput,
} from '@/schemas/patients';

const SEVERITY_VARIANT = { mild: 'info', moderate: 'warning', severe: 'destructive' } as const;

/**
 * Human label for an employee's employment status. The API stores
 * lowercase snake-case values (`active`, `on_leave`, `inactive`); the
 * UI shows them sentence-cased ("Active", "On leave", "Inactive").
 */
function employmentStatusLabel(status: string | null | undefined): string {
  switch (status) {
    case 'active':
      return 'Active';
    case 'inactive':
      return 'Inactive';
    case 'on_leave':
      return 'On leave';
    default:
      return status !== null && status !== undefined && status.length > 0
        ? status.charAt(0).toUpperCase() + status.slice(1)
        : '—';
  }
}

/**
 * Explicit Teaching / Non-teaching / unclassified badge (audit fix).
 * Previously only Teaching was shown, so a NULL flag (e.g. an HR-
 * synced row) looked identical to a non-teaching employee.
 */
function TeachingBadge({ isTeaching }: { isTeaching: boolean | null | undefined }): JSX.Element {
  if (isTeaching === true) return <Badge variant="warning">Teaching</Badge>;
  if (isTeaching === false) return <Badge variant="secondary">Non-teaching</Badge>;
  return <Badge variant="outline">Not classified</Badge>;
}

function PortalCredentialModal({
  kind,
  identifier,
  account,
  onClose,
}: {
  kind: 'student' | 'employee';
  identifier: string;
  account: PortalAccount;
  onClose: () => void;
}) {
  return (
    <Dialog open onOpenChange={(open) => ! open && onClose()}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <KeyRound className="size-4" aria-hidden /> Portal account created
          </DialogTitle>
        </DialogHeader>
        <p className="text-sm text-muted-foreground">
          A SYNAPSE account was created for {kind} <span className="font-mono">{identifier}</span>.
          Share these credentials once through a secure channel — the password is shown here and cannot be retrieved later.
        </p>
        <dl className="space-y-2 text-sm">
          <div className="space-y-0.5">
            <dt className="text-xs text-muted-foreground">Email</dt>
            <dd className="flex items-center gap-2 font-mono">
              <Mail className="size-3.5 text-muted-foreground" aria-hidden />
              <span className="min-w-0 flex-1 truncate">{account.email}</span>
            </dd>
          </div>
          <div className="space-y-0.5">
            <dt className="text-xs text-muted-foreground">Temporary password</dt>
            <dd className="rounded-md border bg-muted/50 px-3 py-2 font-mono text-xs break-all">
              {account.temporary_password}
            </dd>
          </div>
        </dl>
        <DialogFooter>
          <Button onClick={onClose}>Done</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function CreateStudentDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateStudent();
  const [createdAccount, setCreatedAccount] = useState<{ identifier: string; account: PortalAccount } | null>(null);
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<CreateStudentInput>({ resolver: zodResolver(createStudentSchema) });

  const gender = watch('gender');

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: (result) => {
        if (result.portal_account !== undefined) {
          setCreatedAccount({ identifier: result.student_number ?? '', account: result.portal_account });
        } else {
          reset();
          onClose();
        }
      },
    });
  });

  function handleClose() {
    reset();
    onClose();
  }

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
        {/* Identity-consolidated: a portal account is ALWAYS created. */}
        <div className="col-span-2 rounded-lg border bg-muted/30 p-3">
          <p className="text-xs text-muted-foreground">
            A portal login is created automatically for this student. Account email defaults to{' '}
            <code>{`<student_number>@synapse.dev`}</code>.
          </p>
          <div className="mt-3 space-y-1.5">
            <Label htmlFor="student-account-email">Account email (optional)</Label>
            <Input
              id="student-account-email"
              type="email"
              placeholder="patient@synapse.dev"
              aria-invalid={errors.account_email !== undefined}
              {...register('account_email')}
            />
            {errors.account_email !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.account_email.message}</p>
            )}
          </div>
        </div>
        <DialogFooter className="col-span-2">
          <Button type="button" variant="outline" onClick={handleClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Register
          </Button>
        </DialogFooter>
      </form>
      {createdAccount !== null && (
        <PortalCredentialModal
          kind="student"
          identifier={createdAccount.identifier}
          account={createdAccount.account}
          onClose={() => { setCreatedAccount(null); handleClose(); }}
        />
      )}
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
      // student.gender is `string | null` from the schema; narrow it to
      // the male/female/other literal union the edit schema expects.
      gender:      student.gender === 'male' || student.gender === 'female' || student.gender === 'other'
        ? student.gender
        : undefined,
      blood_type:  student.blood_type ?? '',
      date_of_birth: student.date_of_birth ?? '',
      address:     student.address ?? '',
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
        <div className="space-y-1.5">
          <Label htmlFor="es-dob">Date of birth</Label>
          <Input id="es-dob" placeholder="YYYY-MM-DD" {...register('date_of_birth')} />
        </div>
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="es-address">Address</Label>
          <Input id="es-address" {...register('address')} />
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
  const updateAllergy = useUpdateAllergy();
  const deleteAllergy = useDeleteAllergy();
  const updateContact = useUpdateContact();
  const deleteContact = useDeleteContact();
  // When editing an existing row, `editingId` + `editType` tell the forms
  // to prefill and submit an UPDATE instead of an ADD.
  const [editing, setEditing] = useState<{ type: 'allergy' | 'contact'; id: number } | null>(null);

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

  function startEditAllergy(a: { id: number; allergen: string; severity: string; reaction: string | null }): void {
    setEditing({ type: 'allergy', id: a.id });
    allergyForm.reset({
      allergen: a.allergen,
      severity: (a.severity === 'mild' || a.severity === 'moderate' || a.severity === 'severe') ? a.severity : 'mild',
      reaction: a.reaction ?? '',
    });
  }

  function startEditContact(c: { id: number; contact_name: string; relationship: string; phone: string; is_primary: boolean }): void {
    setEditing({ type: 'contact', id: c.id });
    contactForm.reset({
      contact_name: c.contact_name,
      relationship: c.relationship,
      phone: c.phone,
      is_primary: c.is_primary,
    });
  }

  const submitAllergy = allergyForm.handleSubmit((values) => {
    const onDone = () => {
      setEditing(null);
      allergyForm.reset({ allergen: '', severity: 'mild', reaction: '' });
    };
    if (editing?.type === 'allergy') {
      updateAllergy.mutate(
        { studentId, allergyId: editing.id, input: values },
        { onSuccess: onDone },
      );
    } else {
      addAllergy.mutate({ studentId, input: values }, { onSuccess: onDone });
    }
  });
  const submitContact = contactForm.handleSubmit((values) => {
    const onDone = () => {
      setEditing(null);
      contactForm.reset({ contact_name: '', relationship: '', phone: '', is_primary: false });
    };
    if (editing?.type === 'contact') {
      updateContact.mutate(
        { studentId, contactId: editing.id, input: values },
        { onSuccess: onDone },
      );
    } else {
      addContact.mutate({ studentId, input: values }, { onSuccess: onDone });
    }
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
                  {a.reaction !== null && <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">— {a.reaction}</span>}
                  <span className="flex items-center gap-0.5">
                    <Button variant="ghost" size="sm" className="size-7 p-0" aria-label={`Edit allergy ${a.allergen}`} onClick={() => startEditAllergy(a)}>
                      <Pencil className="size-3.5" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="size-7 p-0 text-destructive"
                      aria-label={`Remove allergy ${a.allergen}`}
                      disabled={deleteAllergy.isPending}
                      onClick={() => deleteAllergy.mutate({ studentId, allergyId: a.id })}
                    >
                      <Trash2 className="size-3.5" />
                    </Button>
                  </span>
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
              {editing?.type === 'allergy' ? (
                <>
                  <Button type="button" size="sm" variant="outline" onClick={() => { setEditing(null); allergyForm.reset({ allergen: '', severity: 'mild', reaction: '' }); }}>
                    Cancel
                  </Button>
                  <Button type="submit" size="sm" disabled={updateAllergy.isPending}>
                    {updateAllergy.isPending ? <Loader2 className="animate-spin" /> : <Pencil className="size-3.5" />} Save
                  </Button>
                </>
              ) : (
                <Button type="submit" size="sm" disabled={addAllergy.isPending}>
                  {addAllergy.isPending ? <Loader2 className="animate-spin" /> : <Plus />} Add
                </Button>
              )}
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
                  <span className="min-w-0 flex-1 truncate font-mono text-xs">{c.phone}</span>
                  <span className="flex items-center gap-0.5">
                    <Button variant="ghost" size="sm" className="size-7 p-0" aria-label={`Edit contact ${c.contact_name}`} onClick={() => startEditContact(c)}>
                      <Pencil className="size-3.5" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="size-7 p-0 text-destructive"
                      aria-label={`Remove contact ${c.contact_name}`}
                      disabled={deleteContact.isPending}
                      onClick={() => deleteContact.mutate({ studentId, contactId: c.id })}
                    >
                      <Trash2 className="size-3.5" />
                    </Button>
                  </span>
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
              {editing?.type === 'contact' ? (
                <>
                  <Button type="button" size="sm" variant="outline" onClick={() => { setEditing(null); contactForm.reset({ contact_name: '', relationship: '', phone: '', is_primary: false }); }}>
                    Cancel
                  </Button>
                  <Button type="submit" size="sm" disabled={updateContact.isPending}>
                    {updateContact.isPending ? <Loader2 className="animate-spin" /> : <Pencil className="size-3.5" />} Save
                  </Button>
                </>
              ) : (
                <Button type="submit" size="sm" disabled={addContact.isPending}>
                  {addContact.isPending ? <Loader2 className="animate-spin" /> : <Plus />} Add
                </Button>
              )}
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
  const [createdAccount, setCreatedAccount] = useState<{ identifier: string; account: PortalAccount } | null>(null);
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<CreateEmployeeInput>({
      resolver: zodResolver(createEmployeeSchema),
      defaultValues: { employment_status: 'active' },
    });
  const department = watch('department');

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: (result) => {
        if (result.portal_account !== undefined) {
          setCreatedAccount({ identifier: result.employee_number ?? '', account: result.portal_account });
        } else {
          reset();
          onClose();
        }
      },
    });
  });

  function handleClose() {
    reset();
    onClose();
  }

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
        {/* Identity-consolidated: a portal account is ALWAYS created. */}
        <div className="col-span-2 rounded-lg border bg-muted/30 p-3">
          <p className="text-xs text-muted-foreground">
            A portal login is created automatically for this employee. Account email defaults to{' '}
            <code>{`<employee_number>@synapse.dev`}</code>.
          </p>
          <div className="mt-3 space-y-1.5">
            <Label htmlFor="employee-account-email">Account email (optional)</Label>
            <Input
              id="employee-account-email"
              type="email"
              placeholder="employee@synapse.dev"
              aria-invalid={errors.account_email !== undefined}
              {...register('account_email')}
            />
            {errors.account_email !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.account_email.message}</p>
            )}
          </div>
        </div>
        <DialogFooter className="col-span-2">
          <Button type="button" variant="outline" onClick={handleClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Register
          </Button>
        </DialogFooter>
      </form>
      {createdAccount !== null && (
        <PortalCredentialModal
          kind="employee"
          identifier={createdAccount.identifier}
          account={createdAccount.account}
          onClose={() => { setCreatedAccount(null); handleClose(); }}
        />
      )}
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
        employment_status: employee.employment_status ?? 'active',
        is_teaching: employee.is_teaching ?? false,
        date_hired: employee.date_hired ?? '',
        emergency_contact_name: employee.emergency_contact_name ?? '',
        emergency_contact_phone: employee.emergency_contact_phone ?? '',
        // employee.gender is `string | null`; narrow to the union.
        gender: employee.gender === 'male' || employee.gender === 'female' || employee.gender === 'other'
          ? employee.gender
          : undefined,
        date_of_birth: employee.date_of_birth ?? '',
        address: employee.address ?? '',
      },
    });
  const status = watch('employment_status');
  const department = watch('department');
  const isTeaching = watch('is_teaching');
  const gender = watch('gender');

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
              <SelectItem value="active">Active</SelectItem>
              <SelectItem value="on_leave">On leave</SelectItem>
              <SelectItem value="inactive">Inactive</SelectItem>
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
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="emp-date-hired">Date hired</Label>
            <Input id="emp-date-hired" placeholder="YYYY-MM-DD" {...register('date_hired')} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="emp-dob">Date of birth</Label>
            <Input id="emp-dob" placeholder="YYYY-MM-DD" {...register('date_of_birth')} />
          </div>
        </div>
        <div className="space-y-1.5">
          <Label id="emp-gender-label">Gender</Label>
          <Select
            {...(gender !== undefined ? { value: gender } : {})}
            onValueChange={(v) => setValue('gender', v as UpdateEmployeeInput['gender'])}
          >
            <SelectTrigger aria-labelledby="emp-gender-label"><SelectValue placeholder="Select…" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="male">Male</SelectItem>
              <SelectItem value="female">Female</SelectItem>
              <SelectItem value="other">Other</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="emp-address">Address</Label>
          <Input id="emp-address" {...register('address')} />
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="emp-ec-name">Emergency contact name</Label>
            <Input id="emp-ec-name" {...register('emergency_contact_name')} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="emp-ec-phone">Emergency contact phone</Label>
            <Input id="emp-ec-phone" placeholder="09XX XXX XXXX" {...register('emergency_contact_phone')} />
          </div>
        </div>
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
                {employmentStatusLabel(e.employment_status)}
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
              <TeachingBadge isTeaching={e.is_teaching} />
            </dd>
          </div>
          {e.archived && (
            <div className="col-span-2">
              <Badge variant="secondary">Archived</Badge>
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
  // Creating departments is a separate permission (`clinic.departments.
  // manage`) — read-only roles still see the list, but not a form that
  // can only 403 (2026-09 audit).
  const canManage = useCan('clinic.departments.manage');
  const { register, handleSubmit, formState: { errors }, reset } =
    useForm<CreateDepartmentInput>({ resolver: zodResolver(createDepartmentSchema) });
  const onSubmit = handleSubmit((values) => create.mutate(values, { onSuccess: () => reset() }));

  return (
    <section className="overflow-hidden rounded-xl border bg-card">
      <header className="border-b px-3 py-2 text-sm font-semibold text-foreground">Departments</header>
      {canManage && (
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
      )}
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
  // Registry writes need their own permission (counsellors hold read
  // only) — hide what the backend would 403 (2026-09 audit).
  const canWrite = useCan('clinic.patients.write');
  // Filters live in the URL (PRODUCT principle 5): ?q=, ?archived=1 and
  // ?teaching= survive a refresh and can be shared as links. Each tab
  // keeps its own search key so switching tabs re-seeds from the URL.
  const [query, setQuery, queryDraft] = useUrlFilter('q', { debounceMs: 300 });
  const [empQuery, setEmpQuery, empQueryDraft] = useUrlFilter('emp_q', { debounceMs: 300 });
  const [openCreate, setOpenCreate] = useState(false);
  const [openCreateEmp, setOpenCreateEmp] = useState(false);
  const [detailId, setDetailId] = useState<number | null>(null);
  const [empDetailId, setEmpDetailId] = useState<number | null>(null);
  const [editStudent, setEditStudent] = useState<Student | null>(null);
  const [editEmp, setEditEmp] = useState<Employee | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const [showArchived, setShowArchived] = useUrlFilter('archived', { default: '' });
  const [showArchivedEmp, setShowArchivedEmp] = useUrlFilter('emp_archived', { default: '' });
  // Teaching / non-teaching triage (audit fix) — only teaching
  // employees (faculty) can refer students to counselling.
  const [empTeaching, setEmpTeaching] = useUrlFilter('teaching', { default: 'all' });
  const archiveEmp = useSetEmployeeArchived();

  const searching = query.trim().length >= 2;
  const empSearching = empQuery.trim().length >= 2;
  const list = useStudents(cursor, 25, showArchived === '1');
  const search = useStudentSearch(query);
  const employees = useEmployees(empCursor, 25, showArchivedEmp === '1', empTeaching as 'all' | 'teaching' | 'non_teaching');
  const empSearch = useEmployeeSearch(empQuery);
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
        {canWrite && (
          <DropdownMenuItem className="min-h-11" onSelect={() => setEditStudent(student)}>
            <Pencil /> Edit record
          </DropdownMenuItem>
        )}
        {canWrite && (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              className="min-h-11"
              disabled={setArchived.isPending}
              onSelect={() => {
                if (student.archived) {
                  setConfirm({
                    title: `Restore ${student.student_number}?`,
                    description: 'The student is returned to the active registry and their workflows become available again.',
                    confirmLabel: 'Restore',
                    run: () => setArchived.mutate({ id: student.id, archived: false }),
                  });
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
          </>
        )}
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
        {canWrite && (
          <DropdownMenuItem className="min-h-11" onSelect={() => setEditEmp(employee)}>
            <Pencil /> Edit record
          </DropdownMenuItem>
        )}
        {canWrite && (
          <>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              className="min-h-11"
              disabled={archiveEmp.isPending}
              onSelect={() => {
                if (employee.archived) {
                  setConfirm({
                    title: `Restore ${employee.employee_number}?`,
                    description: 'The employee is returned to the active registry and their workflows become available again.',
                    confirmLabel: 'Restore',
                    run: () => archiveEmp.mutate({ id: employee.id, archived: false }),
                  });
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
          </>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <Tabs value={tab} onValueChange={setTab} className="space-y-4">
        <PageHeader
          title="Patients"
          description="Registry recycled from the legacy system — records are archived, never deleted."
          actions={
            tab === 'students' ? (
              canWrite ? (
                <Dialog open={openCreate} onOpenChange={setOpenCreate}>
                  <Button onClick={() => setOpenCreate(true)}>
                    <UserPlus /> Register student
                  </Button>
                  {openCreate && <CreateStudentDialog onClose={() => setOpenCreate(false)} />}
                </Dialog>
              ) : null
            ) : (
              canWrite ? (
                <Dialog open={openCreateEmp} onOpenChange={setOpenCreateEmp}>
                  <Button onClick={() => setOpenCreateEmp(true)}>
                    <UserPlus /> Register employee
                  </Button>
                  {openCreateEmp && <CreateEmployeeDialog onClose={() => setOpenCreateEmp(false)} />}
                </Dialog>
              ) : null
            )
          }
          tabs={
            <TabsList>
              <TabsTrigger value="students">Students</TabsTrigger>
              <TabsTrigger value="employees">Employees</TabsTrigger>
            </TabsList>
          }
        />

        <TabsContent value="students" className="space-y-4">
          <PageToolbar>
            <div className="relative w-full sm:w-80 lg:flex-1 lg:max-w-md">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                aria-label="Search students"
                placeholder="Search number or name (min 2 chars)…"
                className="pl-9"
                value={queryDraft}
                onChange={(e) => setQuery(e.target.value)}
              />
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Button
                variant={showArchived === '1' ? 'secondary' : 'outline'}
                aria-pressed={showArchived === '1'}
                onClick={() => { setShowArchived(showArchived === '1' ? '' : '1'); setCursor(null); setHistory([null]); }}
              >
                <Archive /> {showArchived === '1' ? 'Hide archived' : 'Show archived'}
              </Button>
            </div>
          </PageToolbar>

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
          <PageToolbar>
            <div className="relative w-full sm:w-80 lg:flex-1 lg:max-w-md">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                aria-label="Search employees"
                placeholder="Search number, name, department (min 2 chars)…"
                className="pl-9"
                value={empQueryDraft}
                onChange={(e) => setEmpQuery(e.target.value)}
              />
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Select
                value={empTeaching}
                onValueChange={(v) => { setEmpTeaching(v); setEmpCursor(null); setEmpHistory([null]); }}
              >
                <SelectTrigger aria-label="Filter by teaching type" className="h-10 w-44 md:h-9">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All employees</SelectItem>
                  <SelectItem value="teaching">Teaching (faculty)</SelectItem>
                  <SelectItem value="non_teaching">Non-teaching</SelectItem>
                </SelectContent>
              </Select>
              <Button
                variant={showArchivedEmp === '1' ? 'secondary' : 'outline'}
                aria-pressed={showArchivedEmp === '1'}
                onClick={() => { setShowArchivedEmp(showArchivedEmp === '1' ? '' : '1'); setEmpCursor(null); setEmpHistory([null]); }}
              >
                <Archive /> {showArchivedEmp === '1' ? 'Hide archived' : 'Show archived'}
              </Button>
            </div>
          </PageToolbar>

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
                            {employmentStatusLabel(e.employment_status)}
                          </Badge>
                          <TeachingBadge isTeaching={e.is_teaching} />
                          {e.archived && <Badge variant="secondary">Archived</Badge>}
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
                          {employmentStatusLabel(e.employment_status)}
                        </Badge>
                      </div>
                      <div className="mb-1 flex flex-wrap gap-1.5">
                        <TeachingBadge isTeaching={e.is_teaching} />
                        {e.archived && <Badge variant="secondary">Archived</Badge>}
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
