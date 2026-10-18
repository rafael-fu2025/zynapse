import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

const envelope = (data: unknown) => ({ contentType: 'application/json', body: JSON.stringify({ success: true, data, errors: [], meta: null }) });
const counts = { pages: 2, fetched: 200, created: 130, updated: 40, unchanged: 20, skipped: 7, duplicates: 3, total_pages: null };
const baseJob = {
  id: 42, tenant_id: 1, kind: 'all', dry_run: true, status: 'queued', kinds: {},
  current_kind: null, current_page: 0, total_pages: null,
  created_at: '2026-10-13 00:00:00', started_at: null, finished_at: null, error: null,
};
type MockJob = typeof baseJob & { [key: string]: unknown };

async function setup(page: Page) {
  let job: MockJob | null = null;
  let fail = false;
  let submitFail = false;
  let posts = 0;
  let reads = 0;
  let usersReads = 0;
  const bodies: unknown[] = [];
  // Never fall through to a live backend, including incidental layout queries.
  await page.route('**/api/v1/**', (route) => route.fulfill(envelope([])));
  await page.route('**/api/v1/admin/users?*', (route) => { usersReads++; return route.fulfill(envelope([])); });
  await page.route('**/api/v1/admin/users/facets', (route) => route.fulfill(envelope({ kinds: [] })));
  await page.route('**/api/v1/rbac/roles', (route) => route.fulfill(envelope({ roles: [] })));
  await page.route('**/api/v1/admin/users/sync-directory/jobs/**', (route) => {
    reads++;
    return fail ? route.abort('failed') : route.fulfill(envelope(job));
  });
  await page.route('**/api/v1/admin/users/sync-directory', (route) => {
    posts++;
    const body = route.request().postDataJSON() as { kind: string; dry_run: boolean };
    bodies.push(body);
    job = { ...baseJob, kind: body.kind, dry_run: body.dry_run, id: 41 + posts };
    return submitFail ? route.abort('failed') : route.fulfill({ ...envelope(job), status: 202 });
  });
  await signInMocked(page, { id: 1, email: 'admin@example.test', username: 'admin', is_active: true, force_reset: false, permissions: ['*'] }, { mockRefresh: true });
  await page.goto('/admin/users');
  await expect(page.getByRole('heading', { name: 'Users', exact: true })).toBeVisible();
  return {
    setJob: (value: Record<string, unknown>) => { job = { ...baseJob, ...value } as MockJob; },
    setFailure: (value: boolean) => { fail = value; },
    setSubmitFailure: (value: boolean) => { submitFail = value; },
    posts: () => posts, reads: () => reads, usersReads: () => usersReads, bodies,
  };
}
async function open(page: Page) {
  await page.getByRole('button', { name: 'Sync from MIS', exact: true }).click();
  return page.getByRole('dialog', { name: 'Sync from MIS', exact: true });
}

test('Preview queues, polls honest counts, closes without cancel and recovers on reload', async ({ page }) => {
  const mock = await setup(page);
  let dialog = await open(page);
  await expect(dialog.getByRole('button', { name: 'Preview', exact: true })).toBeEnabled();
  await dialog.getByRole('combobox', { name: 'Directory kind' }).click();
  await page.getByRole('option', { name: 'Students', exact: true }).click();
  await dialog.getByRole('button', { name: 'Preview', exact: true }).click();
  await expect(dialog.getByText('Queued — waiting for worker')).toBeVisible();
  expect(mock.bodies).toEqual([{ kind: 'student', dry_run: true }]);
  await expect(dialog.getByRole('combobox')).toBeDisabled();
  await expect(dialog.getByRole('button', { name: 'Apply sync', exact: true })).toBeDisabled();
  mock.setJob({ kind: 'student', status: 'running', started_at: '2026-10-13 00:00:01', current_kind: 'student', current_page: 2, kinds: { student: counts } });
  await expect(dialog.getByText(/Page 2 · total pages unknown/)).toBeVisible({ timeout: 10_000 });
  await expect(dialog.getByText(/Elapsed:/)).toBeVisible();
  const reads = mock.reads();
  await dialog.getByRole('button', { name: 'Close', exact: true }).click();
  await expect.poll(mock.reads, { timeout: 10_000 }).toBeGreaterThan(reads);
  await page.reload();
  dialog = await open(page);
  await expect(dialog.getByText('Running', { exact: true })).toBeVisible();
  mock.setJob({ kind: 'student', status: 'completed', current_page: 2, kinds: { student: counts }, finished_at: '2026-10-13 00:00:10' });
  await expect(dialog.getByText('Preview complete', { exact: true })).toBeVisible({ timeout: 10_000 });
  await expect(dialog.getByRole('button', { name: 'Preview', exact: true })).toBeEnabled();
  await expect(dialog.getByText(/proposed changes/)).toBeVisible();
  expect(mock.posts()).toBe(1);
});

