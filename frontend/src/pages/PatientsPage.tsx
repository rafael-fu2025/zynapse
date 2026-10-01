/**
 * PatientsPage — patient registry (Phase 11, recycled from synapse_ag).
 *
 * Students tab: keyset-paginated list with live search (>= 2 chars), a
 * registration dialog (RHF + Zod), a view-only detail dialog, and a
 * "Manage medical record" dialog that edits allergies + emergency
 * contacts. The student record itself is view-only — registry fields
 * have no edit UI (2026-09 product decision). Employees tab: list +
 * registration. Archive is soft — registry rows are never deleted.
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
  Share2,
  Trash2,
  UserPlus,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useForm } from 'react-hook-form';
import { useSearchParams } from 'react-router-dom';
import { ApiEnvelopeError } from '@/api/envelope';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { PageHeader, PageToolbar } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { TableStateRows } from '@/components/TableStates';
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
import { Textarea } from '@/components/ui/textarea';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import {
  useAddAllergy,
  useAddContact,
  useCreateEmployee,
  useCreateStudent,
  useDeleteAllergy,
  useDeleteContact,
  useEmployee,
  useEmployeeEncounters,
  useEmployeeFacets,
  useEmployeeSearch,
  useEmployees,
  useSetEmployeeArchived,
  useSetStudentArchived,
  useStudent,
  useStudentFacets,
  useStudentSearch,
  useStudents,
  useUpdateAllergy,
  useUpdateContact,
  useUpdateEmployee,
} from '@/hooks/usePatients';
import { useCreateReferral } from '@/hooks/useReferrals';
import { createReferralSchema, type CreateReferralInput } from '@/schemas/referrals';
import {
  addAllergySchema,
  addContactSchema,
  createEmployeeSchema,
  createStudentSchema,
  updateEmployeeSchema,
  type AddAllergyInput,
  type AddContactInput,
  type CreateEmployeeInput,
  type CreateStudentInput,
  type Employee,
  type EmployeeRecord,
  type PortalAccount,
  type Student,
  type UpdateEmployeeInput,
} from '@/schemas/patients';
import { deriveUniversityEmail } from '@/lib/universityEmail';
import { fmtUtcToApp } from '@/utils/date';

const SEVERITY_VARIANT = { mild: 'info', moderate: 'warning', severe: 'destructive' } as const;

/**
 * Display casing for registry names. MIS-synced students arrive ALL-CAPS
 * ("VILLANUEVA, ELLAHYZA FAITH") and read as shouting next to the
 * mixed-case employees. Only fully-uppercase names are rewritten — first
 * letter up, rest down, at word starts and after hyphens/apostrophes
 * (JUDY-ANN → Judy-Ann, O'BRIEN → O'Brien). Anything already mixed-case
 * (hand-registered "de la Cruz") passes through untouched.
 */
