/**
 * PortalAppointments — shared self-service booking surface for the
 * student and employee portals.
 *
 * Formatting note: this file was previously one golfed 12-line
 * statement (2026-09 audit P2); it now reads like the rest of the
 * codebase. Booking cancels go through the shared ConfirmDialog, and
 * errors surface the ACTUAL backend reason instead of a canned
 * one-hour-cutoff message.
 *
 * 2026-09-23: "My appointments" now names the counselling type the
 * patient asked for (Initial / Follow-up / Crisis / Referral-based) and
 * lets a row expand into its full details — department, provider,
 * window, status, type and the reason the patient gave.
 *
 * 2026-09-23 (night): The booking container is converted into a modal
 * Dialog, triggered from a "Book an appointment" button at the upper right
 * of the "My appointments" container. The provider name is removed from the
 * booking flow entirely: the patient books a TIME, derived as a pooled union
 * across all rostered staff schedules. The staff member who approves the
 * session becomes the assigned provider.
 */
import { useState, type ReactNode } from 'react';
import { CalendarPlus, ChevronDown, Loader2, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { ApiEnvelopeError } from '@/api/envelope';
import {
  useAppointmentSlots,
  useBookPortalAppointment,
  useCancelPortalAppointment,
  usePortalAppointments,
  type AppointmentSlot,
  type PortalAppointment,
} from '@/hooks/usePortalAppointments';
import { cn, titleCase } from '@/lib/utils';
import { TYPE_LABEL } from '@/components/counselling/constants';
import { fmtUtcToApp } from '@/utils/date';

/**
 * The slot picker is only usable when there is genuinely something to choose.
 *
 * Driven by two inputs (department + date) against server-side availability
 * derived from staff schedules, so it passes through a non-ready state before
 * it has options. Rendering the control enabled in those states produces a
 * zero-option popover — a thin empty dropdown that looks broken — so each
 * state disables the trigger and names itself instead.
 */
type SlotPickerState = 'no-date' | 'loading' | 'error' | 'empty' | 'ready';

const SLOT_PLACEHOLDER: Record<SlotPickerState, string> = {
  'no-date': 'Pick a date first',
  loading: 'Loading available times…',
  error: 'No available time',
  empty: 'No available time',
  ready: 'Choose a time slot',
};

const SLOT_HINT: Record<SlotPickerState, string> = {
  'no-date': 'Pick a date to see available times.',
  loading: 'Loading available times…',
  error: 'Could not load available times. Try picking the date again.',
  empty: 'No available time.',
  ready: '',
};

function firstErrorMessage(err: unknown, fallback: string): string {
  return err instanceof ApiEnvelopeError ? (err.errors[0]?.message ?? fallback) : fallback;
}

function departmentLabel(department: PortalAppointment['department']): string {
  return department === 'clinic' ? 'Clinic' : 'Guidance';
}

/** Counselling type the patient requested; null for Clinic appointments. */
function typeLabel(a: PortalAppointment): string | null {
  if (a.department !== 'counselling' || a.type === null || a.type === '') return null;
  return TYPE_LABEL[a.type] ?? a.type;
}

function DetailRow({ term, children }: { term: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-muted-foreground">{term}</dt>
      <dd className="text-foreground">{children}</dd>
    </>
  );
}

function PortalAppointmentRow({
  appointment,
  expanded,
  onToggle,
  onCancel,
  cancelPending,
}: {
  appointment: PortalAppointment;
  expanded: boolean;
  onToggle: () => void;
  onCancel: () => void;
  cancelPending: boolean;
}) {
  const key = `${appointment.department}-${appointment.id}`;
  const detailsId = `portal-appointment-details-${key}`;
  const type = typeLabel(appointment);

  const providerDisplay =
    appointment.provider_name ??
    (appointment.provider_user_id !== null
      ? `Provider #${appointment.provider_user_id}`
      : 'Awaiting staff assignment');

  return (
    <li className="rounded-lg border text-xs">
      <div className="flex flex-wrap items-center gap-2 p-3">
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={detailsId}
          onClick={onToggle}
          className="flex min-w-0 flex-1 items-start gap-2 rounded-sm text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
        >
          <ChevronDown
            className={cn(
              'mt-0.5 size-4 shrink-0 text-muted-foreground transition-transform',
              expanded && 'rotate-180',
            )}
            aria-hidden
          />
          <span className="min-w-0 flex-1">
            <span className="block font-medium">{fmtUtcToApp(appointment.starts_at)}</span>
            <span className="block truncate text-muted-foreground">
              {departmentLabel(appointment.department)} · {providerDisplay}
            </span>
          </span>
        </button>
        {type !== null && <Badge variant="info">{type}</Badge>}
        <Badge variant="secondary">{titleCase(appointment.status)}</Badge>
        {appointment.status === 'scheduled' && (
          <Button
            size="icon"
            variant="ghost"
            title="Cancel appointment"
            aria-label={`Cancel ${departmentLabel(appointment.department)} appointment on ${fmtUtcToApp(appointment.starts_at)}`}
            onClick={onCancel}
            disabled={cancelPending}
          >
            <XCircle className="size-4" />
          </Button>
        )}
      </div>

      {expanded && (
        <dl
          id={detailsId}
          className="grid grid-cols-[7rem_minmax(0,1fr)] gap-x-3 gap-y-1.5 border-t px-3 py-2"
        >
          <DetailRow term="Department">{departmentLabel(appointment.department)}</DetailRow>
          <DetailRow term="Provider">
            {appointment.provider_name ?? (
              <span className="italic text-muted-foreground">
                Assigned upon approval
              </span>
            )}
          </DetailRow>
          <DetailRow term="Starts">{fmtUtcToApp(appointment.starts_at)}</DetailRow>
          <DetailRow term="Ends">{fmtUtcToApp(appointment.ends_at)}</DetailRow>
          <DetailRow term="Status">{titleCase(appointment.status)}</DetailRow>
          {type !== null && <DetailRow term="Counselling type">{type}</DetailRow>}
          <DetailRow term="Reason">
            {appointment.reason !== null && appointment.reason !== '' ? (
              appointment.reason
            ) : (
              <span className="text-muted-foreground">None given</span>
            )}
          </DetailRow>
        </dl>
      )}
    </li>
  );
}

export function PortalAppointments() {
  const [bookingOpen, setBookingOpen] = useState(false);
  const [department, setDepartment] = useState('clinic');
  const [date, setDate] = useState('');
  const [selected, setSelected] = useState<AppointmentSlot | null>(null);
  const [reason, setReason] = useState('');
  const [type, setType] = useState('initial');
  const [cancelling, setCancelling] = useState<PortalAppointment | null>(null);
  const [expanded, setExpanded] = useState<string | null>(null);

  const slots = useAppointmentSlots(department, date);
  const appointments = usePortalAppointments();
  const book = useBookPortalAppointment();
  const cancel = useCancelPortalAppointment();

  // Options are whatever the server derived from the staff schedules for this
  // department on this date. Empty until a date is picked because the query is
  // disabled without one.
  const slotOptions = slots.data ?? [];

  // Re-validate the selection against the current list rather than trusting
  // state. `setSelected(null)` already fires on a department/date change, but
  // this also covers a slot that disappears underneath the form — another
  // patient taking the last place, or the 1-hour lead window closing.
  const activeSlot =
    selected !== null && slotOptions.some((s) => s.starts_at === selected.starts_at)
      ? selected
      : null;

  const slotState: SlotPickerState =
    date === ''
      ? 'no-date'
      : slots.isLoading
        ? 'loading'
        : slots.isError
          ? 'error'
          : slotOptions.length === 0
            ? 'empty'
            : 'ready';

  function submit() {
    if (activeSlot === null) {
      toast.error('Choose an available slot.');
      return;
    }
    book.mutate(
      {
        department,
        starts_at: activeSlot.starts_at,
        ...(reason.trim() !== '' ? { reason: reason.trim() } : {}),
        ...(department === 'counselling' ? { type } : {}),
      },
      {
        onSuccess: () => {
          setBookingOpen(false);
          setSelected(null);
          setReason('');
          toast.success('Appointment booked.');
        },
        onError: (err) =>
          toast.error(firstErrorMessage(err, 'That slot could not be booked. Refresh and try again.')),
      },
    );
  }

  function cancelOne() {
    const a = cancelling;
    if (a === null) return;
    cancel.mutate(a, {
      onSuccess: () => {
        setCancelling(null);
        toast.success('Appointment cancelled.');
      },
      onError: (err) =>
        toast.error(firstErrorMessage(err, 'Cancellation closed. The slot may have already been resolved.')),
    });
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <CardTitle className="text-base">My appointments</CardTitle>
            <CardDescription className="mt-1">
              Upcoming and past Clinic and Guidance appointments. Expand one to see its full details.
            </CardDescription>
          </div>
          <Button
            onClick={() => setBookingOpen(true)}
            className="shrink-0 gap-1.5"
            size="sm"
          >
            <CalendarPlus className="size-4" />
            Book an appointment
          </Button>
        </CardHeader>
        <CardContent>
          {appointments.isLoading ? (
            <p className="text-sm text-muted-foreground">Loading…</p>
          ) : (appointments.data?.length ?? 0) === 0 ? (
            <div className="py-8 text-center">
              <p className="text-sm text-muted-foreground">No appointments yet.</p>
              <Button
                variant="outline"
                size="sm"
                className="mt-3 gap-1.5"
                onClick={() => setBookingOpen(true)}
              >
                <CalendarPlus className="size-4" />
                Book your first appointment
              </Button>
            </div>
          ) : (
            <ul className="max-h-96 space-y-2 overflow-y-auto">
              {appointments.data?.map((a) => {
                const key = `${a.department}-${a.id}`;
                return (
                  <PortalAppointmentRow
                    key={key}
                    appointment={a}
                    expanded={expanded === key}
                    onToggle={() => setExpanded((current) => (current === key ? null : key))}
                    onCancel={() => setCancelling(a)}
                    cancelPending={cancel.isPending}
                  />
                );
              })}
            </ul>
          )}
        </CardContent>
      </Card>

      {/* Book an Appointment Modal Dialog */}
      <Dialog open={bookingOpen} onOpenChange={setBookingOpen}>
        <DialogContent size="md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-base">
              <CalendarPlus className="size-4 text-primary" />
              Book an appointment
            </DialogTitle>
            <DialogDescription>
              Choose an available 60-minute Clinic or Guidance slot.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3 py-1">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Department</Label>
                <Select
                  value={department}
                  onValueChange={(v) => {
                    setDepartment(v);
                    setSelected(null);
                  }}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="clinic">Clinic</SelectItem>
                    <SelectItem value="counselling">Guidance</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label>Date</Label>
                <DatePicker
                  value={date}
                  onChange={(v) => {
                    setDate(v);
                    setSelected(null);
                  }}
                />
              </div>
            </div>
            {department === 'counselling' && (
              <div>
                <Label>Appointment type</Label>
                <Select value={type} onValueChange={setType}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="initial">Initial</SelectItem>
                    <SelectItem value="follow_up">Follow-up</SelectItem>
                    <SelectItem value="crisis">Crisis</SelectItem>
                    <SelectItem value="referral_based">Referral-based</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            )}
            <div>
              <Label>Available slot</Label>
              <Select
                value={activeSlot?.starts_at ?? ''}
                disabled={slotState !== 'ready'}
                onValueChange={(v) => setSelected(slotOptions.find((s) => s.starts_at === v) ?? null)}
              >
                <SelectTrigger>
                  <SelectValue placeholder={SLOT_PLACEHOLDER[slotState]} />
                </SelectTrigger>
                <SelectContent>
                  {slotOptions.map((s) => (
                    <SelectItem key={s.starts_at} value={s.starts_at}>
                      {fmtUtcToApp(s.starts_at, 'h:mm a')} – {fmtUtcToApp(s.ends_at, 'h:mm a')}
                      {s.remaining > 1 ? ` (${s.remaining} available)` : ''}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {SLOT_HINT[slotState] !== '' && (
                <p
                  className={cn(
                    'mt-1 text-xs',
                    slotState === 'error' ? 'text-destructive' : 'text-muted-foreground',
                  )}
                >
                  {SLOT_HINT[slotState]}
                </p>
              )}
            </div>
            <div>
              <Label>Reason (optional)</Label>
              <Input
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                maxLength={255}
                placeholder="Brief reason for your visit"
              />
            </div>
          </div>
          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              variant="outline"
              type="button"
              onClick={() => setBookingOpen(false)}
              disabled={book.isPending}
            >
              Cancel
            </Button>
            <Button disabled={activeSlot === null || book.isPending} onClick={submit}>
              {book.isPending && <Loader2 className="mr-1.5 size-4 animate-spin" />}
              Book appointment
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={cancelling !== null}
        title="Cancel this appointment?"
        description={
          cancelling === null
            ? undefined
            : `Your ${cancelling.department === 'clinic' ? 'Clinic' : 'Guidance'} slot on ${fmtUtcToApp(cancelling.starts_at)} will be released. Cancellation closes one hour before the appointment.`
        }
        confirmLabel="Cancel appointment"
        pending={cancel.isPending}
        onConfirm={cancelOne}
        onCancel={() => setCancelling(null)}
      />
    </div>
  );
}
