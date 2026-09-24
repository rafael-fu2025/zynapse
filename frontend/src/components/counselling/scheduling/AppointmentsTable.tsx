/**
 * AppointmentsTable — the Guidance booking book (2026-09-23).
 *
 * The table is the *book*, and the work happens elsewhere. Two things it does,
 * both driven by that idea:
 *
 *   1. **Confirm is the only action.** Complete, Mark no-show and Cancel used
 *      to live here as well, which meant the same appointment could be resolved
 *      from two places with different consequences. Confirming a booking is the
 *      one thing that is genuinely this table's job; everything after the
 *      patient arrives belongs to the Queue board, which knows whether anyone
 *      actually showed up.
 *   2. **A fresh booking is pinned to the top.** The list is date-ascending
 *      (soonest first), so a booking made for three weeks out lands at the
 *      bottom, usually off-page — the user pressed Book and saw nothing happen.
 *      Anything created in this sitting is lifted to the top and badged *New*.
 *      A booking that the active filters exclude is not pinned, because
 *      injecting it into a list that says it does not match would be a lie.
 *
 * **This table offers no route into a session** (2026-09-23, third revision).
 * It previously expanded a row into the patient's session and carried an
 * "Open session" button; both are gone, along with the lazy per-row
 * `?appointment_id=` resolution they needed. Sessions are read on the **Queue**
 * board, from a patient's on-going row, so that there is exactly one place to
 * look and one place to complete. The `appointment_id` filter still exists on
 * the backend and is still pinned by `SessionAppointmentLinkContractTest`; it
 * simply has no caller in the SPA now.
 *
 * Filters stay in the URL via `useUrlFilter` in the parent (PRODUCT principle
 * 5), under the same `appt_status` / `appt_date` keys the old sub-tab used.
 */
import { useState } from 'react';
import {
  CalendarPlus,
  Check,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';
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
import { fmtTimeRange } from '@/utils/date';
import { titleCase } from '@/lib/utils';
import {
  APPOINTMENT_STATUSES,
  SOURCE_LABEL,
  type Appointment,
  type AppointmentStatus,
} from '@/schemas/schedule';
import { STATUS_VARIANT, TYPE_LABEL } from '../constants';
import { BookAppointmentDialog } from '../dialogs';

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
  // Appointments created in this sitting, newest first.
  const [pinned, setPinned] = useState<Appointment[]>([]);

  const statusFilter = status === 'all' ? null : (status as AppointmentStatus);
  const appointments = useAppointments({
    status: statusFilter,
    date: date !== '' ? date : null,
    cursor,
  });
  const transition = useAppointmentTransition();

  /** Paging and pins are scoped to one view; changing the view resets both. */
  function resetView() {
    setCursor(null);
    setHistory([null]);
    setPinned([]);
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
    // Land the user on page 1 so the pin is actually on screen, then pin only
    // if the booking is a member of the set the filters describe.
    setCursor(null);
    setHistory([null]);
    const matchesStatus = statusFilter === null || appointment.status === statusFilter;
    const matchesDate = date === '' || appointment.appointment_date === date;
    if (matchesStatus && matchesDate) {
      setPinned((prev) => [appointment, ...prev.filter((p) => p.id !== appointment.id)]);
    }
  }

  const serverRows = appointments.data?.data ?? [];
  const pinnedIds = new Set(pinned.map((p) => p.id));
  const rows = [...pinned, ...serverRows.filter((a) => !pinnedIds.has(a.id))];

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
          {rows.map((a) => {
            const isNew = pinnedIds.has(a.id);
            return (
              <TableRow key={a.id}>
                <TableCell className="px-3 text-xs">
                  {a.id}
                </TableCell>
                <TableCell className="px-3">
                  <div className="flex flex-wrap items-center gap-1.5">
                    <p className="text-xs font-medium text-foreground">
                      {a.patient_display_name ?? a.patient_school_id}
                    </p>
                    {isNew && <Badge variant="info">New</Badge>}
                  </div>
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
                  {/* Confirm is the only action left here: everything after
                      the patient arrives is the Queue board's job, where the
                      desk can see whether anyone showed up. */}
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
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>

      <nav className="mt-auto flex items-center justify-between border-t px-3 py-2">
        <p className="text-xs text-muted-foreground">
          {rows.length} appointment{rows.length === 1 ? '' : 's'}
          {pinned.length > 0 && ` · ${pinned.length} just booked`}
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
