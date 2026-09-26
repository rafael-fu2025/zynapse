/**
 * Date utility — API returns UTC ISO strings, UI renders in Asia/Manila.
 *
 * Centralized so we never accidentally render UTC directly.
 */
import { format, formatDistanceToNow, isValid, parseISO } from 'date-fns';
import { formatInTimeZone, fromZonedTime } from 'date-fns-tz';
import { useAuthStore } from '@/store/auth';

const DEFAULT_TZ = 'Asia/Manila';

/**
 * API timestamps are MySQL `YYYY-MM-DD HH:mm:ss` in UTC with NO zone
 * designator — `parseISO` would read them as LOCAL time and skew every
 * rendered date. Normalize to an explicit UTC instant first.
 * Exported for callers that need the INSTANT (e.g. day-bucketing
 * comparisons), not just a formatted string.
 */
export function parseUtc(isoUtc: string): Date {
  const hasZone = /[zZ]$|[+-]\d{2}:?\d{2}$/.test(isoUtc);
  return parseISO(hasZone ? isoUtc : isoUtc.replace(' ', 'T') + 'Z');
}

export function nowInAppTz(): string {
  return formatInTimeZone(new Date(), useAuthStore.getState().timezone, 'MMM d, yyyy · h:mm:ss a zzz');
}

/**
 * Wall-clock parts in the app timezone (Manila), for day-boundary logic:
 * `date` is `yyyy-MM-dd` and `time` is `HH:mm`. Comparisons against
 * DATE/TIME columns (counselling stores Manila wall time) must use these,
 * never the host clock — the raw `new Date()` day boundary is wrong for
 * anyone east of Manila.
 */
export function appNowParts(): { date: string; time: string } {
  const tz = useAuthStore.getState().timezone ?? DEFAULT_TZ;
  const now = new Date();
  return {
    date: formatInTimeZone(now, tz, 'yyyy-MM-dd'),
    time: formatInTimeZone(now, tz, 'HH:mm'),
  };
}

/**
 * Single display contract for date-times surfaced to end-users
 * (panel revision: non-IT staff found `yyyy-MM-dd HH:mm` unreadable).
 * Default renders in the app's timezone as e.g. `Aug 1, 2026 · 6:37 AM`
 * (12-hour clock, short month name, middle-dot separator). Pass an
 * explicit pattern for the rare surface that needs a different shape
 * (ISO date inputs, compact lists, or surfaces that need the zone).
 */
export function fmtUtcToApp(isoUtc: string, pattern = 'MMM d, yyyy · h:mm a'): string {
  return formatInTimeZone(parseUtc(isoUtc), useAuthStore.getState().timezone ?? DEFAULT_TZ, pattern);
}

export function fmtRelative(isoUtc: string): string {
  return formatDistanceToNow(parseUtc(isoUtc), { addSuffix: true });
}

/**
 * `yyyy-MM-dd` in the APP timezone — never the host zone. date-fns' bare
 * `format()` renders in the host locale, which silently shifted the day
 * boundary for anyone whose OS clock isn't set to Asia/Manila (caught by
 * CI, where the runner runs UTC). The two call sites render `DATE`-column
 * values; parsing those as UTC midnight and formatting in the (east-of-UTC)
 * app zone preserves the calendar day while instants roll over correctly.
 */
export function fmtShort(isoUtc: string): string {
  return formatInTimeZone(parseUtc(isoUtc), useAuthStore.getState().timezone ?? DEFAULT_TZ, 'yyyy-MM-dd');
}

/**
 * Render a date-only string from a MySQL `DATE` column (e.g. process-log
 * `log_date` or batch `expected_completion_date`) as a human-readable
 * date like `Aug 1, 2026` in the app timezone. The bare `YYYY-MM-DD`
 * is treated as midnight in the app timezone (NOT UTC) so the calendar
 * day never shifts across tz boundaries.
 */
export function fmtHumanDate(ymd: string): string {
  const tz = useAuthStore.getState().timezone ?? DEFAULT_TZ;
  return formatInTimeZone(fromZonedTime(`${ymd} 00:00:00`, tz), tz, 'MMM d, yyyy');
}

/** `HH:mm`, with optional `:ss` and optional single-digit hour. */
const WALL_CLOCK = /^(\d{1,2}):(\d{2})/;

