import { expect, test as base, type Locator, type Page, type Route } from '@playwright/test';
import { signInMocked } from './helpers/auth';
import type { Appointment, AppointmentTransition } from '../src/schemas/appointments';

/** CI-safe staff workflow: no live backend, appointment token, or RFID data. */
const appointment: Appointment = {
  id: 31,
  patient_school_id: '2026-0091',
  patient_name: 'Maria Reyes',
  patient_kind: 'student',
  provider_user_id: 8,
  provider_name: 'Santos, Ana',
  scheduled_at: '2026-10-15 06:30:00',
  status: 'scheduled',
  reason: 'Annual check',
  encounter_id: null,
  created_at: '2026-10-08 00:00:00',
};

// The registry uses one person schema for BOTH student searches and employees.
// Populate even the other kind's nullable fields; partial mocks fail Zod parsing.
const person = {
  middle_name: null,
  date_of_birth: null,
  gender: null,
  address: null,
  has_qr: false,
  archived: false,
  created_at: '2026-10-08 00:00:00',
  student_number: null,
  course: null,
  year_level: null,
  section: null,
  blood_type: null,
  consecutive_no_shows: 0,
  employee_number: null,
  department: null,
  position: null,
  date_hired: null,
  employment_status: null,
  hr_synced_at: null,
  emergency_contact_name: null,
  emergency_contact_phone: null,
  is_teaching: null,
};
const patient = {
  ...person, id: 91, kind: 'student', first_name: 'Maria', last_name: 'Reyes',
  student_number: appointment.patient_school_id, course: 'BS Nursing', year_level: 2,
};
const provider = {
  ...person, id: 8, kind: 'employee', first_name: 'Ana', last_name: 'Santos',
  employee_number: 'E-0008', department: 'Clinic', position: 'Nurse',
  employment_status: 'active', is_teaching: false,
};
const encounter = {
  id: 91,
  patient_school_id: appointment.patient_school_id,
  patient_name: appointment.patient_name,
  appointment_id: appointment.id,
  station_id: null,
  chief_complaint: appointment.reason,
  triage_priority: null,
  triage_override: false,
  diagnosis: null,
  status: 'open',
  outcome: null,
  attending_user_id: 8,
  started_at: '2026-10-15 06:30:00',
  closed_at: null,
  archived_at: null,
  queue_entry_id: 9,
  queue_number: 'C-009',
  queue_status: 'waiting',
  queue_called_at: null,
  queue_started_at: null,
  queue_finished_at: null,
  incoming_referral_id: null,
  vitals_count: 0,
  treatment_count: 0,
  assessment_recorded: false,
  outgoing_referral: null,
};
const pagination = { pagination: { limit: 25, next_cursor: null, prev_cursor: null } };

type Payload = Record<string, unknown>;
interface AppointmentApi {
  rows: Appointment[];
  creates: Payload[];
  updates: Payload[];
  transitions: Payload[];
  details: number[];
  encounterDetails: number[];
  qrRequests: string[];
  unexpectedRequests: string[];
}

async function fulfill(route: Route, data: unknown, meta: unknown = null, status = 200) {
  await route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data, errors: [], meta }),
  });
}

