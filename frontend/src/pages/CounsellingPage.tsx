/**
 * CounsellingPage — sessions + encrypted notes + scheduling.
 *
 * Notes are AES-256-GCM encrypted on the backend. The page surface lets
 * the counsellor write plaintext (encrypted server-side) and review
 * decrypted history for the selected session. The Scheduling tab manages
 * weekly availability windows and the appointment lifecycle
 * (scheduled → confirmed → completed / cancelled / no_show) with the
 * three-strike no-show counter enforced server-side.
 */
import { zodResolver } from '@hookform/resolvers/zod';
import {
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  CalendarDays,
  CalendarPlus,
  Check,
  CheckCheck,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  LineChart,
  List,
  Loader2,
  Lock,
  Plus,
  ShieldCheck,
  Trash2,
  UserX,
  X,
} from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { QueryErrorRow } from '@/components/QueryErrorState';
import { useTabParam } from '@/hooks/useTabParam';
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
import { Textarea } from '@/components/ui/textarea';
import {
  useCloseSession,
  useNotes,
  useOpenSession,
  useSessions,
  useWriteNotes,
} from '@/hooks/useCounselling';
import {
  useAddSlot,
  useAppointments,
  useAppointmentTransition,
  useAvailability,
  useBookAppointment,
  useRecomputeAnalytics,
  useRemoveSlot,
  useSchedulingAnalytics,
} from '@/hooks/useSchedule';
import {
  openSessionSchema,
  writeNotesSchema,
  type OpenSessionInput,
  type Session,
  type WriteNotesInput,
} from '@/schemas/counselling';
import {
  addSlotSchema,
  APPOINTMENT_STATUSES,
  APPOINTMENT_TYPES,
  bookAppointmentSchema,
  DAY_NAMES,
  type AddSlotInput,
  type Appointment,
  type Availability,
  type AppointmentStatus,
  type BookAppointmentInput,
  type SlotAnalytics,
} from '@/schemas/schedule';
import { fmtUtcToApp } from '@/utils/date';

const STATUS_VARIANT: Record<AppointmentStatus, 'secondary' | 'info' | 'success' | 'outline' | 'destructive'> = {
  scheduled: 'info',
  confirmed: 'secondary',
  completed: 'success',
  cancelled: 'outline',
  no_show: 'destructive',
};

const TYPE_LABEL: Record<string, string> = {
  initial: 'Initial',
  follow_up: 'Follow-up',
  crisis: 'Crisis',
  referral_based: 'Referral-based',
};

// ------------------------------------------------------------ sessions

