/**
 * GuidanceQueueTab — the Guidance desk's day board.
 *
 * Reworked 2026-09-23. It used to be a FIFO operator console: a flat list of
 * `counselling_queue_entries` with a **Call next** button driving the
 * `waiting → called → in_session` ladder. The desk does not work that way any
 * more — it works from the appointment book, serving whoever's slot is now —
 * so the console was replaced by a **calendar board** with three buckets:
 *
 *   1. Upcoming   — dated after today, still live (scheduled | confirmed)
 *   2. Today      — dated today, still live, plus anyone checked in at the
 *                   kiosk or handed over from Clinic who has no appointment
 *   3. Archived   — resolved (completed | cancelled | no_show) or already
 *                   dated in the past
 *
 * The three buckets are disjoint and resolved **server-side against the
 * Manila business day** (`scope=` on the appointments endpoint) — day
 * bucketing is the one thing this module has regressed on before, so the
 * frontend never derives "today" itself.
 *
 * Every bucket lists appointments from **both** booking origins — what the
 * patient booked through the portal and what the counselling team booked on
 * their behalf. The "Booked by" column is driven by the row's `source`.
 *
 * Call next is gone. `Start Session` is now reachable straight from
 * `waiting` (see QueueService::TRANSITIONS), because `callNext()` was the
 * only writer of `called` and walk-ins would otherwise be stranded with no
 * reachable transition.
 *
 * The Today bucket is a **union**: appointments ∪ active queue entries. A
 * queue entry can exist with no appointment (kiosk walk-in, Clinic handoff,
 * referral follow-up), and it is exactly the live part of the day, so it
 * must render even when the appointments query is unavailable.
 *
 * ---------------------------------------------------------------------------
 * Revision 2 (2026-09-23, later the same day) — the desk asked the board to
 * move on its own:
 *
 *   - **Live state column.** A slot that has started reads *Checked in* and a
 *     slot that has ended reads *Checked out* / *No-show* without anyone
 *     pressing a button. The reading comes from `deriveAppointmentLiveState`,
 *     which compares the appointment's window against the **Manila clock** and
 *     lets a real queue entry outrank the inference.
 *   - **Display only.** Nothing is written by that inference. A derived badge
 *     is labelled "unconfirmed" and the row's dropdown is how a staff member
 *     turns it into a record. There is no background sweep and no automatic
 *     strike, so a display bug can never penalise a student.
 *   - **Outcome dropdown.** "Mark No-Show" offers *No-Show* and *Cancel
 *     Appointment* — the two ways a slot ends without the patient being seen.
 *   - **Expandable rows.** A row whose session has started expands in place to
 *     reveal the session and its notes, with Complete Session at the end of
 *     that flow. This is where the retired Sessions & Notes tab's content now
 *     lives.
 *
 * The board's `now` is deliberately *not* memoised: the queue query re-polls
 * every 10s, so each render re-reads the clock and the derived badges advance
 * on their own.
 *
 * Revision 3 (2026-09-23, third revision) — the board became the only place a
 * session is read, and got quieter:
 *
 *   - **The Live state column is gone.** It earned its keep in revision 2 but
 *     the desk read it as a claim about the patient rather than a reading of
 *     the clock. The derivation itself is untouched and still drives the
 *     outcome dropdown's gating; it just no longer has a column. The queue
 *     number that used to ride in that cell moved under the patient's name,
 *     because removing a column should not silently delete a different fact.
 *   - **The Actions column no longer renders an em dash.** A row with no
 *     action now shows nothing. A placeholder that looks like missing data
 *     beside real buttons reads as a broken row.
 *   - **Sessions open from an on-going session in Today's Sessions.** Only a
 *     row whose queue entry is `in_session` expands on click — the on-going
 *     patient, which is the one the desk is working with. Completed and
 *     cancelled sessions are therefore not reachable by browsing; the
 *     `?session=N` deep link is the way back to a specific one, and it
 *     force-opens its row regardless of state.
 *
 * Revision 4 (2026-09-23, fourth revision) — two of revision 3's calls were
 * reversed. This is not a flip-flop, so the reasoning is worth keeping:
 *
 *   - **The Live state column is back.** Revision 3 removed it because staff
 *     read the badge as a claim about the patient. What that reasoning missed
 *     is the job the column was actually doing: *Checked in* appearing the
 *     moment the scheduled window opens is how the desk sees that a session
 *     has started without opening anything or waiting for someone to press a
 *     button. The derivation was never the problem — the wording was — so the
 *     column returns with its **unconfirmed** qualifier and hint intact.
 *   - **Completion moved into the outcome dropdown.** The menu now carries
 *     Complete Session alongside No-Show and Cancel Appointment: one place a
 *     slot is closed out, and the path for closing a session that was **never
 *     written up**. The **Complete Session inside the session panel is a
 *     different intent** — it is the last step of the write-the-notes flow —
 *     so it stays where the notes are.
 *   - **One Complete per row.** The Actions column keeps its Complete Session
 *     button only for rows the dropdown cannot reach (a walk-in with no
 *     appointment, or a viewer without schedule rights). Keeping both would
 *     show two ways to do the same thing on the same row.
 */