/**
 * Render a wall-clock time (`HH:mm` or `HH:mm:ss`) on a **12-hour** clock —
 * `9:00 AM`, `2:30 PM`.
 *
 * These values are not UTC instants. Columns like `counselling_availability`
 * `.start_time` or `clinic_staff_schedules.shift_start` are app-timezone
 * wall-clock, so this deliberately does **no** zone conversion: it reads the
 * hour and minute and formats them. Routing them through `parseUtc` would
 * shift every one of them by the offset.
 *
 * The clinic runs on a 12-hour clock (2026-09-23): `HH:mm` was being rendered
 * raw via `slice(0, 5)`, which reads as military time to the staff using it.
 *
 * Anything that is not a well-formed time is returned **untouched** rather
 * than rendered as `Invalid Date` or, worse, as a plausible wrong value. That
 * matters for a missing `end_time`: coercing `''` would produce `12:00 AM` and
 * state a time nobody entered.
 */
export function fmtClock(hhmm: string): string {
  const match = hhmm.trim().match(WALL_CLOCK);
  if (match === null) return hhmm;
  const hour = Number(match[1]);
  const minute = Number(match[2]);
  if (hour > 23 || minute > 59) return hhmm;
  // Constructed in local time and formatted in local time, so the hour and
  // minute survive intact whatever zone the host (or CI runner) is in.
  return format(new Date(2000, 0, 1, hour, minute), 'h:mm a');
}

/**
 * `9:00 AM – 10:30 AM` for a start/end wall-clock pair. The spaced en dash is
 * deliberate: with meridiem suffixes attached, the unspaced form this replaced
 * (`09:00–10:30`) collapses into an unreadable run.
 */
export function fmtTimeRange(start: string, end: string): string {
  return `${fmtClock(start)} – ${fmtClock(end)}`;
}

/**
 * Inverse of fmtUtcToApp for form inputs: compose an app-timezone local
 * date (`YYYY-MM-DD`) + time (`HH:mm` or `HH:mm:ss`) into the UTC MySQL
 * string (`YYYY-MM-DD HH:mm:ss`) the API expects. Lets the user think in
 * Asia/Manila while the payload stays UTC.
 */
export function appDateTimeToUtcSql(dateYmd: string, timeHhMm: string): string {
  const tz = useAuthStore.getState().timezone ?? DEFAULT_TZ;
  const time = timeHhMm.length === 5 ? `${timeHhMm}:00` : timeHhMm;
  const utcInstant = fromZonedTime(`${dateYmd} ${time}`, tz);
  return formatInTimeZone(utcInstant, 'UTC', 'yyyy-MM-dd HH:mm:ss');
}

/**
 * Split a UTC timestamp into app-timezone `date` (`YYYY-MM-DD`) and
 * `time` (`HH:mm`) parts for seeding the date/time pickers on edit.
 */
export function utcSqlToAppParts(isoUtc: string): { date: string; time: string } {
  const tz = useAuthStore.getState().timezone ?? DEFAULT_TZ;
  const instant = parseUtc(isoUtc);
  return {
    date: formatInTimeZone(instant, tz, 'yyyy-MM-dd'),
    time: formatInTimeZone(instant, tz, 'HH:mm'),
  };
}

/**
 * `Aug 2026 – Dec 2026` when a range covers whole calendar months — the
 * span the report date picker shows for month-span selections. Returns
 * `null` for day-precision ranges so the caller falls back to the
 * explicit `from to to` rendering. A single whole month reads `Sep 2026`.
 * Calendar-only (YMD strings), so no timezone is involved.
 */
export function wholeMonthSpanLabel(startYmd: string, endYmd: string): string | null {
  const start = parseISO(startYmd);
  const end = parseISO(endYmd);
  if (!isValid(start) || !isValid(end) || start.getDate() !== 1) return null;

  const lastDayOfEndMonth = new Date(end.getFullYear(), end.getMonth() + 1, 0).getDate();
  if (end.getDate() !== lastDayOfEndMonth) return null;

  const startLabel = format(start, 'LLL yyyy');
  const endLabel = format(end, 'LLL yyyy');
  return startLabel === endLabel ? startLabel : `${startLabel} – ${endLabel}`;
}