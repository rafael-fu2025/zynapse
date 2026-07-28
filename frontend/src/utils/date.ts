/**
 * Date utility — API returns UTC ISO strings, UI renders in Asia/Manila.
 *
 * Centralized so we never accidentally render UTC directly.
 */
import { format, formatDistanceToNow, parseISO } from 'date-fns';
import { formatInTimeZone } from 'date-fns-tz';
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
  return formatInTimeZone(new Date(), useAuthStore.getState().timezone, 'yyyy-MM-dd HH:mm:ss zzz');
}

export function fmtUtcToApp(isoUtc: string, pattern = 'yyyy-MM-dd HH:mm zzz'): string {
  return formatInTimeZone(parseUtc(isoUtc), useAuthStore.getState().timezone ?? DEFAULT_TZ, pattern);
}

export function fmtRelative(isoUtc: string): string {
  return formatDistanceToNow(parseUtc(isoUtc), { addSuffix: true });
}

export function fmtShort(isoUtc: string): string {
  return format(parseUtc(isoUtc), 'yyyy-MM-dd');
}