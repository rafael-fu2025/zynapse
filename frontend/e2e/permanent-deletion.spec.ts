import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

test.describe.configure({ timeout: 90_000 });
const ready = expect.configure({ timeout: 20_000 });
const stamp = '2026-10-17 03:00:00';
const announcement = { id: 901, title: 'Unused archived notice', body: 'Notice body', audience: 'all',
  action_url: null, action_label: null, is_required: false, severity: 'normal', publish_at: null,
  unpublish_at: null, created_at: stamp, updated_at: stamp, archived_at: stamp, status: 'live' };

async function signIn(page: Page, permissions: string[]) {
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: { success: true, data: [], errors: [], meta: null } }));
  await signInMocked(page, { id: 8, email: 'admin@example.test', username: 'admin',
    is_active: true, force_reset: false, permissions }, { mockRefresh: true });
}

test('archived notice deletion requires confirmation and cancellation does not delete', async ({ page }) => {
  await signIn(page, ['counselling.announcements.manage']);
  let deleted = false;
  let deleteRequests = 0;
  await page.route('**/api/v1/counselling/announcements?**', (route) => route.fulfill({ json: {
    success: true, data: deleted ? [] : [announcement], errors: [], meta: null,
  } }));
  await page.route('**/api/v1/archived-records/announcements/901/delete-permanently', (route) => {
    expect(route.request().method()).toBe('POST');
    deleteRequests++;
    deleted = true;
    return route.fulfill({ json: { success: true, data: { resource: 'announcements', id: 901, deleted: true }, errors: [], meta: null } });
  });
  await page.goto('/counselling/announcements?archived=1');
  const action = page.getByRole('table').getByRole('button', { name: 'Delete permanently', exact: true });
  await ready(action).toBeEnabled();
  await action.click();
  const dialog = page.getByRole('dialog', { name: 'Delete permanently?' });
  await expect(dialog).toContainText('Unused archived notice');
  await expect(dialog).toContainText('cannot be undone');
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  expect(deleteRequests).toBe(0);
  await expect(page.getByRole('table')).toContainText(announcement.title);
  await action.click();
  await dialog.getByRole('button', { name: 'Delete permanently', exact: true }).click();
  await expect.poll(() => deleteRequests).toBe(1);
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(page.getByRole('table')).toHaveCount(0);
  await expect(page.getByText('No archived announcements.', { exact: true })).toBeVisible();
});

test('dependency refusal stays visible and leaves the archived row intact', async ({ page }) => {
  await signIn(page, ['counselling.announcements.manage']);
  await page.route('**/api/v1/counselling/announcements?**', (route) => route.fulfill({ json: {
    success: true, data: [announcement], errors: [], meta: null,
  } }));
  await page.route('**/api/v1/archived-records/announcements/901/delete-permanently', (route) => route.fulfill({
    status: 409, json: { success: false, data: null,
      errors: [{ code: 'archive.references_protected', message: 'Cannot permanently delete: this record is referenced by retained history.' }], meta: null },
  }));
  await page.goto('/counselling/announcements?archived=1');
  const action = page.getByRole('table').getByRole('button', { name: 'Delete permanently', exact: true });
  await ready(action).toBeEnabled();
  await action.click();
  const dialog = page.getByRole('dialog', { name: 'Delete permanently?' });
  await dialog.getByRole('button', { name: 'Delete permanently', exact: true }).click();
  await expect(dialog.getByRole('alert')).toContainText('referenced by retained history');
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(page.getByRole('table')).toContainText(announcement.title);
});

test('archived supply menu opens a persistent confirmation and refreshes after deletion', async ({ page }) => {
  await signIn(page, ['clinic.inventory.read', 'clinic.inventory.write', 'clinic.inventory.delete']);
  let deleted = false;
  const supply = { id: 902, name: 'Unused archived supply', sku: 'unused', unit: 'pc', quantity_on_hand: 0,
    reorder_level: 0, target_stock: null, low_stock: false, stock_status: 'in_stock', archived: true,
    created_at: stamp, last_movement: null };
  await page.route('**/api/v1/clinic/inventory?**', (route) => route.fulfill({ json: {
    success: true, data: deleted ? [] : [supply], errors: [], meta: null,
  } }));
  await page.route('**/api/v1/archived-records/supplies/902/delete-permanently', (route) => {
    deleted = true;
    return route.fulfill({ json: { success: true, data: { resource: 'supplies', id: 902, deleted: true }, errors: [], meta: null } });
  });
  await page.goto('/inventory?tab=supplies&archived=1');
  const table = page.getByRole('table');
  await ready(table.getByRole('button', { name: 'Actions for Unused archived supply' })).toBeVisible();
  await table.getByRole('button', { name: 'Actions for Unused archived supply' }).click();
  await page.getByRole('menuitem', { name: 'Delete permanently', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'Delete permanently?' });
  await expect(dialog).toBeVisible();
  await expect(page.getByRole('menu')).toHaveCount(0);
  await dialog.getByRole('button', { name: 'Delete permanently', exact: true }).click();
  await expect.poll(() => deleted).toBe(true);
  await expect(table.getByText(supply.name, { exact: true })).toHaveCount(0);
});