function personName(value: string): string {
  if (value !== value.toUpperCase()) return value;
  return value
    .toLowerCase()
    .replace(/(^|[\s,'’-])(\p{L})/gu, (_match, separator: string, letter: string) => separator + letter.toUpperCase());
}

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
      <DialogContent lockDismiss className="max-h-[90dvh] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <KeyRound className="size-4" aria-hidden /> Portal account created
          </DialogTitle>
        </DialogHeader>
        <p className="text-sm text-muted-foreground">
          A SYNAPSE account was created for {kind} <span className="tabular-nums">{identifier}</span>.
          Share these credentials once through a secure channel — the password is shown here and cannot be retrieved later.
        </p>
        <dl className="space-y-2 text-sm">
          <div className="space-y-0.5">
            <dt className="text-xs text-muted-foreground">Email</dt>
            <dd className="flex items-center gap-2 tabular-nums">
              <Mail className="size-3.5 text-muted-foreground" aria-hidden />
              <span className="min-w-0 flex-1 truncate">{account.email}</span>
            </dd>
          </div>
          <div className="space-y-0.5">
            <dt className="text-xs text-muted-foreground">Temporary password</dt>
            <dd className="rounded-md border bg-muted/50 px-3 py-2 tabular-nums text-xs break-all">
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

/**
 * Server rejections (duplicate handles, account-email conflicts) arrive as
 * field-level envelope errors — map those onto the form inputs so the
 * message sits under the offending field; everything else becomes a
 * form-level alert above the footer.
 */
function serverErrorFor(err: unknown, fields: readonly string[]): { field?: string; message: string } {
  if (err instanceof ApiEnvelopeError) {
    const fieldHit = err.errors.find((e) => e.field !== undefined && fields.includes(e.field));
    if (fieldHit?.field !== undefined) return { field: fieldHit.field, message: fieldHit.message };
    const first = err.errors[0];
    if (first !== undefined) return { message: first.message };
  }
  return { message: 'Request failed. Please try again.' };
}

/**
 * ReferStudentDialog — the guidance-side action on a registry record.
 * The direction is fixed (counselling → clinic) and the patient is the
 * row's own record; the operator picks the artifact and may add a reason
 * or notes. Posts through the same referral pipeline the Referrals page
 * uses — the backend enforces `referrals.create`.
 */
function ReferStudentDialog({ student, onClose }: { student: Student; onClose: () => void }) {
  const create = useCreateReferral();
  const { register, handleSubmit, formState: { errors }, watch, setValue } =
    useForm<CreateReferralInput>({
      resolver: zodResolver(createReferralSchema),
      defaultValues: {
        patient_school_id: student.student_number ?? '',
        source_module: 'counselling',
        target_module: 'clinic',
        artifact_type: 'referral_letter',
      },
    });
  // Custom artifact mode (free text) — off by default.
  const [customArtifact, setCustomArtifact] = useState(false);
  const artifactType = watch('artifact_type');

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, { onSuccess: onClose });
  });

  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle>Refer to clinic</DialogTitle>
      </DialogHeader>
      <p className="text-sm text-muted-foreground">
        {personName(`${student.last_name}, ${student.first_name}`)} · {student.student_number}
      </p>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="space-y-1.5">
          <Label id="refer-artifact-label">Artifact type</Label>
          {customArtifact ? (
            <div className="flex gap-2">
              <Input
                id="artifact_type"
                autoFocus
                placeholder="e.g. clearance, school_letter"
                value={artifactType ?? ''}
                aria-invalid={errors.artifact_type !== undefined}
                onChange={(e) => setValue('artifact_type', e.target.value, { shouldValidate: true })}
              />
              <Button type="button" variant="outline" onClick={() => {
                setCustomArtifact(false);
                setValue('artifact_type', 'referral_letter', { shouldValidate: true });
              }}>
                Use preset
              </Button>
            </div>
          ) : (
            <Select
              value={artifactType === 'intake_pass' || artifactType === 'referral_letter' ? artifactType : ''}
              onValueChange={(v) => {
                if (v === 'custom') {
                  setValue('artifact_type', '', { shouldValidate: false });
                  setCustomArtifact(true);
                } else {
                  setValue('artifact_type', v, { shouldValidate: true });
                }
              }}
            >
              <SelectTrigger id="artifact_type" aria-labelledby="refer-artifact-label">
                <SelectValue placeholder="Select…" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="referral_letter">Referral letter</SelectItem>
                <SelectItem value="intake_pass">Intake pass</SelectItem>
                <SelectItem value="custom">Custom…</SelectItem>
              </SelectContent>
            </Select>
          )}
          {errors.artifact_type !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.artifact_type.message}</p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="refer-reason">Reason code (optional)</Label>
          <Input id="refer-reason" {...register('reason_code')} />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="refer-notes">Notes (optional)</Label>
          <Textarea id="refer-notes" rows={3} {...register('notes_plaintext')} />
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Create referral
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function CreateStudentDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateStudent();
  const [createdAccount, setCreatedAccount] = useState<{ identifier: string; account: PortalAccount } | null>(null);
  const [serverError, setServerError] = useState<string | null>(null);
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch, setError } =
    useForm<CreateStudentInput>({ resolver: zodResolver(createStudentSchema) });

  const gender = watch('gender');
  // The account email pre-fills from the university convention
  // (first.last@foundationu.com) as the name is typed — until the user
  // edits it by hand, after which their correction wins.
  const emailManuallyEdited = useRef(false);
  const derivedEmail = deriveUniversityEmail(watch('first_name'), watch('last_name'));
  useEffect(() => {
    if (!emailManuallyEdited.current) {
      setValue('account_email', derivedEmail ?? '');
    }
  }, [derivedEmail, setValue]);

  const onSubmit = handleSubmit((values) => {
    setServerError(null);
    create.mutate(values, {
      onSuccess: (result) => {
        if (result.portal_account !== undefined) {
          setCreatedAccount({ identifier: result.student_number ?? '', account: result.portal_account });
        } else {
          reset();
          onClose();
        }
      },
      onError: (err) => {
        const e = serverErrorFor(err, ['student_number', 'account_email', 'qr_code', 'rfid_tag']);
        if (e.field !== undefined) {
          setError(e.field as keyof CreateStudentInput, { type: 'server', message: e.message });
        } else {
          setServerError(e.message);
        }
      },
    });
  });

  function handleClose() {
    reset();
    onClose();
  }

  return (
    <DialogContent lockDismiss>
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
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="student-account-email">Account email</Label>
          <Input
            id="student-account-email"
            type="email"
            aria-invalid={errors.account_email !== undefined}
            {...register('account_email', { onChange: () => { emailManuallyEdited.current = true; } })}
          />
          {errors.account_email !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.account_email.message}</p>
          )}
        </div>
        {serverError !== null && (
          <Alert variant="destructive" className="col-span-2">
            <AlertDescription>{serverError}</AlertDescription>
          </Alert>
        )}
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
 * ManageMedicalRecordDialog — view AND edit the medical sections of a
 * student record: allergies and emergency contacts (add / update /
 * delete inline). The registry snapshot shown above the sections is
 * context only — the student record itself is view-only, so registry
 * fields have no edit UI anywhere (2026-09 product decision).
 */
