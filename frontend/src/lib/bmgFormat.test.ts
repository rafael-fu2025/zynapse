import { describe, expect, it } from 'vitest';

import {
  describeBatchPhase,
  describeEta,
  describeTurning,
  formatDayCount,
  formatDaysAgo,
  formatKg,
  formatProgress,
} from './bmgFormat';

/**
 * The drum-card wording rules.
 *
 * These strings were open-coded in `DrumCard` and then copied verbatim
 * into `DrumDetailPage`, which is how the two surfaces could drift. The
 * tests pin the wording AND the tone, because the tone is what decides
 * whether an operator's eye is drawn to a drum at all.
 */
describe('formatDayCount', () => {
  it('pluralises only the singular', () => {
    expect(formatDayCount(0)).toBe('0 days');
    expect(formatDayCount(1)).toBe('1 day');
    expect(formatDayCount(2)).toBe('2 days');
  });
});

describe('formatDaysAgo', () => {
  it('reads as elapsed time', () => {
    expect(formatDaysAgo(1)).toBe('1 day ago');
    expect(formatDaysAgo(6)).toBe('6 days ago');
  });
});

describe('describeEta', () => {
  it('counts down while the batch is on track', () => {
    expect(describeEta(5)).toEqual({ label: 'in 5 days', tone: 'muted' });
    expect(describeEta(1)).toEqual({ label: 'in 1 day', tone: 'muted' });
  });

  it('escalates on the due date', () => {
    expect(describeEta(0)).toEqual({ label: 'Due today', tone: 'warning' });
  });

  it('reports an absolute count once overdue', () => {
    expect(describeEta(-1)).toEqual({ label: '1 day overdue', tone: 'danger' });
    expect(describeEta(-4)).toEqual({ label: '4 days overdue', tone: 'danger' });
  });

  it('degrades quietly when the date is unknown', () => {
    expect(describeEta(null)).toEqual({ label: '—', tone: 'muted' });
    expect(describeEta(undefined)).toEqual({ label: '—', tone: 'muted' });
  });
});

describe('describeTurning', () => {
  it('flags a batch that has never been turned', () => {
    // A drum in the processing list is one the operator is already
    // turning, so "never" is an exception worth surfacing.
    expect(describeTurning(null, 4)).toEqual({
      label: 'Never turned',
      tone: 'warning',
    });
    expect(describeTurning(undefined, 4)).toEqual({
      label: 'Never turned',
      tone: 'warning',
    });
  });

  it('is neutral on the day of a turn', () => {
    expect(describeTurning(0, 4)).toEqual({ label: 'Turned today', tone: 'neutral' });
  });

  it('stays muted inside the cadence', () => {
    expect(describeTurning(3, 4)).toEqual({ label: 'Turned 3 days ago', tone: 'muted' });
  });

  it('flags once the cadence is exceeded', () => {
    // Strictly greater than, matching BmgAlertEngine's own comparison.
    expect(describeTurning(4, 4)).toEqual({ label: 'Turned 4 days ago', tone: 'muted' });
    expect(describeTurning(5, 4)).toEqual({ label: 'Turned 5 days ago', tone: 'warning' });
  });

  it('takes the threshold from the caller so it cannot drift from the alert rule', () => {
    // No threshold loaded yet: staleness is unjudgeable, so only the
    // never-turned case is called out.
    expect(describeTurning(30, null)).toEqual({
      label: 'Turned 30 days ago',
      tone: 'muted',
    });
  });
});

describe('formatKg', () => {
  it('always shows two decimals and the unit', () => {
    expect(formatKg(100)).toBe('100.00 kg');
    expect(formatKg(12.5)).toBe('12.50 kg');
  });

  it('degrades to an em dash rather than NaN', () => {
    expect(formatKg(null)).toBe('—');
    expect(formatKg(undefined)).toBe('—');
    expect(formatKg(Number.NaN)).toBe('—');
  });
});

describe('formatProgress', () => {
  it('rounds the label and keeps the exact value for the track width', () => {
    expect(formatProgress(42.4)).toEqual({ value: 42.4, label: '42%' });
    expect(formatProgress(42.6)).toEqual({ value: 42.6, label: '43%' });
  });

  it('clamps so a stray value cannot overflow the track', () => {
    expect(formatProgress(140).value).toBe(100);
    expect(formatProgress(-5).value).toBe(0);
  });
});

describe('describeBatchPhase', () => {
  it('names the loading phase instead of showing 0.00 kg', () => {
    expect(describeBatchPhase(0)).toEqual({ label: 'Loading', tone: 'info' });
    expect(describeBatchPhase(-1)).toEqual({ label: 'Loading', tone: 'info' });
  });

  it('names the running phase once input is recorded', () => {
    expect(describeBatchPhase(0.5)).toEqual({ label: 'Processing', tone: 'info' });
  });
});
