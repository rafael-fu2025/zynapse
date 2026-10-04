/**
 * Skip-window helpers — the countdown and status copy for the Skipped
 * Patients module (October 2026 panel revision).
 *
 * The backend owns the deadline (`clinic_queue_entries.skip_deadline_at`,
 * UTC) and the resolution sweep; this module only RENDERS the remainder.
 * That split is deliberate: a frontend-only timer would reset on reload
 * and could not fire when nobody has the page open, which is exactly the
 * failure the panel called out.
 *
 * `SKIP_WINDOW_MINUTES` mirrors `QueueService::SKIP_WINDOW_MINUTES` and
 * the `QueueSkipWindow` migration — `QueueSkipWindowContractTest`
 * asserts all three agree.
 *
 * Pure functions only (no React, no clock capture at module scope) so
 * the countdown math is unit-testable in the node-environment Vitest
 * runner, mirroring `bmgFormat.ts` / `queueFormat.ts`.
 */
import { parseUtc } from '@/utils/date';

/**
 * Recall grace window, in minutes. Used as the fallback when a row
 * arrives without a stored deadline (should not happen — skip always
 * stamps one — but the UI must not render `NaN:NaN` if it does).
 */
export const SKIP_WINDOW_MINUTES = 60;

/** Status values the Skipped Patients feed can return. */
export const SKIP_STATUSES = ['skipped', 'returned', 'no_show'] as const;
export type SkipStatus = (typeof SKIP_STATUSES)[number];

/**
 * Remaining milliseconds until `deadlineUtc`, floored at 0. Returns
 * `null` when the deadline is missing/unparseable, so callers can
 * render a dash instead of a bogus countdown.
 *
 * `now` is injectable for tests; production passes nothing and reads
 * the clock at call time (never at module scope — a captured module
 * clock would freeze the countdown for the lifetime of the tab).
 */
export function remainingMs(deadlineUtc: string | null | undefined, now: Date = new Date()): number | null {
  if (deadlineUtc === null || deadlineUtc === undefined || deadlineUtc === '') return null;
  const deadline = parseUtc(deadlineUtc);
  if (Number.isNaN(deadline.getTime())) return null;
  return Math.max(0, deadline.getTime() - now.getTime());
}

/**
 * `59:42` / `1:00:00` — the countdown format the panel asked for
 * ("easy-to-read format, such as `59:42 remaining`"). Hours only appear
 * once the remainder exceeds 60 minutes, which the 60-minute window
 * never does — the branch exists so a longer window (or clock skew)
 * still renders sanely.
 */
export function formatRemaining(ms: number | null): string {
  if (ms === null) return '—';
  const totalSeconds = Math.floor(ms / 1000);
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  const pad = (n: number): string => String(n).padStart(2, '0');
  return hours > 0 ? `${hours}:${pad(minutes)}:${pad(seconds)}` : `${pad(minutes)}:${pad(seconds)}`;
}

/** `59:42 remaining`, or `Expired` once the window has lapsed. */
export function remainingLabel(deadlineUtc: string | null | undefined, now: Date = new Date()): string {
  const ms = remainingMs(deadlineUtc, now);
  if (ms === null) return '—';
  return ms === 0 ? 'Expired' : `${formatRemaining(ms)} remaining`;
}

/**
 * Minutes left, rounded UP, for the compact queue-table hint
 * (`~42m left`). Zero once expired.
 */
export function remainingMinutes(deadlineUtc: string | null | undefined, now: Date = new Date()): number | null {
  const ms = remainingMs(deadlineUtc, now);
  if (ms === null) return null;
  return Math.ceil(ms / 60_000);
}

/**
 * Progress through the window, 0 → 1, for the module's progress bar.
 * `null` when the deadline is unusable.
 */
export function windowProgress(deadlineUtc: string | null | undefined, now: Date = new Date()): number | null {
  const ms = remainingMs(deadlineUtc, now);
  if (ms === null) return null;
  const total = SKIP_WINDOW_MINUTES * 60_000;
  return Math.min(1, Math.max(0, ms / total));
}

/**
 * Badge tone per status, matching the panel's spec:
 *   `Skipped`  — neutral / yellow (still inside the window)
 *   `Returned` — blue
 *   `No-Show`  — red
 * Mapped to the shared `Badge` variants so the module inherits the
 * existing dark-theme tokens instead of hardcoding colours.
 *
 * Takes `string` (not `SkipStatus`) because the feed's `status` column
 * is deliberately open-ended: the backend may add a resolution state
 * before the SPA knows about it, and an unknown value must render a
 * neutral badge rather than crash the module.
 */
export function skipStatusVariant(status: string): 'warning' | 'info' | 'destructive' | 'secondary' {
  switch (status) {
    case 'skipped':
      return 'warning';
    case 'returned':
      return 'info';
    case 'no_show':
      return 'destructive';
    default:
      return 'secondary';
  }
}

/** `Skipped` / `Returned` / `No-Show` — the badge copy. */
export function skipStatusLabel(status: string): string {
  switch (status) {
    case 'skipped':
      return 'Skipped';
    case 'returned':
      return 'Returned';
    case 'no_show':
      return 'No-Show';
    default:
      return status;
  }
}

/** A row is actionable only while it is still inside an open window. */
export function isActionable(status: string): boolean {
  return status === 'skipped';
}