import { Fragment, useMemo, useState } from 'react';
import {
  ArrowRight,
  CalendarClock,
  CalendarDays,
  CheckCheck,
  ChevronDown,
  ChevronRight,
  Ellipsis,
  History,
  Loader2,
  PlayCircle,
  SkipForward,
  UserX,
  Wrench,
  X,
} from 'lucide-react';
import { toast } from 'sonner';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { TableStateRows } from '@/components/TableStates';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Dialog } from '@/components/ui/dialog';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  useGuidanceQueueToday,
  useGuidanceQueueTransition,
  useGuidanceRepairSession,
} from '@/hooks/useQueue';
import { useAppointments, useAppointmentTransition } from '@/hooks/useSchedule';
import { titleCase } from '@/lib/utils';
import { hasPermission, useAuthStore } from '@/store/auth';
import type { GuidanceQueueEntry } from '@/schemas/queue';
import { SOURCE_LABEL, type Appointment } from '@/schemas/schedule';
import { deriveAppointmentLiveState, type LiveStateDescriptor } from '@/utils/appointmentLiveState';
import { fmtTimeRange } from '@/utils/date';
import { STATUS_VARIANT, TYPE_LABEL } from '../constants';
import { CancelAppointmentDialog } from '../dialogs';
import { RowSessionPanel } from '../workspace';

interface GuidanceQueueTabProps {
  /**
   * `?session=N` — a session opened by deep link (notification, bookmark)
   * rather than by clicking a row.
   *
   * A link is an *explicit* request for that one session, so it is not held to
   * the on-going-only rule that governs a row click: the matching row is
   * force-opened wherever it sits, and when nothing on the board owns it the
   * session renders in a panel above the board instead of dead-ending.
   */
  selectedSessionId?: number | null;
  /** Clears `?session=`, collapsing the deep-link panel. */
  onCloseSession?: () => void;
  /** Jump to the Appointments section for the full filtered table. */
  onViewAllAppointments: () => void;
}

/** One board row: an appointment, a live queue entry, or both when linked. */
interface BoardRow {
  key: string;
  appointment: Appointment | null;
  queue: GuidanceQueueEntry | null;
}

function queueLabel(entry: GuidanceQueueEntry): string {
  return entry.queue_number ?? `G-${String(entry.position).padStart(3, '0')}`;
}

function PatientCell({ row }: { row: BoardRow }) {
  const name = row.appointment?.patient_display_name ?? row.queue?.display_name ?? null;
  const schoolId = row.appointment?.patient_school_id ?? row.queue?.patient_school_id ?? null;

  return (
    <TableCell className="px-3">
      <p className="font-medium text-foreground">{name ?? schoolId ?? '—'}</p>
      {schoolId !== null && <p className="tabular-nums text-xs text-muted-foreground">{schoolId}</p>}
      {/* The queue number lives here rather than beside the Live state badge,
          where it sat until revision 3. It is the number the desk calls out
          loud, so it belongs next to the patient it identifies — and keeping
          it here means the restored badge column shows one fact, not two. */}
      {row.queue !== null && (
        <p className="tabular-nums text-xs text-muted-foreground">{queueLabel(row.queue)}</p>
      )}
      {row.appointment === null && row.queue !== null && (
        <p className="text-xs text-muted-foreground">Walk-in · {row.queue.purpose}</p>
      )}
    </TableCell>
  );
}

