/**
 * Derived live state for a Guidance board row (2026-09-23).
 *
 * The desk asked for the Today's Sessions board to move on its own: a patient
 * should read as **checked in** once their slot starts and **checked out** once
 * it ends, and a slot that ends with nothing written in Sessions & Notes should
 * surface as a **no-show** so staff can act on it.
 *
 * The deliberate scope decision: this is **display only**. Nothing here writes.
 * `no_show_due` is an *offer* — the row says "this looks like a no-show" and a
 * staff member confirms it from the row's dropdown. There is no background
 * sweep and no automatic strike against the patient's counter, so a display
 * bug can never silently penalise a student. That is why every descriptor
 * carries `derived`: a derived state is a claim, not a record.
 *
 * Two inputs, one truth table:
 *
 *   - the appointment (the book) — supplies the window and the terminal
 *     statuses (completed / cancelled / no_show)
 *   - the linked queue entry (the live desk) — supplies whether anyone is
 *     actually here, and whether a session ever ran
 *
 * Everything is compared on the **app-timezone wall clock**, never a raw UTC
 * date. `appointment_date` + `start_time`/`end_time` are app-timezone wall
 * values (see `appDateTimeToUtcSql`), so "now" is formatted into the same
 * shape and compared as a string. That sidesteps zone arithmetic entirely and
 * keeps the day boundary on the Manila clock — the one thing this module has
 * regressed on before.
 *
 * Known limitation, stated rather than hidden: the board does not fetch each
 * session, so "was anything written?" is approximated by "did a session ever
 * start" (`started_at` / `in_session`). A session that started but has zero
 * notes still reads as activity. `SessionWorkspace` has the real `note_count`
 * once a row is expanded; promoting it to the badge would cost one request per
 * row, which is not worth it for a display hint staff confirm by hand anyway.
 */
import { formatInTimeZone } from 'date-fns-tz';
import type { AppointmentStatus } from '@/schemas/schedule';
import { useAuthStore } from '@/store/auth';

const DEFAULT_TZ = 'Asia/Manila';

/** Badge variants the shared `<Badge>` understands. */
export type LiveStateVariant =
  | 'secondary'
  | 'info'
  | 'success'
  | 'outline'
  | 'destructive'
  | 'warning';

/**
 * The live states a board row can be in. Terminal appointment statuses keep
 * their own names so the board and the Appointments table agree on wording.
 */
export const LIVE_STATES = [
  'scheduled',
  'checked_in',
  'in_session',
  'checked_out',
  'no_show_due',
  'completed',
  'no_show',
  'cancelled',
  'skipped',
] as const;
export type LiveState = (typeof LIVE_STATES)[number];

/** Structural input — an `Appointment` satisfies this as-is. */
export interface LiveStateAppointment {
  status: AppointmentStatus;
  appointment_date: string;
  start_time: string;
  end_time: string;
}

/** Structural input — a `GuidanceQueueEntry` satisfies this as-is. */
export interface LiveStateQueueEntry {
  status: 'waiting' | 'called' | 'in_session' | 'done' | 'skipped';
  // `| undefined` is explicit because `exactOptionalPropertyTypes` is on:
  // the zod-derived entry types carry `undefined` on these keys, and a bare
  // `?:` would refuse them.
  started_at?: string | null | undefined;
  finished_at?: string | null | undefined;
  counselling_session_id?: number | null | undefined;
}

export interface LiveStateInput {
  appointment?: LiveStateAppointment | null;
  queue?: LiveStateQueueEntry | null;
  /** Injectable clock — tests pin this; callers omit it. */
  now?: Date;
  /** Injectable zone — defaults to the signed-in user's, then Manila. */
  timezone?: string;
}

/** Which staff-confirmed outcomes this row can still take. */
export interface LiveStateActions {
  /** Offer "No-show" — live appointment, window over, nothing written. */
  canMarkNoShow: boolean;
  /** Offer "Cancel Appointment" — live appointment, not yet resolved. */
  canCancel: boolean;
  /** Offer "Complete Session" — a session is open and can be closed. */
  canComplete: boolean;
}