test('Apply requires confirmation; partial failure invalidates users and remains bounded on short desktop/mobile', async ({ page }) => {
  const mock = await setup(page);
  const dialog = await open(page);
  await expect(dialog.getByRole('button', { name: 'Apply sync', exact: true })).toBeEnabled();
  await dialog.getByRole('button', { name: 'Apply sync', exact: true }).click();
  let confirm = page.getByRole('dialog', { name: 'Apply directory sync?' });
  await confirm.getByRole('button', { name: 'Cancel', exact: true }).click();
  expect(mock.posts()).toBe(0);
  await dialog.getByRole('button', { name: 'Apply sync', exact: true }).click();
  confirm = page.getByRole('dialog', { name: 'Apply directory sync?' });
  await confirm.getByRole('button', { name: 'Confirm apply', exact: true }).click();
  await expect(dialog.getByText('Queued — waiting for worker')).toBeVisible();
  expect(mock.bodies).toEqual([{ kind: 'all', dry_run: false }]);
  const usersReads = mock.usersReads();
  mock.setJob({ dry_run: false, status: 'failed', current_page: 2, kinds: { student: counts, employee: counts }, error: { code: 'mis.unavailable', message: 'Directory page unavailable. '.repeat(10) } });
  await expect(dialog.getByText('Sync partially applied — failed')).toBeVisible({ timeout: 10_000 });
  await expect.poll(mock.usersReads).toBeGreaterThan(usersReads);
  await expect(dialog.getByText(/Committed changes are retained/)).toBeVisible();
  for (const viewport of [{ width: 1280, height: 500 }, { width: 375, height: 667 }, { width: 320, height: 568 }]) {
    await page.setViewportSize(viewport);
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await expect.poll(async () => dialog.evaluate((el) => {
      const rect = el.getBoundingClientRect();
      return rect.left >= -1 && rect.right <= window.innerWidth + 1 && rect.top >= -1 && rect.bottom <= window.innerHeight + 1;
    })).toBe(true);
    const details = dialog.getByRole('region', { name: 'MIS sync details' });
    await expect.poll(() => details.evaluate((el) => el.scrollHeight > el.clientHeight)).toBe(true);
    await details.evaluate((el) => { el.scrollTop = el.scrollHeight; });
    await expect(dialog.getByRole('button', { name: 'Apply sync', exact: true })).toBeInViewport();
    const table = dialog.getByRole('region', { name: 'Directory sync counts (scroll horizontally)' });
    await expect(table).toHaveAttribute('tabindex', '0');
    if (viewport.width < 640) expect(await table.evaluate((el) => el.scrollWidth > el.clientWidth)).toBe(true);
  }
});

test('Poll errors preserve results, disable controls and recover automatically; interrupted result is retained', async ({ page }) => {
  const mock = await setup(page);
  mock.setJob({ dry_run: false, status: 'running', current_page: 2, kinds: { student: counts } });
  const dialog = await open(page);
  await expect(dialog.getByText('Running', { exact: true })).toBeVisible();
  mock.setFailure(true);
  await expect(dialog.getByText(/Job status is unknown/)).toBeVisible({ timeout: 10_000 });
  await expect(dialog.getByText('Running', { exact: true })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Preview', exact: true })).toBeDisabled();
  await expect(dialog.getByRole('combobox')).toBeDisabled();
  mock.setJob({ dry_run: false, status: 'interrupted', current_page: 2, kinds: { student: counts }, error: { code: 'worker.interrupted', message: 'Worker interrupted.' } });
  mock.setFailure(false);
  await expect(dialog.getByText('Sync interrupted', { exact: true })).toBeVisible({ timeout: 10_000 });
  await expect(dialog.getByText(/Job status is unknown/)).toHaveCount(0);
  await expect(dialog.getByText('Worker interrupted.', { exact: false })).toBeVisible();
});

test('Initial latest failure locks submission; uncertain POST reconciles without replay or toast', async ({ page }) => {
  const mock = await setup(page);
  mock.setFailure(true);
  const dialog = await open(page);
  await expect(dialog.getByText(/Job status is unknown/)).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Preview', exact: true })).toBeDisabled();
  mock.setFailure(false);
  await dialog.getByRole('button', { name: 'Recheck status' }).click();
  await expect(dialog.getByRole('button', { name: 'Preview', exact: true })).toBeEnabled();
  mock.setSubmitFailure(true);
  await dialog.getByRole('button', { name: 'Preview', exact: true }).click();
  await expect(dialog.getByText(/submission could not be verified/)).toBeVisible();
  await expect(dialog.getByText('Queued — waiting for worker')).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Preview', exact: true })).toBeDisabled();
  expect(mock.posts()).toBe(1);
  await expect(page.locator('[data-sonner-toast]')).toHaveCount(0);
});
