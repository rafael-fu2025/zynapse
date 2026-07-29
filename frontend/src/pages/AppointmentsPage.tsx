/**
 * AppointmentsPage — clinic scheduling grid (Phase 9, extended).
 *
 * Keyset-paginated appointment table (shadcn Table) with status
 * badges, lifecycle actions (Check in / Complete / Cancel / No-show),
 * and a CRUD dialog (Schedule / Edit / View).
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
  ChevronLeft,
  ChevronRight,
  Eye,
  Loader2,
  LogIn,
  Pencil,
  Search,
  X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { QueryErrorRow } from '@/components/QueryErrorState';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { DatePicker } from '@/components/ui/date-picker';
import { TimePicker } from '@/components/ui/time-picker';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
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
  useAppointment,
  useAppointments,
  useScheduleAppointment,
  useTransitionAppointment,
  useUpdateAppointment,
} from '@/hooks/useAppointments';
import { useEmployees, useStudentSearch } from '@/hooks/usePatients';
import {
  scheduleAppointmentSchema,
  updateAppointmentSchema,
  type Appointment,
  type AppointmentTransition,
  type ScheduleAppointmentInput,
} from '@/schemas/appointments';
import { appDateTimeToUtcSql, fmtUtcToApp, utcSqlToAppParts } from '@/utils/date';

const STATUS_VARIANT: Record<Appointment['status'], 'info' | 'success' | 'warning' | 'destructive'> = {
  Scheduled: 'info',
  CheckedIn: 'warning',
  Completed: 'success',
  Cancelled: 'destructive',
  NoShow: 'destructive',
};

type FilterTab = 'all' | 'upcoming' | 'past';
const STATUS_OPTIONS: ReadonlyArray<{ value: Appointment['status'] | 'all'; label: string }> = [
  { value: 'all',       label: 'All statuses' },
  { value: 'Scheduled', label: 'Scheduled' },
  { value: 'CheckedIn', label: 'Checked in' },
  { value: 'Completed', label: 'Completed' },
  { value: 'Cancelled', label: 'Cancelled' },
  { value: 'NoShow',    label: 'No-show' },
];

/**
 * PatientPicker — debounced student search. The user types a school
 * id or a name (>= 2 chars) and the dropdown lists matches from the
 * backend's `/clinic/students/search`. Picking a row fills the
 * patient_school_id field on the parent form.
 */
