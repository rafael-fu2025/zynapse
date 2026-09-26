import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

/**
 * Guidance session workflow (2026-09-23, fourth revision).
 *
 * What this spec pins, and why it moved:
 *
 *   - **Sessions are Queue-only.** The Appointments book lost its "Open session"
 *     button and its expandable row, so the Queue board is the single place a
 *     session is read. A row expands on click only when its queue entry is
 *     `in_session` — the patient the desk is working with.
 *   - **`?session=N` resolves to the Queue now.** A link is an explicit request
 *     for one named session, so it is not held to the on-going-only rule: it
 *     force-opens its row, and when nothing on the board owns it the session
 *     renders in a pinned panel above the board rather than dead-ending. Both
 *     branches are covered below — the pinned one is what tests 2 and 3 drive,
 *     since they mock an empty board.
 *   - **The Live state column is back.** Revision 3 removed it; revision 4
 *     restored it because *Checked in* appearing when the window opens is how
 *     the desk sees that a session has started without opening anything. So
 *     the last two tests assert the badge itself — and keep the contrast that
 *     survived the removal, since the derivation still gates the menu.
 *   - **Complete Session moved into the outcome menu.** It is the path for
 *     closing a session that was never written up. The session panel keeps its
 *     own Complete, which is the end of the write-the-notes flow — a different
 *     intent, not a duplicate.
 *
 * The panels carry explicit accessible names — "Session and notes" for the
 * inline drawer, "Session and notes (linked session)" for the deep-link panel —
 * so these assertions scope to a landmark instead of relying on Radix's
 * `aria-labelledby` wiring between a tab trigger and its content.
 */
const session = {
  id: 127, patient_school_id: '2026-0042', counsellor_user_id: 8,
  patient_display_name: 'Reyes, Maria', started_at: '2026-08-14 01:05:00', ended_at: null,
  queue_entry_id: 4, queue_number: 'G-004', queue_status: 'in_session', purpose: 'Initial Consultation',
  appointment_id: 22, incoming_referral_id: null, note_count: 0, outgoing_referral: null,
};

/** Today in Manila, as `YYYY-MM-DD` — the board's day boundary, never the host's. */
function manilaToday(): string {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(new Date());
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
  return `${get('year')}-${get('month')}-${get('day')}`;
}

/**
 * A zero-length window `hoursAhead` hours from now on the **Manila** clock, as
 * the board's `appointment_date` / `start_time` / `end_time` fields.
 *
 * Used to build a slot that has provably *not* started yet. A fixed late-evening
 * time would be flaky: near the end of the Manila day nothing on today's board
 * is still in the future. Deriving it from the clock keeps it future-dated
 * whatever time the suite runs, and `h23` avoids the ICU quirk where
 * `hour12: false` renders midnight as `24`.
 */
function manilaFutureWindow(hoursAhead: number) {
  const at = new Date(Date.now() + hoursAhead * 3_600_000);
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  }).formatToParts(at);
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
  const time = `${get('hour')}:${get('minute')}:${get('second')}`;
  return {
    appointment_date: `${get('year')}-${get('month')}-${get('day')}`,
    start_time: time,
    end_time: time,
  };
}

function appointment(over: Record<string, unknown> = {}) {
  return {
    id: 22, patient_school_id: '2026-0042', counsellor_user_id: 8,
    appointment_date: manilaToday(), start_time: '08:00:00', end_time: '09:00:00',
    type: 'initial', status: 'scheduled', reason: null, cancellation_reason: null,
    source: 'staff', patient_display_name: 'Reyes, Maria', counsellor_display_name: 'Counsellor',
    created_at: '2026-08-01 00:00:00',
    ...over,
  };
}

async function signIn(page: Page, permissions: string[]) {
  await signInMocked(page, {
    id: 8, email: 'guidance@example.test', username: 'guidance-counsellor',
    is_active: true, force_reset: false, permissions,
  }, { mockRefresh: true });
}

async function mockSessionApis(page: Page) {
  await page.route('**/api/v1/counselling/sessions?**', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: [session], errors: [], meta: { pagination: { limit: 25, next_cursor: null, prev_cursor: null } } }) }));
  await page.route('**/api/v1/counselling/sessions/127', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: session, errors: [], meta: null }) }));
  await page.route('**/api/v1/counselling/sessions/127/notes', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { notes: [] }, errors: [], meta: null }) }));
}

