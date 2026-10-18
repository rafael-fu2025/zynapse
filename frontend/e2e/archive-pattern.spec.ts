import { expect, test } from '@playwright/test';
import { signInMocked } from './helpers/auth';

test.describe.configure({ timeout: 90_000 });
const ready = expect.configure({ timeout: 20_000 });

const stamp = '2026-10-17 03:00:00';
const common = { id: 901, created_at: stamp, archived_at: null };
const cases = [
  { resource: 'announcements', row: { ...common, title: 'Lifecycle notice', body: 'Notice body',
    audience: 'all', action_url: null, action_label: null, is_required: false, severity: 'normal',
    publish_at: null, unpublish_at: null, updated_at: stamp, status: 'live' } },
  { resource: 'services', row: { ...common, name: 'Lifecycle service', code: 'lifecycle', description: null,
    cmo_reference: null, sort_order: 0, queue_destination: null, is_active: true } },
  { resource: 'surveys', row: { ...common, title: 'Lifecycle survey', description: null, category: 'survey',
    audience: 'all', year_levels: null, is_required: false, publish_at: null, close_at: null,
    status: 'draft', version_id: null, version_no: null, question_count: 0, response_count: 0, questions: [], links: [] } },
];

for (const { resource, row } of cases) {
  test(`${resource}: archive, archived-only URL/reload, restore and active view`, async ({ page }) => {
    await page.route('**/api/v1/**', (route) => route.fulfill({ json: { success: true, data: [], errors: [], meta: null } }));
    await signInMocked(page, { id: 8, email: 'guidance@example.test', username: 'guidance',
      is_active: true, force_reset: false, permissions: [`counselling.${resource}.manage`] }, { mockRefresh: true });

    let archived = false;
    let restored = false;
    const scopes: string[] = [];
    await page.route(`**/api/v1/counselling/${resource}**`, (route) => {
      const url = new URL(route.request().url());
      if (url.pathname.endsWith('/unarchive')) {
        expect(route.request().method()).toBe('POST');
        archived = false;
        restored = true;
        return route.fulfill({ json: { success: true, data: { id: row.id, archived: false }, errors: [], meta: null } });
      }
      if (url.pathname.endsWith('/archive')) {
        expect(route.request().method()).toBe('POST');
        archived = true;
        return route.fulfill({ json: { success: true, data: { id: row.id, archived: true }, errors: [], meta: null } });
      }
      const scope = url.searchParams.get('archived');
      expect(scope === '0' || scope === '1').toBe(true);
      expect(url.searchParams.has('include_archived')).toBe(false);
      scopes.push(scope ?? '');
      return route.fulfill({ json: { success: true,
        data: archived === (scope === '1') ? [{ ...row, archived_at: archived ? stamp : null }] : [], errors: [], meta: null } });
    });

    await page.goto(`/counselling/${resource}`);
    let table = page.getByRole('table');
    await expect(table.getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
    await expect(table.getByRole('button', { name: 'Restore', exact: true })).toHaveCount(0);
    await table.getByRole('button', { name: 'Archive', exact: true }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Archive', exact: true }).click();
    await expect(page.getByRole('table')).toHaveCount(0);
    await page.getByRole('button', { name: 'Show archived', exact: true }).click();
    await expect(page).toHaveURL(/archived=1/);
    await expect(page.getByRole('table').getByRole('button', { name: 'Restore', exact: true })).toBeVisible();
    await page.reload();
    table = page.getByRole('table');
    await ready(table.getByRole('button', { name: 'Restore', exact: true })).toBeVisible();
    await expect(table.getByRole('button', { name: 'Edit', exact: true })).toHaveCount(0);
    await expect(table.getByRole('button', { name: 'Archive', exact: true })).toHaveCount(0);
    await table.getByRole('button', { name: 'Restore', exact: true }).click();
    await expect.poll(() => restored).toBe(true);
    await expect(page.getByRole('table')).toHaveCount(0);
    await page.getByRole('button', { name: 'Hide archived', exact: true }).click();
    await expect(page.getByRole('table').getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
    expect(scopes).toContain('0');
    expect(scopes).toContain('1');
  });
}
