import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

export async function session(page: Page) {
  // Catch every API path first; more-specific mocks below override this.
  // Nothing, including incidental dashboard requests, reaches a live server.
  await page.route('**/api/v1/**', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: [], errors: [], meta: null }),
  }));
  await signInMocked(page, {
    id: 8, email: 'clinic@example.test', username: 'clinic-test', is_active: true, force_reset: false,
    permissions: ['clinic.appointments.read', 'clinic.encounters.read', 'clinic.queue.read',
      'clinic.inventory.read', 'clinic.inventory.write', 'clinic.encounters.write', 'referrals.read'],
  });
  // Enable cold-load bootstrap only after the real mocked login form ran.
  await page.route('**/api/v1/auth/refresh', (route) => route.fulfill({
    contentType: 'application/json', body: JSON.stringify({
      success: true, data: { access_token: 'mock-token', expires_in: 900 }, errors: [], meta: null,
    }),
  }));
}

test('treatment picker finds and selects a medicine beyond the initial catalogue page', async ({ page }) => {
  await session(page);
  const encounter = {
    id: 91, patient_school_id: 'TEST-91', patient_name: 'Synthetic Patient', appointment_id: null,
    station_id: 'Clinic Desk', chief_complaint: 'Test visit', triage_priority: null, triage_override: false,
    diagnosis: null, status: 'open', outcome: null, attending_user_id: 8,
    started_at: '2026-10-13 02:00:00', closed_at: null,
    queue_entry_id: null, queue_number: null, queue_status: null, queue_called_at: null,
    queue_started_at: null, queue_finished_at: null, incoming_referral_id: null,
    vitals_count: 0, treatment_count: 0, assessment_recorded: false, outgoing_referral: null,
  };
  await page.route('**/api/v1/clinic/encounters/91', (route) => route.fulfill({
    contentType: 'application/json', body: JSON.stringify({ success: true, data: encounter, errors: [], meta: null }),
  }));
  let searched = false;
  await page.route('**/api/v1/clinic/medicines?**', (route) => {
    const q = new URL(route.request().url()).searchParams.get('q');
    searched ||= q === 'Rare medicine';
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true,
      data: q === 'Rare medicine' ? [{
        id: 501, generic_name: 'Rare medicine', brand_name: null, category: null, dosage_form: 'tablet',
        dosage_strength: '20 mg', unit: 'tablet', reorder_threshold: 1, target_stock: null,
        description: null, quantity_on_hand: 12, low_stock: false, stock_status: 'in_stock',
        earliest_expiry: null, archived: false, created_at: '2026-10-13 00:00:00', last_movement: null,
      }] : [], errors: [], meta: { pagination: { next_cursor: q ? null : 'catalogue-page-2' } },
    }) });
  });
  await page.goto('/clinic?encounter=91');
  await page.getByRole('button', { name: /^Care \/ Treatment/ }).click();
  await page.getByRole('button', { name: 'Open care record' }).click();
  const dialog = page.getByRole('dialog', { name: 'Care — encounter #91' });
  await dialog.getByRole('combobox', { name: 'Type', exact: true }).click();
  await page.getByRole('option', { name: 'Medication', exact: true }).click();
  const picker = dialog.locator('#care-medicine');
  await picker.fill('Rare medicine');
  await expect.poll(() => searched).toBe(true);
  await page.getByRole('option', { name: /Rare medicine/ }).click();
  await expect(picker).toHaveValue('Rare medicine');
});

const slices = [
  { name: 'medicines', path: '/inventory', endpoint: '/clinic/medicines',
    search: 'Search medicines by name, brand, or category' },
  { name: 'supplies', path: '/inventory?tab=supplies', endpoint: '/clinic/inventory',
    search: 'Search supplies by SKU or name' },
  { name: 'equipment', path: '/inventory?tab=equipment', endpoint: '/clinic/equipment',
    search: 'Search equipment by name, category, or location' },
  { name: 'closed encounters', path: '/clinic?tab=closed', endpoint: '/clinic/encounters' },
  { name: 'referrals', path: '/referrals', endpoint: '/referrals' },
] as const;

