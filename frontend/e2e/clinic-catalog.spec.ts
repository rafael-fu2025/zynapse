import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

async function session(page: Page) {
  await page.route('**/api/v1/**', (route) => route.fulfill({ contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [], errors: [], meta: null }) }));
  await signInMocked(page, { id: 8, email: 'clinic@example.test', username: 'clinic-test',
    is_active: true, force_reset: false, permissions: ['clinic.inventory.read', 'clinic.inventory.write',
      'clinic.encounters.read', 'clinic.encounters.write'] });
  await page.route('**/api/v1/auth/refresh', (route) => route.fulfill({ contentType: 'application/json',
    body: JSON.stringify({ success: true, data: { access_token: 'mock', expires_in: 900 }, errors: [], meta: null }) }));
}
const medicineRow = (id: number) => ({ id, generic_name: `Catalog medicine ${id}`, brand_name: null,
  category: null, dosage_form: 'tablet', dosage_strength: '20 mg', unit: 'tablet', reorder_threshold: 2,
  target_stock: 20, description: null, quantity_on_hand: 12, low_stock: false, stock_status: 'in_stock',
  earliest_expiry: null, archived: false, created_at: '2026-10-13 00:00:00', last_movement: null });
const supplyRow = (id: number) => ({ id, name: `Catalog supply ${id}`, sku: `SKU-${id}`, unit: 'pc',
  quantity_on_hand: 4, reorder_level: 2, target_stock: 30, low_stock: false, stock_status: 'in_stock',
  archived: false, created_at: '2026-10-13 00:00:00', last_movement: null });

for (const kind of ['medicine', 'supply'] as const) {
  test(`${kind} dispense loads an open encounter beyond first 50 and preserves exact ID`, async ({ page }) => {
    await session(page);
    const endpoint = kind === 'medicine' ? 'medicines' : 'inventory';
    const row = kind === 'medicine' ? medicineRow(1) : supplyRow(1);
    await page.route(`**/api/v1/clinic/${endpoint}?**`, (route) => route.fulfill({ contentType: 'application/json',
      body: JSON.stringify({ success: true, data: [row], errors: [], meta: null }) }));
    let calls = 0;
    await page.route('**/api/v1/clinic/encounters?**', (route) => {
      const params = new URL(route.request().url()).searchParams;
      calls++;
      expect(params.get('status')).toBe('open');
      expect(params.has('q')).toBe(false);
      const offset = Number(params.get('cursor') ?? 0);
      const data = Array.from({ length: offset === 50 ? 1 : 25 }, (_, i) => ({
        id: offset + i + 1, patient_school_id: `TEST-${offset + i + 1}`, patient_name: `Patient ${offset + i + 1}`,
        appointment_id: null, station_id: 'Clinic', chief_complaint: 'Synthetic visit', triage_priority: null,
        triage_override: false, diagnosis: null, status: 'open', attending_user_id: 8,
        started_at: '2026-10-13 00:00:00', closed_at: null,
      }));
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data,
        errors: [], meta: { pagination: { next_cursor: offset === 50 ? null : String(offset + 25) } } }) });
    });
    let payload: unknown;
    await page.route(`**/api/v1/clinic/${endpoint}/1/${kind === 'medicine' ? 'dispense' : 'move'}`, (route) => {
      payload = route.request().postDataJSON();
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: row, errors: [], meta: null }) });
    });
    await page.goto(kind === 'medicine' ? '/inventory' : '/inventory?tab=supplies');
    await page.getByRole('table').getByRole('button', { name: `Actions for Catalog ${kind} 1` }).click();
    await page.getByRole('menuitem', { name: 'Dispense', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: `Dispense — Catalog ${kind} 1` });
    await expect(page.getByRole('status').filter({ hasText: /Search covers loaded open visits only/ })).toBeVisible();
    await page.getByRole('button', { name: 'Load more options' }).click();
    await expect.poll(() => calls).toBe(2);
    await expect(page.getByRole('button', { name: 'Load more options' })).toBeEnabled();
    await page.getByRole('button', { name: 'Load more options' }).click();
    await expect.poll(() => calls).toBe(3);
    await expect(page.getByRole('button', { name: 'Load more options' })).toHaveCount(0);
    const picker = page.locator(kind === 'medicine' ? '#dispense-encounter' : '#dispense_supply_encounter');
    await picker.fill('Patient 51');
    await page.getByRole('option', { name: /#51 · Patient 51/ }).click();
    await dialog.getByLabel('Quantity', { exact: true }).fill('1');
    await dialog.getByRole('button', { name: 'Dispense', exact: true }).click();
    await expect.poll(() => payload).toMatchObject({ encounter_id: 51 });
  });
}

