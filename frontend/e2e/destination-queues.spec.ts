import { expect, test } from '@playwright/test';

async function signInAsKiosk(page: import('@playwright/test').Page) {
  const session = { id: 99, email: 'kiosk.station@foundationu.edu.ph', username: 'synapse-kiosk', is_active: true, force_reset: false, permissions: ['kiosk.checkin.submit'] };
  await page.route('**/api/v1/auth/login', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { access_token: 'test-token', expires_in: 900 }, errors: [], meta: null }) }));
  await page.route('**/api/v1/auth/me', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: session, errors: [], meta: null }) }));
  await page.goto('/login');
  await page.getByLabel(/email/i).fill(session.email);
  await page.getByRole('textbox', { name: 'Password' }).fill('DevPassw0rd!');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL('/');
  await page.evaluate(() => { window.history.pushState({}, '', '/kiosk-station'); window.dispatchEvent(new PopStateEvent('popstate')); });
  await expect(page.getByRole('heading', { name: 'Where are you going?' })).toBeVisible();
}

test('public board renders independent Guidance and Clinic queues', async ({ page }) => {
  await page.route('**/api/v1/clinic/queue/state', async (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ data: {
      guidance: {
        active: [
          { position: 2, queue_number: 'G-002', display_name: 'Dela Cruz, Ana', patient_school_id: '2024-1001' },
          { position: 4, queue_number: 'G-004', display_name: 'Garcia, Eli', patient_school_id: '2024-1005' },
        ],
        now_serving: { position: 2, queue_number: 'G-002', display_name: 'Dela Cruz, Ana', patient_school_id: '2024-1001' },
        waiting: [{ position: 3, queue_number: 'G-003', display_name: 'Reyes, Ben', patient_school_id: '2024-1002', est_wait_minutes: 10 }],
      },
      clinic: {
        now_serving: { position: 7, queue_number: 'C-007', display_name: 'Santos, Cara', patient_school_id: '2024-1003' },
        waiting: [{ position: 8, queue_number: 'C-008', display_name: 'Lim, Dan', patient_school_id: '2024-1004', est_wait_minutes: 8 }],
      },
      updated_at: '2026-08-14 01:00:00',
    } }),
  }));

  await page.goto('/queue-display');
  await expect(page.getByRole('article', { name: 'Guidance now serving' })).toContainText('G-002');
  await expect(page.getByRole('article', { name: 'Guidance now serving' })).toContainText('G-004');
  await expect(page.getByRole('region', { name: 'Guidance waiting' })).toContainText('G-003');
  await expect(page.getByRole('article', { name: 'Clinic now serving' })).toContainText('C-007');
  await expect(page.getByRole('region', { name: 'Clinic waiting' })).toContainText('C-008');
});

test('kiosk requires a destination, isolates purposes, and resets after success', async ({ page }) => {
  await page.route('**/api/v1/clinic/patients/lookup**', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: [], errors: [], meta: null }) }));
  let submitted: Record<string, unknown> | null = null;
  await page.route('**/api/v1/clinic/checkins', async (route) => {
    submitted = await route.request().postDataJSON() as Record<string, unknown>;
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {
      id: 10, destination: 'counselling', outcome: 'counselling_queued', message: 'Added to Guidance.',
      student: { student_number: '20265122', name: 'Frances Bautista', course: null, year_level: null, kind: 'student' },
      allergy_alert: null, counselling_appointment_id: null,
      queue: { position: 1, queue_number: 'G-001', estimated_wait_minutes: 10 },
    }, errors: [], meta: null }) });
  });
  await signInAsKiosk(page);

  await page.getByRole('button', { name: 'Guidance' }).evaluate((button: HTMLButtonElement) => button.click());
  await expect(page.getByRole('button', { name: 'Initial Consultation' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Medical Certificate' })).toHaveCount(0);
  await page.getByRole('button', { name: /change destination/i }).evaluate((button: HTMLButtonElement) => button.click());
  await page.getByRole('button', { name: 'Clinic' }).evaluate((button: HTMLButtonElement) => button.click());
  await expect(page.getByRole('button', { name: 'Medical Certificate' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Initial Consultation' })).toHaveCount(0);

  await page.getByRole('button', { name: /change destination/i }).evaluate((button: HTMLButtonElement) => button.click());
  await page.getByRole('button', { name: 'Guidance' }).evaluate((button: HTMLButtonElement) => button.click());
  await page.getByPlaceholder('ID Number').fill('20265122');
  await page.getByRole('button', { name: 'Initial Consultation' }).click();
  await page.getByRole('button', { name: 'Check in' }).click();
  await expect(page.getByLabel('Queue number G-001')).toBeVisible();
  expect(submitted).toMatchObject({ destination: 'counselling', purpose: 'Initial Consultation', custom_purpose: false });
  await page.getByRole('button', { name: 'Done' }).click();
  await expect(page.getByRole('heading', { name: 'Where are you going?' })).toBeVisible();
});