const test = base.extend<{ api: AppointmentApi }>({
  api: async ({ page }, use) => {
    const api: AppointmentApi = {
      rows: [{ ...appointment }], creates: [], updates: [], transitions: [],
      details: [], encounterDetails: [], qrRequests: [], unexpectedRequests: [],
    };
    // Freeze only Date, not timers: debounced patient searches still execute.
    await page.clock.setFixedTime(new Date('2026-10-08T00:00:00Z'));
    await page.route('**/api/v1/**', async (route) => {
      const request = route.request();
      const url = new URL(request.url());
      const path = url.pathname.replace('/api/v1', '');
      const method = request.method();
      // Catch issue/reissue/verify and RFID attempts, without letting any
      // request fall through to a live backend (including on regressions).
      if (/\/qr(?:\/|$)|\/appointments\/verify(?:\/|$)|rfid/i.test(path)) {
        api.qrRequests.push(`${method} ${path}`);
        await fulfill(route, null);
        return;
      }
      if (method === 'GET' && path === '/notifications') {
        await fulfill(route, [], pagination);
        return;
      }
      if (method === 'GET' && path === '/reports/clinic') {
        await fulfill(route, {
          range: { start: url.searchParams.get('start'), end: url.searchParams.get('end') },
          total_encounters: 0, status_breakdown: [], daily_trend: [], complaint_categories: [],
          referral_flows: [], monthly_visits: [], most_common_medications: [],
          patient_type_breakdown: [], unique_patients: 0, avg_visits_per_patient: 0, avg_per_day: 0,
        });
        return;
      }
      if (method === 'GET' && path === '/clinic/employees') {
        await fulfill(route, [provider], pagination);
        return;
      }
      if (method === 'GET' && path === '/clinic/students/search') {
        await fulfill(route, [patient]);
        return;
      }
      if (path === '/clinic/appointments') {
        if (method === 'GET') {
          const status = url.searchParams.get('status');
          const providerFilter = url.searchParams.get('provider');
          const rows = api.rows.filter((row) =>
            (status === null || row.status === status)
            && (providerFilter !== 'assigned' || row.provider_user_id !== null)
            && (providerFilter !== 'unassigned' || row.provider_user_id === null));
          await fulfill(route, rows, pagination);
          return;
        }
        if (method === 'POST') {
          const payload = request.postDataJSON() as Payload;
          api.creates.push(payload);
          const created = { ...appointment, id: 32, ...payload } as Appointment;
          api.rows.push(created);
          // Intentionally NO qr_token in booking, list, detail, or transition.
          await fulfill(route, created, null, 201);
          return;
        }
      }
      const match = /^\/clinic\/appointments\/(\d+)(\/transition)?$/.exec(path);
      if (match !== null) {
        const id = Number(match[1]);
        const row = api.rows.find((item) => item.id === id);
        if (row !== undefined && method === 'GET' && match[2] === undefined) {
          api.details.push(id);
          await fulfill(route, row);
          return;
        }
        if (row !== undefined && method === 'POST') {
          const payload = request.postDataJSON() as Payload;
          if (match[2] !== undefined) {
            api.transitions.push(payload);
            row.status = payload.status as AppointmentTransition;
            if (row.status === 'confirmed') row.provider_user_id = provider.id;
            if (row.status === 'checked_in') row.encounter_id = encounter.id;
          } else {
            api.updates.push(payload);
            Object.assign(row, payload);
          }
          await fulfill(route, row);
          return;
        }
      }
      if (method === 'GET' && path === '/clinic/encounters') {
        await fulfill(route, [], pagination);
        return;
      }
      if (method === 'GET' && path === `/clinic/encounters/${encounter.id}`) {
        api.encounterDetails.push(encounter.id);
        await fulfill(route, encounter);
        return;
      }
      if (method === 'GET' && path === '/clinic/queue') {
        await fulfill(route, []);
        return;
      }
      api.unexpectedRequests.push(`${method} ${path}${url.search}`);
      await route.abort();
    });
    await use(api);
    expect(api.qrRequests, 'No appointment QR/verify or RFID APIs may be called').toEqual([]);
    expect(api.unexpectedRequests, 'All API calls must be mocked; no live data').toEqual([]);
  },
});

test.use({ timezoneId: 'Asia/Manila', locale: 'en-US' });

async function openAppointments(page: Page, tab = 'all') {
  await signInMocked(page, {
    id: provider.id, email: 'nurse@example.test', username: 'clinic-nurse',
    is_active: true, force_reset: false,
    permissions: [
      'clinic.appointments.read', 'clinic.appointments.write', 'clinic.patients.read',
      'clinic.encounters.read', 'clinic.encounters.write', 'clinic.queue.read',
    ],
  }, { mockRefresh: true });
  await page.goto(`/appointments?tab=${tab}`);
  // The in-page h1 is hidden on mobile (the topbar carries the title), so
  // anchor on the search box that both layouts render.
  await expect(page.getByRole('textbox', { name: 'Search appointments' })).toBeVisible();
}

