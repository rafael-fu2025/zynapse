/**
 * Skip-window countdown math + status copy — the pure half of the
 * Skipped Patients module.
 *
 * The backend owns the deadline and the resolution sweep; these tests
 * pin the rendering contract (the `59:42 remaining` format the panel
 * asked for, the badge tones, and the actionable-window predicate) so a
 * refactor cannot quietly reintroduce a frontend-only timer or drift
 * the copy.
 */
import { describe, expect, it } from 'vitest';
import {
  SKIP_WINDOW_MINUTES,
  formatRemaining,
  isActionable,
  remainingLabel,
  remainingMinutes,
  remainingMs,
  skipStatusLabel,
  skipStatusVariant,
  windowProgress,
} from './queueSkip';

/** A fixed instant so the assertions never depend on the wall clock. */
const NOW = new Date('2026-10-03T02:00:00Z');

/** UTC string `$minutes` after NOW — the shape the API returns. */
function deadlineAfter(minutes: number): string {
  const d = new Date(NOW.getTime() + minutes * 60_000);
  return d.toISOString().replace('T', ' ').replace(/\.\d+Z$/, '');
}

describe('remainingMs', () => {
  it('returns the milliseconds left before the deadline', () => {
    expect(remainingMs(deadlineAfter(30), NOW)).toBe(30 * 60_000);
  });

  it('floors at zero once the window has lapsed', () => {
    expect(remainingMs(deadlineAfter(-5), NOW)).toBe(0);
  });

  it('returns null for a missing or unparseable deadline', () => {
    expect(remainingMs(null, NOW)).toBeNull();
    expect(remainingMs(undefined, NOW)).toBeNull();
    expect(remainingMs('', NOW)).toBeNull();
    expect(remainingMs('not-a-date', NOW)).toBeNull();
  });

  it('reads the deadline as UTC, not local time', () => {
    // A naive `new Date(...)` would interpret this as local time and
    // skew every countdown by the host offset — the bug the whole
    // `parseUtc` helper exists to prevent.
    const ms = remainingMs('2026-10-03 02:01:00', NOW);
    expect(ms).toBe(60_000);
  });
});

describe('formatRemaining', () => {
  it('renders the mm:ss format from the panel example', () => {
    expect(formatRemaining(59 * 60_000 + 42_000)).toBe('59:42');
    expect(formatRemaining(30 * 60_000)).toBe('30:00');
    expect(formatRemaining(60_000)).toBe('01:00');
    expect(formatRemaining(0)).toBe('00:00');
  });

  it('adds an hours field only when the remainder exceeds an hour', () => {
    expect(formatRemaining(60 * 60_000)).toBe('1:00:00');
  });

  it('renders a dash when there is no deadline', () => {
    expect(formatRemaining(null)).toBe('—');
  });
});

describe('remainingLabel', () => {
  it('appends the "remaining" suffix', () => {
    expect(remainingLabel(deadlineAfter(30), NOW)).toBe('30:00 remaining');
  });

  it('switches to Expired at and past zero', () => {
    expect(remainingLabel(deadlineAfter(0), NOW)).toBe('Expired');
    expect(remainingLabel(deadlineAfter(-1), NOW)).toBe('Expired');
  });
});

describe('remainingMinutes', () => {
  it('rounds UP so a part-minute still shows as time left', () => {
    expect(remainingMinutes(deadlineAfter(41.5), NOW)).toBe(42);
  });

  it('is zero, not null, once expired', () => {
    expect(remainingMinutes(deadlineAfter(-1), NOW)).toBe(0);
  });
});

describe('windowProgress', () => {
  it('is 1 at the start of the window and 0 at the end', () => {
    expect(windowProgress(deadlineAfter(SKIP_WINDOW_MINUTES), NOW)).toBe(1);
    expect(windowProgress(deadlineAfter(0), NOW)).toBe(0);
    expect(windowProgress(deadlineAfter(SKIP_WINDOW_MINUTES / 2), NOW)).toBe(0.5);
  });
});

describe('status presentation', () => {
  it('maps each status to the panel-specified tone', () => {
    expect(skipStatusVariant('skipped')).toBe('warning');
    expect(skipStatusVariant('returned')).toBe('info');
    expect(skipStatusVariant('no_show')).toBe('destructive');
  });

  it('labels statuses in the panel wording', () => {
    expect(skipStatusLabel('skipped')).toBe('Skipped');
    expect(skipStatusLabel('returned')).toBe('Returned');
    expect(skipStatusLabel('no_show')).toBe('No-Show');
  });

  it('only treats an open window as actionable', () => {
    expect(isActionable('skipped')).toBe(true);
    expect(isActionable('returned')).toBe(false);
    expect(isActionable('no_show')).toBe(false);
  });
});

describe('SKIP_WINDOW_MINUTES', () => {
  it('is the 60 minutes the panel specified', () => {
    // Mirrors QueueService::SKIP_WINDOW_MINUTES and the migration —
    // the backend contract test asserts the three-way parity.
    expect(SKIP_WINDOW_MINUTES).toBe(60);
  });
});
