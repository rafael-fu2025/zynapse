/**
 * Small date/format helpers shared by the inventory badges, dialogs,
 * and tabs.
 */
import { fmtUtcToApp } from '@/utils/date';

/** Days until a date (negative = past). */
export function daysUntil(date: string): number {
  return Math.ceil((new Date(date).getTime() - Date.now()) / 864e5);
}

/**
 * Small date helpers — kept inline to avoid pulling dayjs/luxon for
 * this one display. Returns strings like "2d ago", "3h ago", "just now".
 */
export function fmtRelativeFromNow(iso: string): string {
  // API timestamps are MySQL `YYYY-MM-DD HH:mm:ss` in UTC with NO zone
  // designator — `new Date` would read them as LOCAL time and skew the
  // offset by the tz difference (e.g. "8h ago" for "just now" in
  // Asia/Manila). Normalize to an explicit UTC instant first, mirroring
  // `parseUtc` in utils/date.ts (which the ledger uses).
  const hasZone = /[zZ]$|[+-]\d{2}:?\d{2}$/.test(iso);
  const then = new Date(hasZone ? iso : iso.replace(' ', 'T') + 'Z').getTime();
  if (Number.isNaN(then)) return '';
  const diffMs = Date.now() - then;
  const diffSec = Math.round(diffMs / 1000);
  if (diffSec < 60) return 'just now';
  const diffMin = Math.round(diffSec / 60);
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHr = Math.round(diffMin / 60);
  if (diffHr < 24) return `${diffHr}h ago`;
  const diffDay = Math.round(diffHr / 24);
  if (diffDay < 7) return `${diffDay}d ago`;
  const diffWk = Math.round(diffDay / 7);
  if (diffWk < 5) return `${diffWk}w ago`;
  // Beyond a month — show the local date the way the rest of the app
  // formats it (UTC + Asia/Manila default).
  try { return fmtUtcToApp(iso); } catch { return ''; }
}

export function initialsFromEmail(email: string): string {
  const local = email.split('@')[0] ?? email;
  const parts = local.split(/[._-]/);
  if (parts.length >= 2 && parts[0] !== undefined && parts[1] !== undefined) {
    return ((parts[0][0] ?? '') + (parts[1][0] ?? '')).toUpperCase();
  }
  return local.slice(0, 2).toUpperCase();
}