/** The board reads three scopes; stub all of them or the unmocked ones hit the real stack. */
async function mockAppointmentScopes(page: Page, byScope: Record<string, unknown[]>) {
  await page.route('**/api/v1/counselling/appointments?**', (route) => {
    const scope = new URL(route.request().url()).searchParams.get('scope') ?? '';
    return route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: byScope[scope] ?? [],
        errors: [],
        meta: { pagination: { limit: 25, next_cursor: null, prev_cursor: null } },
      }),
    });
  });
}

test('Start Session expands the queue row into the active session, in place', async ({ page }) => {
  await signIn(page, ['counselling.queue.read', 'counselling.queue.manage', 'counselling.records.read', 'counselling.records.write']);
  await mockSessionApis(page);
  await mockAppointmentScopes(page, {});

  // Mutable so the refetch after the transition reports the linked session —
  // a static stub would leave `counselling_session_id: null` and the row would
  // have nothing to expand into.
  let queueEntries: unknown[] = [{
    ...session, id: 4, position: 4, status: 'called', display_name: 'Maria',
    counselling_session_id: null, counselling_appointment_id: 22, referral_id: null,
    called_at: '2026-08-14 01:04:00', finished_at: null,
  }];
  await page.route('**/api/v1/counselling/queue', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: queueEntries, errors: [], meta: null }) }));
  await page.route('**/api/v1/counselling/queue/4/transition', async (route) => {
    queueEntries = [{
      ...session, id: 4, position: 4, queue_number: 'G-004', status: 'in_session', display_name: 'Maria',
      counselling_session_id: 127, counselling_appointment_id: 22, referral_id: null,
      called_at: '2026-08-14 01:04:00', started_at: '2026-08-14 01:05:00', finished_at: null,
    }];
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: queueEntries[0], errors: [], meta: null }) });
  });

  await page.goto('/counselling?tab=queue');
  await page.getByRole('button', { name: 'Start Session' }).click();

  // The desk asked to see the notes *inside the row*. Navigating away would
  // also mean the Sessions & Notes tab still exists — it does not.
  await expect(page).not.toHaveURL(/session=/);

  const panel = page.getByLabel('Session and notes');
  await expect(panel.getByText('Session #127', { exact: true })).toBeVisible();
  await expect(panel.getByText('Reyes, Maria')).toBeVisible();
  await expect(page.getByText(/G-004 started — Session #127 is now active/)).toBeVisible();
});

test('deep link survives refresh, completion clears selection, and referral fields are fixed', async ({ page }) => {
  await signIn(page, ['counselling.queue.read', 'counselling.records.read', 'counselling.records.write', 'referrals.create']);
  await mockSessionApis(page);
  await mockAppointmentScopes(page, {});
  await page.route('**/api/v1/counselling/queue', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: [], errors: [], meta: null }) }));

  await page.goto('/counselling?session=127');
  // The board is mocked empty, so nothing owns session 127 — the deep link
  // lands on the pinned panel rather than expanding a row.
  const panel = page.getByLabel('Session and notes (linked session)');
  await expect(panel.getByText('Session #127', { exact: true })).toBeVisible();
  await page.reload();
  await expect(panel.getByText('Session #127', { exact: true })).toBeVisible();

  await page.getByRole('button', { name: /^Referral/ }).click();
  await page.getByRole('button', { name: 'Refer to Clinic' }).click();
  const dialog = page.getByRole('dialog', { name: 'Refer patient to Clinic' });
  await expect(dialog.getByLabel('From')).toHaveValue('Guidance');
  await expect(dialog.getByLabel('To', { exact: true })).toHaveValue('Clinic');
  await expect(dialog.getByLabel('Artifact')).toHaveValue('Referral letter');
  await page.getByRole('button', { name: 'Cancel' }).click();

  await page.route('**/api/v1/counselling/sessions/127/close', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { ...session, ended_at: '2026-08-14 01:20:00' }, errors: [], meta: null }) }));
  await page.getByRole('button', { name: /^Complete/ }).click();
  await page.getByRole('button', { name: 'Complete Session' }).click();
  await page.getByRole('button', { name: 'Complete session' }).click();

  // Selection cleared and the session param dropped. Queue is the default
  // landing for a queue-capable login, so the bare URL is the resting state —
  // and it is the board, the section that hosts the session, they stay on.
  await expect(page).toHaveURL('/counselling');
});

