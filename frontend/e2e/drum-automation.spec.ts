/**
 * Drum automation panel — the unit-level device surface on
 * `/facilities/drums/:unitId`.
 *
 * Guards the things that make this panel different from the rest of the
 * drum page:
 *   - it renders from the UNIT (monitor) payload, not the batch; the
 *     device can be offline while a batch runs;
 *   - an offline device is called out visibly rather than rendering as
 *     a normal tile;
 *   - Run now is confirmation-gated and queues a `run_now` command;
 *   - the schedule editor is admin-gated and hidden from operators.
 *
 * Mocked (CI-safe): no live backend needed.
 */
import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

const ADMIN_PERMISSIONS = [
  'facilities.units.read',
  'facilities.units.manage',
  'facilities.bmg.transition',
  'facilities.bmg.logs.read',
  'facilities.bmg.logs.record',
  'facilities.bmg.io.record',
  'notifications.read',
];

const OPERATOR_PERMISSIONS = [
  'facilities.units.read',
  'facilities.bmg.transition',
  'facilities.bmg.logs.read',
  'facilities.bmg.logs.record',
  'facilities.bmg.io.record',
  'notifications.read',
];

const ACTIVE_BATCH = {
  batch_id: 42,
  batch_code: 'BMG-2026-0042',
  batch_status: 'processing',
  unit_id: 2,
  unit_code: 'drum-busy',
  unit_name: 'Drum Busy',
  unit_location: null,
  category_name: null,
  input_kg: 12,
  output_kg: null,
  started_at: '2026-10-01 09:00:00',
  days_active: 6,
  reference_duration_days: 21,
  expected_days: 21,
  expected_completion_date: '2026-10-22',
  days_until_expected: 15,
  progress_pct: 29,
  last_turned_at: '2026-10-07 01:00:00',
  days_since_last_turning: 0,
};

function monitorPayload(online: boolean) {
  return {
    unit: { id: 2, code: 'drum-busy', display_name: 'Drum Busy', status: 'processing', archived_at: null },
    device: {
      id: 7,
      code: 'b8-1f-3f-d7-ec-18',
      display_name: 'Tumbler A',
      status: 'active',
      firmware: 'bmg-2026.10',
      online,
      offline_after_minutes: 20,
      last_seen_at: '2026-10-07 03:00:00',
      last_heartbeat_at: online ? '2026-10-07 03:00:00' : '2026-10-06 18:00:00',
      heartbeat_age_minutes: online ? 5 : 540,
      last_rssi_dbm: -61,
      last_uptime_seconds: 86400,
      rtc_valid: true,
      rtc_offset_seconds: 12,
      last_run_result: 'completed',
      motor_state: 'idle',
    },
    schedule: {
      unit_id: 2,
      times: ['09:00', '17:00'],
      duration_seconds: 10,
      pwm: 220,
      enabled: true,
      version: 4,
      updated_at: '2026-10-06 02:00:00',
      next_run_at: '2026-10-07 09:00:00',
    },
    runs: {
      recent: [
        {
          id: 91,
          run_uid: 'run-1',
          origin: 'scheduled',
          result: 'completed',
          started_at: '2026-10-07 01:00:00',
          ended_at: '2026-10-07 01:00:10',
          duration_seconds: 10,
          turns_count: 4,
          batch_id: 42,
          created_at: '2026-10-07 01:00:12',
        },
        {
          id: 92,
          run_uid: 'run-2',
          origin: 'scheduled',
          result: 'failed',
          started_at: '2026-10-07 02:00:00',
          ended_at: null,
          duration_seconds: 3,
          turns_count: null,
          batch_id: null,
          created_at: '2026-10-07 02:00:05',
        },
      ],
      failed_count: 1,
      missed_times: ['2026-10-07 10:00'],
      missed_count: 1,
    },
    active_batch: {
      id: 42,
      reference_code: 'BMG-2026-0042',
      status: 'processing',
      started_at: '2026-10-01 09:00:00',
      total_input_weight_kg: 12,
      output_weight_kg: null,
    },
    totals: { input_kg: 12.5, output_kg: 4 },
    open_alerts: [],
  };
}

