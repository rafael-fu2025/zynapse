/**
 * AppointmentsPage — clinic scheduling grid (Phase 9, extended).
 *
 * Keyset-paginated appointment table (shadcn Table) with status
 * badges, lifecycle actions (Complete / Cancel), and a CRUD dialog
 * (Schedule / Edit / View).
 *
 * Panel revision (August 2026): today's `scheduled` appointments
 * auto-check-in on the first staff read of the queue / appointment
 * list, so the inline "Check in" button was removed. "Mark no-show"
 * moved off the appointment dropdown and onto the encounter action
 * menu inside the clinic queue tab, where it cascades the encounter
 * + queue + appointment atomically.
 *
 * Filters: All / Upcoming / Past tabs + a status dropdown for finer
 * filtering. The status filter pushes a `?status=` query param to
 * the backend; the tabs are client-side (derive from the loaded
 * rows' status + scheduled_at) so navigating Upcoming/Past does
 * not refetch the list.
 *
 * Names: the table shows the patient school_id (with a hover
 * tooltip carrying the cached student name when we have it) and
 * the provider's full name from the cached employees list. The
 * schedule/edit dialogs use a Select listing every employee and a
 * debounced student search for the patient picker.
 *
 * All times render in Asia/Manila via `fmtUtcToApp`.
 */
import { zodResolver } from '@hookform/resolvers/zod';
import {
  CalendarPlus,
  Check,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Eye,
  Loader2,
  Pencil,
  Search,
  Stethoscope,
  X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { QueryErrorRow } from '@/components/QueryErrorState';
import { MobileCardList, MobileCard, MobileCardField, MobileCardActions } from '@/components/MobileCardList';
import { PatientIdCell } from '@/components/PatientIdCell';
import { DatePicker } from '@/components/ui/date-picker';
import { TimePicker } from '@/components/ui/time-picker';
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
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PatientPicker } from '@/components/PatientPicker';
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
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import {
  useAppointment,
  useAppointments,
  useAppointmentSearch,
  useScheduleAppointment,
  useTransitionAppointment,
  useUpdateAppointment,
} from '@/hooks/useAppointments';
import { useEmployees } from '@/hooks/usePatients';
import {
  scheduleAppointmentSchema,
  updateAppointmentSchema,
  type Appointment,
  type AppointmentTransition,
  type ScheduleAppointmentInput,
} from '@/schemas/appointments';
import { appDateTimeToUtcSql, fmtUtcToApp, utcSqlToAppParts } from '@/utils/date';
import { statusLabel } from '@/utils/status';

const STATUS_VARIANT: Record<Appointment['status'], 'info' | 'success' | 'warning' | 'destructive'> = {
  scheduled: 'info',
  checked_in: 'warning',
  completed: 'success',
  cancelled: 'destructive',
  no_show: 'destructive',
};

type FilterTab = 'all' | 'upcoming' | 'past';
const STATUS_OPTIONS: ReadonlyArray<{ value: Appointment['status'] | 'all'; label: string }> = [
  { value: 'all',        label: 'All statuses' },
  { value: 'scheduled',  label: 'Scheduled' },
  { value: 'checked_in', label: 'Checked in' },
  { value: 'completed',  label: 'Completed' },
  { value: 'cancelled',  label: 'Cancelled' },
  { value: 'no_show',    label: 'No-show' },
];

/**
 * PatientPicker — see `@/components/PatientPicker`. The shared
 * component is reused by both the schedule dialog (this file) and the
 * new-encounter dialog in `ClinicPage.tsx`.
 */

/**
 * ProviderPicker — Select bound to the cached employees list. The
 * "value" is the `provider_user_id`. When the user picks a row, the
 * form receives the numeric id.
 */