function PatientPicker({
  value,
  onChange,
}: {
  value: string;
  onChange: (next: string) => void;
}) {
  const [q, setQ] = useState(value);
  const debouncedQ = useDebouncedValue(q, 300);
  const search = useStudentSearch(debouncedQ);
  const showList = q.trim().length >= 2 && q.trim() !== value;

  return (
    <div className="space-y-1.5">
      <Label htmlFor="patient_school_id">Patient school ID</Label>
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          id="patient_school_id"
          className="pl-9"
          value={q}
          onChange={(e) => { setQ(e.target.value); onChange(e.target.value); }}
          placeholder="Type school id or name (min 2 chars)…"
        />
      </div>
      {showList && (
        <div className="max-h-40 space-y-1 overflow-auto rounded-md border bg-card p-1.5 text-xs">
          {search.isLoading && <Loader2 className="mx-auto size-3.5 animate-spin text-muted-foreground" />}
          {!search.isLoading && (search.data?.length ?? 0) === 0 && (
            <p className="px-2 py-1 text-muted-foreground">No matches.</p>
          )}
          {(search.data ?? []).slice(0, 8).map((s) => (
            <button
              type="button"
              key={s.id}
              className="flex w-full items-center justify-between rounded px-2 py-1 text-left hover:bg-muted/50"
              onClick={() => { onChange(s.student_number); setQ(s.student_number); }}
            >
              <span className="font-mono">{s.student_number}</span>
              <span className="text-muted-foreground">{s.last_name}, {s.first_name}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

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
  const employees = useEmployees(null, 200);
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
  const employees = useEmployees(null, 200);
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
            <dd className="font-mono text-xs">{fmtUtcToApp(a.scheduled_at)}</dd>
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
  const employees = useEmployees(null, 200);
  return (id) => {
    const e = (employees.data?.data ?? []).find((x) => x.id === id);
    return e !== undefined ? `${e.last_name}, ${e.first_name}` : `#${id}`;
  };
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
  return (
    <TableRow>
      <TableCell className="px-3 font-mono text-xs">#{a.id}</TableCell>
      <TableCell className="px-3">
        {a.patient_name !== undefined && a.patient_name !== null ? (
          <div className="leading-tight">
            <p className="text-sm font-medium">{a.patient_name}</p>
            <p className="font-mono text-[10px] text-muted-foreground">
              {a.patient_school_id}
              {a.patient_kind !== undefined && a.patient_kind !== null ? ` · ${a.patient_kind}` : ''}
            </p>
          </div>
        ) : (
          <span className="font-mono text-xs">{a.patient_school_id}</span>
        )}
      </TableCell>
      <TableCell className="px-3 text-xs">{providerName}</TableCell>
      <TableCell className="px-3 font-mono text-xs text-muted-foreground">{fmtUtcToApp(a.scheduled_at)}</TableCell>
      <TableCell className="px-3"><Badge variant={STATUS_VARIANT[a.status]}>{a.status}</Badge></TableCell>
      <TableCell className="px-3 text-xs">{a.reason ?? '—'}</TableCell>
      <TableCell className="px-3 text-right">
        <div className="flex justify-end gap-1">
          <Button size="sm" variant="outline" onClick={() => onView(a)} aria-label={`View appointment #${a.id}`}>
            <Eye /> View
          </Button>
          {canEdit && (
            <Button size="sm" variant="outline" onClick={() => onEdit(a)} aria-label={`Edit appointment #${a.id}`}>
              <Pencil /> Edit
            </Button>
          )}
          {a.status === 'Scheduled' && (
            <>
              <Button size="sm" variant="secondary" disabled={transitionPending} onClick={() => transition({ id: a.id, status: 'CheckedIn' })}>
                <LogIn /> Check in
              </Button>
              <Button size="sm" variant="outline" disabled={transitionPending} onClick={() => onConfirm({
                title: `Mark appointment #${a.id} as no-show?`,
                description: 'This records a no-show, which counts toward the patient\u2019s three-strike counter.',
                confirmLabel: 'Mark no-show',
                run: () => transition({ id: a.id, status: 'NoShow' }),
              })}>
                No-show
              </Button>
            </>
          )}
          {a.status === 'CheckedIn' && (
            <Button size="sm" variant="secondary" disabled={transitionPending} onClick={() => transition({ id: a.id, status: 'Completed' })}>
              <Check /> Complete
            </Button>
          )}
          {(a.status === 'Scheduled' || a.status === 'CheckedIn') && (
            <Button size="sm" variant="outline" disabled={transitionPending} onClick={() => onConfirm({
              title: `Cancel appointment #${a.id}?`,
              description: 'The appointment will be cancelled. This cannot be undone.',
              confirmLabel: 'Cancel appointment',
              run: () => transition({ id: a.id, status: 'Cancelled' }),
            })}>
              <X /> Cancel
            </Button>
          )}
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

  // Status filter pushes a `?status=` query param to the backend;
  // the tabs are client-side over the loaded rows. This keeps the
  // list stable as the user clicks between Upcoming / Past without
  // a refetch, while a status change does refetch the canonical list.
  const list = useAppointments(cursor, 25, statusFilter === 'all' ? null : statusFilter);
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
    () => list.data?.data ?? [],
    [list.data],
  );
  const counts = useMemo(() => {
    let upcoming = 0;
    let past = 0;
    for (const a of rows) {
      const t = Date.parse(a.scheduled_at);
      const isOpen = a.status === 'Scheduled' || a.status === 'CheckedIn';
      if (isOpen && t >= now) upcoming += 1;
      else past += 1;
    }
    return { all: rows.length, upcoming, past };
  }, [rows, now]);
  const visibleRows = useMemo(() => {
    if (tab === 'all') return rows;
    if (tab === 'upcoming') {
      return rows.filter((a) => (a.status === 'Scheduled' || a.status === 'CheckedIn') && Date.parse(a.scheduled_at) >= now);
    }
    return rows.filter((a) => !((a.status === 'Scheduled' || a.status === 'CheckedIn') && Date.parse(a.scheduled_at) >= now));
  }, [rows, tab, now]);

  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Appointments</h1>
          <p className="text-sm text-muted-foreground">Times shown in Asia/Manila; stored in UTC.</p>
        </div>
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
      </header>

      <Tabs value={tab} onValueChange={(v) => setTab(v as FilterTab)}>
        <TabsList>
          <TabsTrigger value="all">All ({counts.all})</TabsTrigger>
          <TabsTrigger value="upcoming">Upcoming ({counts.upcoming})</TabsTrigger>
          <TabsTrigger value="past">Past ({counts.past})</TabsTrigger>
        </TabsList>

        <TabsContent value={tab} className="space-y-3">
          <section className="overflow-hidden rounded-xl border bg-card">
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
                {list.isLoading && (
                  <TableRow>
                    <TableCell colSpan={7} className="px-3 py-6 text-center text-muted-foreground">
                      <Loader2 className="mx-auto size-4 animate-spin" />
                    </TableCell>
                  </TableRow>
                )}
                {!list.isLoading && !list.isError && visibleRows.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={7} className="px-3 py-6 text-center text-muted-foreground">
                      No appointments in this view.
                    </TableCell>
                  </TableRow>
                )}
                {list.isError && !list.isLoading && (
                  <QueryErrorRow colSpan={7} message="Failed to load appointments." onRetry={() => void list.refetch()} pending={list.isFetching} />
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
                    canEdit={a.status === 'Scheduled'}
                  />
                ))}
              </TableBody>
            </Table>
          </section>

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
