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

test('booking modal opens from My Appointments and shows time slots without provider name', async ({ page }) => {
  let bookedPayload: Record<string, unknown> | null = null;

  await signIn(page, 'student');

  await page.route('**/api/v1/me/appointment-slots**', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          slots: [
            {
              department: 'clinic',
              starts_at: '2026-09-25T01:00:00Z',
              ends_at: '2026-09-25T02:00:00Z',
              duration_minutes: 60,
              remaining: 3,
            },
          ],
        },
      }),
    }),
  );

  await page.route('**/api/v1/me/appointments', (route) => {
    if (route.request().method() === 'POST') {
      bookedPayload = route.request().postDataJSON() as Record<string, unknown>;
      return route.fulfill({
        contentType: 'application/json',
        status: 201,
        body: JSON.stringify({
          success: true,
          data: {
            department: 'clinic',
            id: 99,
            provider_user_id: null,
            provider_name: null,
            starts_at: '2026-09-25T01:00:00Z',
            ends_at: '2026-09-25T02:00:00Z',
            status: 'scheduled',
            reason: 'Annual check',
            type: null,
          },
        }),
      });
    }
    return route.continue();
  });
  await page.getByRole('tab', { name: 'Appointments' }).click();

  // "Book an appointment" button is located in the upper right of "My appointments"
  const bookBtn = page.getByRole('button', { name: 'Book an appointment' });
  await expect(bookBtn).toBeVisible();
  await bookBtn.click();

  // Modal dialog is open
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('heading', { name: 'Book an appointment' })).toBeVisible();

  // Before a date is picked there is no availability to choose from: the
  // control is disabled and says so, rather than opening an empty popover.
  const slotBefore = dialog.getByRole('combobox').filter({ hasText: /pick a date first/i });
  await expect(slotBefore).toBeDisabled();
  await expect(dialog.getByText('Pick a date to see available times.')).toBeVisible();

  // Set date to trigger slot fetch via DatePicker
  await dialog.getByRole('button', { name: /pick a date/i }).click();
  const dayButton = page.locator('[role="gridcell"] button, button[name="day"], .rdp-day_button').filter({ hasNotText: '' }).first();
  await dayButton.click();

  // Select slot — options show time slot and remaining count, NEVER a provider name
  const slotSelect = dialog.getByRole('combobox').filter({ hasText: /choose a time slot/i });
  await slotSelect.click();

  const slotOption = page.getByRole('option', { name: /9:00 AM/i });
  await expect(slotOption).toBeVisible();
  // Verify provider name is NOT in the option text
  await expect(slotOption).not.toContainText('Provider');
  await expect(slotOption).not.toContainText('Nurse');
  await expect(slotOption).not.toContainText('Dr');
  await slotOption.click();

  // Fill optional reason
  const reasonInput = dialog.getByPlaceholder('Brief reason for your visit');
  await reasonInput.fill('Annual check');

  // Submit booking
  const submitBtn = dialog.getByRole('button', { name: 'Book appointment', exact: true });
  await submitBtn.click();

  // Modal closes upon successful booking
  await expect(dialog).not.toBeVisible();
  await expect(page.getByText('Appointment booked.')).toBeVisible();

  // Verify the payload sent to the backend contained NO provider_user_id
  expect(bookedPayload).not.toBeNull();
  expect(bookedPayload?.['department']).toBe('clinic');
  expect(bookedPayload?.['starts_at']).toBe('2026-09-25T01:00:00Z');
  expect(bookedPayload?.['reason']).toBe('Annual check');
  expect(bookedPayload).not.toHaveProperty('provider_user_id');
});

test('slot picker disables and reports "No available time." when nothing is bookable', async ({ page }) => {
  await signIn(page, 'student');

  // Staff schedules are set, but every slot on the day is taken.
  await page.route('**/api/v1/me/appointment-slots**', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { slots: [] } }),
    }),
  );

  await page.getByRole('tab', { name: 'Appointments' }).click();
  await page.getByRole('button', { name: 'Book an appointment' }).click();

  const dialog = page.getByRole('dialog');
  await dialog.getByRole('button', { name: /pick a date/i }).click();
  const dayButton = page.locator('[role="gridcell"] button, button[name="day"], .rdp-day_button').filter({ hasNotText: '' }).first();
  await dayButton.click();

  // No thin empty dropdown: the control is disabled and the state is named.
  const slotSelect = dialog.getByRole('combobox').filter({ hasText: /no available time/i });
  await expect(slotSelect).toBeDisabled();
  await expect(dialog.getByText('No available time.')).toBeVisible();

  // Booking cannot proceed without a slot.
  await expect(
    dialog.getByRole('button', { name: 'Book appointment', exact: true }),
  ).toBeDisabled();
});