function ManageMedicalRecordDialog({ studentId, onClose }: { studentId: number | string; onClose: () => void }) {
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
  const activeStudentId = s?.id ?? (typeof studentId === 'number' && studentId > 0 ? studentId : 0);
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
    if (activeStudentId <= 0) return;
    const onDone = () => {
      setEditing(null);
      allergyForm.reset({ allergen: '', severity: 'mild', reaction: '' });
    };
    if (editing?.type === 'allergy') {
      updateAllergy.mutate(
        { studentId: activeStudentId, allergyId: editing.id, input: values },
        { onSuccess: onDone },
      );
    } else {
      addAllergy.mutate({ studentId: activeStudentId, input: values }, { onSuccess: onDone });
    }
  });
  const submitContact = contactForm.handleSubmit((values) => {
    if (activeStudentId <= 0) return;
    const onDone = () => {
      setEditing(null);
      contactForm.reset({ contact_name: '', relationship: '', phone: '', is_primary: false });
    };
    if (editing?.type === 'contact') {
      updateContact.mutate(
        { studentId: activeStudentId, contactId: editing.id, input: values },
        { onSuccess: onDone },
      );
    } else {
      addContact.mutate({ studentId: activeStudentId, input: values }, { onSuccess: onDone });
    }
  });

  return (
    <DialogContent lockDismiss className="max-h-[85vh] overflow-y-auto sm:max-w-xl">
      <DialogHeader>
        <DialogTitle>
          {s !== undefined ? `${personName(`${s.last_name}, ${s.first_name}`)} — ${s.student_number}` : 'Student'}
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
                      disabled={deleteAllergy.isPending || activeStudentId <= 0}
                      onClick={() => deleteAllergy.mutate({ studentId: activeStudentId, allergyId: a.id })}
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
                  {c.is_primary && <Badge variant="info">Primary</Badge>}
                  <span className="font-medium">{c.contact_name}</span>
                  <span className="text-xs text-muted-foreground capitalize">({c.relationship})</span>
                  <span className="min-w-0 flex-1 truncate tabular-nums text-xs">{c.phone}</span>
                  <span className="flex items-center gap-0.5">
                    <Button variant="ghost" size="sm" className="size-7 p-0" aria-label={`Edit contact ${c.contact_name}`} onClick={() => startEditContact(c)}>
                      <Pencil className="size-3.5" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="size-7 p-0 text-destructive"
                      aria-label={`Remove contact ${c.contact_name}`}
                      disabled={deleteContact.isPending || activeStudentId <= 0}
                      onClick={() => deleteContact.mutate({ studentId: activeStudentId, contactId: c.id })}
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

/**
 * StudentDetailDialog — VIEW-ONLY student record: registry snapshot plus
 * the medical sections with no mutation controls. Editing lives in
 * ManageMedicalRecordDialog so the menu's "View" and "Manage" actions
 * match what they can actually do.
 */
function StudentDetailDialog({ studentId, onClose }: { studentId: number | string; onClose: () => void }) {
  const detail = useStudent(studentId);
  const s = detail.data;

  return (
    <DialogContent lockDismiss className="max-h-[85vh] overflow-y-auto sm:max-w-xl">
      <DialogHeader>
        <DialogTitle>
          {s !== undefined ? `${personName(`${s.last_name}, ${s.first_name}`)} — ${s.student_number}` : 'Student'}
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
                </li>
              ))}
            </ul>
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
                  {c.is_primary && <Badge variant="info">Primary</Badge>}
                  <span className="font-medium">{c.contact_name}</span>
                  <span className="text-xs text-muted-foreground capitalize">({c.relationship})</span>
                  <span className="min-w-0 flex-1 truncate tabular-nums text-xs">{c.phone}</span>
                </li>
              ))}
            </ul>
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
  const [createdAccount, setCreatedAccount] = useState<{ identifier: string; account: PortalAccount } | null>(null);
  const [serverError, setServerError] = useState<string | null>(null);
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch, setError } =
    useForm<CreateEmployeeInput>({
      resolver: zodResolver(createEmployeeSchema),
      defaultValues: { employment_status: 'active' },
    });

  // Same account-email pre-fill as the student dialog: university address
  // from the name, released to the user the moment they edit it.
  const emailManuallyEdited = useRef(false);
  const derivedEmail = deriveUniversityEmail(watch('first_name'), watch('last_name'));
  useEffect(() => {
    if (!emailManuallyEdited.current) {
      setValue('account_email', derivedEmail ?? '');
    }
  }, [derivedEmail, setValue]);

  const onSubmit = handleSubmit((values) => {
    setServerError(null);
    create.mutate(values, {
      onSuccess: (result) => {
        if (result.portal_account !== undefined) {
          setCreatedAccount({ identifier: result.employee_number ?? '', account: result.portal_account });
        } else {
          reset();
          onClose();
        }
      },
      onError: (err) => {
        const e = serverErrorFor(err, ['employee_number', 'account_email', 'qr_code', 'rfid_tag']);
        if (e.field !== undefined) {
          setError(e.field as keyof CreateEmployeeInput, { type: 'server', message: e.message });
        } else {
          setServerError(e.message);
        }
      },
    });
  });

  function handleClose() {
    reset();
    onClose();
  }

  return (
    <DialogContent lockDismiss>
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
          <Label htmlFor="create-emp-dept">Department</Label>
          {/*
            Free text: the employee `department` field is owned by the FU
            MIS integration and is overwritten on login and on HR sync, so
            a locally curated picker could not stay consistent with it.
          */}
          <Input id="create-emp-dept" {...register('department')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="position">Position</Label>
          <Input id="position" {...register('position')} />
        </div>
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="employee-account-email">Account email</Label>
          <Input
            id="employee-account-email"
            type="email"
            aria-invalid={errors.account_email !== undefined}
            {...register('account_email', { onChange: () => { emailManuallyEdited.current = true; } })}
          />
          {errors.account_email !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.account_email.message}</p>
          )}
        </div>
        {serverError !== null && (
          <Alert variant="destructive" className="col-span-2">
            <AlertDescription>{serverError}</AlertDescription>
          </Alert>
        )}
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
  const isTeaching = watch('is_teaching');
  const gender = watch('gender');

  const onSubmit = handleSubmit((values) => {
    update.mutate({ id: employee.id, input: values }, { onSuccess: () => { reset(); onClose(); } });
  });

  return (
    <DialogContent lockDismiss>
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
            <Label htmlFor="emp-dept">Department</Label>
            <Input id="emp-dept" {...register('department')} />
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
 * EmployeeRecordHistory — one MIS record's expandable clinic history.
 *
 * FU MIS issues one record (its own employee number) per appointment, so
 * one person can hold several registry records. This block renders one
 * record inside the registry row accordion (and the detail dialog);
 * expanding it lazily fetches that record's encounters.
 */
function EmployeeRecordHistory({ record }: { record: EmployeeRecord }) {
  const [open, setOpen] = useState(false);
  const encounters = useEmployeeEncounters(record.id, open);

  return (
    <div className="rounded-lg border bg-background">
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
        className="flex w-full flex-wrap items-center gap-2 px-3 py-2 text-left text-xs hover:bg-muted/50"
      >
        <ChevronDown
          className={`size-3.5 shrink-0 text-muted-foreground transition-transform ${open ? '' : '-rotate-90'}`}
          aria-hidden
        />
        <span className="tabular-nums font-medium">{record.employee_number}</span>
        {record.is_primary && <Badge variant="outline">current</Badge>}
        {record.archived && <Badge variant="secondary">Archived</Badge>}
        <span className="min-w-0 flex-1 truncate">
          {record.position ?? 'No position'}
          {record.department !== null && <span className="text-muted-foreground"> — {record.department}</span>}
        </span>
        <span className="tabular-nums text-muted-foreground">
          {record.position_year !== null ? `${record.position_year} · ` : ''}
          {record.visit_count} visit{record.visit_count === 1 ? '' : 's'}
        </span>
      </button>
      {open && (
        <div className="border-t px-3 py-2">
          {encounters.isLoading && (
            <p className="flex items-center gap-2 text-xs text-muted-foreground" role="status">
              <Loader2 className="size-3.5 animate-spin" aria-hidden /> Loading clinic history…
            </p>
          )}
          {encounters.isError && (
            <p className="text-xs text-destructive">Failed to load this record's clinic history.</p>
          )}
          {encounters.data !== undefined && encounters.data.length === 0 && (
            <p className="text-xs text-muted-foreground">No clinic visits under this record.</p>
          )}
          {encounters.data !== undefined && encounters.data.length > 0 && (
            <ul className="divide-y">
              {encounters.data.map((v) => (
                <li key={v.id} className="flex items-center gap-3 py-1.5 text-xs">
                  <span className="w-40 shrink-0 text-muted-foreground">{fmtUtcToApp(v.started_at)}</span>
                  <span className="min-w-0 flex-1 truncate">{v.chief_complaint}</span>
                  <Badge variant="secondary">{v.status}</Badge>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}

/**
 * EmployeeRowGroup — one registry row plus its expandable record-group
 * sub-row. The row IS the person's primary MIS record (the backend lists
 * one row per person, newest appointment first); the sub-row holds the
 * position-history accordion over the person's remaining records.
 */
function EmployeeRowGroup({
  employee: e,
  multiRecord,
  expanded,
  onToggle,
  actions,
}: {
  employee: Employee;
  multiRecord: boolean;
  expanded: boolean;
  onToggle: () => void;
  /** The row's Actions cell — a closure of the page (permission-aware). */
  actions: ReactNode;
}) {
  const records = e.records ?? [];
  return (
    <>
      <TableRow>
        <TableCell className="px-3 tabular-nums text-xs">{e.employee_number}</TableCell>
        <TableCell className="px-3">
          <div className="flex items-center gap-1.5">
            {multiRecord && (
              <button
                type="button"
                aria-expanded={expanded}
                aria-label={`${expanded ? 'Hide' : 'Show'} the ${records.length} MIS records of ${e.last_name}, ${e.first_name}`}
                onClick={onToggle}
                className="rounded p-0.5 hover:bg-muted"
              >
                <ChevronDown
                  className={`size-4 text-muted-foreground transition-transform ${expanded ? '' : '-rotate-90'}`}
                  aria-hidden
                />
              </button>
            )}
            <span>{e.last_name}, {e.first_name}</span>
          </div>
        </TableCell>
        <TableCell className="px-3 text-xs">{e.department ?? '—'}</TableCell>
        <TableCell className="px-3 text-xs">{e.position ?? '—'}</TableCell>
        <TableCell className="px-3">
          <div className="flex flex-wrap items-center gap-1">
            {e.is_directory_record ? (
              <Badge variant="outline" className="border-sky-300 bg-sky-50 text-sky-700 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300">
                MIS Directory
              </Badge>
            ) : (
              <>
                <Badge variant={e.employment_status === 'active' ? 'success' : e.employment_status === 'on_leave' ? 'warning' : 'secondary'}>
                  {employmentStatusLabel(e.employment_status)}
                </Badge>
                <TeachingBadge isTeaching={e.is_teaching} />
                {multiRecord && <Badge variant="info">{records.length} records</Badge>}
                {e.archived && <Badge variant="secondary">Archived</Badge>}
              </>
            )}
          </div>
        </TableCell>
        <TableCell className="px-3 text-right">
          {actions}
        </TableCell>
      </TableRow>
      {multiRecord && expanded && (
        <TableRow className="bg-muted/40 hover:bg-muted/40">
          <TableCell colSpan={6} className="px-3 py-3 align-top">
            <p className="mb-2 text-xs font-medium text-muted-foreground">
              MIS records — position history, newest first
            </p>
            <div className="space-y-1.5">
              {records.map((r) => <EmployeeRecordHistory key={r.id} record={r} />)}
            </div>
          </TableCell>
        </TableRow>
      )}
    </>
  );
}

/**
 * EmployeeDetailDialog — read-only detail for the Employees tab. Mirrors
 * `StudentDetailDialog` for the students side. The schema already
 * carries the emergency contact + date-hired fields, so the dialog
 * can show them without an extra round trip.
 */
function EmployeeDetailDialog({ employeeId, onClose }: { employeeId: number | string; onClose: () => void }) {
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
            <dd className="tabular-nums text-xs">{e.date_hired ?? '—'}</dd>
          </div>
          <div className="col-span-2">
            <dt className="text-xs text-muted-foreground">Emergency contact</dt>
            <dd>
              {e.emergency_contact_name !== null || e.emergency_contact_phone !== null
                ? (
                  <span>
                    {e.emergency_contact_name ?? '—'}
                    {e.emergency_contact_phone !== null && (
                      <span className="ml-2 tabular-nums text-xs">{e.emergency_contact_phone}</span>
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
          {(e.records?.length ?? 0) > 1 && (
            <div className="col-span-2">
              <dt className="text-xs text-muted-foreground">MIS records — position history</dt>
              <dd className="mt-1.5 space-y-1.5">
                {e.records?.map((r) => <EmployeeRecordHistory key={r.id} record={r} />)}
              </dd>
            </div>
          )}
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

export default function PatientsPage() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [empCursor, setEmpCursor] = useState<string | null>(null);
  const [empHistory, setEmpHistory] = useState<Array<string | null>>([null]);
  // Registry rows are one-per-person (the primary MIS record); these ids
  // mark the rows whose record-group accordion is expanded.
  const [expandedEmp, setExpandedEmp] = useState<ReadonlySet<number>>(new Set());
  const toggleEmpExpanded = (id: number) => {
    setExpandedEmp((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };
  const [tab, setTab] = useTabParam('students');
  // Registry writes need their own permission (counsellors hold read
  // only) — hide what the backend would 403 (2026-09 audit).
  const canWrite = useCan('clinic.patients.write');
  // Clinic records and guidance records are NOT the same surface: the
  // actions differ (clinic registers/treats/archives; guidance refers to
  // clinic). The sidebar entry picks the side via ?side=guidance; writers
  // default to the clinic side, everyone else lands on the guidance side.
  const [searchParams] = useSearchParams();
  const side: 'clinic' | 'guidance' =
    canWrite && searchParams.get('side') !== 'guidance' ? 'clinic' : 'guidance';
  const canRefer = useCan('referrals.create');
  const [referStudent, setReferStudent] = useState<Student | null>(null);
  // Filters live in the URL (PRODUCT principle 5): ?q=, ?archived=1,
  // ?emp_q=, ?emp_archived=, ?emp_department= and ?emp_position= survive a
  // refresh and can be shared as links. Each tab keeps its own search key
  // so switching tabs re-seeds from the URL.
  const [query, setQuery, queryDraft] = useUrlFilter('q', { debounceMs: 300 });
  const [empQuery, setEmpQuery, empQueryDraft] = useUrlFilter('emp_q', { debounceMs: 300 });
  const [openCreate, setOpenCreate] = useState(false);
  const [openCreateEmp, setOpenCreateEmp] = useState(false);
  const [detailId, setDetailId] = useState<number | string | null>(null);
  const [empDetailId, setEmpDetailId] = useState<number | string | null>(null);
  const [manageId, setManageId] = useState<number | string | null>(null);
  const [editEmp, setEditEmp] = useState<Employee | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const [showArchived, setShowArchived] = useUrlFilter('archived', { default: '' });
  const [showArchivedEmp, setShowArchivedEmp] = useUrlFilter('emp_archived', { default: '' });
  // Students tab facets. Department, Course (MIS `program`) and Year Level
  // (MIS `level`) are the three categorical fields the MIS student endpoint
  // itself filters on, and the only ones our synced rows carry. There is
  // deliberately no Section select: MIS returns no section for a student, so
  // every synced row has it null and the filter could never match.
  const [stuDepartment, setStuDepartment] = useUrlFilter('stu_department', { default: 'all' });
  const [stuCourse, setStuCourse] = useUrlFilter('stu_course', { default: 'all' });
  const [stuYear, setStuYear] = useUrlFilter('stu_year', { default: 'all' });
  // Employees tab facets. Department and Position are the only two
  // categorical fields the FU MIS employee payload actually supplies
  // (`department_name` and `position`), so they are the only facets the
  // tab offers. The former teaching/non-teaching Type select was removed:
  // MIS returns no teaching flag for any employee, so "Teaching (faculty)"
  // could never match a synced row — it always returned zero results while
  // "Non-teaching" returned everyone.
  const [empDepartment, setEmpDepartment] = useUrlFilter('emp_department', { default: 'all' });
  const [empPosition, setEmpPosition] = useUrlFilter('emp_position', { default: 'all' });
  const archiveEmp = useSetEmployeeArchived();

  const searching = query.trim().length >= 2;
  const empSearching = empQuery.trim().length >= 2;
  // An active facet is a filter even with nothing typed, so the empty state
  // must read "no matches" rather than "no students registered".
  const studentFiltered = searching
    || stuDepartment !== 'all' || stuCourse !== 'all' || stuYear !== 'all';
  const list = useStudents(cursor, 25, showArchived === '1', stuDepartment, stuCourse, stuYear);
  const search = useStudentSearch(query, {
    department: stuDepartment,
    course: stuCourse,
    yearLevel: stuYear,
  });
  const stuFacets = useStudentFacets();
  const employees = useEmployees(empCursor, 25, showArchivedEmp === '1', 'all', empDepartment, empPosition);
  const empFacets = useEmployeeFacets();
  const empSearch = useEmployeeSearch(empQuery, empDepartment, empPosition);
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
        <Button className="min-h-11 md:min-h-0" size="sm" variant="outline" aria-label={`Actions for ${student.student_number}`}>
          Actions <ChevronDown className="size-3.5" aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-48">
        <DropdownMenuItem className="min-h-11" onSelect={() => setDetailId(student.id <= 0 && student.student_number ? student.student_number : student.id)}>
          <Eye /> View record
        </DropdownMenuItem>
        {/* Guidance-side action (2026-09-27): the guidance record surface
            refers a student to the clinic — clinic-only actions (medical
            edits, archiving) stay below, gated on write. */}
        {side === 'guidance' && canRefer && (
          <DropdownMenuItem className="min-h-11" onSelect={() => setReferStudent(student)}>
            <Share2 /> Refer to clinic
          </DropdownMenuItem>
        )}
        {canWrite && (
          <DropdownMenuItem className="min-h-11" onSelect={() => setManageId(student.id <= 0 && student.student_number ? student.student_number : student.id)}>
            <HeartPulse /> Manage medical record
          </DropdownMenuItem>
        )}
        {canWrite && !student.is_directory_record && (
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
        <Button className="min-h-11 md:min-h-0" size="sm" variant="outline" aria-label={`Actions for ${employee.employee_number}`}>
          Actions <ChevronDown className="size-3.5" aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-48">
        <DropdownMenuItem className="min-h-11" onSelect={() => setEmpDetailId(employee.id <= 0 && employee.employee_number ? employee.employee_number : employee.id)}>
          <Eye /> View employee record
        </DropdownMenuItem>
        {canWrite && !employee.is_directory_record && (
          <DropdownMenuItem className="min-h-11" onSelect={() => setEditEmp(employee)}>
            <Pencil /> Edit employee record
          </DropdownMenuItem>
        )}
        {canWrite && !employee.is_directory_record && (
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
    // md+: the page becomes a flex column exactly one viewport tall
    // (100dvh minus the h-14 topbar), so the registry tables flex to the
    // real leftover space and the pagers sit inside the fold — no
    // estimated constants to go stale when filters wrap. Mobile keeps
    // the natural scrolling flow.
    <main className="space-y-4 p-6 md:flex md:h-[calc(100dvh-3.5rem)] md:flex-col md:overflow-hidden">
      <Tabs value={tab} onValueChange={setTab} className="space-y-4 md:min-h-0 md:flex-1 md:flex md:flex-col">
        <div className="md:shrink-0">
          <PageHeader
          title="Records"
          description={
            side === 'guidance'
              ? 'The student and employee registry as seen from Guidance — refer records to the clinic; clinical actions stay clinic-side.'
              : 'Registry recycled from the legacy system — records are archived, never deleted.'
          }
          actions={
            tab === 'students' ? (
              side === 'clinic' && canWrite ? (
                <Dialog open={openCreate} onOpenChange={setOpenCreate}>
                  <Button onClick={() => setOpenCreate(true)}>
                    <UserPlus /> Register student
                  </Button>
                  {openCreate && <CreateStudentDialog onClose={() => setOpenCreate(false)} />}
                </Dialog>
              ) : null
            ) : (
              side === 'clinic' && canWrite ? (
                <Dialog open={openCreateEmp} onOpenChange={setOpenCreateEmp}>
                  <Button onClick={() => setOpenCreateEmp(true)}>
                    <UserPlus /> Register employee
                  </Button>
                  {openCreateEmp && <CreateEmployeeDialog onClose={() => setOpenCreateEmp(false)} />}
                </Dialog>
              ) : null
            )
          }
          />
        </div>

        <TabsContent value="students" className="space-y-4 md:min-h-0 md:flex-1 md:flex md:flex-col">
          <PageToolbar className="md:shrink-0">
            <div className="w-full space-y-1 sm:w-80 lg:flex-1 lg:max-w-md">
              <Label htmlFor="student-search" className="text-xs">Search</Label>
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  id="student-search"
                  aria-label="Search students"
                  placeholder="Search number, name, department (min 2 chars)"
                  className="pl-9 placeholder:truncate"
                  value={queryDraft}
                  onChange={(e) => setQuery(e.target.value)}
                />
              </div>
            </div>
            <div className="space-y-1">
              <Label id="stu-department-label" className="text-xs">Department</Label>
              <Select
                value={stuDepartment}
                onValueChange={(v) => { setStuDepartment(v); setCursor(null); setHistory([null]); }}
              >
                <SelectTrigger
                  aria-labelledby="stu-department-label"
                  className="w-52"
                  disabled={stuFacets.isLoading || (stuFacets.data?.departments.length ?? 0) === 0}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All departments</SelectItem>
                  {(stuFacets.data?.departments ?? []).map((d) => (
                    <SelectItem key={d} value={d}>{d}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label id="stu-course-label" className="text-xs">Program</Label>
              <Select
                value={stuCourse}
                onValueChange={(v) => { setStuCourse(v); setCursor(null); setHistory([null]); }}
              >
                <SelectTrigger
                  aria-labelledby="stu-course-label"
                  className="w-56"
                  disabled={stuFacets.isLoading || (stuFacets.data?.courses.length ?? 0) === 0}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All programs</SelectItem>
                  {(stuFacets.data?.courses ?? []).map((c) => (
                    <SelectItem key={c} value={c}>{c}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label id="stu-year-label" className="text-xs">Year level</Label>
              <Select
                value={stuYear}
                onValueChange={(v) => { setStuYear(v); setCursor(null); setHistory([null]); }}
              >
                <SelectTrigger
                  aria-labelledby="stu-year-label"
                  className="w-36"
                  disabled={stuFacets.isLoading || (stuFacets.data?.yearLevels.length ?? 0) === 0}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All years</SelectItem>
                  {(stuFacets.data?.yearLevels ?? []).map((y) => (
                    <SelectItem key={y} value={String(y)}>Year {y}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Status</Label>
              <div>
                <Button
                  variant={showArchived === '1' ? 'secondary' : 'outline'}
                  aria-pressed={showArchived === '1'}
                  onClick={() => { setShowArchived(showArchived === '1' ? '' : '1'); setCursor(null); setHistory([null]); }}
                >
                  <Archive /> {showArchived === '1' ? 'Hide archived' : 'Show archived'}
                </Button>
              </div>
            </div>
          </PageToolbar>

          <section className="hidden overflow-hidden rounded-xl border bg-card md:block md:min-h-0 md:flex-1">
            {/* The table flexes to the real leftover space (no estimated
                constants): the section is a flex-1 child of the viewport-
                tall page column, and the wrapper below is its h-full
                scroll container — which is also what the sticky header
                binds to. Solid header bg (a translucent one lets rows
                bleed through while scrolling) and a box-shadow bottom
                border, which survives border-collapse where a real
                border would scroll away. */}
            <Table
              ariaLabel="Student registry"
              wrapperClassName="h-full overflow-y-auto"
              className="[&_td]:py-1.5"
            >
              <TableHeader className="sticky top-0 z-10 bg-muted shadow-[0_1px_0_0_var(--border)]">
                <TableRow>
                  <TableHead className="px-3">Number</TableHead>
                  <TableHead className="px-3">Name</TableHead>
                  <TableHead className="px-3">Course</TableHead>
                  <TableHead className="px-3">Year</TableHead>
                  {/* Blood type is a clinical detail — the guidance side of
                      Records shows the department instead. */}
                  {side === 'clinic' && <TableHead className="px-3">Blood</TableHead>}
                  <TableHead className="px-3">Department</TableHead>
                  <TableHead className="px-3">Status</TableHead>
                  <TableHead className="px-3 text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableStateRows
                  colSpan={side === 'clinic' ? 8 : 7}
                  isLoading={loading}
                  isError={errored}
                  isEmpty={rows.length === 0}
                  onRetry={retry}
                  pending={retrying}
                  errorMessage="Failed to load students."
                  loadingLabel="Loading students"
                  empty={{
                    title: 'No students registered.',
                    description: 'Students appear after their first university-ID login or an HR/MIS sync.',
                  }}
                  noResults={{
                    title: 'No students match these filters.',
                    description: 'Try a different student number, name, department, program, or year level.',
                  }}
                  hasFilters={studentFiltered}
                />
                {rows.map((s) => (
                  <TableRow key={s.id}>
                    <TableCell className="px-3 tabular-nums text-xs">{s.student_number}</TableCell>
                    <TableCell className="px-3">{personName(`${s.last_name}, ${s.first_name}`)}</TableCell>
                    <TableCell className="px-3 text-xs">{s.course ?? '—'}</TableCell>
                    <TableCell className="px-3 tabular-nums text-xs">
                      {s.year_level !== null ? s.year_level : '—'}
                    </TableCell>
                    {side === 'clinic' && (
                      <TableCell className="px-3 tabular-nums text-xs">{s.blood_type ?? '—'}</TableCell>
                    )}
                    <TableCell className="px-3 text-xs">{s.department ?? '—'}</TableCell>
                    <TableCell className="px-3">
                      {s.is_directory_record ? (
                        <Badge variant="outline" className="border-sky-300 bg-sky-50 text-sky-700 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300">
                          MIS Directory
                        </Badge>
                      ) : s.archived ? (
                        <Badge variant="secondary">Archived</Badge>
                      ) : (
                        <Badge variant="success">Active</Badge>
                      )}
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
              {studentFiltered ? 'No matches.' : 'No students registered.'}
            </p>
          )}
          <MobileCardList>
            {rows.map((s) => (
              <MobileCard key={s.id} aria-label={`Student ${s.student_number}`}>
                <div className="mb-1 flex items-center justify-between gap-2">
                  <span className="text-sm font-medium text-foreground">{personName(`${s.last_name}, ${s.first_name}`)}</span>
                  {s.is_directory_record ? (
                    <Badge variant="outline" className="border-sky-300 bg-sky-50 text-sky-700 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300">
                      MIS Directory
                    </Badge>
                  ) : s.archived ? (
                    <Badge variant="secondary">Archived</Badge>
                  ) : (
                    <Badge variant="success">Active</Badge>
                  )}
                </div>
                <MobileCardField label="Number"><span className="tabular-nums text-xs">{s.student_number}</span></MobileCardField>
                <MobileCardField label="Course"><span className="text-xs">{s.course ?? '—'}</span></MobileCardField>
                <MobileCardField label="Year"><span className="tabular-nums text-xs">{s.year_level !== null ? s.year_level : '—'}</span></MobileCardField>
                {side === 'clinic' && (
                  <MobileCardField label="Blood"><span className="tabular-nums text-xs">{s.blood_type ?? '—'}</span></MobileCardField>
                )}
                <MobileCardField label="Department"><span className="text-xs">{s.department ?? '—'}</span></MobileCardField>
                <MobileCardActions>{studentActions(s)}</MobileCardActions>
              </MobileCard>
            ))}
          </MobileCardList>

          {!searching && (
            <nav className="flex items-center justify-between md:shrink-0" aria-label="pagination">
              <p className="text-xs text-muted-foreground">Page {history.length} · {rows.length} student{rows.length === 1 ? '' : 's'} shown</p>
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

        <TabsContent value="employees" className="space-y-4 md:min-h-0 md:flex-1 md:flex md:flex-col">
          <PageToolbar className="md:shrink-0">
            <div className="w-full space-y-1 sm:w-80 lg:flex-1 lg:max-w-md">
              <Label htmlFor="employee-search" className="text-xs">Search</Label>
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  id="employee-search"
                  aria-label="Search employees"
                  placeholder="Search number, name, department (min 2 chars)"
                  className="pl-9 placeholder:truncate"
                  value={empQueryDraft}
                  onChange={(e) => setEmpQuery(e.target.value)}
                />
              </div>
            </div>
            <div className="space-y-1">
              <Label id="emp-department-label" className="text-xs">Department</Label>
              <Select
                value={empDepartment}
                onValueChange={(v) => { setEmpDepartment(v); setEmpCursor(null); setEmpHistory([null]); }}
              >
                <SelectTrigger
                  aria-labelledby="emp-department-label"
                  className="w-52"
                  disabled={empFacets.isLoading || (empFacets.data?.departments.length ?? 0) === 0}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All departments</SelectItem>
                  {(empFacets.data?.departments ?? []).map((d) => (
                    <SelectItem key={d} value={d}>{d}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label id="emp-position-label" className="text-xs">Position</Label>
              <Select
                value={empPosition}
                onValueChange={(v) => { setEmpPosition(v); setEmpCursor(null); setEmpHistory([null]); }}
              >
                <SelectTrigger
                  aria-labelledby="emp-position-label"
                  className="w-52"
                  disabled={empFacets.isLoading || (empFacets.data?.positions.length ?? 0) === 0}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All positions</SelectItem>
                  {(empFacets.data?.positions ?? []).map((p) => (
                    <SelectItem key={p} value={p}>{p}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label className="text-xs">Status</Label>
              <div>
                <Button
                  variant={showArchivedEmp === '1' ? 'secondary' : 'outline'}
                  aria-pressed={showArchivedEmp === '1'}
                  onClick={() => { setShowArchivedEmp(showArchivedEmp === '1' ? '' : '1'); setEmpCursor(null); setEmpHistory([null]); }}
                >
                  <Archive /> {showArchivedEmp === '1' ? 'Hide archived' : 'Show archived'}
                </Button>
              </div>
            </div>
          </PageToolbar>

          <section className="hidden overflow-hidden rounded-xl border bg-card md:block md:min-h-0 md:flex-1">
            {/* Same flex-to-fit recipe as the students table (see the
                comment there). */}
            <Table
              ariaLabel="Employee registry"
              wrapperClassName="h-full overflow-y-auto"
              className="[&_td]:py-1.5"
            >
              <TableHeader className="sticky top-0 z-10 bg-muted shadow-[0_1px_0_0_var(--border)]">
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
                  return (
                    <>
                      <TableStateRows
                        colSpan={6}
                        isLoading={empLoading}
                        isError={empErrored}
                        isEmpty={empRows.length === 0}
                        onRetry={() => void (empSearching ? empSearch.refetch() : employees.refetch())}
                        pending={empSearching ? empSearch.isFetching : employees.isFetching}
                        errorMessage="Failed to load employees."
                        loadingLabel="Loading employees"
                        empty={{
                          title: 'No employees registered.',
                          description: 'Employees appear after an MIS directory sync (synapse:mis-sync) or their first university-ID login.',
                        }}
                        noResults={{
                          title: 'No employees match these filters.',
                          description: 'Try a different employee number, name, department, or position.',
                        }}
                        hasFilters={empSearching || empDepartment !== 'all' || empPosition !== 'all'}
                      />
                      {empRows.map((e) => {
                        const multiRecord = !e.is_directory_record && (e.records?.length ?? 0) > 1;
                        const expanded = expandedEmp.has(e.id);
                        return (
                          <EmployeeRowGroup
                            key={e.id}
                            employee={e}
                            multiRecord={multiRecord}
                            expanded={expanded}
                            onToggle={() => toggleEmpExpanded(e.id)}
                            actions={employeeActions(e)}
                          />
                        );
                      })}
                    </>
                  );
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
                    {(empSearching || empDepartment !== 'all' || empPosition !== 'all')
                      ? 'No employees match these filters.'
                      : 'No employees registered.'}
                  </p>
                )}
                <MobileCardList>
                  {empRows.map((e) => (
                    <MobileCard key={e.id} aria-label={`Employee ${e.employee_number}`}>
                      <div className="mb-1 flex items-center justify-between gap-2">
                        <span className="text-sm font-medium text-foreground">{e.last_name}, {e.first_name}</span>
                        {e.is_directory_record ? (
                          <Badge variant="outline" className="border-sky-300 bg-sky-50 text-sky-700 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-300">
                            MIS Directory
                          </Badge>
                        ) : (
                          <Badge variant={e.employment_status === 'active' ? 'success' : e.employment_status === 'on_leave' ? 'warning' : 'secondary'}>
                            {employmentStatusLabel(e.employment_status)}
                          </Badge>
                        )}
                      </div>
                      <div className="mb-1 flex flex-wrap gap-1.5">
                        {!e.is_directory_record && <TeachingBadge isTeaching={e.is_teaching} />}
                        {e.archived && <Badge variant="secondary">Archived</Badge>}
                      </div>
                      <MobileCardField label="Number"><span className="tabular-nums text-xs">{e.employee_number}</span></MobileCardField>
                      <MobileCardField label="Department"><span className="text-xs">{e.department ?? '—'}</span></MobileCardField>
                      <MobileCardField label="Position"><span className="text-xs">{e.position ?? '—'}</span></MobileCardField>
                      {!e.is_directory_record && (e.records?.length ?? 0) > 1 && (
                        <div className="mt-2 space-y-1.5" role="group" aria-label={`MIS records of ${e.employee_number}`}>
                          <p className="text-xs font-medium text-muted-foreground">MIS records — position history</p>
                          {e.records?.map((r) => <EmployeeRecordHistory key={r.id} record={r} />)}
                        </div>
                      )}
                      <MobileCardActions>{employeeActions(e)}</MobileCardActions>
                    </MobileCard>
                  ))}
                </MobileCardList>
              </div>
            );
          })()}

          {!empSearching && (
            <nav className="flex items-center justify-between md:shrink-0" aria-label="pagination">
              <p className="text-xs text-muted-foreground">Page {empHistory.length} · {(employees.data?.data ?? []).length} employee{(employees.data?.data ?? []).length === 1 ? '' : 's'} shown</p>
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
        </TabsContent>
      </Tabs>

      {detailId !== null && (
        <Dialog open onOpenChange={(o) => !o && setDetailId(null)}>
          <StudentDetailDialog studentId={detailId} onClose={() => setDetailId(null)} />
        </Dialog>
      )}

      {manageId !== null && (
        <Dialog open onOpenChange={(o) => !o && setManageId(null)}>
          <ManageMedicalRecordDialog studentId={manageId} onClose={() => setManageId(null)} />
        </Dialog>
      )}

      {referStudent !== null && (
        <ReferStudentDialog student={referStudent} onClose={() => setReferStudent(null)} />
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
