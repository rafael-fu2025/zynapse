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
  diagnosis: null,
  status: 'open',
  outcome: null,
  attending_user_id: 8,
  started_at: '2026-08-14 02:00:00',
  closed_at: null,
};

const detail = {
  ...encounter,
  queue_entry_id: 9,
  queue_number: 'C-009',
  queue_status: 'in_session',
  queue_called_at: '2026-08-14 01:59:00',
  queue_started_at: '2026-08-14 02:00:00',
  queue_finished_at: null,
  incoming_referral_id: null,
  vitals_count: 0,
  treatment_count: 0,
  assessment_recorded: false,
  outgoing_referral: null,
};

const queueEntry = {
  id: 9,
  destination: 'clinic',
  queue_number: 'C-009',
  encounter_id: 91,
  position: 9,
  status: 'in_session',
  display_name: 'Maria',
  patient_school_id: '2026-0091',
  patient_name: 'Maria Reyes',
  chief_complaint: 'Headache',
  station_id: 'Clinic Desk',
  called_at: '2026-08-14 01:59:00',
  started_at: '2026-08-14 02:00:00',
  finished_at: null,
  encounter_status: 'open',
  outcome: null,
  encounter_outcome: null,
};

async function signIn(page: Page) {
  const permissions = ['clinic.queue.read', 'clinic.queue.manage', 'clinic.encounters.read', 'clinic.encounters.write', 'referrals.create'];
  await signInMocked(page, {
    id: 8, email: 'nurse@example.test', username: 'clinic-nurse',
    is_active: true, force_reset: false, permissions,
  }, { mockRefresh: true });
}

async function mockClinicApis(page: Page, queue = [queueEntry]) {
  await page.route('**/api/v1/clinic/encounters?**', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: [], errors: [], meta: { pagination: { limit: 25, next_cursor: null, prev_cursor: null } } }) }));
  await page.route('**/api/v1/clinic/encounters/91', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: detail, errors: [], meta: null }) }));
  await page.route('**/api/v1/clinic/queue', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: queue, errors: [], meta: null }) }));
}

test('Clinic deep link keeps one tracked workspace and fixes the referral context', async ({ page }) => {
  await signIn(page);
  await mockClinicApis(page);
  await page.goto('/clinic?encounter=91');
  await expect(page.getByRole('dialog', { name: 'Maria Reyes' })).toBeVisible();
  await expect(page.getByRole('button', { name: /^Vitals/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /^Assessment/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /^Care \/ Treatment/ })).toBeVisible();
  await page.getByRole('button', { name: /^Referral/ }).click();
  await page.getByRole('button', { name: 'Refer to Guidance' }).click();
  const dialog = page.getByRole('dialog', { name: 'Refer patient to Guidance' });
  await expect(dialog.getByLabel('From')).toHaveValue('Clinic');
  await expect(dialog.getByLabel('To', { exact: true })).toHaveValue('Guidance');
  await expect(dialog.getByLabel('Artifact')).toHaveValue('Intake pass');
  await page.getByRole('button', { name: 'Cancel' }).click();
  await page.reload();
  await expect(page).toHaveURL(/\/clinic\?encounter=91/);
  await expect(page.getByRole('dialog', { name: 'Maria Reyes' })).toBeVisible();
});

test('starting a called Clinic queue entry opens its exact encounter workspace', async ({ page }) => {
  await signIn(page);
  await mockClinicApis(page, [{ ...queueEntry, status: 'called', started_at: null }]);
  await page.route('**/api/v1/clinic/queue/9/transition', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: queueEntry, errors: [], meta: null }) }));
  await page.goto('/clinic');
  await page.getByRole('button', { name: 'Start Session' }).first().click();
  await expect(page).toHaveURL(/\/clinic\?encounter=91/);
  await expect(page.getByRole('dialog', { name: 'Maria Reyes' })).toBeVisible();
});

test('Clinic completion warns but does not block missing optional or recommended steps', async ({ page }) => {
  await signIn(page);
  await mockClinicApis(page);
  await page.route('**/api/v1/clinic/encounters/91/close', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { ...encounter, status: 'closed', closed_at: '2026-08-14 02:20:00' }, errors: [], meta: null }) }));
  await page.goto('/clinic?encounter=91');
  await page.getByRole('button', { name: /^Complete/ }).click();
  await expect(page.getByText(/vitals and assessment are not recorded/i)).toBeVisible();
  await page.getByRole('button', { name: 'Complete encounter' }).click();
  await page.getByRole('button', { name: 'Complete encounter' }).click();
  await expect(page).toHaveURL('/clinic');
});
