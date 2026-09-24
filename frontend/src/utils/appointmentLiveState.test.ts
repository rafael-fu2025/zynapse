import { describe, expect, it } from 'vitest';
import {
  deriveAppointmentLiveState,
  type LiveStateAppointment,
  type LiveStateInput,
  type LiveStateQueueEntry,
} from './appointmentLiveState';

/**
 * The contract under test: the board's live state is *derived on the Manila
 * clock* and never written. The two things that have bitten this module
 * before are day boundaries and auto-penalising a patient, so those get the
 * sharpest cases:
 *
 *   - a slot is "over" on the Manila wall clock, not the UTC one
 *   - a derived no-show is an offer (`canMarkNoShow`), not a record
 */
const MANILA = 'Asia/Manila';

/** An appointment on 2026-09-23, 08:00–09:00 Manila. */
function appointment(over: Partial<LiveStateAppointment> = {}): LiveStateAppointment {
  return {
    status: 'scheduled',
    appointment_date: '2026-09-23',
    start_time: '08:00:00',
    end_time: '09:00:00',
    ...over,
  };
}

function queue(over: Partial<LiveStateQueueEntry> = {}): LiveStateQueueEntry {
  return { status: 'waiting', started_at: null, finished_at: null, ...over };
}

function derive(over: LiveStateInput = {}) {
  return deriveAppointmentLiveState({ timezone: MANILA, ...over });
}

/** 08:00 Manila on 2026-09-23 is 00:00 UTC the same day. */
const MANILA_0800 = new Date('2026-09-23T00:00:00Z');
/** 09:30 Manila — half an hour past the slot's end. */
const MANILA_0930 = new Date('2026-09-23T01:30:00Z');
/** 07:30 Manila — half an hour before the slot starts. */
const MANILA_0730 = new Date('2026-09-22T23:30:00Z');

describe('deriveAppointmentLiveState — the clock speaks when nobody checked in', () => {
  it('reads Scheduled before the slot starts', () => {
    const state = derive({ appointment: appointment(), now: MANILA_0730 });

    expect(state.state).toBe('scheduled');
    expect(state.derived).toBe(false);
    expect(state.windowElapsed).toBe(false);
  });

  it('reads Checked in once the slot is running, with no queue entry', () => {
    // 08:30 Manila — inside the window, nobody at the desk.
    const state = derive({ appointment: appointment(), now: MANILA_0800 });

    expect(state.state).toBe('checked_in');
    // Inferred, so the UI must present it as provisional.
    expect(state.derived).toBe(true);
  });

  it('reads No-show once the slot has ended with nothing written', () => {
    const state = derive({ appointment: appointment(), now: MANILA_0930 });

    expect(state.state).toBe('no_show_due');
    expect(state.label).toBe('No-show');
    expect(state.derived).toBe(true);
    expect(state.hasActivity).toBe(false);
    // The offer, not the record.
    expect(state.actions.canMarkNoShow).toBe(true);
  });

  it('does not offer No-show before the window has elapsed', () => {
    const running = derive({ appointment: appointment(), now: MANILA_0800 });
    const early = derive({ appointment: appointment(), now: MANILA_0730 });

    expect(running.actions.canMarkNoShow).toBe(false);
    expect(early.actions.canMarkNoShow).toBe(false);
    // Cancelling is available the whole time the appointment is live.
    expect(early.actions.canCancel).toBe(true);
  });
});

describe('deriveAppointmentLiveState — the Manila day boundary', () => {
  it('treats a slot as elapsed on the Manila clock, not the UTC one', () => {
    // Slot ends 23:00 Manila on the 23rd == 15:00 UTC. "Now" is 16:00 UTC,
    // which is already 00:00 Manila on the 24th. A UTC comparison would read
    // 16:00 < 23:00 and wrongly call the slot still open.
    const state = derive({
      appointment: appointment({ start_time: '22:00:00', end_time: '23:00:00' }),
      now: new Date('2026-09-23T16:00:00Z'),
    });

    expect(state.windowElapsed).toBe(true);
    expect(state.state).toBe('no_show_due');
  });

  it('treats a slot as started on the Manila clock, not the UTC one', () => {
    // 08:30 Manila == 00:30 UTC. Comparing the raw UTC clock against the
    // appointment's 08:00 wall time would leave this "Scheduled".
    const state = derive({
      appointment: appointment(),
      now: new Date('2026-09-23T00:30:00Z'),
    });

    expect(state.windowElapsed).toBe(false);
    expect(state.state).toBe('checked_in');
  });

  it('is independent of the host machine timezone', () => {
    // The same instant, expressed two ways, must resolve identically — the
    // zone argument is what decides, never the runner's local clock.
    const viaUtc = derive({
      appointment: appointment(),
      now: new Date('2026-09-23T01:30:00Z'),
    });
    const viaOffset = derive({
      appointment: appointment(),
      now: new Date('2026-09-23T09:30:00+08:00'),
    });

    expect(viaUtc.state).toBe('no_show_due');
    expect(viaOffset.state).toBe(viaUtc.state);
  });

  it('defaults to the signed-in user zone when none is passed', () => {
    // The store seeds `Asia/Manila`; omitting `timezone` must not fall back
    // to the host zone.
    const state = deriveAppointmentLiveState({
      appointment: appointment(),
      now: new Date('2026-09-23T01:30:00Z'),
    });

    expect(state.state).toBe('no_show_due');
  });
});

