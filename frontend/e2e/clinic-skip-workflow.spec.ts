/**
 * Clinic skip / recall-window workflow (October 2026 panel revision).
 *
 * Mocked (CI-safe): no live backend needed — same envelope shapes as
 * clinic-session-workflow.spec.ts. These cover the STAFF-facing contract
 * of the Skipped Patients module:
 *
 *   - a skipped patient appears with a live countdown and actionable
 *     buttons while the window is open;
 *   - `Return to Queue` goes through a confirmation dialog and posts the
 *     `return` action;
 *   - a resolved row (returned / no-show) loses its actions and shows
 *     the read-only "Open encounter" affordance instead.
 *
 * The backend's own behaviour (deadline stamping, expiry sweep,
 * idempotency) is covered by the PHPUnit suites — this file only proves
 * the UI wires to the right endpoints and renders the right states.
 */
import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

const encounter = {
  id: 91,
  patient_school_id: '2026-0091',
  patient_name: 'Maria Reyes',
  appointment_id: 31,
  station_id: 'Clinic Desk',
  chief_complaint: 'Headache',
  triage_priority: null,
  triage_override: false,
  diagnosis: null,
  status: 'open',
  outcome: null,
  attending_user_id: 8,
  started_at: '2026-08-14 02:00:00',
  closed_at: null,
};

const detail = {
  ...encounter,
  queue_entry_id: 9,
  queue_number: 'C-009',
  queue_status: 'skipped',
  queue_called_at: '2026-08-14 01:59:00',
  queue_started_at: null,
  queue_finished_at: null,
  incoming_referral_id: null,
  vitals_count: 0,
  treatment_count: 0,
  assessment_recorded: false,
  outgoing_referral: null,
};

/** A skip still inside its window — deadline is 30 minutes out. */
function activeSkip() {
  const now = Date.now();
  const deadline = new Date(now + 30 * 60_000);
  const skippedAt = new Date(now - 30 * 60_000);
  const iso = (d: Date): string => d.toISOString().replace('T', ' ').replace(/\.\d+Z$/, '');
  return {
    id: 9,
    queue_number: 'C-009',
    encounter_id: 91,
    position: 9,
    status: 'skipped',
    queue_status: 'skipped',
    display_name: 'Maria',
    patient_school_id: '2026-0091',
    patient_name: 'Maria Reyes',
    chief_complaint: 'Headache',
    appointment_id: 31,
    appointment_at: '2026-08-14 02:00:00',
    appointment_status: 'checked_in',
    skipped_at: iso(skippedAt),
    skip_deadline_at: iso(deadline),
    returned_at: null,
    encounter_status: 'open',
    outcome: null,
  };
}

const returnedRow = {
  ...activeSkip(),
  status: 'returned',
  queue_status: 'waiting',
  returned_at: '2026-08-14 02:10:00',
};

const noShowRow = {
  ...activeSkip(),
  status: 'no_show',
  queue_status: 'done',
  outcome: 'no_show',
};

async function signIn(page: Page) {
  await signInMocked(
    page,
    {
      id: 8,
      email: 'nurse@example.test',
      username: 'clinic-nurse',
      is_active: true,
      force_reset: false,
      permissions: [
        'clinic.queue.read',
        'clinic.queue.manage',
        'clinic.encounters.read',
        'clinic.encounters.write',
        'notifications.read',
      ],
    },
    { mockRefresh: true },
  );
}

async function mockClinicApis(page: Page, skipped: unknown[]) {
  await page.route('**/api/v1/clinic/encounters?**', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: [],
        errors: [],
        meta: { pagination: { limit: 25, next_cursor: null, prev_cursor: null } },
      }),
    }),
  );
  await page.route('**/api/v1/clinic/encounters/91', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: detail, errors: [], meta: null }) }),
  );
  await page.route('**/api/v1/clinic/queue', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: [], errors: [], meta: null }) }),
  );
  await page.route('**/api/v1/clinic/queue/skipped', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { data: skipped, server_now: new Date().toISOString().replace('T', ' ').replace(/\.\d+Z$/, '') },
        errors: [],
        meta: null,
      }),
    }),
  );
}

test('a skipped patient shows a live countdown and returns to the queue through the confirm dialog', async ({ page }) => {
  await signIn(page);
  await mockClinicApis(page, [activeSkip()]);

  let transitionBody: unknown = null;
  await page.route('**/api/v1/clinic/queue/9/transition', (route) => {
    transitionBody = route.request().postDataJSON();
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {}, errors: [], meta: null }) });
  });

  await page.goto('/clinic?tab=skipped');

  // The row is listed with the queue number and the countdown label.
  // Assertions scope to the desktop table: the mobile card mirror renders
  // the same rows, so page-wide locators would hit strict-mode clashes.
  const table = page.getByRole('table', { name: 'Skipped clinic patients' });
  await expect(table).toBeVisible();
  await expect(table.getByRole('cell', { name: 'C-009', exact: true })).toBeVisible();
  await expect(table.getByText(/remaining$/).first()).toBeVisible();

  // Returning goes through the confirmation dialog, then posts `return`.
  await table.getByRole('button', { name: 'Return to Queue' }).click();
  await expect(page.getByRole('dialog', { name: /Return C-009 to the queue\?/ })).toBeVisible();
  await page.getByRole('button', { name: 'Return to Queue' }).last().click();

  await expect.poll(() => transitionBody).toEqual({ action: 'return' });
});

test('a resolved skip shows no countdown and only the read-only encounter action', async ({ page }) => {
  await signIn(page);
  await mockClinicApis(page, [returnedRow, noShowRow]);

  await page.goto('/clinic?tab=skipped');

  const table = page.getByRole('table', { name: 'Skipped clinic patients' });

  // Both statuses are rendered as badges …
  await expect(table.getByText('Returned', { exact: true })).toBeVisible();
  await expect(table.getByText('No-Show', { exact: true })).toBeVisible();

  // … and neither offers the actionable buttons any more.
  await expect(table.getByRole('button', { name: 'Return to Queue' })).toHaveCount(0);
  await expect(table.getByRole('button', { name: 'Call Again' })).toHaveCount(0);
  await expect(table.getByRole('button', { name: 'Open encounter' })).toHaveCount(2);
});

test('marking a skipped patient no-show confirms before posting the cascade', async ({ page }) => {
  await signIn(page);
  await mockClinicApis(page, [activeSkip()]);

  let noShowCalled = false;
  await page.route('**/api/v1/clinic/encounters/91/no-show', (route) => {
    noShowCalled = true;
    return route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { ...encounter, status: 'closed', outcome: 'no_show' }, errors: [], meta: null }),
    });
  });

  await page.goto('/clinic?tab=skipped');

  await page.getByRole('button', { name: /Skipped patient actions for C-009/ }).click();
  await page.getByRole('menuitem', { name: 'Mark No-Show' }).click();

  await expect(page.getByRole('dialog', { name: /Mark C-009 as no-show\?/ })).toBeVisible();
  await page.getByRole('button', { name: 'Mark No-Show' }).last().click();

  await expect.poll(() => noShowCalled).toBe(true);
});

test('the Skipped tab is reachable from the clinic sidebar accordion', async ({ page }) => {
  await signIn(page);
  await mockClinicApis(page, [activeSkip()]);

  await page.goto('/clinic');
  await page.getByRole('link', { name: 'Skipped' }).click();

  await expect(page).toHaveURL(/\/clinic\?tab=skipped/);
  await expect(page.getByRole('table', { name: 'Skipped clinic patients' })).toBeVisible();
});