export interface LiveStateDescriptor {
  state: LiveState;
  /** Badge text. */
  label: string;
  variant: LiveStateVariant;
  /**
   * True when the state was inferred from the clock rather than recorded by
   * staff. The UI must present derived states as provisional.
   */
  derived: boolean;
  /** The appointment's window has already ended on the app clock. */
  windowElapsed: boolean;
  /** A session actually ran for this slot (someone was here). */
  hasActivity: boolean;
  /** Short qualifier for tooltips / `aria-description`. */
  hint: string;
  actions: LiveStateActions;
}

const LABEL: Record<LiveState, string> = {
  scheduled: 'Scheduled',
  checked_in: 'Checked in',
  in_session: 'In session',
  checked_out: 'Checked out',
  no_show_due: 'No-show',
  completed: 'Completed',
  no_show: 'No-show',
  cancelled: 'Cancelled',
  skipped: 'Skipped',
};

const VARIANT: Record<LiveState, LiveStateVariant> = {
  scheduled: 'secondary',
  checked_in: 'info',
  in_session: 'success',
  checked_out: 'warning',
  // Derived, so it is tinted like a no-show but qualified in the hint.
  no_show_due: 'destructive',
  completed: 'success',
  no_show: 'destructive',
  cancelled: 'outline',
  skipped: 'outline',
};

const HINT: Record<LiveState, string> = {
  scheduled: 'Booked — the slot has not started yet.',
  checked_in: 'The slot has started.',
  in_session: 'A session is open for this patient.',
  checked_out: 'The slot has ended and a session ran — wrap it up.',
  no_show_due: 'The slot ended with nothing written. Not confirmed yet.',
  completed: 'This appointment is closed.',
  no_show: 'Recorded as a no-show.',
  cancelled: 'This appointment was cancelled.',
  skipped: 'This queue entry was skipped.',
};

const LIVE_STATUSES: readonly AppointmentStatus[] = ['scheduled', 'confirmed'];

/**
 * States that are already resolved. Nothing may be confirmed on a row the
 * badge calls closed — the actions and the badge have to agree, or the desk
 * gets a "Completed" row that still offers to cancel it.
 */
const TERMINAL_STATES: ReadonlySet<LiveState> = new Set<LiveState>([
  'completed',
  'no_show',
  'cancelled',
  'skipped',
]);

/** `HH:MM` → `HH:MM:SS`, so string comparison against a formatted clock is safe. */
function withSeconds(time: string): string {
  return time.length === 5 ? `${time}:00` : time;
}

/** `now` as the app timezone's wall clock, shaped like the appointment columns. */
function wallClock(now: Date, timezone: string): string {
  return formatInTimeZone(now, timezone, 'yyyy-MM-dd HH:mm:ss');
}

function describe(
  state: LiveState,
  fields: { derived?: boolean; windowElapsed?: boolean; hasActivity?: boolean },
): LiveStateDescriptor {
  const windowElapsed = fields.windowElapsed ?? false;
  const hasActivity = fields.hasActivity ?? false;

  return {
    state,
    label: LABEL[state],
    variant: VARIANT[state],
    derived: fields.derived ?? false,
    windowElapsed,
    hasActivity,
    hint: HINT[state],
    actions: {
      canMarkNoShow: false,
      canCancel: false,
      canComplete: false,
    },
  };
}

/**
 * Resolve the live state of one board row.
 *
 * Precedence, and why:
 *   1. **Terminal appointment status wins.** Once the book says cancelled /
 *      no-show / completed, no amount of clock-reading should contradict it —
 *      the record is the record.
 *   2. **The queue entry is ground truth for "is anyone here".** A real
 *      check-in beats an inference, so a live entry is consulted before the
 *      clock is.
 *   3. **Only then does the clock speak.** With no entry at all, a slot that
 *      has started reads checked-in and a slot that has ended reads
 *      no-show — both derived, both awaiting staff confirmation.
 *
 * Pass `null` for both inputs and you get a neutral `scheduled` descriptor
 * rather than a throw; this runs during render and must not blow up a board.
 */
