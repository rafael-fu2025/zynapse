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
  CalendarPlus,
  Check,
  CheckCheck,
  ChevronLeft,
  ChevronRight,
  LineChart,
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
  const [openWrite, setOpenWrite] = useState<Session | null>(null);
  const sessions = useSessions(cursor, 25);
  const notes = useNotes(openWrite?.id ?? 0);
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
              {sessions.data?.data.map((s) => (
                <TableRow
                  key={s.id}
                  className={`cursor-pointer ${openWrite?.id === s.id ? 'bg-accent/40' : ''}`}
                  onClick={() => setOpenWrite(s)}
                >
                  <TableCell className="px-3 font-mono text-xs">{s.id}</TableCell>
                  <TableCell className="px-3 font-mono text-xs">{s.patient_school_id}</TableCell>
                  <TableCell className="px-3 font-mono text-xs text-muted-foreground">{fmtUtcToApp(s.started_at)}</TableCell>
                  <TableCell className="px-3 font-mono text-xs text-muted-foreground">
                    {s.ended_at === null ? <Badge variant="info">Open</Badge> : fmtUtcToApp(s.ended_at)}
                  </TableCell>
                  <TableCell className="px-3 text-right">
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={s.ended_at !== null || close.isPending}
                      onClick={(ev) => { ev.stopPropagation(); close.mutate(s.id); }}
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
              Notes {openWrite !== null ? `— session #${openWrite.id}` : ''}
            </p>
            {openWrite !== null && (
              <Dialog open={openWrite !== null} onOpenChange={(o) => !o && setOpenWrite(null)}>
                <Button size="sm" onClick={() => setOpenWrite(openWrite)}>
                  <Plus /> Write
                </Button>
                {openWrite !== null && <WriteNotesDialog session={openWrite} onClose={() => setOpenWrite(null)} />}
              </Dialog>
            )}
          </header>
          <div className="max-h-[480px] space-y-3 overflow-auto p-3">
            {openWrite === null && (
              <p className="text-sm text-muted-foreground">Select a session to view its encrypted notes.</p>
            )}
            {openWrite !== null && notes.isLoading && (
              <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />
            )}
            {openWrite !== null && !notes.isLoading && (notes.data?.length ?? 0) === 0 && (
              <p className="text-sm text-muted-foreground">No notes yet.</p>
            )}
            {openWrite !== null && notes.data?.map((n) => (
              <section key={n.created_at} className="rounded-md border p-3">
                <header className="flex items-center justify-between">
                  <p className="font-mono text-[10px] text-muted-foreground">{fmtUtcToApp(n.created_at)}</p>
                  <Badge variant="info">kv={n.key_version}</Badge>
                </header>
                <p className="mt-2 whitespace-pre-wrap text-sm text-foreground">{n.plaintext}</p>
              </section>
            ))}
          </div>
        </article>
      </section>
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
            <Input id="slot-start" type="time" aria-invalid={errors.start_time !== undefined} {...register('start_time')} />
            {errors.start_time !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.start_time.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="slot-end">End time</Label>
            <Input id="slot-end" type="time" aria-invalid={errors.end_time !== undefined} {...register('end_time')} />
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
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<BookAppointmentInput>({
      resolver: zodResolver(bookAppointmentSchema),
      defaultValues: { type: 'initial', reason: '' },
    });

  const type = watch('type');

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
          <Label htmlFor="appt-date">Date</Label>
          <Input id="appt-date" type="date" aria-invalid={errors.appointment_date !== undefined} {...register('appointment_date')} />
          {errors.appointment_date !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.appointment_date.message}</p>
          )}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="appt-start">Start time</Label>
            <Input id="appt-start" type="time" aria-invalid={errors.start_time !== undefined} {...register('start_time')} />
            {errors.start_time !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.start_time.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="appt-end">End time</Label>
            <Input id="appt-end" type="time" aria-invalid={errors.end_time !== undefined} {...register('end_time')} />
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

function SchedulingTab() {
  const [statusFilter, setStatusFilter] = useState<AppointmentStatus | null>(null);
  const [openAddSlot, setOpenAddSlot] = useState(false);
  const [openBook, setOpenBook] = useState(false);
  const [cancelling, setCancelling] = useState<Appointment | null>(null);

  const availability = useAvailability();
  const appointments = useAppointments(statusFilter);
  const removeSlot = useRemoveSlot();
  const transition = useAppointmentTransition();

  return (
    <div className="space-y-4">
      <section className="grid gap-4 lg:grid-cols-5">
        <article className="overflow-hidden rounded-xl border bg-card lg:col-span-2">
          <header className="flex items-center justify-between border-b px-3 py-2">
            <p className="text-sm font-semibold text-foreground">Availability windows</p>
            <Dialog open={openAddSlot} onOpenChange={setOpenAddSlot}>
              <Button size="sm" onClick={() => setOpenAddSlot(true)}><Plus /> Add</Button>
              {openAddSlot && <AddSlotDialog onClose={() => setOpenAddSlot(false)} />}
            </Dialog>
          </header>
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
                      onClick={() => removeSlot.mutate(w.id)}
                    >
                      <Trash2 /> Remove
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </article>

        <article className="overflow-hidden rounded-xl border bg-card lg:col-span-3">
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
                              size="sm"
                              variant="outline"
                              aria-label={`Confirm appointment #${a.id}`}
                              disabled={transition.isPending}
                              onClick={() => transition.mutate({ id: a.id, action: 'confirm' })}
                            >
                              <Check /> Confirm
                            </Button>
                          )}
                          <Button
                            size="sm"
                            variant="outline"
                            aria-label={`Complete appointment #${a.id}`}
                            disabled={transition.isPending}
                            onClick={() => transition.mutate({ id: a.id, action: 'complete' })}
                          >
                            <CheckCheck /> Complete
                          </Button>
                          <Button
                            size="sm"
                            variant="outline"
                            aria-label={`Mark appointment #${a.id} as no-show`}
                            disabled={transition.isPending}
                            onClick={() => transition.mutate({ id: a.id, action: 'no_show' })}
                          >
                            <UserX /> No-show
                          </Button>
                          <Button
                            size="sm"
                            variant="outline"
                            aria-label={`Cancel appointment #${a.id}`}
                            disabled={transition.isPending}
                            onClick={() => setCancelling(a)}
                          >
                            <X /> Cancel
                          </Button>
                        </div>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </article>
      </section>

      <Dialog open={cancelling !== null} onOpenChange={(o) => !o && setCancelling(null)}>
        {cancelling !== null && (
          <CancelAppointmentDialog appointment={cancelling} onClose={() => setCancelling(null)} />
        )}
      </Dialog>
    </div>
  );
}

// ---------------------------------------------------------- analytics

function AnalyticsTab() {
  const analytics = useSchedulingAnalytics();
  const recompute = useRecomputeAnalytics();

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
              <TableHead className="px-3">Appts</TableHead>
              <TableHead className="px-3">No-shows</TableHead>
              <TableHead className="px-3">No-show rate</TableHead>
              <TableHead className="px-3">Rec. overbooking</TableHead>
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
            {analytics.data?.map((s: SlotAnalytics) => (
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
  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header>
        <h1 className="text-xl font-semibold text-foreground">Counselling</h1>
        <p className="text-sm text-muted-foreground">
          Notes are encrypted with AES-256-GCM. Bookings must fit an active availability window;
          no-shows drive the three-strike counter.
        </p>
      </header>

      <Tabs defaultValue="sessions">
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