async function signIn(page: Page, permissions: string[]): Promise<void> {
  await signInMocked(
    page,
    {
      id: 9,
      email: 'bmg_admin@example.test',
      username: 'bmg-admin',
      is_active: true,
      force_reset: false,
      permissions,
    },
    { mockRefresh: true },
  );

  const json = (data: unknown) => ({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data, errors: [], meta: null }),
  });

  await page.route('**/api/v1/facilities/batches/active*', (route) =>
    route.fulfill(json({ data: [ACTIVE_BATCH], turning_due_days: 4 })),
  );
  await page.route('**/api/v1/facilities/units/*/monitor*', (route) =>
    route.fulfill(json(monitorPayload(true))),
  );
  await page.route('**/api/v1/facilities/units/*/schedule*', (route) =>
    route.fulfill(
      json({
        unit_id: 2,
        unit_code: 'drum-busy',
        schedule: { times: ['09:00', '17:00'], duration_seconds: 10, pwm: 220, enabled: true, version: 4 },
        next_run_at: '2026-10-07 09:00:00',
        history: [],
      }),
    ),
  );
  await page.route('**/api/v1/facilities/batches/*/inputs*', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/facilities/batches/*/outputs*', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/facilities/batches/*/logs*', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/facilities/batches/*/analytics*', (route) => route.fulfill(json({})));
  await page.route('**/api/v1/facilities/batches/*/alerts*', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/facilities/batches/*/losses*', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/facilities/alerts/open*', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/facilities/waste-categories*', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/notifications**', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/dashboard/counters**', (route) =>
    route.fulfill(json({ facilities: { units_idle: 0, units_processing: 1, units_awaiting: 0, at_risk: 0 } })),
  );
}

/**
 * Client-side navigation only — a hard reload drops the mocked session.
 * With an active batch the drum page opens on its Overview tab; the
 * automation panel lives in the Automation tab, so open it unless the
 * caller wants to start from Overview.
 */
async function gotoDrum(page: Page, { automation = true }: { automation?: boolean } = {}): Promise<void> {
  await page.getByRole('link', { name: /facilities/i }).first().click();
  await page.waitForURL(/\/facilities(\?|$)/);
  await page.getByRole('link', { name: /drum-busy/i }).first().click();
  await page.waitForURL(/\/facilities\/drums\/2$/);
  if (automation) {
    await page.getByRole('tab', { name: 'Automation' }).click();
  }
}

test.describe('drum automation panel', () => {
  test('shows the device state, schedule and recent runs from the monitor payload', async ({ page }) => {
    await signIn(page, ADMIN_PERMISSIONS);
    await gotoDrum(page);

    const panel = page.getByText('Drum automation');
    await expect(panel).toBeVisible();
    await expect(page.getByText('Online —')).toBeVisible();
    await expect(page.getByText('Tumbler A')).toBeVisible();
    await expect(page.getByText('09:00, 17:00')).toBeVisible();
    await expect(page.getByText(/missed/i).first()).toBeVisible();
    await expect(page.getByText('Recent runs')).toBeVisible();
    // The failed run is called out, and the no-batch run carries its badge.
    await expect(page.getByText('Failed').first()).toBeVisible();
    await expect(page.getByText('No batch').first()).toBeVisible();
  });

  test('run now is confirmation-gated and queues a run_now command', async ({ page }) => {
    await signIn(page, ADMIN_PERMISSIONS);
    await gotoDrum(page);

    let queuedBody = '';
    await page.route('**/api/v1/facilities/devices/*/commands', async (route) => {
      queuedBody = route.request().postData() ?? '';
      await route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: { device_id: 7, command: 'run_now', status: 'pending', enqueued: true },
          errors: [],
          meta: null,
        }),
      });
    });

    await page.getByRole('button', { name: 'Run now' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Run now' }).click();

    await expect.poll(() => queuedBody).toContain('run_now');
  });

  test('the schedule editor is admin-only', async ({ page }) => {
    await signIn(page, OPERATOR_PERMISSIONS);
    await gotoDrum(page);

    await expect(page.getByText('Drum automation')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Edit schedule' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Run now' })).toHaveCount(0);
  });

  test('an offline device reads as offline, not merely stale', async ({ page }) => {
    await signIn(page, ADMIN_PERMISSIONS);
    await page.route('**/api/v1/facilities/units/*/monitor*', (route) =>
      route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: monitorPayload(false), errors: [], meta: null }),
      }),
    );
    await gotoDrum(page);

    await expect(page.getByText(/Offline —/)).toBeVisible();
  });
});

test.describe('drum page layout', () => {
  test('an offline controller is flagged in the header and jumps to Automation', async ({ page }) => {
    await signIn(page, ADMIN_PERMISSIONS);
    await page.route('**/api/v1/facilities/units/*/monitor*', (route) =>
      route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: monitorPayload(false), errors: [], meta: null }),
      }),
    );
    // Stay on Overview: the pill must be visible without opening Automation.
    await gotoDrum(page, { automation: false });

    const pill = page.getByRole('button', { name: /Controller offline/ });
    await expect(pill).toBeVisible();
    await pill.click();

    await expect(page.getByRole('tab', { name: 'Automation' })).toHaveAttribute('data-state', 'active');
    await expect(page).toHaveURL(/tab=automation/);
    await expect(page.getByText(/Offline —/)).toBeVisible();
  });

  test('the Drums breadcrumb leads back to Facilities, not a 404', async ({ page }) => {
    await signIn(page, ADMIN_PERMISSIONS);
    await gotoDrum(page, { automation: false });

    await page.getByRole('navigation', { name: 'Breadcrumbs' }).getByRole('link', { name: 'Drums' }).click();

    await page.waitForURL(/\/facilities$/);
    await expect(page.getByText('Page not found')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Facilities' }).first()).toBeVisible();
  });

  test('entry forms are closed until asked for', async ({ page }) => {
    await signIn(page, ADMIN_PERMISSIONS);
    await gotoDrum(page, { automation: false });

    await expect(page.getByRole('button', { name: 'Log observation' })).toBeVisible();
    await expect(page.getByLabel('Observation note')).toHaveCount(0);

    await page.getByRole('button', { name: 'Log observation' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByLabel('Observation note')).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toHaveCount(0);

    // Ledger forms live under Materials, also closed by default.
    await page.getByRole('tab', { name: 'Materials' }).click();
    await expect(page.getByLabel('Weight (kg)')).toHaveCount(0);
    await page.getByRole('button', { name: 'Record input' }).click();
    await expect(page.getByRole('dialog').getByLabel('Weight (kg)')).toBeVisible();
  });

  test('read-only staff see the drum but no entry buttons', async ({ page }) => {
    await signIn(page, ['facilities.units.read', 'facilities.bmg.logs.read', 'notifications.read']);
    await gotoDrum(page, { automation: false });

    await expect(page.getByRole('tab', { name: 'Overview' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Log observation' })).toHaveCount(0);
    await page.getByRole('tab', { name: 'Materials' }).click();
    await expect(page.getByRole('button', { name: 'Record input' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Record loss' })).toHaveCount(0);
  });
});