function ProviderPicker({
  value,
  onChange,
}: {
  value: number | null;
  onChange: (next: number) => void;
}) {
  const employees = useEmployees(null, 100);
  return (
    <div className="space-y-1.5">
      <Label id="appt-provider-label">Provider</Label>
      <Select
        value={value === null ? '' : String(value)}
        onValueChange={(v) => onChange(Number(v))}
      >
        <SelectTrigger aria-labelledby="appt-provider-label">
          <SelectValue placeholder="Select a provider…" />
        </SelectTrigger>
        <SelectContent>
          {(employees.data?.data ?? []).map((e) => (
            <SelectItem key={e.id} value={String(e.id)}>
              {e.last_name}, {e.first_name} — {e.department ?? e.position ?? e.employee_number}
            </SelectItem>
          ))}
          {(employees.data?.data.length ?? 0) === 0 && (
            <p className="px-2 py-1 text-xs text-muted-foreground">No employees registered.</p>
          )}
        </SelectContent>
      </Select>
    </div>
  );
}

/**
 * ScheduleDialog — used for both create and edit. The `mode` prop
 * toggles the title, submit label, and which fields can be changed.
 * Provider and patient pickers are the same in both modes.
 */
function ScheduleDialog({
  mode,
  initial,
  onClose,
}: {
  mode: 'create' | 'edit';
  initial?: Appointment;
  onClose: () => void;
}) {
  const isEdit = mode === 'edit';
  const schedule = useScheduleAppointment();
  const update = useUpdateAppointment();

  // The two input shapes (create vs update) share the same field set
  // and differ only in required-vs-optional. Using ScheduleAppointmentInput
  // (the stricter one) as the form type keeps react-hook-form happy
  // and the mutation hooks accept both via structural typing.
  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<ScheduleAppointmentInput>({
    resolver: zodResolver(isEdit ? updateAppointmentSchema : scheduleAppointmentSchema),
    defaultValues: initial !== undefined
      ? {
          patient_school_id: initial.patient_school_id,
          provider_user_id: initial.provider_user_id,
          scheduled_at: initial.scheduled_at,
          reason: initial.reason ?? '',
        }
      : { patient_school_id: '', reason: '' },
  });

  const patientId = watch('patient_school_id') ?? '';
  const providerId = watch('provider_user_id');

  // The API contract for scheduled_at is a UTC `YYYY-MM-DD HH:mm:ss`
  // string, but the user thinks in Asia/Manila. Keep the form field as
  // the composed UTC string; drive it from local date + time pickers.
  const initialParts = initial !== undefined
    ? utcSqlToAppParts(initial.scheduled_at)
    : { date: '', time: '' };
  const [schedDate, setSchedDate] = useState(initialParts.date);
  const [schedTime, setSchedTime] = useState(initialParts.time);

  function updateSchedule(nextDate: string, nextTime: string) {
    setSchedDate(nextDate);
    setSchedTime(nextTime);
    setValue(
      'scheduled_at',
      nextDate !== '' && nextTime !== '' ? appDateTimeToUtcSql(nextDate, nextTime) : '',
      { shouldValidate: true },
    );
  }

  const onSubmit = handleSubmit((values) => {
    // Trim empty optional fields so the backend does not see explicit
    // nulls where the user simply did not edit a value.
    const base = {
      patient_school_id: values.patient_school_id,
      provider_user_id:  values.provider_user_id,
      scheduled_at:      values.scheduled_at,
      ...(values.reason !== undefined && values.reason !== '' ? { reason: values.reason } : {}),
    };
    const run = isEdit
      ? update.mutateAsync({ id: initial!.id, input: base })
      : schedule.mutateAsync(base);
    run.then(onClose).catch(() => { /* toast already fired */ });
  });

  const pending = schedule.isPending || update.isPending;

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>
          {isEdit ? `Edit appointment #${initial!.id}` : 'Schedule appointment'}
        </DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <PatientPicker
          value={patientId}
          invalid={errors.patient_school_id !== undefined}
          onChange={(v) => setValue('patient_school_id', v, { shouldValidate: true })}
        />
        {errors.patient_school_id !== undefined && (
          <p role="alert" className="text-xs text-destructive">
            {(errors.patient_school_id as { message?: string }).message ?? 'Invalid patient school id.'}
          </p>
        )}

        <ProviderPicker
          value={typeof providerId === 'number' ? providerId : null}
          onChange={(v) => setValue('provider_user_id', v, { shouldValidate: true })}
        />
        {errors.provider_user_id !== undefined && (
          <p role="alert" className="text-xs text-destructive">
            {(errors.provider_user_id as { message?: string }).message ?? 'Pick a provider.'}
          </p>
        )}

        <div className="space-y-1.5">
          <Label htmlFor="scheduled_at">Scheduled at (Asia/Manila)</Label>
          <div className="flex flex-wrap items-center gap-2">
            <DatePicker
              id="scheduled_at"
              value={schedDate}
              onChange={(v) => updateSchedule(v, schedTime)}
              className="w-44"
              aria-invalid={errors.scheduled_at !== undefined}
            />
            <TimePicker
              value={schedTime}
              onChange={(v) => updateSchedule(schedDate, v)}
              aria-invalid={errors.scheduled_at !== undefined}
            />
          </div>
          {schedDate !== '' && schedTime !== '' && (
            <p className="text-[11px] text-muted-foreground">
              Stored as {appDateTimeToUtcSql(schedDate, schedTime)} UTC.
            </p>
          )}
          {errors.scheduled_at !== undefined && (
            <p role="alert" className="text-xs text-destructive">
              {(errors.scheduled_at as { message?: string }).message ?? 'Pick a date and time.'}
            </p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="reason">Reason (optional)</Label>
          <Input id="reason" {...register('reason')} maxLength={255} />
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={pending}>
            {pending && <Loader2 className="animate-spin" />}
            {isEdit ? 'Save changes' : 'Schedule'}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

/**
 * AppointmentDetailDialog — read-only view. Uses the single-row hook
 * (lazy-loaded) so opening it does not refetch the list.
 */
function AppointmentDetailDialog({ appointmentId, onClose }: { appointmentId: number; onClose: () => void }) {
  const detail = useAppointment(appointmentId);
  const employees = useEmployees(null, 100);
  const a = detail.data;
  const provider = useMemo(
    () => (employees.data?.data ?? []).find((e) => e.id === a?.provider_user_id) ?? null,
    [employees.data, a?.provider_user_id],
  );

  return (
    <DialogContent className="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>
          {a !== undefined ? `Appointment #${a.id}` : 'Appointment'}
        </DialogTitle>
      </DialogHeader>
      {detail.isLoading && <Loader2 className="mx-auto size-5 animate-spin text-muted-foreground" />}
      {a !== undefined && (
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          <div className="col-span-2">
            <dt className="text-xs text-muted-foreground">Status</dt>
            <dd>
              <Badge variant={STATUS_VARIANT[a.status]}>{a.status}</Badge>
            </dd>
          </div>
          <div className="col-span-2">
            <dt className="text-xs text-muted-foreground">Patient</dt>
            <dd>
              {a.patient_name !== undefined && a.patient_name !== null ? (
                <span>
                  {a.patient_name}
                  {a.patient_kind !== undefined && a.patient_kind !== null ? (
                    <Badge variant="outline" className="ml-2 align-middle text-[10px]">
                      {a.patient_kind}
                    </Badge>
                  ) : null}
                  <span className="block font-mono text-[10px] text-muted-foreground">
                    {a.patient_school_id}
                  </span>
                </span>
              ) : (
                <span className="font-mono text-xs">{a.patient_school_id}</span>
              )}
            </dd>
          </div>
          <div className="col-span-2">
            <dt className="text-xs text-muted-foreground">Provider</dt>
            <dd>
              {a.provider_name !== undefined && a.provider_name !== null
                ? a.provider_name
                : provider !== null
                  ? `${provider.last_name}, ${provider.first_name}${provider.department !== null ? ` — ${provider.department}` : ''}`
                  : `User #${a.provider_user_id}`}
            </dd>
          </div>
          <div className="col-span-2">
            <dt className="text-xs text-muted-foreground">Scheduled at</dt>
            <dd className="text-xs">{fmtUtcToApp(a.scheduled_at)}</dd>
          </div>
          <div className="col-span-2">
            <dt className="text-xs text-muted-foreground">Reason</dt>
            <dd>{a.reason ?? '—'}</dd>
          </div>
        </dl>
      )}
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
      </DialogFooter>
    </DialogContent>
  );
}

/**
 * Build a `name → id` map from the cached employees for fast lookups
 * at row-render time. We avoid the per-row hook call (which would
 * fire one query per appointment) and just match against the list.
 */
function useProviderNameLookup(): (id: number) => string {
  const employees = useEmployees(null, 100);
  return (id) => {
    const e = (employees.data?.data ?? []).find((x) => x.id === id);
    return e !== undefined ? `${e.last_name}, ${e.first_name}` : `#${id}`;
  };
}

interface AppointmentActionProps {
  a: Appointment;
  onView: (a: Appointment) => void;
  onEdit: (a: Appointment) => void;
  transition: (vars: { id: number; status: AppointmentTransition }) => void;
  onConfirm: (action: ConfirmAction) => void;
  transitionPending: boolean;
  canEdit: boolean;
}

/**
 * AppointmentActions — the lifecycle button cluster, shared by the
 * desktop table row and the mobile card so both surfaces stay in sync.
 */
function AppointmentActions({ a, onView, onEdit, transition, onConfirm, transitionPending, canEdit }: AppointmentActionProps) {
  return (
    <>
      {/* Panel revision (August 2026): today's `scheduled` appointments
          are auto-checked-in on the first staff read of the queue /
          appointment list, so the inline "Check in" button is gone.
          For `checked_in`, the only meaningful single-click advance
          is to "Complete" — that's where staff land after they wrap
          up the encounter. */}
      {a.status === 'checked_in' && (
        <Button className="min-h-11" size="sm" variant="secondary" disabled={transitionPending} onClick={() => transition({ id: a.id, status: 'completed' })}>
          <Check /> Complete
        </Button>
      )}
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button className="min-h-11" size="sm" variant="outline" aria-label={`Actions for appointment #${a.id}`}>
            Actions <ChevronDown className="size-3.5" aria-hidden />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-52">
          <DropdownMenuItem className="min-h-11" onSelect={() => onView(a)}>
            <Eye /> View appointment
          </DropdownMenuItem>
          {canEdit && (
            <DropdownMenuItem className="min-h-11" onSelect={() => onEdit(a)}>
              <Pencil /> Edit appointment
            </DropdownMenuItem>
          )}
          {/* Panel revision (August 2026): "Mark no-show" moved off
              the appointment surface and onto the encounter row in
              the queue tab. From here it's only ever a cancel. */}
          {(a.status === 'scheduled' || a.status === 'checked_in') && (
            <DropdownMenuItem
              className="min-h-11 text-destructive focus:text-destructive"
              disabled={transitionPending}
              onSelect={() => onConfirm({
                title: `Cancel appointment #${a.id}?`,
                description: 'The appointment will be cancelled. This cannot be undone.',
                confirmLabel: 'Cancel appointment',
                run: () => transition({ id: a.id, status: 'cancelled' }),
              })}
            >
              <X /> Cancel appointment
            </DropdownMenuItem>
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    </>
  );
}

/** Mobile card for one appointment — same data + actions as the row. */
function AppointmentCard(props: AppointmentActionProps & { providerName: string }) {
  const { a, providerName } = props;
  // Local employee lookup misses clinic staff — prefer the API name.
  const displayProvider = providerName.startsWith('#')
    ? (a.provider_name ?? providerName)
    : providerName;

  return (
    <MobileCard aria-label={`Appointment ${a.id}`}>
      <div className="mb-1 flex items-center justify-between gap-2">
        <span className="font-mono text-xs text-muted-foreground">#{a.id}</span>
        <Badge variant={STATUS_VARIANT[a.status]}>{statusLabel(a.status)}</Badge>
      </div>
      <p className="text-sm font-medium text-foreground">
        {a.patient_name !== undefined && a.patient_name !== null ? a.patient_name : a.patient_school_id}
      </p>
      {a.patient_name !== undefined && a.patient_name !== null && (
        <p className="font-mono text-[10px] text-muted-foreground">
          <PatientIdCell id={a.patient_school_id} name={a.patient_name} />
          {a.patient_kind !== undefined && a.patient_kind !== null ? ` · ${a.patient_kind}` : ''}
        </p>
      )}
      <MobileCardField label="Provider">
        <span className="flex items-center gap-1.5">
          <span>{displayProvider}</span>
          <PatientIdCell
            id={`#${a.provider_user_id}`}
            name={displayProvider.startsWith('#') ? null : displayProvider}
          />
        </span>
      </MobileCardField>
      <MobileCardField label="When"><span className="text-xs text-muted-foreground">{fmtUtcToApp(a.scheduled_at)}</span></MobileCardField>
      <MobileCardField label="Reason">
        <span>
          {a.reason ?? '—'}
          {a.encounter_id !== undefined && a.encounter_id !== null && (
            <Link to={`/clinic?encounter=${a.encounter_id}`} className="ml-2 inline-flex items-center gap-0.5 text-primary underline-offset-2 hover:underline">
              <Stethoscope className="size-3" /> Visit #{a.encounter_id}
            </Link>
          )}
        </span>
      </MobileCardField>
      <MobileCardActions>
        <AppointmentActions {...props} />
      </MobileCardActions>
    </MobileCard>
  );
}

function AppointmentRow({
  a,
  providerName,
  onView,
  onEdit,
  transition,
  onConfirm,
  transitionPending,
  canEdit,
}: {
  a: Appointment;
  providerName: string;
  onView: (a: Appointment) => void;
  onEdit: (a: Appointment) => void;
  transition: (vars: { id: number; status: AppointmentTransition }) => void;
  onConfirm: (action: ConfirmAction) => void;
  transitionPending: boolean;
  canEdit: boolean;
}) {
  // The local employee lookup may miss clinic staff (they aren't in
  // the employee roster) — prefer the API-provided provider name then.
  const displayProvider = providerName.startsWith('#')
    ? (a.provider_name ?? providerName)
    : providerName;

  return (
    <TableRow>
      <TableCell className="px-3 font-mono text-xs">#{a.id}</TableCell>
      <TableCell className="px-3">
        {a.patient_name !== undefined && a.patient_name !== null ? (
          <div className="leading-tight">
            <p className="text-sm font-medium">{a.patient_name}</p>
            <p className="font-mono text-[10px] text-muted-foreground">
              <PatientIdCell id={a.patient_school_id} name={a.patient_name} />
              {a.patient_kind !== undefined && a.patient_kind !== null ? ` · ${a.patient_kind}` : ''}
            </p>
          </div>
        ) : (
          <PatientIdCell id={a.patient_school_id} name={null} />
        )}
      </TableCell>
      <TableCell className="px-3 text-xs">
        {displayProvider}
        <span className="ml-1.5">
          <PatientIdCell
            id={`#${a.provider_user_id}`}
            name={displayProvider.startsWith('#') ? null : displayProvider}
          />
        </span>
      </TableCell>

      <TableCell className="px-3 text-xs text-muted-foreground">{fmtUtcToApp(a.scheduled_at)}</TableCell>
      <TableCell className="px-3"><Badge variant={STATUS_VARIANT[a.status]}>{statusLabel(a.status)}</Badge></TableCell>
      <TableCell className="px-3 text-xs">
        {a.reason ?? '—'}
        {a.encounter_id !== undefined && a.encounter_id !== null && (
          <Link to={`/clinic?encounter=${a.encounter_id}`} className="ml-2 inline-flex items-center gap-0.5 text-primary underline-offset-2 hover:underline">
            <Stethoscope className="size-3" /> Visit #{a.encounter_id}
          </Link>
        )}
      </TableCell>
      <TableCell className="px-3 text-right">
        <div className="flex justify-end gap-1">
          <AppointmentActions
            a={a}
            onView={onView}
            onEdit={onEdit}
            transition={transition}
            onConfirm={onConfirm}
            transitionPending={transitionPending}
            canEdit={canEdit}
          />
        </div>
      </TableCell>
    </TableRow>
  );
}

export default function AppointmentsPage() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [tab, setTab] = useState<FilterTab>('all');
  const [statusFilter, setStatusFilter] = useState<Appointment['status'] | 'all'>('all');
  const [openSchedule, setOpenSchedule] = useState(false);
  const [editing, setEditing] = useState<Appointment | null>(null);
  const [viewing, setViewing] = useState<Appointment | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  // Live search (2026-08-05) — mirrors the Patients page: `search` is
  // the raw input, debounced 300ms, and only terms >= 2 chars trigger a
  // dedicated search query which REPLACES the paged list while active.
  const [search, setSearch] = useState('');
  const debouncedSearch = useDebouncedValue(search, 300);
  const searching = debouncedSearch.trim().length >= 2;

  // Status filter pushes a `?status=` query param to the backend;
  // the tabs are client-side over the loaded rows. This keeps the
  // list stable as the user clicks between Upcoming / Past without
  // a refetch, while a status change does refetch the canonical list.
  const list = useAppointments(cursor, 25, statusFilter === 'all' ? null : statusFilter);
  const searchQuery = useAppointmentSearch(debouncedSearch, statusFilter);
  const transition = useTransitionAppointment();
  const providerName = useProviderNameLookup();

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

  // Derive tab buckets client-side. Tabs do NOT refetch.
  const now = Date.now();
  const rows = useMemo<Appointment[]>(
    () => (searching ? (searchQuery.data ?? []) : (list.data?.data ?? [])),
    [searching, searchQuery.data, list.data],
  );
  // While a search is active the results come from the dedicated search
  // query; otherwise from the paged list — same split as PatientsPage.
  const loading = searching ? searchQuery.isLoading : list.isLoading;
  const errored = searching ? searchQuery.isError : list.isError;
  const retry = () => void (searching ? searchQuery.refetch() : list.refetch());
  const counts = useMemo(() => {
    let upcoming = 0;
    let past = 0;
    for (const a of rows) {
      const t = Date.parse(a.scheduled_at);
      const isOpen = a.status === 'scheduled' || a.status === 'checked_in';
      if (isOpen && t >= now) upcoming += 1;
      else past += 1;
    }
    return { all: rows.length, upcoming, past };
  }, [rows, now]);
  const visibleRows = useMemo(() => {
    if (tab === 'all') return rows;
    if (tab === 'upcoming') {
      return rows.filter((a) => (a.status === 'scheduled' || a.status === 'checked_in') && Date.parse(a.scheduled_at) >= now);
    }
    return rows.filter((a) => !((a.status === 'scheduled' || a.status === 'checked_in') && Date.parse(a.scheduled_at) >= now));
  }, [rows, tab, now]);

  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Appointments</h1>
          <p className="text-sm text-muted-foreground">Times shown in Asia/Manila; stored in UTC.</p>
        </div>
      </header>

      {/* Live-search toolbar — same layout as the Patients page: a
          bordered card with the magnifier icon INSIDE the input on the
          left and the actions on the right. Typing >= 2 chars searches
          as you type (debounced); clearing restores the paged list. */}
      <section className="flex flex-wrap items-end justify-between gap-3 rounded-xl border bg-card p-3">
        <div className="w-full space-y-1 sm:w-72">
          <Label htmlFor="appt-search" className="text-xs">Search</Label>
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              id="appt-search"
              aria-label="Search appointments"
              placeholder="Search number, name, ID, provider, date…"
              className="pl-9"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>
        {/* items-end keeps the unlabeled Schedule button bottom-aligned
            with the Status select field (whose label makes it taller) —
            items-center would float the button against the block's
            middle instead of lining it up with the field. */}
        <div className="flex flex-wrap items-end gap-2">
          <div className="space-y-1">
            <Label id="appt-status-label" className="text-xs">Status</Label>
            <Select
              value={statusFilter}
              onValueChange={(v) => { setStatusFilter(v as Appointment['status'] | 'all'); setCursor(null); setHistory([null]); }}
            >
              <SelectTrigger aria-labelledby="appt-status-label" className="w-44">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {STATUS_OPTIONS.map((o) => (
                  <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Dialog open={openSchedule} onOpenChange={setOpenSchedule}>
            <Button onClick={() => setOpenSchedule(true)}>
              <CalendarPlus /> Schedule
            </Button>
            {openSchedule && <ScheduleDialog mode="create" onClose={() => setOpenSchedule(false)} />}
          </Dialog>
        </div>
      </section>

      <Tabs value={tab} onValueChange={(v) => setTab(v as FilterTab)}>
        <TabsList>
          <TabsTrigger value="all">All ({counts.all})</TabsTrigger>
          <TabsTrigger value="upcoming">Upcoming ({counts.upcoming})</TabsTrigger>
          <TabsTrigger value="past">Past ({counts.past})</TabsTrigger>
        </TabsList>

        <TabsContent value={tab} className="space-y-3">
          <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
            <Table>
              <TableHeader className="bg-muted/50">
                <TableRow>
                  <TableHead className="px-3">#</TableHead>
                  <TableHead className="px-3">Patient</TableHead>
                  <TableHead className="px-3">Provider</TableHead>
                  <TableHead className="px-3">When</TableHead>
                  <TableHead className="px-3">Status</TableHead>
                  <TableHead className="px-3">Reason</TableHead>
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
                {!loading && !errored && visibleRows.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={7} className="px-3 py-6 text-center text-muted-foreground">
                      {searching ? 'No matches.' : 'No appointments in this view.'}
                    </TableCell>
                  </TableRow>
                )}
                {errored && !loading && (
                  <QueryErrorRow colSpan={7} message="Failed to load appointments." onRetry={retry} pending={searching ? searchQuery.isFetching : list.isFetching} />
                )}
                {visibleRows.map((a) => (
                  <AppointmentRow
                    key={a.id}
                    a={a}
                    providerName={providerName(a.provider_user_id)}
                    onView={setViewing}
                    onEdit={setEditing}
                    transition={(vars) => transition.mutate(vars)}
                    onConfirm={setConfirm}
                    transitionPending={transition.isPending}
                    canEdit={a.status === 'scheduled'}
                  />
                ))}
              </TableBody>
            </Table>
          </section>

          {/* Mobile: stacked cards from the same visible rows. */}
          {loading && (
            <p className="py-6 text-center text-sm text-muted-foreground md:hidden" role="status">
              <Loader2 className="mx-auto size-4 animate-spin" />
            </p>
          )}
          {errored && !loading && (
            <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-center text-sm text-destructive md:hidden">
              <p>Failed to load appointments.</p>
              <Button variant="outline" size="sm" className="mt-2" onClick={retry} disabled={searching ? searchQuery.isFetching : list.isFetching}>Retry</Button>
            </div>
          )}
          {!loading && !errored && visibleRows.length === 0 && (
            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground md:hidden">{searching ? 'No matches.' : 'No appointments in this view.'}</p>
          )}
          <MobileCardList>
            {visibleRows.map((a) => (
              <AppointmentCard
                key={a.id}
                a={a}
                providerName={providerName(a.provider_user_id)}
                onView={setViewing}
                onEdit={setEditing}
                transition={(vars) => transition.mutate(vars)}
                onConfirm={setConfirm}
                transitionPending={transition.isPending}
                canEdit={a.status === 'scheduled'}
              />
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
      </Tabs>

      {editing !== null && (
        <Dialog open onOpenChange={(o) => !o && setEditing(null)}>
          <ScheduleDialog mode="edit" initial={editing} onClose={() => setEditing(null)} />
        </Dialog>
      )}

      {viewing !== null && (
        <Dialog open onOpenChange={(o) => !o && setViewing(null)}>
          <AppointmentDetailDialog appointmentId={viewing.id} onClose={() => setViewing(null)} />
        </Dialog>
      )}

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        pending={transition.isPending}
        onConfirm={() => {
          confirm?.run();
          setConfirm(null);
        }}
        onCancel={() => setConfirm(null)}
      />
    </main>
  );
}
