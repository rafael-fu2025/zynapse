/**
 * Facilities drum-menu gating.
 *
 * This spec was previously live-only and asserted a "Move to curing" menu
 * item. That action had been removed from the product, so the assertion
 * had been failing on anyone who actually ran it — but the spec was
 * gated behind `SYNAPSE_E2E=1` and therefore never ran in CI, so nothing
 * flagged it. The rotting stayed invisible for months.
 *
 * The state rules now live in `src/lib/bmgActions.ts` and are unit-tested
 * there. This spec is the surface-level guard: it drives the real menu
 * from a mocked API and asserts which items are actually actionable for
 * each drum state, including the negative case that the retired `curing`
 * state must not reappear.
 *
 * Mocked (CI-safe): no live backend needed.
 */
import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

const PERMISSIONS = [
  'facilities.units.read',
  'facilities.units.manage',
  'facilities.bmg.transition',
  'facilities.bmg.record_output',
  'facilities.bmg.logs.read',
  'facilities.bmg.logs.record',
  'facilities.categories.manage',
  'notifications.read',
];

interface SeedUnit {
  id: number;
  code: string;
  status: string;
  active_batch_id: number | null;
  archived_at: string | null;
}

const IDLE: SeedUnit = {
  id: 1,
  code: 'drum-idle',
  status: 'idle',
  active_batch_id: null,
  archived_at: null,
};

const PROCESSING: SeedUnit = {
  id: 2,
  code: 'drum-busy',
  status: 'processing',
  active_batch_id: 42,
  archived_at: null,
};

const AWAITING: SeedUnit = {
  id: 3,
  code: 'drum-waiting',
  status: 'awaiting_output',
  active_batch_id: 43,
  archived_at: null,
};

const ARCHIVED: SeedUnit = {
  id: 4,
  code: 'drum-old',
  status: 'idle',
  active_batch_id: null,
  archived_at: '2026-08-01 09:00:00',
};

function unitRow(u: SeedUnit) {
  return {
    id: u.id,
    code: u.code,
    display_name: u.code,
    status: u.status,
    location_code: null,
    spec_capacity_kg: 120,
    default_category_id: null,
    notes: null,
    created_at: '2026-08-01 09:00:00',
    updated_at: '2026-08-01 09:00:00',
    archived_at: u.archived_at,
    active_batch_id: u.active_batch_id,
  };
}

async function signIn(page: Page): Promise<void> {
  await signInMocked(
    page,
    {
      id: 9,
      email: 'bmg_admin@example.test',
      username: 'bmg-admin',
      is_active: true,
      force_reset: false,
      permissions: PERMISSIONS,
    },
    { mockRefresh: true },
  );

  const json = (data: unknown) => ({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data, errors: [], meta: null }),
  });

  // NOTE: Playwright treats `?` in a glob as a single-character wildcard,
  // so a literal query separator must be expressed with `*` instead.
  //
  // The response shape must mirror what the BMG controllers ACTUALLY emit:
  // a bare row array in `data`, with the cursor in `meta.pagination`
  // (they flatten the service's `{data, next}` page). An earlier version
  // of this mock nested `{data, next}` inside `data` and so passed
  // against a client bug it should have caught.
  await page.route('**/api/v1/facilities/units*', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: [IDLE, PROCESSING, AWAITING, ARCHIVED].map(unitRow),
        errors: [],
        meta: { pagination: { limit: 50, next_cursor: null, prev_cursor: null } },
      }),
    }),
  );
  // Active batches are NOT flattened — that endpoint returns its
  // `{data, turning_due_days}` payload verbatim.
  await page.route('**/api/v1/facilities/batches/active*', (route) =>
    route.fulfill(json({ data: [], turning_due_days: 4 })),
  );
  await page.route('**/api/v1/facilities/alerts/open*', (route) =>
    route.fulfill(json([])),
  );
  await page.route('**/api/v1/facilities/waste-categories*', (route) =>
    route.fulfill(json([])),
  );
  await page.route('**/api/v1/notifications**', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/dashboard/counters**', (route) =>
    route.fulfill(json({ facilities: { units_idle: 1, units_processing: 1, units_awaiting: 1, at_risk: 0 } })),
  );
}