for (const slice of slices) {
  test(`${slice.name}: envelope pages, final Next, Prev and URL filter resets`, async ({ page }) => {
    await session(page);
    const requests: URLSearchParams[] = [];
    await page.route(`**/api/v1${slice.endpoint}?**`, (route) => {
      const params = new URL(route.request().url()).searchParams;
      requests.push(params);
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({
        success: true, data: [], errors: [],
        meta: { pagination: { limit: 25, next_cursor: params.has('cursor') ? null : 'page-2' } },
      }) });
    });
    await page.goto(slice.path);
    const next = page.getByRole('button', { name: 'Next', exact: true });
    const prev = page.getByRole('button', { name: 'Prev', exact: true });
    await expect(next).toBeEnabled();
    await next.click();
    await expect(page.getByText(/Page 2 ·/)).toBeVisible();
    await expect(next).toBeDisabled();
    await expect(prev).toBeEnabled();
    expect(requests.at(-1)!.get('cursor')).toBe('page-2');
    await prev.click();
    await expect(page.getByText(/Page 1 ·/)).toBeVisible();
    await expect(next).toBeEnabled();
    await next.click();
    await expect(page.getByText(/Page 2 ·/)).toBeVisible();
    const before = requests.length;
    // Same-document URL navigation tests the filter reset independently
    // of click handlers (sidebar and Back/Forward also update URL filters).
    const target = 'search' in slice
      ? `${slice.path}${slice.path.includes('?') ? '&' : '?'}archived=1&q=gauze`
      : slice.name === 'referrals' ? '/referrals?status=submitted' : '/clinic?tab=archived';
    await page.evaluate((url) => {
      history.pushState(null, '', url);
      window.dispatchEvent(new PopStateEvent('popstate'));
    }, target);
    await expect(page.getByText(/Page 1 ·/)).toBeVisible();
    await expect.poll(() => requests.length).toBeGreaterThan(before);
    for (const params of requests.slice(before)) expect(params.has('cursor')).toBe(false);
    await expect(prev).toBeDisabled();
    if ('search' in slice) {
      await expect(page.getByRole('searchbox', { name: slice.search })).toHaveValue('gauze');
      expect(requests.at(-1)!.get('include_archived')).toBe('1');
      expect(requests.at(-1)!.get('q')).toBe('gauze');
    }
  });
}

for (const [tab, scope, provider] of [
  ['upcoming', 'upcoming', 'assigned'], ['needs-action', 'upcoming', 'unassigned'],
  ['past', 'past', 'any'], ['all', 'all', 'any'],
] as const) {
  test(`appointment ${tab}: filtered search paging and search/status/tab resets`, async ({ page }) => {
    await session(page);
    const requests: URLSearchParams[] = [];
    await page.route('**/api/v1/clinic/appointments?**', (route) => {
      const params = new URL(route.request().url()).searchParams;
      requests.push(params);
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({
        success: true, data: [{
          id: params.has('cursor') ? 202 : 201, patient_school_id: 'TEST-201',
          patient_name: params.has('cursor') ? 'Reyes Second' : 'Reyes First',
          provider_user_id: provider === 'unassigned' ? null : 8, provider_name: 'Test Nurse',
          scheduled_at: '2026-10-13 02:00:00', created_at: '2026-10-12 02:00:00',
          status: params.get('status') ?? 'scheduled', reason: null,
        }], errors: [], meta: { pagination: { limit: 25, next_cursor: params.has('cursor') ? null : 'search-page-2' } },
      }) });
    });
    await page.goto(`/appointments?tab=${tab}&status=scheduled&q=Reyes`);
    const next = page.getByRole('button', { name: 'Next', exact: true });
    await expect(next).toBeEnabled();
    await next.click();
    await expect(page.getByRole('table', { name: 'Clinic appointments' })).toContainText('Reyes Second');
    await expect(next).toBeDisabled();
    for (const params of requests) {
      expect(params.get('scope')).toBe(scope);
      expect(params.get('provider')).toBe(provider);
      expect(params.get('status')).toBe('scheduled');
      expect(params.get('q')).toBe('Reyes');
    }
    const search = page.getByRole('textbox', { name: 'Search appointments' });
    let before = requests.length;
    await search.fill('Santos');
    await expect(page.getByText(/Page 1 ·/)).toBeVisible();
    await expect.poll(() => requests.at(-1)!.get('q')).toBe('Santos');
    for (const params of requests.slice(before)) expect(params.has('cursor')).toBe(false);
    await expect(next).toBeEnabled();
    await next.click();
    await expect(next).toBeDisabled();
    before = requests.length;
    await page.evaluate(() => {
      const url = new URL(location.href);
      url.searchParams.set('status', 'confirmed');
      url.searchParams.set('tab', 'past');
      history.pushState(null, '', url);
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    await expect(page.getByText(/Page 1 ·/)).toBeVisible();
    await expect.poll(() => requests.at(-1)!.get('status')).toBe('confirmed');
    for (const params of requests.slice(before)) {
      expect(params.has('cursor')).toBe(false);
      expect(params.get('scope')).toBe('past');
      expect(params.get('provider')).toBe('any');
      expect(params.get('q')).toBe('Santos');
    }
    await next.click();
    await expect(next).toBeDisabled();
    before = requests.length;
    await search.fill('');
    await expect(page.getByText(/Page 1 ·/)).toBeVisible();
    await expect.poll(() => requests.at(-1)!.has('q')).toBe(false);
    for (const params of requests.slice(before)) expect(params.has('cursor')).toBe(false);
    await expect(page.getByRole('button', { name: 'Prev', exact: true })).toBeDisabled();
  });
}