function appointmentSurface(page: Page, mobile: boolean, id = appointment.id): Locator {
  // Both responsive surfaces are mounted: scope explicitly instead of first()
  // so a hidden desktop button cannot accidentally stand in for the mobile UI.
  return mobile
    ? page.getByRole('listitem', { name: `Appointment ${id}`, exact: true })
    : page.getByRole('table', { name: 'Clinic appointments' }).getByRole('row')
      .filter({ has: page.getByRole('button', { name: `Actions for appointment #${id}`, exact: true }) });
}

async function openActions(page: Page, surface: Locator, id = appointment.id) {
  await surface.getByRole('button', { name: `Actions for appointment #${id}`, exact: true }).click();
  const menu = page.getByRole('menu');
  await expect(menu).toBeVisible();
  await expect(menu.getByRole('menuitem', { name: 'Appointment QR', exact: true })).toHaveCount(0);
  await expect(menu.getByRole('menuitem', { name: 'View appointment', exact: true })).toBeVisible();
  return menu;
}

async function selectTime(page: Page, dialog: Locator, hour: string, minute: string, meridiem: string) {
  for (const [label, value] of [['Hour', hour], ['Minute', minute], ['AM/PM', meridiem]] as const) {
    await dialog.getByRole('combobox', { name: label, exact: true }).click();
    await page.getByRole('option', { name: value, exact: true }).click();
  }
}

