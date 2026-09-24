import { describe, expect, it } from 'vitest';
import {
  appDateTimeToUtcSql,
  fmtClock,
  fmtShort,
  fmtTimeRange,
  fmtUtcToApp,
  utcSqlToAppParts,
  wholeMonthSpanLabel,
} from './date';

/**
 * The timezone contract: the API speaks UTC MySQL datetimes
 * (`YYYY-MM-DD HH:mm:ss`, NO zone designator), the UI renders in
 * Asia/Manila (UTC+8). The boundary case is the whole point — a naive
 * `parseISO` reads the string as local time and silently shifts dates.
 */
const MANILA_OFFSET_HOURS = 8;

describe('fmtShort / fmtUtcToApp', () => {
  it('renders a UTC timestamp on the same Manila calendar day', () => {
    // 10:30 UTC = 18:30 Manila — same day.
    expect(fmtShort('2026-08-01 10:30:00')).toBe('2026-08-01');
  });

  it('rolls forward across the UTC/Manila day boundary', () => {
    // 17:00 UTC = 01:00 NEXT DAY in Manila. A local-time parse of the
    // raw string would render 2026-08-01.
    expect(fmtShort('2026-08-01 17:00:00')).toBe('2026-08-02');
  });

  it('honors an explicit zone designator when the API ever sends one', () => {
    // Zone-suffixed input must NOT get a second "Z" appended — parse as
    // given. 2026-08-01T17:00:00+00:00 == 17:00 UTC.
    expect(fmtShort('2026-08-01T17:00:00+00:00')).toBe('2026-08-02');
  });

  it('renders the display contract (12-hour clock, middle dot)', () => {
    expect(fmtUtcToApp('2026-08-01 10:30:00')).toBe('Aug 1, 2026 · 6:30 PM');
  });
});

describe('appDateTimeToUtcSql / utcSqlToAppParts', () => {
  it('converts Manila local input to the UTC SQL string the API expects', () => {
    // 00:30 Manila on Aug 1 is 16:30 UTC on Jul 31.
    expect(appDateTimeToUtcSql('2026-08-01', '00:30')).toBe('2026-07-31 16:30:00');
  });

  it('accepts HH:mm:ss input without re-adding seconds', () => {
    expect(appDateTimeToUtcSql('2026-08-01', '14:00:00')).toBe('2026-08-01 06:00:00');
  });

  it('round-trips UTC SQL → Manila parts → UTC SQL', () => {
    const utcSql = '2026-08-01 16:30:00';
    const parts = utcSqlToAppParts(utcSql);

    // Aug 2, 00:30 Manila.
    expect(parts.date).toBe('2026-08-02');
    expect(parts.time).toBe('00:30');

    expect(appDateTimeToUtcSql(parts.date, parts.time)).toBe(utcSql);
  });

  it('keeps the conversion fixed regardless of the host machine timezone', () => {
    // The offset between Manila and UTC is constant (+8, no DST).
    // Whatever the CI runner's local zone is, 08:00 Manila must be
    // 00:00 UTC the same day.
    expect(appDateTimeToUtcSql('2026-08-01', '08:00')).toBe('2026-08-01 00:00:00');
    expect(MANILA_OFFSET_HOURS).toBe(8); // guard the assumption this file makes
  });
});

describe('fmtClock / fmtTimeRange', () => {
  /**
   * The clinic reads a 12-hour clock (2026-09-23). These values are
   * app-timezone *wall-clock*, NOT UTC instants — the whole point of the
   * helper is that it does no zone conversion, so these assertions must hold
   * on a CI runner set to any zone.
   */
  it('renders a morning hour on the 12-hour clock', () => {
    expect(fmtClock('09:00')).toBe('9:00 AM');
    expect(fmtClock('09:00:00')).toBe('9:00 AM');
    expect(fmtClock('08:15')).toBe('8:15 AM');
  });

  it('renders an afternoon hour on the 12-hour clock', () => {
    expect(fmtClock('13:30')).toBe('1:30 PM');
    expect(fmtClock('23:59')).toBe('11:59 PM');
  });

  it('renders noon as 12 PM, not 0 PM or 12 AM', () => {
    // The classic off-by-one: hour 12 is PM but must not render as "0 PM".
    expect(fmtClock('12:00')).toBe('12:00 PM');
    expect(fmtClock('12:45')).toBe('12:45 PM');
  });

  it('renders midnight as 12 AM, not 0 AM', () => {
    // The other classic: hour 0 is AM but must not render as "0 AM".
    expect(fmtClock('00:00')).toBe('12:00 AM');
    expect(fmtClock('00:05')).toBe('12:05 AM');
  });

  it('does not shift the value by the host timezone', () => {
    // A wall-clock column must render as written. If this were routed through
    // parseUtc, an 09:00 shift would come back as 5:00 PM on a UTC+8 runner.
    expect(fmtClock('09:00')).toBe('9:00 AM');
    expect(fmtClock('18:00')).toBe('6:00 PM');
  });

  it('returns unparseable input untouched rather than "Invalid Date"', () => {
    expect(fmtClock('')).toBe('');
    expect(fmtClock('not-a-time')).toBe('not-a-time');
    // Out-of-range hours are rejected, not wrapped.
    expect(fmtClock('25:00')).toBe('25:00');
  });

  it('joins a start/end pair with a spaced en dash', () => {
    expect(fmtTimeRange('09:00:00', '10:30:00')).toBe('9:00 AM – 10:30 AM');
    expect(fmtTimeRange('13:00', '17:00')).toBe('1:00 PM – 5:00 PM');
  });
});

describe('wholeMonthSpanLabel', () => {
  it('renders a whole-month span as the month range label', () => {
    expect(wholeMonthSpanLabel('2026-08-01', '2026-12-31')).toBe('Aug 2026 – Dec 2026');
  });

  it('renders a single whole month without a dash', () => {
    expect(wholeMonthSpanLabel('2026-09-01', '2026-09-30')).toBe('Sep 2026');
  });

  it('handles a 28-day February and a leap February', () => {
    expect(wholeMonthSpanLabel('2027-02-01', '2027-02-28')).toBe('Feb 2027');
    expect(wholeMonthSpanLabel('2028-02-01', '2028-02-29')).toBe('Feb 2028');
  });

  it('spans a year boundary (academic-year style range)', () => {
    expect(wholeMonthSpanLabel('2026-11-01', '2027-02-28')).toBe('Nov 2026 – Feb 2027');
  });

  it('returns null for day-precision ranges', () => {
    expect(wholeMonthSpanLabel('2026-08-19', '2026-09-17')).toBeNull();
    expect(wholeMonthSpanLabel('2026-08-01', '2026-12-30')).toBeNull();
  });
});
