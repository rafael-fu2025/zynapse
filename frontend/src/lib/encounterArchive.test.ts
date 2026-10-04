/**
 * Archive-gating predicates — the pure half of the Archive button.
 *
 * The UI gate and the server gate (`ClinicService::archiveEncounter`
 * accepts only `closed` / `referred`) must agree, or staff get a button
 * that 409s. These tests pin both sides of that contract: which queue
 * rows offer Archive, which encounter rows offer it, and that an
 * already-archived row never re-offers it.
 */
import { describe, expect, it } from 'vitest';
import {
  ARCHIVABLE_ENCOUNTER_STATUSES,
  ARCHIVABLE_QUEUE_STATUS,
  canArchiveEncounter,
  canArchiveQueueRow,
  canRestoreEncounter,
} from './encounterArchive';

describe('constants', () => {
  it('matches the server-side gate exactly', () => {
    // Mirrors ClinicService::archiveEncounter — keep in lockstep.
    expect(ARCHIVABLE_ENCOUNTER_STATUSES).toEqual(['closed', 'referred']);
    expect(ARCHIVABLE_QUEUE_STATUS).toBe('done');
  });
});

describe('canArchiveQueueRow', () => {
  it('allows a Done row whose encounter is finished', () => {
    expect(canArchiveQueueRow('done', 'closed')).toBe(true);
    expect(canArchiveQueueRow('done', 'referred')).toBe(true);
  });

  it('hides Archive for every unfinished queue status', () => {
    // The panel's rule: only "Done" rows get the button. This also keeps
    // the button away from a patient still being served.
    for (const status of ['waiting', 'called', 'in_session', 'skipped']) {
      expect(canArchiveQueueRow(status, 'closed')).toBe(false);
    }
  });

  it('hides Archive when the linked encounter is still open', () => {
    // A Done queue row can outlive a reopened/odd encounter state; the
    // server validates the ENCOUNTER, so the button must too.
    expect(canArchiveQueueRow('done', 'open')).toBe(false);
  });

  it('does not treat an unknown status as archivable', () => {
    expect(canArchiveQueueRow('done', 'something_new')).toBe(false);
  });
});

describe('canArchiveEncounter', () => {
  it('allows a finished, not-yet-archived encounter', () => {
    expect(canArchiveEncounter('closed', null)).toBe(true);
    expect(canArchiveEncounter('referred', null)).toBe(true);
    // Absent (older payloads) is treated as not-archived.
    expect(canArchiveEncounter('closed', undefined)).toBe(true);
  });

  it('refuses an open encounter', () => {
    expect(canArchiveEncounter('open', null)).toBe(false);
  });

  it('refuses an already-archived row so the action is never a no-op', () => {
    expect(canArchiveEncounter('closed', '2026-10-03 04:00:00')).toBe(false);
    expect(canArchiveEncounter('referred', '2026-10-03 04:00:00')).toBe(false);
  });
});

describe('canRestoreEncounter', () => {
  it('offers restore only for archived rows', () => {
    expect(canRestoreEncounter('2026-10-03 04:00:00')).toBe(true);
    expect(canRestoreEncounter(null)).toBe(false);
    expect(canRestoreEncounter(undefined)).toBe(false);
  });
});
