/**
 * Date utility — API returns UTC ISO strings, UI renders in Asia/Manila.
 *
 * Centralized so we never accidentally render UTC directly.
 */
import { format, formatDistanceToNow, parseISO } from 'date-fns';
import { formatInTimeZone, fromZonedTime } from 'date-fns-tz';
import { useAuthStore } from '@/store/auth';

const DEFAULT_TZ = 'Asia/Manila';

/**
 * API timestamps are MySQL `YYYY-MM-DD HH:mm:ss` in UTC with NO zone
 * designator — `parseISO` would read them as LOCAL time and skew every
 * rendered date. Normalize to an explicit UTC instant first.
 */
function parseUtc(isoUtc: string): Date {
  const hasZone = /[zZ]$|[+-]\d{2}:?\d{2}$/.test(isoUtc);
  return parseISO(hasZone ? isoUtc : isoUtc.replace(' ', 'T') + 'Z');
}

export function nowInAppTz(): string {
  return formatInTimeZone(new Date(), useAuthStore.getState().timezone, 'MMM d, yyyy · h:mm:ss a zzz');
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

export function fmtShort(isoUtc: string): string {
  return format(parseUtc(isoUtc), 'yyyy-MM-dd');
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