function OpenSessionDialog({ onClose }: { onClose: () => void }) {
  const open = useOpenSession();
  const { register, handleSubmit, formState: { errors }, reset } =
    useForm<OpenSessionInput>({ resolver: zodResolver(openSessionSchema) });

  const onSubmit = handleSubmit((values) => {
    open.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Open session</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="patient_school_id">Patient school ID</Label>
          <Input id="patient_school_id" aria-invalid={errors.patient_school_id !== undefined} {...register('patient_school_id')} />
          {errors.patient_school_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.patient_school_id.message}</p>
          )}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={open.isPending}>
            {open.isPending && <Loader2 className="animate-spin" />} Open
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function WriteNotesDialog({ session, onClose }: { session: Session; onClose: () => void }) {
  const write = useWriteNotes();
  const { register, handleSubmit, formState: { errors }, reset } =
    useForm<WriteNotesInput>({ resolver: zodResolver(writeNotesSchema) });

  const onSubmit = handleSubmit((values) => {
    write.mutate(
      { sessionId: session.id, input: values },
      {
        onSuccess: () => {
          reset();
          onClose();
        },
      },
    );
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <Lock className="size-4" /> Encrypted notes — session #{session.id}
        </DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="plaintext">
            Notes (will be encrypted with AES-256-GCM server-side)
          </Label>
          <Textarea
            id="plaintext"
            rows={8}
            className="min-h-40"
            aria-invalid={errors.plaintext !== undefined}
            {...register('plaintext')}
          />
          {errors.plaintext !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.plaintext.message}</p>
          )}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={write.isPending}>
            {write.isPending && <Loader2 className="animate-spin" />}
            <ShieldCheck /> Encrypt & save
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function SessionsTab() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [openOpen, setOpenOpen] = useState(false);
  // Selection (which session's notes are shown) is independent from
  // the write dialog: clicking a row selects it, and the explicit
  // "Write" button opens the dialog. Closing the dialog keeps the
  // selection so the decrypted history stays visible.
  const [selected, setSelected] = useState<Session | null>(null);
  const [writeOpen, setWriteOpen] = useState(false);
  const [closingId, setClosingId] = useState<number | null>(null);
  const sessions = useSessions(cursor, 25);
  const notes = useNotes(selected?.id ?? 0);
  const close = useCloseSession();

  function nextPage() {
    if (sessions.data?.next !== null && sessions.data?.next !== undefined) {
      const n = sessions.data.next;
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

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Dialog open={openOpen} onOpenChange={setOpenOpen}>
          <Button onClick={() => setOpenOpen(true)}><Plus /> Open session</Button>
          {openOpen && <OpenSessionDialog onClose={() => setOpenOpen(false)} />}
        </Dialog>
      </div>

      <section className="grid gap-4 lg:grid-cols-2">
        <article className="overflow-hidden rounded-xl border bg-card">
          <header className="border-b px-3 py-2 text-sm font-semibold text-foreground">
            Sessions
          </header>
          <Table>
            <TableHeader className="bg-muted/50">
              <TableRow>
                <TableHead className="px-3">#</TableHead>
                <TableHead className="px-3">Patient</TableHead>
                <TableHead className="px-3">Started</TableHead>
                <TableHead className="px-3">Ended</TableHead>
                <TableHead className="px-3 text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {sessions.isLoading && (
                <TableRow>
                  <TableCell colSpan={5} className="px-3 py-6 text-center text-muted-foreground">
                    <Loader2 className="mx-auto size-4 animate-spin" />
                  </TableCell>
                </TableRow>
              )}
              {!sessions.isLoading && (sessions.data?.data.length ?? 0) === 0 && (
                <TableRow>
                  <TableCell colSpan={5} className="px-3 py-6 text-center text-muted-foreground">
                    No sessions.
                  </TableCell>
                </TableRow>
              )}
              {sessions.isError && !sessions.isLoading && (
                <QueryErrorRow colSpan={5} message="Failed to load sessions." onRetry={() => void sessions.refetch()} pending={sessions.isFetching} />
              )}
              {sessions.data?.data.map((s) => (
                <TableRow
                  key={s.id}
                  className={`cursor-pointer ${selected?.id === s.id ? 'bg-accent/40' : ''}`}
                  onClick={() => setSelected(s)}
                >
                  <TableCell className="px-3 font-mono text-xs">{s.id}</TableCell>
                  <TableCell className="px-3 font-mono text-xs">{s.patient_school_id}</TableCell>
                  <TableCell className="px-3 text-xs text-muted-foreground">{fmtUtcToApp(s.started_at)}</TableCell>
                  <TableCell className="px-3 text-xs text-muted-foreground">
                    {s.ended_at === null ? <Badge variant="info">Open</Badge> : fmtUtcToApp(s.ended_at)}
                  </TableCell>
                  <TableCell className="px-3 text-right">
                    <Button
                      size="sm"
                      variant="outline"
                      aria-label={`Close session #${s.id}`}
                      disabled={s.ended_at !== null || close.isPending}
                      onClick={(ev) => { ev.stopPropagation(); setClosingId(s.id); }}
                    >
                      Close
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <nav className="flex items-center justify-between border-t px-3 py-2" aria-label="pagination">
            <p className="text-xs text-muted-foreground">Page {history.length}</p>
            <div className="flex gap-2">
              <Button size="sm" variant="outline" onClick={prevPage} disabled={history.length < 2}>
                <ChevronLeft /> Prev
              </Button>
              <Button
                size="sm"
                variant="outline"
                onClick={nextPage}
                disabled={sessions.data?.next === null || sessions.data?.next === undefined}
              >
                Next <ChevronRight />
              </Button>
            </div>
          </nav>
        </article>

        <article className="overflow-hidden rounded-xl border bg-card">
          <header className="flex items-center justify-between border-b px-3 py-2">
            <p className="text-sm font-semibold text-foreground">
              Notes {selected !== null ? `— session #${selected.id}` : ''}
            </p>
            {selected !== null && (
              <Dialog open={writeOpen} onOpenChange={setWriteOpen}>
                <Button size="sm" onClick={() => setWriteOpen(true)}>
                  <Plus /> Write
                </Button>
                {writeOpen && <WriteNotesDialog session={selected} onClose={() => setWriteOpen(false)} />}
              </Dialog>
            )}
          </header>
          <div className="max-h-[480px] space-y-3 overflow-auto p-3">
            {selected === null && (
              <p className="text-sm text-muted-foreground">Select a session to view its encrypted notes.</p>
            )}
            {selected !== null && notes.isLoading && (
              <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />
            )}
            {selected !== null && !notes.isLoading && (notes.data?.length ?? 0) === 0 && (
              <p className="text-sm text-muted-foreground">No notes yet.</p>
            )}
            {selected !== null && notes.data?.map((n) => (
              <section key={n.created_at} className="rounded-md border p-3">
                <header className="flex items-center justify-between">
                  <p className="text-[10px] text-muted-foreground">{fmtUtcToApp(n.created_at)}</p>
                  <Badge variant="info">kv={n.key_version}</Badge>
                </header>
                <p className="mt-2 whitespace-pre-wrap text-sm text-foreground">{n.plaintext}</p>
              </section>
            ))}
          </div>
        </article>
      </section>

      <ConfirmDialog
        open={closingId !== null}
        title={closingId !== null ? `Close session #${closingId}?` : ''}
        description="Closing a session is final and cannot be reopened."
        confirmLabel="Close session"
        pending={close.isPending}
        onConfirm={() => {
          if (closingId !== null) close.mutate(closingId, { onSuccess: () => setClosingId(null) });
        }}
        onCancel={() => setClosingId(null)}
      />
    </div>
  );
}

// ---------------------------------------------------------- scheduling

function AddSlotDialog({ onClose }: { onClose: () => void }) {
  const add = useAddSlot();
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<AddSlotInput>({
      resolver: zodResolver(addSlotSchema),
      defaultValues: { day_of_week: 1, max_slots: 1 },
    });

  const dow = watch('day_of_week');

  const onSubmit = handleSubmit((values) => {
    add.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Add availability window</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="space-y-1.5">
          <Label id="slot-dow-label">Day of week</Label>
          <Select
            value={String(dow)}
            onValueChange={(v) => setValue('day_of_week', Number(v), { shouldValidate: true })}
          >
            <SelectTrigger aria-labelledby="slot-dow-label"><SelectValue /></SelectTrigger>
            <SelectContent>
              {DAY_NAMES.map((name, i) => (
                <SelectItem key={name} value={String(i)}>{name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="slot-start">Start time</Label>
            <TimePicker id="slot-start" aria-invalid={errors.start_time !== undefined} value={watch('start_time') ?? ''} onChange={(v) => setValue('start_time', v, { shouldValidate: true, shouldDirty: true })} />
            {errors.start_time !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.start_time.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="slot-end">End time</Label>
            <TimePicker id="slot-end" aria-invalid={errors.end_time !== undefined} value={watch('end_time') ?? ''} onChange={(v) => setValue('end_time', v, { shouldValidate: true, shouldDirty: true })} />
            {errors.end_time !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.end_time.message}</p>
            )}
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="slot-max">Concurrent capacity (max slots)</Label>
          <Input id="slot-max" type="number" min={1} aria-invalid={errors.max_slots !== undefined} {...register('max_slots', { valueAsNumber: true })} />
          {errors.max_slots !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.max_slots.message}</p>
          )}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={add.isPending}>
            {add.isPending && <Loader2 className="animate-spin" />} Add window
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function BookAppointmentDialog({ onClose }: { onClose: () => void }) {
  const book = useBookAppointment();
  const availability = useAvailability();
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<BookAppointmentInput>({
      resolver: zodResolver(bookAppointmentSchema),
      defaultValues: { type: 'initial', reason: '' },
    });

  const type = watch('type');
  const counsellorId = watch('counsellor_user_id');

  // Only counsellors that actually own availability windows can be
  // booked against — the backend rejects anything else with
  // "No active availability window covers this time."
  const counsellorIds = [...new Set((availability.data ?? []).map((w) => w.counsellor_user_id))]
    .sort((a, b) => a - b);

  const onSubmit = handleSubmit((values) => {
    book.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Book appointment</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="appt-patient">Patient school ID</Label>
          <Input id="appt-patient" aria-invalid={errors.patient_school_id !== undefined} {...register('patient_school_id')} />
          {errors.patient_school_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.patient_school_id.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label id="appt-counsellor-label">Counsellor</Label>
          <Select
            value={counsellorId !== undefined ? String(counsellorId) : ''}
            onValueChange={(v) => setValue('counsellor_user_id', Number(v), { shouldValidate: true, shouldDirty: true })}
          >
            <SelectTrigger aria-labelledby="appt-counsellor-label" aria-invalid={errors.counsellor_user_id !== undefined}>
              <SelectValue placeholder={counsellorIds.length === 0 ? 'No counsellors with availability' : 'Select counsellor'} />
            </SelectTrigger>
            <SelectContent>
              {counsellorIds.map((id) => (
                <SelectItem key={id} value={String(id)}>Counsellor #{id}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors.counsellor_user_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.counsellor_user_id.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="appt-date">Date</Label>
          <DatePicker id="appt-date" aria-invalid={errors.appointment_date !== undefined} value={watch('appointment_date') ?? ''} onChange={(v) => setValue('appointment_date', v, { shouldValidate: true, shouldDirty: true })} />
          {errors.appointment_date !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.appointment_date.message}</p>
          )}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="appt-start">Start time</Label>
            <TimePicker id="appt-start" aria-invalid={errors.start_time !== undefined} value={watch('start_time') ?? ''} onChange={(v) => setValue('start_time', v, { shouldValidate: true, shouldDirty: true })} />
            {errors.start_time !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.start_time.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="appt-end">End time</Label>
            <TimePicker id="appt-end" aria-invalid={errors.end_time !== undefined} value={watch('end_time') ?? ''} onChange={(v) => setValue('end_time', v, { shouldValidate: true, shouldDirty: true })} />
            {errors.end_time !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.end_time.message}</p>
            )}
          </div>
        </div>
        <div className="space-y-1.5">
          <Label id="appt-type-label">Type</Label>
          <Select
            value={type}
            onValueChange={(v) => setValue('type', v as BookAppointmentInput['type'], { shouldValidate: true })}
          >
            <SelectTrigger aria-labelledby="appt-type-label"><SelectValue /></SelectTrigger>
            <SelectContent>
              {APPOINTMENT_TYPES.map((t) => (
                <SelectItem key={t} value={t}>{TYPE_LABEL[t]}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="appt-reason">Reason (optional)</Label>
          <Input id="appt-reason" aria-invalid={errors.reason !== undefined} {...register('reason')} />
          {errors.reason !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.reason.message}</p>
          )}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={book.isPending}>
            {book.isPending && <Loader2 className="animate-spin" />}
            <CalendarPlus /> Book
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function CancelAppointmentDialog({ appointment, onClose }: { appointment: Appointment; onClose: () => void }) {
  const transition = useAppointmentTransition();
  const [reason, setReason] = useState('');

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Cancel appointment #{appointment.id}</DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="cancel-reason">Cancellation reason (optional)</Label>
          <Textarea
            id="cancel-reason"
            rows={3}
            maxLength={255}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Keep</Button>
          <Button
            type="button"
            variant="destructive"
            disabled={transition.isPending}
            onClick={() =>
              transition.mutate(
                { id: appointment.id, action: 'cancel', cancellation_reason: reason },
                { onSuccess: onClose },
              )
            }
          >
            {transition.isPending && <Loader2 className="animate-spin" />}
            <X /> Cancel appointment
          </Button>
        </DialogFooter>
      </div>
    </DialogContent>
  );
}

// Weekly calendar rendering of availability windows. Windows are
// recurring (day-of-week + time), so a Sun–Sat time grid is the truest
// visual: each window becomes a block in its weekday column.

function timeToMinutes(t: string): number {
  return Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5));
}

// Greedy interval partitioning: overlapping windows on the same day get
// separate lanes so blocks never cover each other.
function layoutDayLanes(windows: Availability[]): Array<{ w: Availability; lane: number; lanes: number }> {
  const sorted = [...windows].sort(
    (a, b) => timeToMinutes(a.start_time) - timeToMinutes(b.start_time),
  );
  const laneEnds: number[] = [];
  const placed = sorted.map((w) => {
    const start = timeToMinutes(w.start_time);
    let lane = laneEnds.findIndex((end) => end <= start);
    if (lane === -1) {
      lane = laneEnds.length;
      laneEnds.push(0);
    }
    laneEnds[lane] = timeToMinutes(w.end_time);
    return { w, lane };
  });
  const lanes = Math.max(1, laneEnds.length);
  return placed.map((p) => ({ ...p, lanes }));
}

const HOUR_PX = 48;

function AvailabilityCalendar({
  windows,
  onRemove,
  removing,
}: {
  windows: Availability[];
  onRemove: (w: Availability) => void;
  removing: boolean;
}) {
  // Visible hour range hugs the data; fall back to 08:00–18:00 when it
  // would collapse (defensive — the empty case is handled by the caller).
  let startHour = 8;
  let endHour = 18;
  if (windows.length > 0) {
    startHour = Math.floor(Math.min(...windows.map((w) => timeToMinutes(w.start_time))) / 60);
    endHour = Math.ceil(Math.max(...windows.map((w) => timeToMinutes(w.end_time))) / 60);
    if (endHour <= startHour) {
      startHour = 8;
      endHour = 18;
    }
  }
  const hours = Array.from({ length: endHour - startHour }, (_, i) => startHour + i);
  const gridHeight = hours.length * HOUR_PX;

  return (
    <div className="overflow-x-auto">
      <div className="min-w-[840px]">
        <div className="grid grid-cols-[3.5rem_repeat(7,minmax(0,1fr))] border-b bg-muted/50">
          <div />
          {DAY_NAMES.map((name) => (
            <p key={name} className="border-l px-2 py-2 text-center text-xs font-medium text-foreground">
              {name.slice(0, 3)}
            </p>
          ))}
        </div>
        <div className="grid grid-cols-[3.5rem_repeat(7,minmax(0,1fr))]">
          <div className="relative" style={{ height: gridHeight }}>
            {hours.map((h, i) => (
              <p
                key={h}
                className="absolute right-1.5 font-mono text-[10px] text-muted-foreground"
                style={{ top: i * HOUR_PX + 2 }}
              >
                {String(h).padStart(2, '0')}:00
              </p>
            ))}
          </div>
          {DAY_NAMES.map((name, day) => (
            <div key={name} className="relative border-l" style={{ height: gridHeight }}>
              {hours.map((h, i) => i > 0 && (
                <div
                  key={h}
                  aria-hidden
                  className="absolute inset-x-0 border-t border-border/50"
                  style={{ top: i * HOUR_PX }}
                />
              ))}
              {layoutDayLanes(windows.filter((w) => w.day_of_week === day)).map(({ w, lane, lanes }) => {
                const top = ((timeToMinutes(w.start_time) - startHour * 60) / 60) * HOUR_PX;
                const height = Math.max(
                  28,
                  ((timeToMinutes(w.end_time) - timeToMinutes(w.start_time)) / 60) * HOUR_PX,
                );
                return (
                  <div
                    key={w.id}
                    className="absolute overflow-hidden rounded-md border border-primary/30 bg-primary/10 p-1"
                    style={{
                      top,
                      height,
                      left: `calc(${(lane / lanes) * 100}% + 2px)`,
                      width: `calc(${(1 / lanes) * 100}% - 4px)`,
                    }}
                  >
                    <div className="flex items-start justify-between gap-1">
                      <p className="font-mono text-[10px] leading-tight text-foreground">
                        {w.start_time.slice(0, 5)}–{w.end_time.slice(0, 5)}
                      </p>
                      <button
                        type="button"
                        aria-label={`Remove window #${w.id}`}
                        disabled={removing}
                        onClick={() => onRemove(w)}
                        className="shrink-0 rounded-sm p-0.5 text-muted-foreground hover:text-destructive disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                      >
                        <Trash2 className="size-3" aria-hidden />
                      </button>
                    </div>
                    <p className="text-[10px] text-muted-foreground">cap {w.max_slots}</p>
                  </div>
                );
              })}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function SchedulingTab() {
  const [statusFilter, setStatusFilter] = useState<AppointmentStatus | null>(null);
  const [openAddSlot, setOpenAddSlot] = useState(false);
  const [openBook, setOpenBook] = useState(false);
  const [cancelling, setCancelling] = useState<Appointment | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const [availabilityView, setAvailabilityView] = useState<'list' | 'calendar'>('list');

  const availability = useAvailability();
  const appointments = useAppointments(statusFilter);
  const removeSlot = useRemoveSlot();
  const transition = useAppointmentTransition();

  // Shared by the list rows and the calendar blocks so both views run
  // the exact same confirm-then-remove flow.
  function confirmRemove(w: Availability) {
    setConfirm({
      title: `Remove availability window on ${DAY_NAMES[w.day_of_week]}?`,
      description: 'Existing bookings in this window are not automatically cancelled, but no new bookings can be made against it.',
      confirmLabel: 'Remove window',
      run: () => removeSlot.mutate(w.id),
    });
  }

  return (
    <div className="space-y-4">
      {/* Availability and Appointments live in their own sub-tabs so each
          gets the full width instead of sharing a split row. */}
      <Tabs defaultValue="availability" className="space-y-4">
        <TabsList>
          <TabsTrigger value="availability">Availability</TabsTrigger>
          <TabsTrigger value="appointments">Appointments</TabsTrigger>
        </TabsList>

        <TabsContent value="availability">
        <article className="overflow-hidden rounded-xl border bg-card">
          <header className="flex items-center justify-between border-b px-3 py-2">
            <p className="text-sm font-semibold text-foreground">Availability windows</p>
            <div className="flex items-center gap-2">
              <div className="flex rounded-md border p-0.5">
                <Button
                  size="sm"
                  variant={availabilityView === 'list' ? 'secondary' : 'ghost'}
                  aria-pressed={availabilityView === 'list'}
                  className="h-7"
                  onClick={() => setAvailabilityView('list')}
                >
                  <List /> List
                </Button>
                <Button
                  size="sm"
                  variant={availabilityView === 'calendar' ? 'secondary' : 'ghost'}
                  aria-pressed={availabilityView === 'calendar'}
                  className="h-7"
                  onClick={() => setAvailabilityView('calendar')}
                >
                  <CalendarDays /> Calendar
                </Button>
              </div>
              <Dialog open={openAddSlot} onOpenChange={setOpenAddSlot}>
                <Button size="sm" onClick={() => setOpenAddSlot(true)}><Plus /> Add</Button>
                {openAddSlot && <AddSlotDialog onClose={() => setOpenAddSlot(false)} />}
              </Dialog>
            </div>
          </header>
          {availabilityView === 'list' && (
          <Table>
            <TableHeader className="bg-muted/50">
              <TableRow>
                <TableHead className="px-3">Day</TableHead>
                <TableHead className="px-3">Window</TableHead>
                <TableHead className="px-3">Capacity</TableHead>
                <TableHead className="px-3 text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {availability.isLoading && (
                <TableRow>
                  <TableCell colSpan={4} className="px-3 py-6 text-center text-muted-foreground">
                    <Loader2 className="mx-auto size-4 animate-spin" />
                  </TableCell>
                </TableRow>
              )}
              {!availability.isLoading && (availability.data?.length ?? 0) === 0 && (
                <TableRow>
                  <TableCell colSpan={4} className="px-3 py-6 text-center text-muted-foreground">
                    No active windows. Add one to accept bookings.
                  </TableCell>
                </TableRow>
              )}
              {availability.isError && !availability.isLoading && (
                <QueryErrorRow colSpan={4} message="Failed to load availability windows." onRetry={() => void availability.refetch()} pending={availability.isFetching} />
              )}
              {availability.data?.map((w) => (
                <TableRow key={w.id}>
                  <TableCell className="px-3 text-xs font-medium">{DAY_NAMES[w.day_of_week]}</TableCell>
                  <TableCell className="px-3 font-mono text-xs">
                    {w.start_time.slice(0, 5)}–{w.end_time.slice(0, 5)}
                  </TableCell>
                  <TableCell className="px-3 text-xs">{w.max_slots}</TableCell>
                  <TableCell className="px-3 text-right">
                    <Button
                      size="sm"
                      variant="outline"
                      aria-label={`Remove window #${w.id}`}
                      disabled={removeSlot.isPending}
                      onClick={() => confirmRemove(w)}
                    >
                      <Trash2 /> Remove
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          )}
          {availabilityView === 'calendar' && (
            <div>
              {availability.isLoading && (
                <div className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </div>
              )}
              {!availability.isLoading && !availability.isError && (availability.data?.length ?? 0) === 0 && (
                <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                  No active windows. Add one to accept bookings.
                </p>
              )}
              {availability.isError && !availability.isLoading && (
                <div className="space-y-2 px-3 py-6 text-center">
                  <p className="text-sm text-muted-foreground">Failed to load availability windows.</p>
                  <Button size="sm" variant="outline" onClick={() => void availability.refetch()} disabled={availability.isFetching}>
                    {availability.isFetching && <Loader2 className="animate-spin" />} Retry
                  </Button>
                </div>
              )}
              {!availability.isLoading && !availability.isError && (availability.data?.length ?? 0) > 0 && (
                <AvailabilityCalendar
                  windows={availability.data ?? []}
                  onRemove={confirmRemove}
                  removing={removeSlot.isPending}
                />
              )}
            </div>
          )}
        </article>
        </TabsContent>

        <TabsContent value="appointments">
        <article className="overflow-hidden rounded-xl border bg-card">
          <header className="flex items-center justify-between gap-2 border-b px-3 py-2">
            <p className="text-sm font-semibold text-foreground">Appointments</p>
            <div className="flex items-center gap-2">
              <Select
                value={statusFilter ?? 'all'}
                onValueChange={(v) => setStatusFilter(v === 'all' ? null : (v as AppointmentStatus))}
              >
                <SelectTrigger aria-label="Status filter" className="h-8 w-36">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All statuses</SelectItem>
                  {APPOINTMENT_STATUSES.map((s) => (
                    <SelectItem key={s} value={s}>{s.replace('_', '-')}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Dialog open={openBook} onOpenChange={setOpenBook}>
                <Button size="sm" onClick={() => setOpenBook(true)}><CalendarPlus /> Book</Button>
                {openBook && <BookAppointmentDialog onClose={() => setOpenBook(false)} />}
              </Dialog>
            </div>
          </header>
          <Table>
            <TableHeader className="bg-muted/50">
              <TableRow>
                <TableHead className="px-3">#</TableHead>
                <TableHead className="px-3">Patient</TableHead>
                <TableHead className="px-3">Date & time</TableHead>
                <TableHead className="px-3">Type</TableHead>
                <TableHead className="px-3">Status</TableHead>
                <TableHead className="px-3 text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {appointments.isLoading && (
                <TableRow>
                  <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                    <Loader2 className="mx-auto size-4 animate-spin" />
                  </TableCell>
                </TableRow>
              )}
              {!appointments.isLoading && (appointments.data?.length ?? 0) === 0 && (
                <TableRow>
                  <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                    No appointments.
                  </TableCell>
                </TableRow>
              )}
              {appointments.isError && !appointments.isLoading && (
                <QueryErrorRow colSpan={6} message="Failed to load appointments." onRetry={() => void appointments.refetch()} pending={appointments.isFetching} />
              )}
              {appointments.data?.map((a) => {
                const active = a.status === 'scheduled' || a.status === 'confirmed';
                return (
                  <TableRow key={a.id}>
                    <TableCell className="px-3 font-mono text-xs">{a.id}</TableCell>
                    <TableCell className="px-3 font-mono text-xs">{a.patient_school_id}</TableCell>
                    <TableCell className="px-3 font-mono text-xs text-muted-foreground">
                      {a.appointment_date} {a.start_time.slice(0, 5)}–{a.end_time.slice(0, 5)}
                    </TableCell>
                    <TableCell className="px-3 text-xs">{TYPE_LABEL[a.type]}</TableCell>
                    <TableCell className="px-3">
                      <Badge variant={STATUS_VARIANT[a.status]}>{a.status.replace('_', '-')}</Badge>
                    </TableCell>
                    <TableCell className="px-3 text-right">
                      {active && (
                        <div className="flex justify-end gap-1">
                          {a.status === 'scheduled' && (
                            <Button
                              className="min-h-11"
                              size="sm"
                              variant="secondary"
                              aria-label={`Confirm appointment #${a.id}`}
                              disabled={transition.isPending}
                              onClick={() => transition.mutate({ id: a.id, action: 'confirm' })}
                            >
                              <Check /> Confirm
                            </Button>
                          )}
                          <Button
                            className="min-h-11"
                            size="sm"
                            variant="secondary"
                            aria-label={`Complete appointment #${a.id}`}
                            disabled={transition.isPending}
                            onClick={() => transition.mutate({ id: a.id, action: 'complete' })}
                          >
                            <CheckCheck /> Complete
                          </Button>
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button className="min-h-11" size="sm" variant="outline" aria-label={`Actions for appointment #${a.id}`}>
                                Actions <ChevronDown className="size-3.5" aria-hidden />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-52">
                              <DropdownMenuItem
                                className="min-h-11"
                                disabled={transition.isPending}
                                onSelect={() => setConfirm({
                                  title: `Mark appointment #${a.id} as no-show?`,
                                  description: 'This records a no-show, which counts toward the patient\u2019s three-strike counter.',
                                  confirmLabel: 'Mark no-show',
                                  run: () => transition.mutate({ id: a.id, action: 'no_show' }),
                                })}
                              >
                                <UserX /> Mark no-show
                              </DropdownMenuItem>
                              <DropdownMenuSeparator />
                              <DropdownMenuItem
                                className="min-h-11 text-destructive focus:text-destructive"
                                disabled={transition.isPending}
                                onSelect={() => setCancelling(a)}
                              >
                                <X /> Cancel appointment
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </div>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </article>
        </TabsContent>
      </Tabs>

      <Dialog open={cancelling !== null} onOpenChange={(o) => !o && setCancelling(null)}>
        {cancelling !== null && (
          <CancelAppointmentDialog appointment={cancelling} onClose={() => setCancelling(null)} />
        )}
      </Dialog>

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        pending={transition.isPending || removeSlot.isPending}
        onConfirm={() => {
          confirm?.run();
          setConfirm(null);
        }}
        onCancel={() => setConfirm(null)}
      />
    </div>
  );
}

// ---------------------------------------------------------- analytics

function AnalyticsTab() {
  const analytics = useSchedulingAnalytics();
  const recompute = useRecomputeAnalytics();

  // Client-side sorting is correct here (unlike the keyset-paginated
  // tables): this endpoint returns the FULL analytics dataset. Default
  // to worst no-show rate first — the primary use of this screen.
  type SortKey = 'total_appointments' | 'total_no_shows' | 'no_show_rate' | 'recommended_overbooking';
  const [sort, setSort] = useState<{ key: SortKey; dir: 'asc' | 'desc' }>({ key: 'no_show_rate', dir: 'desc' });

  function toggleSort(key: SortKey) {
    setSort((prev) => (prev.key === key ? { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'desc' }));
  }

  const sorted = [...(analytics.data ?? [])].sort((a, b) => {
    const delta = a[sort.key] - b[sort.key];
    return sort.dir === 'asc' ? delta : -delta;
  });

  function SortHeader({ label, k }: { label: string; k: SortKey }) {
    const active = sort.key === k;
    return (
      <TableHead
        className="px-3"
        aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
      >
        <button
          type="button"
          onClick={() => toggleSort(k)}
          className="inline-flex items-center gap-1 rounded-sm hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
        >
          {label}
          {active
            ? sort.dir === 'asc'
              ? <ArrowUp className="size-3" aria-hidden />
              : <ArrowDown className="size-3" aria-hidden />
            : <ArrowUpDown className="size-3 opacity-40" aria-hidden />}
        </button>
      </TableHead>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          Deterministic no-show optimizer. Recompute aggregates the appointment history into per-slot
          no-show rates and an overbooking recommendation.
        </p>
        <Button size="sm" onClick={() => recompute.mutate()} disabled={recompute.isPending}>
          {recompute.isPending ? <Loader2 className="animate-spin" /> : <LineChart />} Recompute
        </Button>
      </div>
      <section className="overflow-hidden rounded-xl border bg-card">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">Counsellor</TableHead>
              <TableHead className="px-3">Day</TableHead>
              <TableHead className="px-3">Slot</TableHead>
              <SortHeader label="Appts" k="total_appointments" />
              <SortHeader label="No-shows" k="total_no_shows" />
              <SortHeader label="No-show rate" k="no_show_rate" />
              <SortHeader label="Rec. overbooking" k="recommended_overbooking" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {analytics.isLoading && (
              <TableRow>
                <TableCell colSpan={7} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!analytics.isLoading && (analytics.data?.length ?? 0) === 0 && (
              <TableRow>
                <TableCell colSpan={7} className="px-3 py-6 text-center text-muted-foreground">
                  No analytics yet. Recompute to build slot statistics from the appointment history.
                </TableCell>
              </TableRow>
            )}
            {analytics.isError && !analytics.isLoading && (
              <QueryErrorRow colSpan={7} message="Failed to load analytics." onRetry={() => void analytics.refetch()} pending={analytics.isFetching} />
            )}
            {sorted.map((s: SlotAnalytics) => (
              <TableRow key={s.id}>
                <TableCell className="px-3 font-mono text-xs">#{s.counsellor_user_id}</TableCell>
                <TableCell className="px-3 text-xs">{DAY_NAMES[s.day_of_week]}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{s.time_slot.slice(0, 5)}</TableCell>
                <TableCell className="px-3 text-xs">{s.total_appointments}</TableCell>
                <TableCell className="px-3 text-xs">{s.total_no_shows}</TableCell>
                <TableCell className="px-3">
                  <Badge variant={s.no_show_rate >= 0.30 ? 'destructive' : s.no_show_rate >= 0.15 ? 'warning' : 'success'}>
                    {(s.no_show_rate * 100).toFixed(1)}%
                  </Badge>
                </TableCell>
                <TableCell className="px-3">
                  {s.recommended_overbooking > 0
                    ? <Badge variant="info">+{s.recommended_overbooking}</Badge>
                    : <span className="text-xs text-muted-foreground">—</span>}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>
    </div>
  );
}

// ---------------------------------------------------------------- page

export default function CounsellingPage() {
  const [tab, setTab] = useTabParam('sessions');
  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header>
        <h1 className="text-xl font-semibold text-foreground">Counselling</h1>
        <p className="text-sm text-muted-foreground">
          Notes are encrypted with AES-256-GCM. Bookings must fit an active availability window;
          no-shows drive the three-strike counter.
        </p>
      </header>

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="sessions">Sessions & Notes</TabsTrigger>
          <TabsTrigger value="scheduling">Scheduling</TabsTrigger>
          <TabsTrigger value="analytics">Analytics</TabsTrigger>
        </TabsList>
        <TabsContent value="sessions" className="mt-4">
          <SessionsTab />
        </TabsContent>
        <TabsContent value="scheduling" className="mt-4">
          <SchedulingTab />
        </TabsContent>
        <TabsContent value="analytics" className="mt-4">
          <AnalyticsTab />
        </TabsContent>
      </Tabs>
    </main>
  );
}