for (const mobile of [false, true]) {
  test.describe(mobile ? 'mobile appointment workflow' : 'desktop appointment workflow', () => {
    test.use({ viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 900 } });

    test('schedules without qr_token and closes all dialogs without issuing a QR', async ({ page, api }) => {
      api.rows = [];
      await openAppointments(page);
      await page.getByRole('button', { name: 'Schedule', exact: true }).click();
      const dialog = page.getByRole('dialog', { name: 'Schedule appointment', exact: true });
      await dialog.getByRole('combobox', { name: 'Patient school ID' }).fill('Maria');
      await page.getByRole('option', { name: /2026-0091.*Reyes, Maria/ }).click();
      await expect(dialog.getByRole('combobox', { name: 'Patient school ID' })).toHaveValue(appointment.patient_school_id);
      await dialog.getByRole('combobox', { name: 'Provider', exact: true }).click();
      await page.getByRole('option', { name: 'Santos, Ana — Clinic', exact: true }).click();
      await dialog.getByLabel('Scheduled at (Asia/Manila)', { exact: true }).click();
      await page.getByRole('button', { name: /October 15.*2026/ }).click();
      await selectTime(page, dialog, '2', '30', 'PM');
      await expect(dialog.getByText('Stored as 2026-10-15 06:30:00 UTC.')).toBeVisible();
      await dialog.getByLabel('Reason (optional)', { exact: true }).fill('Annual check');
      await dialog.getByRole('button', { name: 'Schedule', exact: true }).click();
      await expect(dialog).toBeHidden();
      await expect(page.getByText('Appointment #32 scheduled.', { exact: true })).toBeVisible();
      const created = appointmentSurface(page, mobile, 32);
      await expect(created).toBeVisible();
      await expect(created.getByText('Annual check', { exact: true })).toBeVisible();
      await expect(page.getByRole('dialog')).toHaveCount(0);
      expect(api.creates).toEqual([{
        patient_school_id: appointment.patient_school_id, provider_user_id: provider.id,
        scheduled_at: '2026-10-15 06:30:00', reason: 'Annual check',
      }]);
      expect(api.rows[0]).not.toHaveProperty('qr_token');
      await openActions(page, created, 32);
      await page.keyboard.press('Escape');
    });

    test('action menu omits Appointment QR while view and edit still work', async ({ page, api }) => {
      await openAppointments(page);
      const surface = appointmentSurface(page, mobile);
      let menu = await openActions(page, surface);
      await expect(menu.getByRole('menuitem', { name: 'Edit appointment', exact: true })).toBeVisible();
      await menu.getByRole('menuitem', { name: 'View appointment', exact: true }).click();
      let detail = page.getByRole('dialog', { name: 'Appointment #31', exact: true });
      // Name, kind badge and school id share one <dd>, so match by substring.
      await expect(detail.getByText('Maria Reyes')).toBeVisible();
      await expect(detail.getByText(appointment.patient_school_id)).toBeVisible();
      await expect(detail.getByText('Santos, Ana', { exact: true })).toBeVisible();
      await expect(detail.getByText('Annual check', { exact: true })).toBeVisible();
      expect(api.details).toContain(31);
      await detail.getByRole('button', { name: 'Close', exact: true }).first().click();
      menu = await openActions(page, surface);
      await menu.getByRole('menuitem', { name: 'Edit appointment', exact: true }).click();
      const edit = page.getByRole('dialog', { name: 'Edit appointment #31', exact: true });
      await expect(edit.getByRole('combobox', { name: 'Patient school ID' })).toHaveValue(appointment.patient_school_id);
      await expect(edit.getByRole('combobox', { name: 'Provider', exact: true })).toContainText('Santos, Ana');
      await expect(edit.getByRole('combobox', { name: 'Hour', exact: true })).toContainText('2');
      await expect(edit.getByRole('combobox', { name: 'Minute', exact: true })).toContainText('30');
      await expect(edit.getByRole('combobox', { name: 'AM/PM', exact: true })).toContainText('PM');
      // Re-select the patient: the shared picker opens its search on edit.
      await page.getByRole('option', { name: /2026-0091.*Reyes, Maria/ }).click();
      await edit.getByLabel('Scheduled at (Asia/Manila)', { exact: true }).click();
      await page.getByRole('button', { name: /October 16.*2026/ }).click();
      await selectTime(page, edit, '3', '45', 'PM');
      await edit.getByLabel('Reason (optional)', { exact: true }).fill('Follow-up assessment');
      await edit.getByRole('button', { name: 'Save changes', exact: true }).click();
      await expect(edit).toBeHidden();
      await expect(page.getByText('Appointment #31 updated.', { exact: true })).toBeVisible();
      await expect(surface.getByText('Follow-up assessment', { exact: true })).toBeVisible();
      expect(api.updates).toEqual([{
        patient_school_id: appointment.patient_school_id, provider_user_id: provider.id,
        scheduled_at: '2026-10-16 07:45:00', reason: 'Follow-up assessment',
      }]);
      await expect(page.getByRole('dialog')).toHaveCount(0);
      menu = await openActions(page, surface);
      await menu.getByRole('menuitem', { name: 'View appointment', exact: true }).click();
      detail = page.getByRole('dialog', { name: 'Appointment #31', exact: true });
      await expect(detail.getByText('Follow-up assessment', { exact: true })).toBeVisible();
      await detail.getByRole('button', { name: 'Close', exact: true }).first().click();
    });

    test('confirms a booking and manually checks in to the exact linked encounter', async ({ page, api }) => {
      api.rows = [{ ...appointment, provider_user_id: null, provider_name: null }];
      await openAppointments(page, 'needs-action');
      let surface = appointmentSurface(page, mobile);
      await surface.getByRole('button', { name: 'Confirm', exact: true }).click();
      await expect(page.getByText('Appointment #31 → confirmed.', { exact: true })).toBeVisible();
      await expect(surface).toHaveCount(0);
      expect(api.transitions).toEqual([{ status: 'confirmed' }]);
      // Confirmation assigns the acting provider and moves the portal booking
      // into Upcoming; neither approval nor check-in supplies any scan token.
      await page.goto('/appointments');
      surface = appointmentSurface(page, mobile);
      await expect(surface.getByText('Confirmed', { exact: true })).toBeVisible();
      await expect(surface.getByRole('button', { name: 'Confirm', exact: true })).toHaveCount(0);
      await openActions(page, surface);
      await page.keyboard.press('Escape');
      await surface.getByRole('button', { name: 'Check in', exact: true }).click();
      await expect(surface.getByText('Checked in', { exact: true })).toBeVisible();
      const visit = surface.getByRole('link', { name: 'Visit #91', exact: true });
      await expect(visit).toHaveAttribute('href', '/clinic?encounter=91');
      await expect(surface.getByRole('button', { name: 'Check in', exact: true })).toHaveCount(0);
      await expect(surface.getByRole('button', { name: /complete/i })).toHaveCount(0);
      expect(api.transitions).toEqual([{ status: 'confirmed' }, { status: 'checked_in' }]);
      expect(api.rows[0]?.encounter_id).toBe(91);
      expect(api.rows[0]).not.toHaveProperty('qr_token');
      await openActions(page, surface);
      await expect(page.getByRole('menuitem', { name: 'Edit appointment', exact: true })).toHaveCount(0);
      await page.keyboard.press('Escape');
      await visit.click();
      await expect(page).toHaveURL(/\/clinic\?encounter=91$/);
      const workspace = page.getByRole('dialog', { name: 'Maria Reyes', exact: true });
      await expect(workspace).toBeVisible();
      await expect(workspace.getByText(/2026-0091 · Encounter #91/)).toBeVisible();
      expect(api.encounterDetails).toContain(91);
      await workspace.getByRole('button', { name: /^Session Started/ }).click();
      await expect(workspace.getByText('#31', { exact: true })).toBeVisible();
      await page.reload();
      await expect(page).toHaveURL(/\/clinic\?encounter=91$/);
      await expect(workspace).toBeVisible();
    });

    for (const action of [
      { menu: 'Cancel appointment', title: 'Cancel appointment #31?', status: 'cancelled', label: 'Cancelled' },
      { menu: 'Mark no-show', title: 'Mark appointment #31 as no-show?', status: 'no_show', label: 'No show' },
    ]) {
      test(`${action.menu} requires confirmation and updates the appointment without scanning`, async ({ page, api }) => {
        await openAppointments(page);
        const surface = appointmentSurface(page, mobile);
        let menu = await openActions(page, surface);
        await menu.getByRole('menuitem', { name: action.menu, exact: true }).click();
        let confirmation = page.getByRole('dialog', { name: action.title, exact: true });
        await expect(confirmation.getByText(/This cannot be undone\./)).toBeVisible();
        expect(api.transitions).toEqual([]);
        await confirmation.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(confirmation).toBeHidden();
        await expect(surface.getByText('Scheduled', { exact: true })).toBeVisible();
        expect(api.transitions).toEqual([]);
        menu = await openActions(page, surface);
        await menu.getByRole('menuitem', { name: action.menu, exact: true }).click();
        confirmation = page.getByRole('dialog', { name: action.title, exact: true });
        await confirmation.getByRole('button', { name: action.menu, exact: true }).click();
        await expect(confirmation).toBeHidden();
        await expect(surface.getByText(action.label, { exact: true })).toBeVisible();
        expect(api.transitions).toEqual([{ status: action.status }]);
        await expect(surface.getByRole('button', { name: 'Confirm', exact: true })).toHaveCount(0);
        await expect(surface.getByRole('button', { name: 'Check in', exact: true })).toHaveCount(0);
        menu = await openActions(page, surface);
        for (const name of ['Edit appointment', 'Cancel appointment', 'Mark no-show']) {
          await expect(menu.getByRole('menuitem', { name, exact: true })).toHaveCount(0);
        }
        await page.keyboard.press('Escape');
      });
    }
  });
}