test('active duplicate referral displays the existing referral and keeps the session open', async ({ page }) => {
  // `counselling.queue.read` is now required to reach a session at all: the
  // Queue is the only surface that renders one, so a records-only login cannot
  // open `?session=N` and the param is dropped. This spec used to sign in
  // without it.
  await signIn(page, ['counselling.queue.read', 'counselling.records.read', 'counselling.records.write', 'referrals.create']);
  await mockSessionApis(page);
  await mockAppointmentScopes(page, {});
  await page.route('**/api/v1/counselling/sessions/127/referrals', (route) => route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ success: false, data: null, errors: [{ code: 'referral.active_duplicate', message: 'Referral #55 is already submitted for this patient.', details: { referral: { id: 55, patient_school_id: '2026-0042', source_encounter_id: null, source_session_id: 127, source_module: 'counselling', target_module: 'clinic', artifact_type: 'referral_letter', status: 'submitted', reason_code: null, provider_user_id: null, provider_name: null, queue_handoff_destination: null, queue_handoff_entry_id: null, queue_handoff_at: null, created_at: '2026-08-14 01:10:00', updated_at: '2026-08-14 01:10:00', qr_expires_at: null, qr_revoked_at: null } } }], meta: null }) }));
  await page.goto('/counselling?session=127');
  await page.getByRole('button', { name: /^Referral/ }).click();
  await page.getByRole('button', { name: 'Refer to Clinic' }).click();
  await page.getByRole('button', { name: 'Submit referral' }).click();
  await expect(page.getByText('Referral #55', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Continue session' }).click();
  await page.getByRole('button', { name: /^Complete/ }).click();
  await expect(page.getByRole('button', { name: 'Complete Session' })).toBeVisible();
});

test('an elapsed slot reads No-show unconfirmed while one that has not started refuses it', async ({ page }) => {
  await signIn(page, [
    'counselling.queue.read', 'counselling.queue.manage',
    'counselling.records.read', 'counselling.schedule.read', 'counselling.schedule.manage',
  ]);
  await mockSessionApis(page);

  // Two rows, and the **pair** is the point: the rule is only legible as a
  // contrast. Asserting a single enabled menu would pass even if the gating
  // were inverted.
  //
  // The elapsed row uses a zero-length window at 00:00, which is in the past
  // for every moment of the Manila day — it cannot go flaky around the day
  // boundary the way a "now minus an hour" window would.
  const elapsed = appointment({ id: 22, start_time: '00:00:00', end_time: '00:00:00' });
  const notStarted = appointment({ id: 23, ...manilaFutureWindow(2) });
  await mockAppointmentScopes(page, { today: [elapsed, notStarted] });
  await page.route('**/api/v1/counselling/queue', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: [], errors: [], meta: null }) }));

  let transitionBody: unknown = null;
  await page.route('**/api/v1/counselling/appointments/22/transition', async (route) => {
    transitionBody = route.request().postDataJSON();
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: appointment({ status: 'no_show' }), errors: [], meta: null }) });
  });

  await page.goto('/counselling?tab=queue');

  // The column is back (fourth revision), and only the elapsed row is derived
  // — so the qualifier appears exactly once across two rows. "No-show" is
  // unambiguous here: the Status column says "Scheduled" on both rows.
  await expect(page.getByText('No-show', { exact: true })).toHaveCount(1);
  await expect(page.getByText('unconfirmed')).toHaveCount(1);

  // The same reading, as *whether the menu will act*.
  const elapsedMenu = page.getByRole('button', { name: 'Outcome actions for appointment #22' });
  await elapsedMenu.click();
  await expect(page.getByRole('menuitem', { name: 'No-Show' })).toBeEnabled();
  await expect(page.getByRole('menuitem', { name: 'Cancel Appointment' })).toBeEnabled();
  // Nothing is open for this row, so there is nothing to complete — the item is
  // present and refused rather than absent.
  await expect(page.getByRole('menuitem', { name: 'Complete Session' })).toBeDisabled();
  await page.keyboard.press('Escape');

  // The contrast: same menu, same appointment shape, but the window has not
  // ended, so No-Show is offered-but-refused and says why. Cancel stays open —
  // staff unavailability can strike before a slot even starts.
  const notStartedMenu = page.getByRole('button', { name: 'Outcome actions for appointment #23' });
  await notStartedMenu.click();
  await expect(page.getByRole('menuitem', { name: 'No-Show' })).toBeDisabled();
  await expect(
    page.getByText('No-show is offered once the slot ends with nothing written.'),
  ).toBeVisible();
  await expect(page.getByRole('menuitem', { name: 'Cancel Appointment' })).toBeEnabled();
  await page.keyboard.press('Escape');

  await elapsedMenu.click();
  await page.getByRole('menuitem', { name: 'No-Show' }).click();
  await page.getByRole('button', { name: 'Mark no-show' }).click();

  // Nothing was written until a staff member confirmed it.
  await expect.poll(() => transitionBody).toEqual({ action: 'no_show' });
});