for (const kind of ['medicine', 'supply'] as const) {
  test(`purchase ${kind} finds beyond old limit, retains quantity and resets search pages`, async ({ page }) => {
    await session(page);
    const endpoint = kind === 'medicine' ? 'medicines' : 'inventory';
    const requests: URLSearchParams[] = [];
    await page.route(`**/api/v1/clinic/${endpoint}?**`, (route) => {
      const params = new URL(route.request().url()).searchParams;
      requests.push(params);
      const offset = Number(params.get('cursor') ?? 0);
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true,
        data: Array.from({ length: offset === 100 ? 1 : 25 }, (_, i) => kind === 'medicine' ? medicineRow(offset + i + 1) : supplyRow(offset + i + 1)),
        errors: [], meta: { pagination: { next_cursor: offset === 100 ? null : String(offset + 25) } },
      }) });
    });
    await page.goto('/inventory?tab=reorders');
    await page.getByRole('button', { name: 'New request' }).click();
    const dialog = page.getByRole('dialog', { name: 'New purchase request' });
    if (kind === 'supply') {
      await dialog.getByRole('combobox', { name: 'Item type' }).click();
      await page.getByRole('option', { name: 'Supply', exact: true }).click();
    }
    for (let i = 0; i < 4; i++) {
      const before = requests.length;
      await page.getByRole('button', { name: 'Load more options' }).click();
      await expect.poll(() => requests.length).toBeGreaterThan(before);
      if (i < 3) await expect(page.getByRole('button', { name: 'Load more options' })).toBeEnabled();
    }
    await expect(page.getByRole('button', { name: 'Load more options' })).toHaveCount(0);
    const picker = page.locator('#reorder-item');
    await picker.click();
    await page.getByRole('option', { name: new RegExp(`Catalog ${kind} 101`) }).click();
    await expect(dialog.getByLabel('Quantity', { exact: true })).toHaveValue(kind === 'medicine' ? '8' : '26');
    await picker.fill('Other');
    await expect.poll(() => requests.at(-1)!.get('q')).toBe('Other');
    expect(requests.at(-1)!.has('cursor')).toBe(false);
    await expect(page.getByRole('button', { name: 'Load more options' })).toBeEnabled();
    await expect(page.locator('#reorder_quantity')).toHaveValue(kind === 'medicine' ? '8' : '26');
  });
}

test('equipment Insights explicitly loads beyond 100 before reporting full attention totals', async ({ page }) => {
  await session(page);
  let calls = 0;
  await page.route('**/api/v1/clinic/equipment?**', (route) => {
    const offset = Number(new URL(route.request().url()).searchParams.get('cursor') ?? 0);
    calls++;
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true,
      data: Array.from({ length: offset === 100 ? 1 : 25 }, (_, i) => ({ id: offset + i + 1,
        name: `Equipment ${offset + i + 1}`, category: null, location: null, notes: null, archived: false,
        working: 1, for_repair: 0, for_replacement: offset === 100 ? 3 : 0, retired: 0,
        total_units: offset === 100 ? 4 : 1, created_at: '2026-10-13 00:00:00' })),
      errors: [], meta: { pagination: { next_cursor: offset === 100 ? null : String(offset + 25) } },
    }) });
  });
  await page.goto('/inventory?tab=insights');
  await expect(page.getByRole('button', { name: /Equipment attention/ })).toContainText('partial');
  await expect(page.getByText('No equipment is flagged for replacement.')).toHaveCount(0);
  for (let i = 0; i < 4; i++) {
    await page.getByRole('button', { name: 'Load more equipment' }).click();
    await expect.poll(() => calls).toBe(i + 2);
    if (i < 3) await expect(page.getByRole('button', { name: 'Load more equipment' })).toBeEnabled();
  }
  await expect(page.getByText('Equipment 101', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: /Equipment attention/ })).toContainText('3');
  await expect(page.getByRole('button', { name: /Equipment attention/ })).not.toContainText('partial');
  await expect(page.getByRole('button', { name: 'Load more equipment' })).toHaveCount(0);
});
