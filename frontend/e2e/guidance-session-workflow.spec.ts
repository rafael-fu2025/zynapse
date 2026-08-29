import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

const session = {
  id: 127, patient_school_id: '2026-0042', counsellor_user_id: 8,
  patient_display_name: 'Reyes, Maria', started_at: '2026-08-14 01:05:00', ended_at: null,
  queue_entry_id: 4, queue_number: 'G-004', queue_status: 'in_session', purpose: 'Initial Consultation',
  appointment_id: 22, incoming_referral_id: null, note_count: 0, outgoing_referral: null,
};

async function signIn(page: Page, permissions: string[]) {
  await signInMocked(page, {
    id: 8, email: 'guidance@example.test', username: 'guidance-counsellor',
    is_active: true, force_reset: false, permissions,
  }, { mockRefresh: true });
}

async function mockSessionApis(page: Page) {
  await page.route('**/api/v1/counselling/sessions?**', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: [session], errors: [], meta: { pagination: { limit: 25, next_cursor: null, prev_cursor: null } } }) }));
  await page.route('**/api/v1/counselling/sessions/127', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: session, errors: [], meta: null }) }));
  await page.route('**/api/v1/counselling/sessions/127/notes', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { notes: [] }, errors: [], meta: null }) }));
}

test('Start Session uses the returned id and opens the exact active workspace', async ({ page }) => {
  await signIn(page, ['counselling.queue.read', 'counselling.queue.manage', 'counselling.records.read', 'counselling.records.write']);
  await mockSessionApis(page);
  await page.route('**/api/v1/counselling/queue', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: [{ ...session, id: 4, position: 4, status: 'called', display_name: 'Maria', counselling_session_id: null, counselling_appointment_id: 22, referral_id: null, called_at: '2026-08-14 01:04:00', finished_at: null }], errors: [], meta: null }) }));
  await page.route('**/api/v1/counselling/queue/4/transition', async (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { id: 4, position: 4, queue_number: 'G-004', status: 'in_session', display_name: 'Maria', patient_school_id: '2026-0042', purpose: 'Initial Consultation', counselling_session_id: 127, counselling_appointment_id: 22, referral_id: null, called_at: '2026-08-14 01:04:00', started_at: '2026-08-14 01:05:00', finished_at: null }, errors: [], meta: null }) }));
  await page.goto('/counselling?tab=queue');
  await page.getByRole('button', { name: 'Start Session' }).click();
  await expect(page).toHaveURL(/\/counselling\?session=127/);
  await expect(page.getByText('Active Session — #127')).toBeVisible();
  await expect(page.getByText('Reyes, Maria')).toBeVisible();
  await expect(page.getByText(/G-004 started — Session #127 is now active/)).toBeVisible();
});

test('deep link survives refresh, completion clears selection, and referral fields are fixed', async ({ page }) => {
  await signIn(page, ['counselling.queue.read', 'counselling.records.read', 'counselling.records.write', 'referrals.create']);
  await mockSessionApis(page);
  await page.route('**/api/v1/counselling/queue', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: [], errors: [], meta: null }) }));
  await page.goto('/counselling?session=127');
  await expect(page.getByText('Active Session — #127')).toBeVisible();
  await page.reload();
  await expect(page.getByText('Active Session — #127')).toBeVisible();
  await page.getByRole('button', { name: /^Referral/ }).click();
  await page.getByRole('button', { name: 'Refer Clinic' }).click();
  const dialog = page.getByRole('dialog', { name: 'Refer patient to Clinic' });
  await expect(dialog.getByLabel('From')).toHaveValue('Guidance');
  await expect(dialog.getByLabel('To', { exact: true })).toHaveValue('Clinic');
  await expect(dialog.getByLabel('Artifact')).toHaveValue('Referral letter');
  await page.getByRole('button', { name: 'Cancel' }).click();
  await page.route('**/api/v1/counselling/sessions/127/close', (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { ...session, ended_at: '2026-08-14 01:20:00' }, errors: [], meta: null }) }));
  await page.getByRole('button', { name: /^Complete/ }).click();
  await page.getByRole('button', { name: 'Complete Session' }).click();
  await page.getByRole('button', { name: 'Complete session' }).click();
  await expect(page).toHaveURL('/counselling');
});

test('active duplicate referral displays the existing referral and keeps the session open', async ({ page }) => {
  await signIn(page, ['counselling.records.read', 'counselling.records.write', 'referrals.create']);
  await mockSessionApis(page);
  await page.route('**/api/v1/counselling/sessions/127/referrals', (route) => route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ success: false, data: null, errors: [{ code: 'referral.active_duplicate', message: 'Referral #55 is already submitted for this patient.', details: { referral: { id: 55, patient_school_id: '2026-0042', source_encounter_id: null, source_session_id: 127, source_module: 'counselling', target_module: 'clinic', artifact_type: 'referral_letter', status: 'submitted', reason_code: null, provider_user_id: null, provider_name: null, queue_handoff_destination: null, queue_handoff_entry_id: null, queue_handoff_at: null, created_at: '2026-08-14 01:10:00', updated_at: '2026-08-14 01:10:00', qr_expires_at: null, qr_revoked_at: null } } }], meta: null }) }));
  await page.goto('/counselling?session=127');
  await page.getByRole('button', { name: /^Referral/ }).click();
  await page.getByRole('button', { name: 'Refer Clinic' }).click();
  await page.getByRole('button', { name: 'Submit referral' }).click();
  await expect(page.getByText('Referral #55', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Continue session' }).click();
  await page.getByRole('button', { name: /^Complete/ }).click();
  await expect(page.getByRole('button', { name: 'Complete Session' })).toBeVisible();
});
