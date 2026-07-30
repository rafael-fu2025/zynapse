/**
 * Status display helpers — panel revision (July 2026).
 *
 * The API speaks lowercase snake_case status values everywhere
 * (`checked_in`, `awaiting_output`, `under_review`, …). Raw enum
 * values must never be rendered directly: route every status through
 * `statusLabel()` so users always see a human-friendly form.
 */

/** `checked_in` → `Checked in`, `open` → `Open`. */
export function statusLabel(status: string): string {
  const words = status.replace(/[_-]+/g, ' ').trim();
  if (words === '') return status;
  return words.charAt(0).toUpperCase() + words.slice(1);
}