/**
 * The derived live state — the *reading*, not the record.
 *
 * A state the board inferred from the Manila clock is qualified **unconfirmed**
 * and carries its hint, because nothing was written for it: staff turn it into
 * a record from the row's dropdown. A state that came from a real queue
 * transition renders bare, because it is not a claim.
 *
 * This column is why the desk can see that a session has started — *Checked in*
 * appears when the scheduled window opens — without opening the row or waiting
 * for anyone to press a button. Revision 3 removed it; revision 4 restored it.
 */
function LiveStateCell({ live }: { live: LiveStateDescriptor }) {
  return (
    <TableCell className="px-3">
      <div className="flex flex-wrap items-center gap-1.5">
        <Badge variant={live.variant} title={live.hint}>
          {live.label}
        </Badge>
        {live.derived && (
          <>
            <span className="text-[10px] uppercase tracking-wide text-muted-foreground">unconfirmed</span>
            {/* `title` is not reliably announced, so the reason is also in the
                accessibility tree — the qualifier alone reads as a doubt. */}
            <span className="sr-only">{live.hint}</span>
          </>
        )}
      </div>
    </TableCell>
  );
}

function BookedByCell({ row }: { row: BoardRow }) {
  if (row.appointment === null) {
    return <TableCell className="px-3 text-xs text-muted-foreground">—</TableCell>;
  }
  return (
    <TableCell className="px-3 text-xs">
      <Badge variant={row.appointment.source === 'patient' ? 'info' : 'secondary'}>
        {SOURCE_LABEL[row.appointment.source]}
      </Badge>
    </TableCell>
  );
}

/**
 * One menu for closing a slot out: complete it, or record that it ended without
 * the patient being seen.
 *
 * Three outcomes over two different writes, and that split is why Complete
 * belongs here as well as in the session panel:
 *
 *   - **Complete Session** closes the session that is open. This is the path
 *     for a session that was **never written up** — the desk needs it closed
 *     without opening the panel first and inventing a note. It writes through
 *     the queue entry, so it also closes that entry and promotes the linked
 *     appointment (`QueueService::completeLinkedRecords`, which deliberately
 *     leaves a cancelled appointment alone).
 *   - **No-Show** / **Cancel Appointment** write to the *appointment*, so they
 *     are only offered when an appointment is behind the row — a kiosk walk-in
 *     has nothing to no-show.
 *
 * The `Complete Session` at the end of the session panel is a **different
 * intent**, not a duplicate: it is the last step of the write-the-notes flow,
 * so it stays where the notes are.
 *
 * Items are rendered disabled rather than hidden, so the rule is discoverable
 * instead of the menu mysteriously missing an entry.
 */
