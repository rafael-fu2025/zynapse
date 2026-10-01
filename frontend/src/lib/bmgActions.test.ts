import { describe, expect, it } from 'vitest';

import { ACTIVE_STATUSES, bmgActionAvailability, type BmgAction } from './bmgActions';

/**
 * The drum menu's state rules.
 *
 * These assertions exist because the rules were once inlined as a dozen
 * `disabled={...}` expressions in the Facilities table, where they drifted
 * out of step with the backend without anything failing: `curing` stayed
 * wired into three of them long after the transition producing that state
 * was removed from the product. Any status the backend can persist must
 * resolve to a coherent set here.
 */
const unit = (status: string, activeBatchId: number | null = null, archivedAt: string | null = null) => ({
  status,
  active_batch_id: activeBatchId,
  archived_at: archivedAt,
});

const enabled = (s: string, id: number | null = null, archived: string | null = null): BmgAction[] =>
  (Object.entries(bmgActionAvailability(unit(s, id, archived))) as Array<[BmgAction, boolean]>)
    .filter(([, ok]) => ok)
    .map(([k]) => k);

describe('bmgActionAvailability', () => {
  it('offers only start/archive on an idle drum', () => {
    expect(enabled('idle')).toEqual(['start', 'drumStatus', 'archive']);
  });

  it('offers the batch actions on a processing drum and hides start', () => {
    expect(enabled('processing', 7)).toEqual([
      'addUpdate',
      'viewUpdates',
      'finish',
      'cancel',
      'certificate',
      'analytics',
    ]);
  });

  it('treats awaiting_output exactly like processing', () => {
    expect(enabled('awaiting_output', 7)).toEqual(enabled('processing', 7));
  });

  it('never offers both start and a batch action', () => {
    for (const status of ['idle', ...ACTIVE_STATUSES]) {
      const on = enabled(status, status === 'idle' ? null : 1);
      expect(on.includes('start')).toBe(!on.includes('addUpdate'));
    }
  });

  it('refuses every batch action when the status is active but no batch is joined', () => {
    // Defensive: the list join is what supplies `active_batch_id`. If it
    // ever drops, the drum must not offer actions against a null batch.
    for (const status of ACTIVE_STATUSES) {
      const on = enabled(status, null);
      expect(on).not.toContain('addUpdate');
      expect(on).not.toContain('finish');
      expect(on).not.toContain('cancel');
      expect(on).not.toContain('certificate');
    }
  });

  it('refuses every batch action when a batch id is joined but the status is terminal', () => {
    for (const status of ['idle', 'cancelled', 'maintenance', 'released', 'curing']) {
      const on = enabled(status, 7);
      expect(on).not.toContain('addUpdate');
      expect(on).not.toContain('finish');
      expect(on).not.toContain('cancel');
    }
  });

  it('never offers a drum-status toggle while a batch is live', () => {
    // Parking a drum mid-run would strand the batch and break the
    // backend's one-active-batch-per-drum invariant.
    expect(enabled('idle', 7)).not.toContain('drumStatus');
    expect(enabled('processing', 7)).not.toContain('drumStatus');
  });

  it('offers restore — and nothing else destructive — for an archived drum', () => {
    const on = enabled('idle', null, '2026-09-29 10:00:00');
    expect(on).toContain('restore');
    expect(on).not.toContain('archive');
    expect(on).not.toContain('start');
    expect(on).not.toContain('drumStatus');
  });

  it('resolves an unknown status to the safe deny-everything default', () => {
    // A status the client has never heard of must not unlock anything.
    expect(enabled('quantum', 3)).toEqual([]);
  });

  it('disables the action whose mutation is in flight', () => {
    const u = unit('processing', 7);
    const on = (pending: Parameters<typeof bmgActionAvailability>[1]) =>
      (Object.entries(bmgActionAvailability(u, pending)) as Array<[BmgAction, boolean]>)
        .filter(([, ok]) => ok)
        .map(([k]) => k);

    expect(on({ finish: true })).not.toContain('finish');
    expect(on({ cancel: true })).not.toContain('cancel');
    // An unrelated pending mutation must not disable the others.
    expect(on({ finish: true })).toContain('cancel');
  });
});
