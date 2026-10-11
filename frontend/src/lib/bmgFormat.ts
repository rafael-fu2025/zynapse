/**
 * bmgFormat — the wording rules for BMG drum readouts.
 *
 * These are presentational decisions (how a duration is phrased, and how
 * urgent it reads) that were open-coded in `DrumCard` and then copied
 * verbatim into `DrumDetailPage`. Two copies meant the drum grid and the
 * drum page could describe the same batch differently. This module is
 * the single place that decides the words; the views decide the colour.
 *
 * Pure and framework-free so it can be unit-tested directly.
 */

/** How urgently a readout reads. Views map these onto design tokens. */
export type Tone = 'neutral' | 'muted' | 'info' | 'warning' | 'danger';

/** A readout: what to say, and how loudly. */
export interface Readout {
  label: string;
  tone: Tone;
}

/** "1 day" / "3 days". Spelled out because these are scanned in bulk. */
export function formatDayCount(n: number): string {
  return `${n} ${n === 1 ? 'day' : 'days'}`;
}

/** "1 day ago" / "3 days ago". */
export function formatDaysAgo(n: number): string {
  return `${formatDayCount(n)} ago`;
}

/**
 * Time remaining until a batch's expected completion.
 *
 * `days` goes negative once the batch is past its expected date, which
 * is the only reading on the card that is genuinely actionable, so it
 * is the only one that gets the loud tone.
 */
export function describeEta(days: number | null | undefined): Readout {
  if (days === null || days === undefined) {
    return { label: '—', tone: 'muted' };
  }
  if (days < 0) {
    return { label: `${formatDayCount(Math.abs(days))} overdue`, tone: 'danger' };
  }
  if (days === 0) {
    return { label: 'Due today', tone: 'warning' };
  }
  return { label: `in ${formatDayCount(days)}`, tone: 'muted' };
}

/**
 * How long since the drum was last turned.
 *
 * `turningDueDays` is the cadence the backend enforces; it ships with
 * the active-batches payload as `turning_due_days` (see
 * `BmgAlertEngine::TURNING_DUE_DAYS`). Taking it as a parameter — rather
 * than hard-coding a local copy — is what keeps this readout from
 * drifting from the rule that actually raises the TURNING_DUE alert.
 *
 * A batch that has never been turned is flagged rather than merely
 * labelled: a drum sitting in the processing list is one the operator is
 * already turning, so "never" is the exception, not the default.
 *
 * With no threshold loaded yet, staleness cannot be judged, so only the
 * never-turned case is called out.
 */
export function describeTurning(
  daysSinceLastTurning: number | null | undefined,
  turningDueDays: number | null | undefined,
): Readout {
  if (daysSinceLastTurning === null || daysSinceLastTurning === undefined) {
    return { label: 'Never turned', tone: 'warning' };
  }
  if (daysSinceLastTurning === 0) {
    return { label: 'Turned today', tone: 'neutral' };
  }
  const stale =
    turningDueDays !== null && turningDueDays !== undefined
      && daysSinceLastTurning > turningDueDays;
  return {
    label: `Turned ${formatDaysAgo(daysSinceLastTurning)}`,
    tone: stale ? 'warning' : 'muted',
  };
}

/** Mass to two decimals with a unit, or an em dash when unknown. */
export function formatKg(kg: number | null | undefined): string {
  if (kg === null || kg === undefined || Number.isNaN(kg)) return '—';
  return `${kg.toFixed(2)} kg`;
}

/**
 * Decomposition progress as a percentage, clamped to 0-100.
 *
 * The backend clamps too, but the card is the last place a stray value
 * should be able to overflow its track.
 */
export function formatProgress(pct: number): { value: number; label: string } {
  const value = Math.min(100, Math.max(0, pct));
  return { value, label: `${Math.round(value)}%` };
}

/**
 * A drum with no recorded input is still being charged. The backend
 * reports `input_kg: 0` until then, and "0.00 kg" reads like a failure
 * rather than a step in the process.
 */
export function describeBatchPhase(inputKg: number): Readout {
  return inputKg <= 0
    ? { label: 'Loading', tone: 'info' }
    : { label: 'Processing', tone: 'info' };
}

// ---- Device automation readouts --------------------------------------

