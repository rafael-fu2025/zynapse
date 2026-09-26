/**
 * AppointmentsTable — the Guidance booking book (2026-09-23, reworked
 * 2026-09-25 per staff meeting).
 *
 * The table is the *book*, and the work happens elsewhere. What it does:
 *
 *   1. **Strictly chronological.** Rows render in the server's schedule
 *      order (appointment_date, start_time ASC — soonest first) with no
 *      re-ordering of any kind. The earlier "pin a fresh booking to the
 *      top" behaviour is gone: the meeting was explicit that display
 *      order follows the schedule, never the booking moment. Instead,
 *      booking now moves the date filter to the booked day, so the new
 *      row is on screen without breaking the order.
 *
 *   2. **Needs Action is its own group.** Unapproved bookings
 *      (`scheduled`) render under a highlighted *Needs action* divider
 *      above the confirmed schedule; confirming is the button that
 *      moves a row out of it. Pending work no longer hides inside the
 *      confirmed book.
 *
 *   3. **Session & notes deep link.** A confirmed-or-later row whose
 *      session has been started resolves its session and jumps to the
 *      Queue workspace via `?session=N` — the one place sessions live.
 *      Rows whose session has not started say so instead of dead-ending.
 *
 * Rows whose slot has fully elapsed render grayed (2026-09-25 meeting),
 * using Manila wall-clock parts — the counselling book stores Manila
 * dates, so "past" is a Manila comparison, never the host clock.
 *
 * Filters stay in the URL via `useUrlFilter` in the parent (PRODUCT
 * principle 5), under the same `appt_status` / `appt_date` keys the old
 * sub-tab used.
 */
import { useState } from 'react';
import { z } from 'zod';
import {
  CalendarPlus,
  Check,
  ChevronLeft,
  ChevronRight,
  NotebookPen,
} from 'lucide-react';
import { toast } from 'sonner';
import { useSearchParams } from 'react-router-dom';
import { apiClient } from '@/api/client';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { TableStateRows } from '@/components/TableStates';
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
import { useAppointments, useAppointmentTransition } from '@/hooks/useSchedule';
import { hasPermission, useAuthStore } from '@/store/auth';
import { appNowParts, fmtTimeRange } from '@/utils/date';
import { titleCase } from '@/lib/utils';
import {
  APPOINTMENT_STATUSES,
  SOURCE_LABEL,
  type Appointment,
  type AppointmentStatus,
} from '@/schemas/schedule';
import { STATUS_VARIANT, TYPE_LABEL } from '../constants';
import { BookAppointmentDialog } from '../dialogs';

/**
 * Gray a row once its slot has fully elapsed: an earlier Manila date,
 * or today with the end time already past. String comparisons are safe
 * — both sides are zero-padded `yyyy-MM-dd` / `HH:mm` wall clocks.
 */
function isPastSlot(date: string, endTime: string): boolean {
  const now = appNowParts();
  if (date < now.date) return true;
  if (date > now.date) return false;
  return endTime.slice(0, 5) <= now.time;
}

interface AppointmentsTableProps {
  status: string;
  onStatusChange: (status: string) => void;
  date: string;
  onDateChange: (date: string) => void;
}