export function deriveAppointmentLiveState(input: LiveStateInput = {}): LiveStateDescriptor {
  const appointment = input.appointment ?? null;
  const queue = input.queue ?? null;
  const timezone = input.timezone ?? useAuthStore.getState().timezone ?? DEFAULT_TZ;
  const now = input.now ?? new Date();

  // ---- 1. the record is the record -----------------------------------
  if (appointment !== null) {
    if (appointment.status === 'cancelled') return describe('cancelled', {});
    if (appointment.status === 'no_show') return describe('no_show', {});
    if (appointment.status === 'completed') return describe('completed', {});
  }

  // ---- the window, on the app clock ----------------------------------
  const stamp = wallClock(now, timezone);
  const windowElapsed =
    appointment !== null &&
    stamp >= `${appointment.appointment_date} ${withSeconds(appointment.end_time)}`;
  const windowStarted =
    appointment !== null &&
    stamp >= `${appointment.appointment_date} ${withSeconds(appointment.start_time)}`;

  // A session ran iff someone actually started one. A bare link is not
  // evidence — links can be repaired without a session having happened.
  const hasActivity =
    queue !== null &&
    (queue.started_at != null || queue.status === 'in_session' || queue.status === 'done');

  const isLiveAppointment =
    appointment !== null && LIVE_STATUSES.includes(appointment.status);

  /** Shared tail: attach the action affordances every state computes the same way. */
  function finish(descriptor: LiveStateDescriptor): LiveStateDescriptor {
    // Derived from the *resolved* state, not the raw appointment status: the
    // desk can close a queue entry before the book catches up, and a row that
    // reads "Completed" must not offer to cancel itself.
    const actionable = !TERMINAL_STATES.has(descriptor.state);

    return {
      ...descriptor,
      actions: {
        // Only offered once the slot is over and nothing was written — the
        // exact condition the badge is claiming. Staff confirm; we never write.
        canMarkNoShow: actionable && isLiveAppointment && windowElapsed && !hasActivity,
        canCancel: actionable && isLiveAppointment,
        canComplete:
          queue !== null &&
          queue.status === 'in_session' &&
          queue.counselling_session_id != null,
      },
    };
  }

  // ---- 2. the live desk ----------------------------------------------
  if (queue !== null) {
    if (queue.status === 'skipped') {
      return finish(describe('skipped', { windowElapsed, hasActivity }));
    }
    if (queue.status === 'done') {
      // The desk finished the work even if the book has not caught up yet.
      return finish(describe('completed', { windowElapsed, hasActivity }));
    }
    if (queue.status === 'in_session') {
      // Past the end of the slot with a session still open: the desk asked for
      // exactly this nudge — "checked out", time to close it.
      return finish(
        describe(windowElapsed ? 'checked_out' : 'in_session', {
          windowElapsed,
          hasActivity,
        }),
      );
    }
    // waiting | called
    return finish(describe('checked_in', { windowElapsed, hasActivity }));
  }

  // ---- 3. the clock, when nobody checked in --------------------------
  if (appointment !== null) {
    if (!windowStarted) return finish(describe('scheduled', { windowElapsed }));
    if (!windowElapsed) {
      // Auto check-in: the slot is running and no entry was opened.
      return finish(describe('checked_in', { derived: true, windowElapsed }));
    }
    // Auto no-show: the slot is over and nothing was written. Display only.
    return finish(describe('no_show_due', { derived: true, windowElapsed }));
  }

  // Neither side supplied — neutral, non-committal.
  return finish(describe('scheduled', {}));
}