test('a slot whose window has opened reads Checked in before anyone presses anything', async ({ page }) => {
  await signIn(page, [
    'counselling.queue.read', 'counselling.records.read',
    'counselling.schedule.read', 'counselling.schedule.manage',
  ]);
  await mockSessionApis(page);

  // A window covering the whole Manila day: started at 00:00, ends at 23:59:59,
  // so at any moment the suite runs the slot has begun and has not yet ended.
  // The final second of the Manila day is the one moment that is not true —
  // cheaper than a clock-derived window, which would have to handle the start
  // crossing midnight and would then disagree with `appointment_date`.
  const running = appointment({ id: 22, start_time: '00:00:00', end_time: '23:59:59' });
  const notStarted = appointment({ id: 23, ...manilaFutureWindow(2) });
  await mockAppointmentScopes(page, { today: [running, notStarted] });
  await page.route('**/api/v1/counselling/queue', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: [], errors: [], meta: null }) }));

  await page.goto('/counselling?tab=queue');

  // This is the whole reason the column exists: "the time arrived" has to be
  // visible on the board without opening the row or waiting for a button. No
  // queue entry exists here, so nobody checked in — the reading is inferred.
  await expect(page.getByText('Checked in', { exact: true })).toHaveCount(1);
  // Derived, therefore qualified: nothing was written for this reading, and a
  // viewer must not read it as a record. One qualifier across two rows is also
  // what proves the future row is *not* derived.
  await expect(page.getByText('unconfirmed')).toHaveCount(1);
});

test('Complete Session sits in the outcome menu for a booked row and in Actions for a walk-in', async ({ page }) => {
  await signIn(page, [
    'counselling.queue.read', 'counselling.queue.manage',
    'counselling.records.read', 'counselling.schedule.read', 'counselling.schedule.manage',
  ]);
  await mockSessionApis(page);

  // Inside its window, so the row is live rather than a no-show offer.
  const booked = appointment({ id: 22, start_time: '00:00:00', end_time: '23:59:59' });
  await mockAppointmentScopes(page, { today: [booked] });

  // Two open sessions: one booked (it has an appointment, so the menu can reach
  // it) and one walk-in (it has none, so the menu cannot). Both carry notes —
  // a session without notes cannot complete (2026-09-25 meeting gate).
  await page.route('**/api/v1/counselling/queue', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({
      success: true,
      data: [
        { ...session, id: 4, position: 4, status: 'in_session', display_name: 'Maria',
          counselling_session_id: 127, counselling_appointment_id: 22, referral_id: null, note_count: 2,
          called_at: '2026-08-14 01:04:00', started_at: '2026-08-14 01:05:00', finished_at: null },
        { ...session, id: 9, position: 9, status: 'in_session', queue_number: 'G-009', display_name: 'Walk In',
          counselling_session_id: 130, counselling_appointment_id: null, referral_id: null, note_count: 1,
          called_at: '2026-08-14 01:10:00', started_at: '2026-08-14 01:11:00', finished_at: null },
      ],
      errors: [],
      meta: null,
    }),
  }));

  let completeBody: unknown = null;
  await page.route('**/api/v1/counselling/queue/4/transition', async (route) => {
    completeBody = route.request().postDataJSON();
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: null, errors: [], meta: null }) });
  });

  await page.goto('/counselling?tab=queue');

  // One Complete per row. The booked row's lives in the menu, so the Actions
  // column must not offer a second one — which is why exactly one such button
  // exists, and it is the walk-in's.
  await expect(page.getByRole('button', { name: /^Complete session for/ })).toHaveCount(1);
  await expect(page.getByRole('button', { name: 'Complete session for G-009' })).toBeVisible();

  await page.getByRole('button', { name: 'Outcome actions for appointment #22' }).click();
  await expect(page.getByRole('menuitem', { name: 'Complete Session' })).toBeEnabled();
  await page.getByRole('menuitem', { name: 'Complete Session' }).click();

  // Closing out cascades — session, queue entry and the linked appointment — so
  // it is confirmed rather than fired on a menu click.
  await page.getByRole('button', { name: 'Complete session', exact: true }).click();

  // It writes through the *queue* entry, not the appointment: that is what
  // closes the session and promotes the booking in one transaction.
  await expect.poll(() => completeBody).toEqual({ action: 'complete' });
});