function OutcomeMenu({
  appointment,
  live,
  pending,
  canComplete,
  onNoShow,
  onCancel,
  onComplete,
}: {
  appointment: Appointment;
  live: LiveStateDescriptor;
  pending: boolean;
  /** The viewer holds `counselling.queue.manage` — completion runs through the queue. */
  canComplete: boolean;
  onNoShow: () => void;
  onCancel: () => void;
  onComplete: () => void;
}) {
  const completeOffered = live.actions.canComplete && canComplete;

  if (!completeOffered && !live.actions.canMarkNoShow && !live.actions.canCancel) return null;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          size="sm"
          variant="outline"
          aria-label={`Outcome actions for appointment #${appointment.id}`}
        >
          <Ellipsis className="size-3.5" /> Outcome
          <ChevronDown className="size-3.5" aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-64">
        {!completeOffered && (
          <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
            {live.actions.canComplete
              ? 'Completing a session needs the Guidance queue permission.'
              : 'Complete is offered while a session is open for this patient.'}
          </DropdownMenuLabel>
        )}
        <DropdownMenuItem disabled={!completeOffered || pending} onSelect={onComplete}>
          <CheckCheck className="size-3.5" /> Complete Session
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        {!live.actions.canMarkNoShow && (
          <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
            No-show is offered once the slot ends with nothing written.
          </DropdownMenuLabel>
        )}
        <DropdownMenuItem disabled={!live.actions.canMarkNoShow || pending} onSelect={onNoShow}>
          <UserX className="size-3.5" /> No-Show
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          className="text-destructive focus:text-destructive"
          disabled={!live.actions.canCancel || pending}
          onSelect={onCancel}
        >
          <X className="size-3.5" /> Cancel Appointment
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/** Non-live buckets: a plain schedule row, no queue actions. */
function ScheduleTable({
  rows,
  emptyTitle,
  emptyDescription,
  isLoading,
  isError,
  isFetching,
  onRetry,
  loadingLabel,
  ariaLabel,
  hasMore,
  onViewAllAppointments,
}: {
  rows: BoardRow[];
  emptyTitle: string;
  emptyDescription: string;
  isLoading: boolean;
  isError: boolean;
  isFetching: boolean;
  onRetry: () => void;
  loadingLabel: string;
  ariaLabel: string;
  hasMore: boolean;
  onViewAllAppointments: () => void;
}) {
  return (
    <>
      <Table ariaLabel={ariaLabel}>
        <TableHeader className="bg-muted/50">
          <TableRow>
            <TableHead className="px-3">When</TableHead>
            <TableHead className="px-3">Patient</TableHead>
            <TableHead className="px-3">Type</TableHead>
            <TableHead className="px-3">Booked by</TableHead>
            <TableHead className="px-3">Status</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableStateRows
            colSpan={5}
            isLoading={isLoading}
            isError={isError}
            isEmpty={rows.length === 0}
            onRetry={onRetry}
            pending={isFetching}
            errorMessage="Failed to load these appointments."
            loadingLabel={loadingLabel}
            empty={{ title: emptyTitle, description: emptyDescription }}
          />
          {rows.map((row) => {
            const a = row.appointment;
            if (a === null) return null;
            return (
              <TableRow key={row.key}>
                <TableCell className="px-3 tabular-nums text-xs text-muted-foreground">
                  {a.appointment_date} {fmtTimeRange(a.start_time, a.end_time)}
                </TableCell>
                <PatientCell row={row} />
                <TableCell className="px-3 text-xs">{TYPE_LABEL[a.type]}</TableCell>
                <BookedByCell row={row} />
                <TableCell className="px-3">
                  <Badge variant={STATUS_VARIANT[a.status]}>{titleCase(a.status)}</Badge>
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
      {hasMore && (
        <div className="flex items-center justify-between gap-3 border-t px-3 py-2">
          <p className="text-xs text-muted-foreground">
            Showing the first page — older entries continue in Appointments.
          </p>
          <Button size="sm" variant="outline" onClick={onViewAllAppointments}>
            Open Appointments <ArrowRight className="size-3.5" />
          </Button>
        </div>
      )}
    </>
  );
}

export function GuidanceQueueTab({
  selectedSessionId = null,
  onCloseSession,
  onViewAllAppointments,
}: GuidanceQueueTabProps) {
  const authState = useAuthStore();
  const canManage = hasPermission(authState, 'counselling.queue.manage');
  const canMutateSchedule =
    hasPermission(authState, 'counselling.schedule.manage') ||
    hasPermission(authState, 'counselling.schedule.team_manage');

  const queue = useGuidanceQueueToday();
  const today = useAppointments({ scope: 'today' });
  const upcoming = useAppointments({ scope: 'upcoming' });
  const archived = useAppointments({ scope: 'archived' });

  const transition = useGuidanceQueueTransition();
  const repair = useGuidanceRepairSession();
  const appointmentTransition = useAppointmentTransition();

  // One row expanded at a time — the inline drawer is tall, and two open
  // workspaces on one board is a way to lose track of which patient is which.
  const [expandedKey, setExpandedKey] = useState<string | null>(null);
  const [cancelling, setCancelling] = useState<Appointment | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);

  // Queue entry per appointment, so a linked row renders its live state.
  const queueByAppointment = useMemo(() => {
    const map = new Map<number, GuidanceQueueEntry>();
    for (const entry of queue.data ?? []) {
      const appointmentId = entry.counselling_appointment_id;
      if (appointmentId !== null && appointmentId !== undefined) map.set(appointmentId, entry);
    }
    return map;
  }, [queue.data]);

  const todayRows = useMemo<BoardRow[]>(() => {
    const scheduled: BoardRow[] = (today.data?.data ?? []).map((a) => ({
      key: `a-${a.id}`,
      appointment: a,
      queue: queueByAppointment.get(a.id) ?? null,
    }));

    // Queue entries with no appointment on today's book: kiosk walk-ins,
    // Clinic handoffs, referral follow-ups. Ordered by FIFO position, which is
    // the only ordering they have. Resolved entries (done / skipped) are not
    // "in play" and are excluded — when they have an appointment, the
    // appointment's own status is what carries them into the archived bucket.
    const linked = new Set(scheduled.map((row) => row.appointment?.id));
    const walkIns: BoardRow[] = (queue.data ?? [])
      .filter((entry) => entry.status === 'waiting' || entry.status === 'called' || entry.status === 'in_session')
      .filter((entry) => entry.counselling_appointment_id === null || entry.counselling_appointment_id === undefined || !linked.has(entry.counselling_appointment_id))
      .sort((a, b) => a.position - b.position)
      .map((entry) => ({ key: `q-${entry.id}`, appointment: null, queue: entry }));

    return [...scheduled, ...walkIns];
  }, [today.data, queue.data, queueByAppointment]);

  const upcomingRows = useMemo<BoardRow[]>(
    () => (upcoming.data?.data ?? []).map((a) => ({ key: `u-${a.id}`, appointment: a, queue: null })),
    [upcoming.data],
  );

  const archivedRows = useMemo<BoardRow[]>(
    () => (archived.data?.data ?? []).map((a) => ({ key: `x-${a.id}`, appointment: a, queue: null })),
    [archived.data],
  );

  /**
   * Whether any row on the board owns the deep-linked session.
   *
   * Only the Today bucket can answer this: it is the one bucket whose rows
   * carry a queue entry, and the session link lives on the queue entry. An
   * Upcoming or Archived row is an appointment with no entry, so a link to a
   * session from a past day cannot be matched to a row — see the pinned panel
   * below, which is where those land.
   */
  const deepLinkMatched =
    selectedSessionId !== null &&
    todayRows.some((row) => row.queue?.counselling_session_id === selectedSessionId);

  // The live bucket stays useful on the queue feed alone, so it only reports
  // an error when both sources failed.
  const todayIsLoading = today.isLoading && queue.isLoading;
  const todayIsError = today.isError && queue.isError;
  const todayIsFetching = today.isFetching || queue.isFetching;

  function retryToday() {
    void today.refetch();
    void queue.refetch();
  }

  function startSession(entry: GuidanceQueueEntry) {
    transition.mutate(
      { id: entry.id, action: 'start' },
      {
        onSuccess: (started) => {
          const sessionId = started.counselling_session_id;
          if (sessionId === null || sessionId === undefined) {
            toast.error('The queue started, but its linked session is missing. Refresh and retry opening it.');
            return;
          }
          toast.success(`${queueLabel(started)} started — Session #${sessionId} is now active.`);
          // Expand in place: the desk asked to see the notes inside the row,
          // not to be thrown onto another tab.
          setExpandedKey(`q-${started.id}`);
        },
      },
    );
  }

  return (
    <div className="space-y-4">
      <section className="rounded-xl border bg-card p-4">
        <h2 className="font-semibold text-foreground">Guidance sessions</h2>
        <p className="text-sm text-muted-foreground">
          Your day in three buckets — what is booked ahead, what is happening today, and what is already
          closed. Every appointment shows whether the patient booked it themselves or the desk booked it
          for them.
        </p>
      </section>

      {/* -------------------------------------------- deep-linked session */}
      {/* `?session=N` is a notification or a bookmark naming one session. When
          a row on the board owns it, that row expands and this stays hidden.
          When nothing does — a session from a past day, whose appointment sits
          in the Archived bucket with no queue entry to carry the link — the
          session renders here rather than the link dead-ending. */}
      {selectedSessionId !== null && !deepLinkMatched && (
        <section
          aria-label="Session and notes (linked session)"
          className="overflow-hidden rounded-xl border bg-card"
        >
          <RowSessionPanel sessionId={selectedSessionId} onClose={() => onCloseSession?.()} />
        </section>
      )}

      {/* ------------------------------------------------ today (live) */}
      <section
        aria-labelledby="guidance-today-heading"
        className="overflow-hidden rounded-xl border bg-card"
      >
        <header className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
          <div className="flex items-center gap-2">
            <CalendarDays className="size-4 text-muted-foreground" aria-hidden />
            <h2 id="guidance-today-heading" className="text-sm font-semibold text-foreground">
              Today&rsquo;s sessions
            </h2>
          </div>
          <p className="text-xs text-muted-foreground">
            {todayRows.length} in play
          </p>
        </header>

        <Table ariaLabel="Today's Guidance sessions">
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">Time</TableHead>
              <TableHead className="px-3">Patient</TableHead>
              <TableHead className="px-3">Type</TableHead>
              <TableHead className="px-3">Booked by</TableHead>
              <TableHead className="px-3">Status</TableHead>
              <TableHead className="px-3">Live state</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableStateRows
              colSpan={7}
              isLoading={todayIsLoading}
              isError={todayIsError}
              isEmpty={todayRows.length === 0}
              onRetry={retryToday}
              pending={todayIsFetching}
              errorMessage="Failed to load today's sessions."
              loadingLabel="Loading today's sessions"
              empty={{
                title: 'Nothing on today’s schedule.',
                description:
                  'Today’s appointments and anyone checked in at the kiosk appear here as they arrive.',
              }}
            />
            {todayRows.map((row) => {
              const a = row.appointment;
              const entry = row.queue;

              // Read the clock on every render, never memoised: the queue
              // query re-polls every 10s, so the derived reading advances by
              // itself without a second timer. Revision 3 removed the column
              // that displayed it; revision 4 put it back, and it still gates
              // the outcome dropdown either way.
              const live = deriveAppointmentLiveState({ appointment: a, queue: entry });

              // The dropdown owns completion for rows that have one; the
              // Actions-column button covers the rows it cannot reach. Without
              // this split a row would show two ways to complete, or a walk-in
              // would have none.
              const hasOutcomeMenu = a !== null && canMutateSchedule;

              const sessionId = entry?.counselling_session_id ?? null;
              // Browsing reaches the **on-going** patient only — the one the
              // desk is working with. A deep link is an explicit request for
              // one named session, so it force-opens its row whatever state
              // that row is in; otherwise a notification about a finished
              // session would have nowhere to land.
              const linkedHere = selectedSessionId !== null && sessionId === selectedSessionId;
              const onGoing = entry !== null && entry.status === 'in_session' && sessionId !== null;
              const expandable = onGoing || linkedHere;
              const expanded = linkedHere || expandedKey === row.key;

              function toggle() {
                if (expanded) {
                  // Closing a deep-linked row has to clear the URL too, or the
                  // next render would simply re-open it.
                  if (linkedHere) onCloseSession?.();
                  else setExpandedKey(null);
                  return;
                }
                // Opening another row supersedes an active deep link, so two
                // panels can never be open at once.
                if (selectedSessionId !== null) onCloseSession?.();
                setExpandedKey(row.key);
              }

              return (
                <Fragment key={row.key}>
                  <TableRow
                    className={expandable ? 'cursor-pointer' : undefined}
                    onClick={expandable ? toggle : undefined}
                  >
                    <TableCell className="px-3 tabular-nums text-xs text-muted-foreground">
                      <div className="flex items-center gap-1.5">
                        {expandable && (
                          <button
                            type="button"
                            aria-expanded={expanded}
                            aria-label={`${expanded ? 'Hide' : 'Show'} sessions and notes for ${
                              a?.patient_display_name ?? entry?.display_name ?? 'this row'
                            }`}
                            className="rounded p-0.5 text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            onClick={(e) => {
                              e.stopPropagation();
                              toggle();
                            }}
                          >
                            {expanded ? (
                              <ChevronDown className="size-3.5" />
                            ) : (
                              <ChevronRight className="size-3.5" />
                            )}
                          </button>
                        )}
                        <span>
                          {a === null
                            ? '—'
                            : `${a.appointment_date} ${fmtTimeRange(a.start_time, a.end_time)}`}
                        </span>
                      </div>
                    </TableCell>
                    <PatientCell row={row} />
                    <TableCell className="px-3 text-xs">
                      {a === null ? '—' : TYPE_LABEL[a.type]}
                    </TableCell>
                    <BookedByCell row={row} />
                    <TableCell className="px-3">
                      {a === null ? (
                        <span className="text-xs text-muted-foreground">—</span>
                      ) : (
                        <Badge variant={STATUS_VARIANT[a.status]}>{titleCase(a.status)}</Badge>
                      )}
                    </TableCell>
                    <LiveStateCell live={live} />
                    <TableCell className="px-3 text-right">
                      {/* The cell swallows clicks so acting on a row never
                          toggles its drawer. */}
                      <div
                        className="flex flex-wrap items-center justify-end gap-1"
                        onClick={(e) => e.stopPropagation()}
                      >
                        {/* No em dash when a row has no queue actions: a
                            placeholder that reads like missing data sitting
                            beside real buttons looks like a broken row. The
                            appointment-only actions below still apply. */}
                        {entry !== null && (
                          <>
                            {(entry.status === 'waiting' || entry.status === 'called') && (
                              <>
                                {entry.status === 'called' && canManage && (
                                  <Button
                                    size="sm"
                                    variant="outline"
                                    aria-label={`Skip ${queueLabel(entry)}`}
                                    disabled={transition.isPending && transition.variables?.id === entry.id}
                                    onClick={() =>
                                      transition.mutate(
                                        { id: entry.id, action: 'skip' },
                                        { onSuccess: () => toast.success(`${queueLabel(entry)} skipped.`) },
                                      )
                                    }
                                  >
                                    <SkipForward className="size-3.5" /> Skip
                                  </Button>
                                )}
                                {canManage && (
                                  <Button
                                    size="sm"
                                    aria-label={`Start session for ${queueLabel(entry)}`}
                                    disabled={transition.isPending && transition.variables?.id === entry.id}
                                    onClick={() => startSession(entry)}
                                  >
                                    {transition.isPending && transition.variables?.id === entry.id ? (
                                      <Loader2 className="animate-spin" />
                                    ) : (
                                      <PlayCircle className="size-3.5" />
                                    )}
                                    Start Session
                                  </Button>
                                )}
                              </>
                            )}

                            {entry.status === 'in_session' && (
                              <>
                                {sessionId === null ? (
                                  <>
                                    <span className="text-xs text-destructive">Linked session missing.</span>
                                    {canManage && (
                                      <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={repair.isPending}
                                        onClick={() =>
                                          repair.mutate(entry.id, {
                                            onSuccess: (repaired) => {
                                              const repairedId = repaired.counselling_session_id;
                                              if (repairedId !== null && repairedId !== undefined) {
                                                toast.success(`Session #${repairedId} linked.`);
                                                setExpandedKey(row.key);
                                              }
                                            },
                                          })
                                        }
                                      >
                                        <Wrench className="size-3.5" /> Repair link
                                      </Button>
                                    )}
                                  </>
                                ) : (
                                  <Button
                                    size="sm"
                                    variant="outline"
                                    aria-expanded={expanded}
                                    onClick={toggle}
                                  >
                                    {expanded ? 'Hide Session' : 'View Session & Notes'}
                                  </Button>
                                )}
                                {/* Completion lives in the outcome dropdown
                                    for rows that have one (revision 4). This
                                    button is the fallback for the rows it
                                    cannot reach — a walk-in with no
                                    appointment, or a viewer without schedule
                                    rights — so every in-session row has exactly
                                    one way to close out, never two. */}
                                {canManage && !hasOutcomeMenu && (
                                  <Button
                                    size="sm"
                                    variant="secondary"
                                    aria-label={`Complete session for ${queueLabel(entry)}`}
                                    disabled={transition.isPending && transition.variables?.id === entry.id}
                                    onClick={() =>
                                      setConfirm({
                                        title: `Complete ${queueLabel(entry)}?`,
                                        description:
                                          'This closes the session, completes the queue entry, and completes its linked appointment. Use it when the session ran but was not written up.',
                                        confirmLabel: 'Complete session',
                                        run: () =>
                                          transition.mutate(
                                            { id: entry.id, action: 'complete' },
                                            {
                                              onSuccess: () =>
                                                toast.success(`${queueLabel(entry)} completed.`),
                                            },
                                          ),
                                      })
                                    }
                                  >
                                    <CheckCheck className="size-3.5" /> Complete Session
                                  </Button>
                                )}
                              </>
                            )}

                            {entry.status !== 'waiting' &&
                              entry.status !== 'called' &&
                              entry.status !== 'in_session' && (
                                <span className="text-xs text-muted-foreground">Closed</span>
                              )}
                          </>
                        )}

                        {/* Outcomes write to the appointment, so they need one
                            and the rights to change it. Completion rides along
                            because the desk asked for the three in one place;
                            it is gated on the queue permission separately. */}
                        {a !== null && canMutateSchedule && (
                          <OutcomeMenu
                            appointment={a}
                            live={live}
                            pending={appointmentTransition.isPending || transition.isPending}
                            canComplete={canManage}
                            onComplete={() => {
                              if (entry === null) return;
                              setConfirm({
                                title: `Complete ${queueLabel(entry)}?`,
                                description:
                                  'This closes the session, completes the queue entry, and completes its linked appointment. Use it when the session ran but was not written up — write the notes from the row first if there are any.',
                                confirmLabel: 'Complete session',
                                run: () =>
                                  transition.mutate(
                                    { id: entry.id, action: 'complete' },
                                    {
                                      onSuccess: () => toast.success(`${queueLabel(entry)} completed.`),
                                    },
                                  ),
                              });
                            }}
                            onNoShow={() =>
                              setConfirm({
                                title: `Mark appointment #${a.id} as no-show?`,
                                description:
                                  'This records a no-show, which counts toward the patient\'s three-strike counter.',
                                confirmLabel: 'Mark no-show',
                                run: () =>
                                  appointmentTransition.mutate({ id: a.id, action: 'no_show' }),
                              })
                            }
                            onCancel={() => setCancelling(a)}
                          />
                        )}
                      </div>
                    </TableCell>
                  </TableRow>

                  {expanded && (
                    <TableRow>
                      <TableCell colSpan={7} className="p-0">
                        <RowSessionPanel
                          sessionId={sessionId}
                          onClose={toggle}
                          emptyTitle="No session for this patient"
                          emptyHint="A session opens when the patient is started from the row's action."
                        />
                      </TableCell>
                    </TableRow>
                  )}
                </Fragment>
              );
            })}
          </TableBody>
        </Table>
      </section>

      {/* ------------------------------------------------------ upcoming */}
      <section
        aria-labelledby="guidance-upcoming-heading"
        className="overflow-hidden rounded-xl border bg-card"
      >
        <header className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
          <div className="flex items-center gap-2">
            <CalendarClock className="size-4 text-muted-foreground" aria-hidden />
            <h2 id="guidance-upcoming-heading" className="text-sm font-semibold text-foreground">
              Upcoming sessions
            </h2>
          </div>
          <p className="text-xs text-muted-foreground">{upcomingRows.length} booked</p>
        </header>
        <ScheduleTable
          rows={upcomingRows}
          isLoading={upcoming.isLoading}
          isError={upcoming.isError}
          isFetching={upcoming.isFetching}
          onRetry={() => void upcoming.refetch()}
          loadingLabel="Loading upcoming sessions"
          ariaLabel="Upcoming Guidance sessions"
          emptyTitle="No upcoming sessions."
          emptyDescription="Appointments booked by patients through the portal, and by the counselling team, both land here."
          hasMore={upcoming.data?.next !== null && upcoming.data?.next !== undefined}
          onViewAllAppointments={onViewAllAppointments}
        />
      </section>

      {/* ------------------------------------------------------ archived */}
      <section
        aria-labelledby="guidance-archived-heading"
        className="overflow-hidden rounded-xl border bg-card"
      >
        <header className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
          <div className="flex items-center gap-2">
            <History className="size-4 text-muted-foreground" aria-hidden />
            <h2 id="guidance-archived-heading" className="text-sm font-semibold text-foreground">
              Archived &amp; completed
            </h2>
          </div>
          <p className="text-xs text-muted-foreground">most recent first</p>
        </header>
        <ScheduleTable
          rows={archivedRows}
          isLoading={archived.isLoading}
          isError={archived.isError}
          isFetching={archived.isFetching}
          onRetry={() => void archived.refetch()}
          loadingLabel="Loading archived sessions"
          ariaLabel="Archived and completed Guidance sessions"
          emptyTitle="No archived sessions yet."
          emptyDescription="Completed, cancelled and no-show appointments collect here, newest first."
          hasMore={archived.data?.next !== null && archived.data?.next !== undefined}
          onViewAllAppointments={onViewAllAppointments}
        />
      </section>

      <Dialog open={cancelling !== null} onOpenChange={(o) => !o && setCancelling(null)}>
        {cancelling !== null && (
          <CancelAppointmentDialog appointment={cancelling} onClose={() => setCancelling(null)} />
        )}
      </Dialog>

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel ?? 'Confirm'}
        pending={appointmentTransition.isPending || transition.isPending}
        onConfirm={() => {
          if (confirm) {
            confirm.run();
            setConfirm(null);
          }
        }}
        onCancel={() => setConfirm(null)}
      />
    </div>
  );
}