/**
 * Land on /facilities by CLIENT-SIDE navigation.
 *
 * The mocked session lives in memory only — a hard `page.goto` reloads
 * the SPA, drops the token, and bounces to /login. The live spec this
 * replaces hit exactly that and worked around it with a link click.
 */
async function gotoFacilities(page: Page): Promise<void> {
  await page.getByRole('link', { name: /facilities/i }).first().click();
  await page.waitForURL(/\/facilities(\?|$)/);
  await expect(page.getByRole('heading', { name: 'Facilities' }).first()).toBeVisible();
}

async function openMenu(page: Page, code: string) {
  const trigger = page.getByRole('button', { name: `Actions for ${code}` });
  await expect(trigger).toBeVisible();
  await trigger.click();
  return page.getByRole('menu');
}

test.describe('BMG drum menu gating', () => {
  test('an idle drum offers Start batch and can be parked or archived', async ({ page }) => {
    await signIn(page);
    await gotoFacilities(page);

    const menu = await openMenu(page, IDLE.code);
    await expect(menu.getByRole('menuitem', { name: 'Start batch' })).toBeEnabled();
    await expect(menu.getByRole('menuitem', { name: 'Add update' })).toBeDisabled();
    await expect(menu.getByRole('menuitem', { name: 'Finish batch' })).toBeDisabled();
    await expect(menu.getByRole('menuitem', { name: 'Cancel batch' })).toBeDisabled();
    await expect(menu.getByRole('menuitem', { name: 'Drum status' })).toBeEnabled();
  });

  test('a processing drum offers the batch actions and hides Start batch', async ({ page }) => {
    await signIn(page);
    await gotoFacilities(page);

    const menu = await openMenu(page, PROCESSING.code);
    await expect(menu.getByRole('menuitem', { name: 'Start batch' })).toBeDisabled();
    await expect(menu.getByRole('menuitem', { name: 'Add update' })).toBeEnabled();
    await expect(menu.getByRole('menuitem', { name: 'Finish batch' })).toBeEnabled();
    await expect(menu.getByRole('menuitem', { name: 'Cancel batch' })).toBeEnabled();
    // Parking a drum mid-run would strand the batch.
    await expect(menu.getByRole('menuitem', { name: 'Drum status' })).toBeDisabled();
  });

  test('an awaiting-output drum behaves like a processing drum', async ({ page }) => {
    await signIn(page);
    await gotoFacilities(page);

    const menu = await openMenu(page, AWAITING.code);
    await expect(menu.getByRole('menuitem', { name: 'Add update' })).toBeEnabled();
    await expect(menu.getByRole('menuitem', { name: 'Finish batch' })).toBeEnabled();
    await expect(menu.getByRole('menuitem', { name: 'Start batch' })).toBeDisabled();
  });

  test('an archived drum offers Restore and nothing that starts work', async ({ page }) => {
    await signIn(page);
    await gotoFacilities(page);

    // Archived rows are hidden by default; the toggle brings them back.
    await page.getByRole('button', { name: /show archived/i }).click();
    await expect(page.getByRole('button', { name: /hide archived/i })).toBeVisible();

    const menu = await openMenu(page, ARCHIVED.code);
    await expect(menu.getByRole('menuitem', { name: 'Restore' })).toBeEnabled();
    await expect(menu.getByRole('menuitem', { name: 'Start batch' })).toBeDisabled();
    await expect(menu.getByRole('menuitem', { name: 'Drum status' })).toBeDisabled();
  });

  test('the retired curing transition is not offered anywhere', async ({ page }) => {
    // The `curing` state was removed from the domain on 2026-09-29. This
    // is the assertion that would have caught the stale e2e spec that
    // used to check for exactly this menu item.
    await signIn(page);
    await gotoFacilities(page);

    for (const code of [IDLE.code, PROCESSING.code, AWAITING.code]) {
      const menu = await openMenu(page, code);
      await expect(menu.getByRole('menuitem', { name: /curing/i })).toHaveCount(0);
      await page.keyboard.press('Escape');
    }
  });

  test('the archived toggle round-trips through the URL', async ({ page }) => {
    await signIn(page);
    await gotoFacilities(page);

    const toggle = page.getByRole('button', { name: /show archived/i });
    await expect(toggle).toBeVisible();
    await toggle.click();
    await expect(page).toHaveURL(/[?&]archived=1/);
    await expect(page.getByRole('button', { name: /hide archived/i })).toBeVisible();
  });
});
