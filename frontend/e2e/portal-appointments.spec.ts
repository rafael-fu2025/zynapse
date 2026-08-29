import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

/**
 * Portal appointments/queues per role, against mocked `/me/*` endpoints.
 * The pushState to /me mimics client-side navigation: a hard reload would
 * hit the cold-load bootstrap path that these mocks intentionally don't
 * stub (mockRefresh stays off).
 */
async function signIn(page: Page, role: 'student' | 'employee'): Promise<void> {
  const permission = role === 'student' ? 'student.portal.read' : 'employee.portal.read';
  const session = {
    id: 42,
    email: `${role}@example.test`,
    username: role,
    is_active: true,
    force_reset: false,
    permissions: [permission, 'portal.appointments.read', 'portal.appointments.manage', 'portal.queue.read'],
  };

  await page.route(`**/api/v1/me/${role}-profile`, (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: role === 'student'
      ? { id: 1, kind: 'student', student_number: '2026-1', first_name: 'Test', middle_name: null, last_name: 'User', course: null, year_level: null, section: null, date_of_birth: null, gender: null, blood_type: null, has_rfid: false, has_qr: false, consecutive_no_shows: 0, archived: false, created_at: '2026-01-01 00:00:00', kiosk_identifier: 'stu:2026-1' }
      : { id: 1, kind: 'employee', employee_number: 'E-1', first_name: 'Test', middle_name: null, last_name: 'User', department: null, position: null, date_hired: null, employment_status: null, hr_synced_at: null, emergency_contact_name: null, emergency_contact_phone: null, date_of_birth: null, gender: null, has_rfid: false, has_qr: false, kiosk_identifier: 'emp:E-1', is_teaching: false, archived: false, created_at: '2026-01-01 00:00:00' } }),
  }));
  await page.route('**/api/v1/me/*clinic-visits**', (route) => route.fulfill({
    contentType: 'application/json', body: JSON.stringify({ success: true, data: [] }),
  }));
  await page.route('**/api/v1/notifications**', (route) => route.fulfill({
    contentType: 'application/json', body: JSON.stringify({ success: true, data: [], meta: { next_cursor: null } }),
  }));
  await page.route('**/api/v1/me/appointments**', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: { appointments: [
      { department: 'clinic', id: 1, provider_user_id: 9, provider_name: 'Clinic Provider', starts_at: '2026-08-25T01:00:00+00:00', ends_at: '2026-08-25T02:00:00+00:00', status: 'scheduled', reason: null, type: null, queue_entry_id: null },
      { department: 'counselling', id: 2, provider_user_id: 10, provider_name: 'Guidance Provider', starts_at: '2026-08-26T02:00:00+00:00', ends_at: '2026-08-26T03:00:00+00:00', status: 'confirmed', reason: null, type: 'initial', queue_entry_id: null },
    ] } }),
  }));
  await page.route('**/api/v1/me/queues', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data: { queues: [
      { destination: 'clinic', queue_entry_id: 3, encounter_id: 4, position: 1, queue_number: 'C-001', status: 'waiting', called_at: null, started_at: null, people_ahead: 0, estimated_wait_minutes: 0 },
      { destination: 'counselling', queue_entry_id: 5, session_id: null, appointment_id: 2, position: 2, queue_number: 'G-002', status: 'called', called_at: '2026-08-21 01:00:00', started_at: null, people_ahead: 0, estimated_wait_minutes: null },
    ] } }),
  }));

  await signInMocked(page, session);
  await page.evaluate(() => {
    window.history.pushState({}, '', '/me');
    window.dispatchEvent(new PopStateEvent('popstate'));
  });
}

for (const role of ['student', 'employee'] as const) {
  test(`${role} sees unified appointments and destination queues`, async ({ page }) => {
    await signIn(page, role);
    await expect(page.getByText('C-001')).toBeVisible();
    await expect(page.getByText('G-002')).toBeVisible();
    await expect(page.getByText('Proceed to Guidance.')).toBeVisible();
    if (role === 'student') await page.getByRole('tab', { name: 'Appointments' }).click();
    await expect(page.getByText('Clinic Provider')).toBeVisible();
    await expect(page.getByText('Guidance Provider')).toBeVisible();
  });
}
