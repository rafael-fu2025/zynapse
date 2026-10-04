/**
 * Clinic encounter archive workflow (October 2026 panel revision).
 *
 * Mocked (CI-safe): no live backend needed — same envelope shapes as
 * clinic-session-workflow.spec.ts. These cover the STAFF-facing contract
 * of the Archive action:
 *
 *   - the Archive button sits beside Actions and appears ONLY on a
 *     finished ("Done") row;
 *   - clicking it opens the "Archive Encounter?" confirmation;
 *   - confirming posts to the archive endpoint;
 *   - the Archived Encounters view lists archived rows and offers
 *     Restore.
 *
 * The backend's own behaviour (the finished-status gate, idempotency,
 * the queue-feed exclusion) is covered by the PHPUnit suites — this file
 * proves the UI wires to the right endpoints and hides the button where
 * it should.
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
  diagnosis: 'Resolved.',
  status: 'closed',
  outcome: null,
  attending_user_id: 8,
  started_at: '2026-08-14 02:00:00',
  closed_at: '2026-08-14 02:30:00',
  archived_at: null,
};

/** A finished queue row — the only shape that offers Archive. */
const doneQueueEntry = {
  id: 9,
  destination: 'clinic',
  queue_number: 'C-009',
  encounter_id: 91,
  position: 9,
  status: 'done',
  display_name: 'Maria',
  patient_school_id: '2026-0091',
  patient_name: 'Maria Reyes',
  chief_complaint: 'Headache',
  station_id: 'Clinic Desk',
  called_at: '2026-08-14 01:59:00',
  started_at: '2026-08-14 02:00:00',
  finished_at: '2026-08-14 02:30:00',
  skipped_at: null,
  skip_deadline_at: null,
  returned_at: null,
  encounter_status: 'closed',
  outcome: null,
  encounter_outcome: null,
};

const archivedRow = {
  ...encounter,
  archived_at: '2026-10-03 04:00:00',
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
      ],
    },
    { mockRefresh: true },
  );
}

/** Queue-tab API surface. */
async function mockQueueApis(page: Page, queue: unknown[]) {
  await page.route('**/api/v1/clinic/queue', (route) =>
    route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: queue, errors: [], meta: null }) }),
  );
  await page.route('**/api/v1/clinic/queue/skipped', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { data: [], server_now: '2026-10-03 04:00:00' }, errors: [], meta: null }),
    }),
  );
  await page.route('**/api/v1/clinic/encounters?**', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: [], errors: [], meta: { pagination: { limit: 25, next_cursor: null, prev_cursor: null } } }),
    }),
  );
}

test('Archive appears beside Actions only on a finished queue row, and confirms before posting', async ({ page }) => {
  await signIn(page);
  await mockQueueApis(page, [doneQueueEntry]);

  let archiveCalled = false;
  await page.route('**/api/v1/clinic/encounters/91/archive', (route) => {
    archiveCalled = true;
    return route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: archivedRow, errors: [], meta: null }),
    });
  });

  await page.goto('/clinic');

  const table = page.getByRole('table', { name: "Today's clinic queue" });
  await expect(table.getByRole('button', { name: 'Archive encounter for queue C-009' })).toBeVisible();

  await table.getByRole('button', { name: 'Archive encounter for queue C-009' }).click();

  const dialog = page.getByRole('dialog', { name: 'Archive Encounter?' });
  await expect(dialog).toBeVisible();
  await expect(dialog).toContainText('Are you sure you want to archive this completed encounter?');

  await dialog.getByRole('button', { name: 'Archive' }).click();
  await expect.poll(() => archiveCalled).toBe(true);
});

test('an unfinished queue row offers no Archive button', async ({ page }) => {
  await signIn(page);
  // In-session: the patient is being served — archiving must not be offered.
  await mockQueueApis(page, [
    { ...doneQueueEntry, status: 'in_session', finished_at: null, encounter_status: 'open' },
  ]);

  await page.goto('/clinic');

  const table = page.getByRole('table', { name: "Today's clinic queue" });
  await expect(table.getByRole('button', { name: 'Open Session' })).toBeVisible();
  await expect(table.getByRole('button', { name: /^Archive encounter for queue/ })).toHaveCount(0);
});

test('the Archived Encounters view lists archived rows and offers Restore', async ({ page }) => {
  await signIn(page);
  await mockQueueApis(page, []);

  await page.route('**/api/v1/clinic/encounters?**', (route) => {
    const url = route.request().url();
    const isArchived = url.includes('status=archived');
    return route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: isArchived ? [archivedRow] : [],
        errors: [],
        meta: { pagination: { limit: 25, next_cursor: null, prev_cursor: null } },
      }),
    });
  });

  let restoreCalled = false;
  await page.route('**/api/v1/clinic/encounters/91/restore', (route) => {
    restoreCalled = true;
    return route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: encounter, errors: [], meta: null }),
    });
  });

  await page.goto('/clinic?tab=archived');

  const table = page.getByRole('table', { name: 'Archived clinic encounters' });
  await expect(table).toBeVisible();
  await expect(table.getByText('Archived', { exact: true })).toBeVisible();

  await table.getByRole('button', { name: 'Restore encounter 91' }).click();
  const dialog = page.getByRole('dialog', { name: 'Restore Encounter?' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Restore' }).click();

  await expect.poll(() => restoreCalled).toBe(true);
});

test('the Archived tab is reachable from the clinic sidebar accordion', async ({ page }) => {
  await signIn(page);
  await mockQueueApis(page, []);

  await page.goto('/clinic');
  await page.getByRole('link', { name: 'Archived' }).click();

  await expect(page).toHaveURL(/\/clinic\?tab=archived/);
  await expect(page.getByRole('table', { name: 'Archived clinic encounters' })).toBeVisible();
});