/** "just now" / "12 min ago" / "3 days ago" for a heartbeat age. */
export function formatAge(minutes: number | null | undefined): string {
  if (minutes === null || minutes === undefined) return 'never';
  if (minutes < 1) return 'just now';
  if (minutes < 60) return `${minutes} min ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 48) return `${hours} h ago`;
  return formatDaysAgo(Math.floor(hours / 24));
}

/**
 * Online/offline from the heartbeat age. `offlineAfterMinutes` ships in
 * the monitor payload so the label and the backend's watchdog can never
 * disagree about what "offline" means.
 */
export function describeHeartbeatAge(
  minutes: number | null | undefined,
  offlineAfterMinutes: number | null | undefined,
): Readout {
  if (minutes === null || minutes === undefined) {
    return { label: 'Never checked in', tone: 'warning' };
  }
  const threshold = offlineAfterMinutes ?? 20;
  if (minutes > threshold) {
    return { label: `Offline — ${formatAge(minutes)}`, tone: 'danger' };
  }
  return { label: `Online — ${formatAge(minutes)}`, tone: 'neutral' };
}

/**
 * DS3231 clock health from the drift the server measured at the last
 * heartbeat. The board's clock running a few seconds off is normal NTP
 * jitter; minutes of drift is what corrupts run timestamps.
 */
export function describeClockDrift(
  rtcValid: boolean | null | undefined,
  offsetSeconds: number | null | undefined,
): Readout {
  if (rtcValid === null || rtcValid === undefined) {
    return { label: 'RTC unknown', tone: 'muted' };
  }
  if (rtcValid === false) {
    return { label: 'RTC invalid', tone: 'danger' };
  }
  if (offsetSeconds === null || offsetSeconds === undefined) {
    return { label: 'RTC OK', tone: 'neutral' };
  }
  const drift = Math.abs(offsetSeconds);
  if (drift <= 60) {
    return { label: `RTC OK (${offsetSeconds >= 0 ? '+' : ''}${offsetSeconds}s)`, tone: 'neutral' };
  }
  if (drift <= 300) {
    return { label: `Clocking ${offsetSeconds >= 0 ? '+' : ''}${Math.round(offsetSeconds / 60)} min`, tone: 'warning' };
  }
  return { label: `Clock off by ${Math.round(drift / 60)} min`, tone: 'danger' };
}

/**
 * The next scheduled run, from the monitor's UTC timestamp. Accepts a
 * `now` for tests; renders in the browser's locale time (the schedule
 * itself is Manila time, which staff read directly).
 */
export function describeNextRun(
  nextRunAtUtc: string | null | undefined,
  enabled: boolean | null | undefined,
  now: Date = new Date(),
): Readout {
  if (!enabled) {
    return { label: 'Schedule off', tone: 'muted' };
  }
  if (!nextRunAtUtc) {
    return { label: 'No run times set', tone: 'muted' };
  }
  const next = parseUtcSql(nextRunAtUtc);
  if (next === null) return { label: '—', tone: 'muted' };
  const diffMin = Math.round((next.getTime() - now.getTime()) / 60000);
  const clock = next.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  if (diffMin < 0) {
    return { label: `${clock} (due)`, tone: 'warning' };
  }
  if (diffMin < 60) {
    return { label: `${clock} (in ${diffMin} min)`, tone: 'info' };
  }
  return { label: clock, tone: 'neutral' };
}

/**
 * A run row's outcome as readout text. Anything that is not `completed`
 * is operational debt and reads loudly.
 */
export function describeRunResult(result: string): Readout {
  switch (result) {
    case 'completed':
      return { label: 'Completed', tone: 'neutral' };
    case 'failed':
      return { label: 'Failed', tone: 'danger' };
    case 'aborted':
      return { label: 'Aborted', tone: 'warning' };
    default:
      return { label: result, tone: 'muted' };
  }
}

/** Input vs output totals for the unit's ledger, with the balance. */
export function formatIoTotals(
  inputKg: number | null | undefined,
  outputKg: number | null | undefined,
): { input: string; output: string; balance: Readout } {
  const input = inputKg ?? 0;
  const output = outputKg ?? 0;
  const balanceKg = input - output;
  return {
    input: formatKg(input),
    output: formatKg(output),
    balance: {
      label: formatKg(balanceKg),
      tone: balanceKg < 0 ? 'danger' : 'muted',
    },
  };
}

/**
 * The server's UTC SQL timestamps (`YYYY-MM-DD HH:MM:SS`) are not ISO —
 * Safari refuses them outright. Parse explicitly.
 */
export function parseUtcSql(value: string): Date | null {
  const m = value.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})/);
  if (m === null) return null;
  return new Date(
    Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]), Number(m[6])),
  );
}