export function AppointmentsTable({
  status,
  onStatusChange,
  date,
  onDateChange,
}: AppointmentsTableProps) {
  const auth = useAuthStore();
  const canMutate = hasPermission(auth, 'counselling.schedule.manage') || hasPermission(auth, 'counselling.schedule.team_manage');

  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [openBook, setOpenBook] = useState(false);
  // Resolving the appointment → session hop on click (one query, only
  // when asked); `sessionLookupId` drives the button's pending state.
  const [sessionLookupId, setSessionLookupId] = useState<number | null>(null);
  const [, setParams] = useSearchParams();

  const statusFilter = status === 'all' ? null : (status as AppointmentStatus);
  const appointments = useAppointments({
    status: statusFilter,
    date: date !== '' ? date : null,
    cursor,
  });
  const transition = useAppointmentTransition();

  /** Paging is scoped to one view; changing the view resets it. */
  function resetView() {
    setCursor(null);
    setHistory([null]);
  }

  function nextPage() {
    if (appointments.data?.next !== null && appointments.data?.next !== undefined) {
      const n = appointments.data.next;
      setHistory((h) => [...h, n]);
      setCursor(n);
    }
  }

  function prevPage() {
    if (history.length > 1) {
      const nextH = history.slice(0, -1);
      setHistory(nextH);
      setCursor(nextH[nextH.length - 1] ?? null);
    }
  }

  function handleBooked(appointment: Appointment) {
    // The book is strictly chronological, so instead of pinning the new
    // row out of order, move the date filter to the booked day — the
    // user sees their booking without the list ever lying about order.
    onDateChange(appointment.appointment_date);
    resetView();
  }

  const rows = appointments.data?.data ?? [];
  // Approval groups (2026-09-25 meeting): unapproved bookings live under
  // their own divider, so they cannot clutter the confirmed schedule.
  const needsActionRows = rows.filter((a) => a.status === 'scheduled');
  const scheduleRows = rows.filter((a) => a.status !== 'scheduled');
  const grouped = needsActionRows.length > 0 && scheduleRows.length > 0;

  /**
   * Resolve the appointment's session (the link runs through the queue
   * entry) and jump to the Queue workspace that hosts it. An appointment
   * whose session has not started yet says so — the desk starts sessions
   * from the board, where the patient's arrival is known.
   */
  async function openSessionFor(appointment: Appointment) {
    setSessionLookupId(appointment.id);
    try {
      const res = await apiClient.get<unknown[]>(
        `/counselling/sessions?appointment_id=${appointment.id}&limit=1`,
      );
      const sessions = z.array(z.object({ id: z.number() })).parse(res.data);
      if (sessions.length === 0) {
        toast.info('No session has been started for this appointment yet — start one from the Queue board.');
        return;
      }
      // `?session=N` without a tab resolves to the Queue (page contract).
      setParams(new URLSearchParams({ session: String(sessions[0]!.id) }));
    } catch {
      toast.error('Could not resolve the session for this appointment.');
    } finally {
      setSessionLookupId(null);
    }
  }

  const renderRow = (a: Appointment) => (
    <TableRow key={a.id} className={isPastSlot(a.appointment_date, a.end_time) ? 'bg-muted/40 text-muted-foreground' : undefined}>
      <TableCell className="px-3 text-xs">
        {a.id}
      </TableCell>
      <TableCell className="px-3">
        <p className="text-xs font-medium text-foreground">
          {a.patient_display_name ?? a.patient_school_id}
        </p>
        <p className="text-xs text-muted-foreground">{a.patient_school_id}</p>
      </TableCell>
      <TableCell className="px-3 text-xs text-muted-foreground">
        {a.appointment_date} {fmtTimeRange(a.start_time, a.end_time)}
      </TableCell>
      <TableCell className="px-3 text-xs">{TYPE_LABEL[a.type]}</TableCell>
      <TableCell className="px-3 text-xs">
        <Badge variant={a.source === 'patient' ? 'info' : 'secondary'}>
          {SOURCE_LABEL[a.source]}
        </Badge>
      </TableCell>
      <TableCell className="px-3">
        <Badge variant={STATUS_VARIANT[a.status]}>{titleCase(a.status)}</Badge>
      </TableCell>
      <TableCell className="px-3 text-right">
        <div className="flex justify-end gap-1.5">
          {/* Confirm is what moves a row out of Needs action: everything
              after the patient arrives is the Queue board's job, where
              the desk can see whether anyone showed up. */}
          {canMutate && a.status === 'scheduled' && (
            <Button
              size="sm"
              variant="outline"
              aria-label={`Confirm appointment #${a.id}`}
              disabled={transition.isPending}
              onClick={() => transition.mutate({ id: a.id, action: 'confirm' })}
            >
              <Check className="size-3.5" /> Confirm
            </Button>
          )}
          {a.status !== 'scheduled' && (
            <Button
              size="sm"
              variant="ghost"
              aria-label={`Open session and notes for appointment #${a.id}`}
              disabled={sessionLookupId === a.id}
              onClick={() => void openSessionFor(a)}
            >
              <NotebookPen className="size-3.5" /> Session &amp; notes
            </Button>
          )}
        </div>
      </TableCell>
    </TableRow>
  );

  return (
    <article className="flex flex-col overflow-hidden rounded-xl border bg-card">
      <header className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
        <p className="text-sm font-semibold text-foreground">Appointments</p>
        <div className="flex flex-wrap items-center gap-2">
          <Input
            type="date"
            aria-label="Filter by date"
            className="h-8 w-40 text-xs"
            value={date}
            onChange={(e) => { onDateChange(e.target.value); resetView(); }}
          />
          {date !== '' && (
            <Button
              size="sm"
              variant="ghost"
              className="h-8 px-2 text-xs"
              onClick={() => { onDateChange(''); resetView(); }}
            >
              Clear date
            </Button>
          )}
          <Select
            value={status}
            onValueChange={(v) => { onStatusChange(v); resetView(); }}
          >
            <SelectTrigger aria-label="Status filter" className="h-8 w-36">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              {APPOINTMENT_STATUSES.map((s) => (
                <SelectItem key={s} value={s}>{titleCase(s)}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          {canMutate && (
            <Button size="sm" onClick={() => setOpenBook(true)}>
              <CalendarPlus className="size-3.5" /> Book
            </Button>
          )}
        </div>
      </header>

      <Table ariaLabel="Counselling appointments">
        <TableHeader className="bg-muted/50">
          <TableRow>
            <TableHead className="px-3">#</TableHead>
            <TableHead className="px-3">Patient</TableHead>
            <TableHead className="px-3">Date &amp; time</TableHead>
            <TableHead className="px-3">Type</TableHead>
            <TableHead className="px-3">Booked by</TableHead>
            <TableHead className="px-3">Status</TableHead>
            <TableHead className="px-3 text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableStateRows
            colSpan={7}
            isLoading={appointments.isLoading}
            isError={appointments.isError}
            isEmpty={rows.length === 0}
            onRetry={() => void appointments.refetch()}
            pending={appointments.isFetching}
            errorMessage="Failed to load appointments."
            loadingLabel="Loading appointments"
            empty={{
              title: 'No appointments booked.',
              description: 'Bookings must fall inside an availability window.',
            }}
            noResults={{
              title: 'No appointments match these filters.',
              description: 'Try a different date or status.',
              action: (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    onDateChange('');
                    onStatusChange('all');
                    resetView();
                  }}
                >
                  Clear filters
                </Button>
              ),
            }}
            hasFilters={date !== '' || status !== 'all'}
          />
          {needsActionRows.length > 0 && (
            <TableRow className="hover:bg-transparent">
              <TableCell colSpan={7} className="border-y border-amber-500/40 bg-amber-500/10 px-3 py-1.5">
                <p className="text-xs font-semibold text-foreground">
                  Needs action
                  <span className="ml-2 font-normal text-muted-foreground">
                    {needsActionRows.length} awaiting confirmation
                  </span>
                </p>
              </TableCell>
            </TableRow>
          )}
          {needsActionRows.map(renderRow)}
          {grouped && (
            <TableRow className="hover:bg-transparent">
              <TableCell colSpan={7} className="border-y bg-muted/30 px-3 py-1.5">
                <p className="text-xs font-semibold text-foreground">Confirmed schedule</p>
              </TableCell>
            </TableRow>
          )}
          {scheduleRows.map(renderRow)}
        </TableBody>
      </Table>

      <nav className="mt-auto flex items-center justify-between border-t px-3 py-2">
        <p className="text-xs text-muted-foreground">
          {rows.length} appointment{rows.length === 1 ? '' : 's'}
          {needsActionRows.length > 0 && ` · ${needsActionRows.length} need${needsActionRows.length === 1 ? 's' : ''} action`}
        </p>
        <div className="flex gap-2">
          <Button
            size="sm"
            variant="outline"
            onClick={prevPage}
            disabled={history.length <= 1}
          >
            <ChevronLeft className="size-3.5" /> Previous
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={nextPage}
            disabled={appointments.data?.next === null || appointments.data?.next === undefined}
          >
            Next <ChevronRight className="size-3.5" />
          </Button>
        </div>
      </nav>

      <Dialog open={openBook} onOpenChange={setOpenBook}>
        {openBook && (
          <BookAppointmentDialog onClose={() => setOpenBook(false)} onBooked={handleBooked} />
        )}
      </Dialog>
    </article>
  );
}
