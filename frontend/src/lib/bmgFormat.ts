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
