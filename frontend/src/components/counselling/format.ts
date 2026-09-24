/**
 * Counselling-local time helpers.
 *
 * Rendering is **not** here. The 12-hour clock contract lives in
 * `@/utils/date` (`fmtClock`, `fmtTimeRange`) because it is a system-wide rule,
 * not a Counselling one — this file keeps only the arithmetic that is specific
 * to laying availability windows out on a grid.
 */

/** Minutes past midnight for a wall-clock `HH:mm[:ss]` value. */
export function timeToMinutes(t: string): number {
  return Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5));
}