describe('deriveAppointmentLiveState — the queue entry outranks the clock', () => {
  it('reads Checked in from a waiting entry, and is not derived', () => {
    const state = derive({
      appointment: appointment(),
      queue: queue({ status: 'waiting' }),
      now: MANILA_0800,
    });

    expect(state.state).toBe('checked_in');
    // A real check-in beats an inference.
    expect(state.derived).toBe(false);
  });

  it('reads In session while the window is still running', () => {
    const state = derive({
      appointment: appointment(),
      queue: queue({ status: 'in_session', started_at: '2026-09-23 00:10:00', counselling_session_id: 12 }),
      now: MANILA_0800,
    });

    expect(state.state).toBe('in_session');
    expect(state.hasActivity).toBe(true);
    expect(state.actions.canComplete).toBe(true);
  });

  it('reads Checked out when the window ends with a session still open', () => {
    const state = derive({
      appointment: appointment(),
      queue: queue({ status: 'in_session', started_at: '2026-09-23 00:10:00', counselling_session_id: 12 }),
      now: MANILA_0930,
    });

    expect(state.state).toBe('checked_out');
    expect(state.windowElapsed).toBe(true);
    expect(state.hasActivity).toBe(true);
  });

  it('withdraws the No-show offer once a session has run', () => {
    // The window is over, but the patient was seen — no no-show offer, and
    // no derived state to mislead anyone.
    const state = derive({
      appointment: appointment(),
      queue: queue({ status: 'in_session', started_at: '2026-09-23 00:10:00' }),
      now: MANILA_0930,
    });

    expect(state.actions.canMarkNoShow).toBe(false);
    expect(state.state).toBe('checked_out');
  });

  it('reads Completed when the desk closed the entry', () => {
    const state = derive({
      appointment: appointment(),
      queue: queue({ status: 'done', started_at: '2026-09-23 00:10:00', finished_at: '2026-09-23 00:50:00' }),
      now: MANILA_0930,
    });

    expect(state.state).toBe('completed');
    expect(state.actions.canCancel).toBe(false);
  });

  it('reads Skipped for a skipped entry', () => {
    const state = derive({
      appointment: appointment(),
      queue: queue({ status: 'skipped' }),
      now: MANILA_0930,
    });

    expect(state.state).toBe('skipped');
  });
});

describe('deriveAppointmentLiveState — terminal statuses win outright', () => {
  it.each([
    ['cancelled', 'cancelled'],
    ['no_show', 'no_show'],
    ['completed', 'completed'],
  ] as const)('keeps a %s appointment as %s however the clock reads', (status, expected) => {
    const state = derive({
      appointment: appointment({ status }),
      now: MANILA_0930,
    });

    expect(state.state).toBe(expected);
    expect(state.derived).toBe(false);
    // Resolved is resolved — nothing left to confirm.
    expect(state.actions.canMarkNoShow).toBe(false);
    expect(state.actions.canCancel).toBe(false);
  });

  it('does not let a live queue entry resurrect a cancelled appointment', () => {
    const state = derive({
      appointment: appointment({ status: 'cancelled' }),
      queue: queue({ status: 'in_session', started_at: '2026-09-23 00:10:00' }),
      now: MANILA_0800,
    });

    expect(state.state).toBe('cancelled');
  });

  it('treats a confirmed appointment as live', () => {
    const state = derive({
      appointment: appointment({ status: 'confirmed' }),
      now: MANILA_0930,
    });

    expect(state.state).toBe('no_show_due');
    expect(state.actions.canCancel).toBe(true);
  });
});

describe('deriveAppointmentLiveState — walk-ins and empty input', () => {
  it('reads a walk-in from its queue entry alone, with no cancel offer', () => {
    const state = derive({ queue: queue({ status: 'waiting' }), now: MANILA_0930 });

    expect(state.state).toBe('checked_in');
    // Nothing to cancel or no-show — there is no appointment behind it.
    expect(state.actions.canCancel).toBe(false);
    expect(state.actions.canMarkNoShow).toBe(false);
  });

  it('offers Complete only when a session is actually linked', () => {
    const unlinked = derive({
      appointment: appointment(),
      queue: queue({ status: 'in_session', started_at: '2026-09-23 00:10:00' }),
      now: MANILA_0800,
    });
    const linked = derive({
      appointment: appointment(),
      queue: queue({ status: 'in_session', started_at: '2026-09-23 00:10:00', counselling_session_id: 7 }),
      now: MANILA_0800,
    });

    expect(unlinked.actions.canComplete).toBe(false);
    expect(linked.actions.canComplete).toBe(true);
  });

  it('returns a neutral state for an empty row instead of throwing', () => {
    const state = derive({ now: MANILA_0800 });

    expect(state.state).toBe('scheduled');
    expect(state.actions.canCancel).toBe(false);
    expect(state.actions.canMarkNoShow).toBe(false);
  });

  it('does not treat a bare session link as activity', () => {
    // Links can be repaired without a session having happened, so a link
    // alone must not withdraw the no-show offer.
    const state = derive({
      appointment: appointment(),
      queue: queue({ status: 'waiting', counselling_session_id: 99 }),
      now: MANILA_0930,
    });

    expect(state.hasActivity).toBe(false);
  });
});
