/**
 * Drum-card gear gauge.
 *
 * The gear replaces the static drum picture on each Processing Drums
 * card, and it carries meaning: it turns while a batch is processing,
 * turns amber/slowly when turning is overdue, and holds still when the
 * drum is waiting for waste. This guards the behaviour a screenshot
 * can't — that it really animates and really stops — plus the two
 * degradations that must never break the card: reduced-motion and no
 * WebGL.
 *
 * Mocked (CI-safe): no live backend needed.
 */
import { expect, test, type Locator, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

function batch(unitId: number, code: string, inputKg: number, daysSinceTurning: number | null) {
  return {
    batch_id: 100 + unitId,
    batch_code: `BMG-${code}`,
    batch_status: 'processing',
    unit_id: unitId,
    unit_code: code,
    unit_name: `${code} drum`,
    unit_location: null,
    category_name: 'Fruit Waste',
    input_kg: inputKg,
    output_kg: null,
    started_at: '2026-10-01 09:00:00',
    days_active: 4,
    reference_duration_days: 21,
    expected_days: 21,
    expected_completion_date: '2026-10-22',
    days_until_expected: 15,
    progress_pct: 40,
    last_turned_at: null,
    days_since_last_turning: daysSinceTurning,
  };
}

async function openFacilities(page: Page): Promise<void> {
  await signInMocked(
    page,
    {
      id: 9,
      email: 'bmg_admin@example.test',
      username: 'bmg-admin',
      is_active: true,
      force_reset: false,
      permissions: ['facilities.units.read', 'notifications.read'],
    },
    { mockRefresh: true },
  );
  const json = (data: unknown) => ({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data, errors: [], meta: null }),
  });
  await page.route('**/api/v1/facilities/units*', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: [],
        errors: [],
        meta: { pagination: { limit: 50, next_cursor: null, prev_cursor: null } },
      }),
    }),
  );
  await page.route('**/api/v1/facilities/batches/active*', (route) =>
    route.fulfill(
      json({
        turning_due_days: 4,
        data: [batch(1, 'gear-running', 12, 0), batch(2, 'gear-overdue', 8, 9), batch(3, 'gear-idle', 0, null)],
      }),
    ),
  );
  await page.route('**/api/v1/facilities/alerts/open*', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/facilities/waste-categories*', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/notifications**', (route) => route.fulfill(json([])));
  await page.route('**/api/v1/dashboard/counters**', (route) =>
    route.fulfill(json({ facilities: { units_idle: 0, units_processing: 3, units_awaiting: 0, at_risk: 0 } })),
  );
  // Client-side navigation only — a hard reload drops the mocked session.
  await page.getByRole('link', { name: /facilities/i }).first().click();
  await page.waitForURL(/\/facilities(\?|$)/);
}

function gearOf(page: Page, code: string): Locator {
  return page.locator('a', { hasText: code }).locator('canvas');
}

/** Pixels of a gear's canvas now, vs. after letting it run a moment. */
async function frames(canvas: Locator): Promise<[string, string]> {
  await canvas.scrollIntoViewIfNeeded();
  // The first frame lands only once the lazy three.js chunk has loaded;
  // a cold dev server compiles it on demand, hence the long allowance.
  await expect(canvas).toHaveClass(/\bopacity-100\b/, { timeout: 30_000 });
  const grab = () => canvas.evaluate((c: HTMLCanvasElement) => c.toDataURL());
  const before = await grab();
  await canvas.page().waitForTimeout(600);
  return [before, await grab()];
}

test.describe('drum gear gauge', () => {
  test('a processing gear turns; a gear waiting for waste holds still', async ({ page }) => {
    await openFacilities(page);

    const [runBefore, runAfter] = await frames(gearOf(page, 'gear-running'));
    expect(runAfter).not.toBe(runBefore);

    const [idleBefore, idleAfter] = await frames(gearOf(page, 'gear-idle'));
    expect(idleAfter).toBe(idleBefore);
  });

  test('the tooltip explains what the gear is saying', async ({ page }) => {
    await openFacilities(page);

    await expect(page.getByTitle('Processing — turned on schedule')).toHaveCount(1);
    await expect(page.getByTitle('Needs turning — Turned 9 days ago')).toHaveCount(1);
    await expect(page.getByTitle('Loading — waiting for waste input')).toHaveCount(1);
  });

  test('prefers-reduced-motion freezes every gear', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await openFacilities(page);

    const [before, after] = await frames(gearOf(page, 'gear-running'));
    expect(after).toBe(before);
  });

  test('without WebGL the card keeps the drum artwork and stays usable', async ({ page }) => {
    await page.addInitScript(() => {
      const original = HTMLCanvasElement.prototype.getContext;
      HTMLCanvasElement.prototype.getContext = function (this: HTMLCanvasElement, type: string, ...rest: unknown[]) {
        if (type === 'webgl' || type === 'webgl2' || type === 'experimental-webgl') return null;
        return (original as (...a: unknown[]) => unknown).call(this, type, ...rest);
      } as typeof original;
    });
    await openFacilities(page);

    const card = page.getByRole('link', { name: /gear-running/ });
    await expect(card).toBeVisible();
    await expect(card.locator('img[src="/drum-maroon.png"]')).toBeVisible();
    await expect(card.locator('canvas')).toHaveCount(0);
  });
});
