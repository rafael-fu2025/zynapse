/**
 * Encounter archive gating — the pure predicates behind the Archive
 * button (October 2026 panel revision).
 *
 * Archiving is list hygiene: it moves a FINISHED visit from the active
 * Encounters surfaces to the Archived Encounters view without touching
 * the clinical record. Two surfaces offer it, and each has its own
 * notion of "finished":
 *
 *   - the **Queue** tab lists queue entries, where "Done" is the
 *     terminal queue status (`done` also covers no-shows, which carry
 *     `outcome = 'no_show'`);
 *   - the **Closed** tab lists encounters, where the terminal statuses
 *     are `closed` / `referred`.
 *
 * Both must agree with the server gate (`ClinicService::archiveEncounter`
 * accepts only `closed` / `referred`), or the UI would offer a button
 * that 409s. Keeping the predicate here — rather than inline in the page
 * — makes that agreement unit-testable, the same way `queueSkip.ts`
 * pins the recall-window copy.
 *
 * Pure functions only: no React, no clock, no fetch.
 */

/** The queue status that marks a visit as finished for archiving. */
export const ARCHIVABLE_QUEUE_STATUS = 'done' as const;

/**
 * Encounter statuses the server accepts for archiving — a visit that is
 * no longer in flight.
 */
export const ARCHIVABLE_ENCOUNTER_STATUSES = ['closed', 'referred'] as const;

/**
 * May this QUEUE row be archived?
 *
 * `queueStatus` is the entry's own status (`done` is the only terminal
 * one); `encounterStatus` is the linked encounter's status, which is
 * what the server actually validates. Requiring both means the button
 * appears exactly where the server would accept it.
 */
export function canArchiveQueueRow(
  queueStatus: string,
  encounterStatus: string,
): boolean {
  if (queueStatus !== ARCHIVABLE_QUEUE_STATUS) return false;
  return (ARCHIVABLE_ENCOUNTER_STATUSES as readonly string[]).includes(encounterStatus);
}

/**
 * May this ENCOUNTER row (Closed tab) be archived? Already-archived rows
 * are excluded so the button never offers a no-op.
 */
export function canArchiveEncounter(
  status: string,
  archivedAt: string | null | undefined,
): boolean {
  if (archivedAt !== null && archivedAt !== undefined) return false;
  return (ARCHIVABLE_ENCOUNTER_STATUSES as readonly string[]).includes(status);
}

/** Rows in the Archived Encounters view are restorable. */
export function canRestoreEncounter(archivedAt: string | null | undefined): boolean {
  return archivedAt !== null && archivedAt !== undefined;
}
