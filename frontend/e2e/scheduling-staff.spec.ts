import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';
import type { SchedulingStaff, SchedulingUnit } from '../src/schemas/schedulingStaff';

test.describe.configure({ timeout: 90_000 });
const ready = expect.configure({ timeout: 20_000 });
const screen = (unit: SchedulingUnit) => unit === 'clinic' ? '/clinic?tab=staff' : '/counselling?tab=scheduling&view=list';
const panelName = (unit: SchedulingUnit) => `${unit === 'clinic' ? 'Clinic' : 'Guidance'} department staff scheduling`;

function employee(unit: SchedulingUnit, changes: Partial<SchedulingStaff> = {}): SchedulingStaff {
  return { user_id: 41, employee_number: 'EMP-41', name: 'Ada Directory',
    department: unit === 'clinic' ? 'Health Services' : 'Guidance', position: 'Employee',
    employment_status: 'active', assignment_id: null, enrollment_status: null,
    eligible: true, can_enroll: true, status: 'not_enrolled', future_appointments: 0, ...changes };
}

async function session(page: Page, unit: SchedulingUnit, manager = true) {
  await page.route('**/api/v1/**', (route) => route.fulfill({ json: { success: true, data: [], errors: [], meta: null } }));
  const permissions = unit === 'clinic' ? ['clinic.encounters.read', 'clinic.schedules.manage']
    : ['counselling.records.read', 'counselling.schedule.read', 'counselling.schedule.manage',
      ...(manager ? ['counselling.schedule.team_manage'] : [])];
  await signInMocked(page, { id: 8, email: 'scheduler@example.test', username: 'scheduler',
    is_active: true, force_reset: false, permissions }, { mockRefresh: true });
}

for (const unit of ['clinic', 'guidance'] as const) {
  test(`${unit}: directory discovery, explicit enrollment, preserved templates and enrollment status`, async ({ page }) => {
    await session(page, unit);
    const target = employee(unit);
    const inactive = employee(unit, { user_id: 42, employee_number: 'EMP-42', name: 'Ben Inactive',
      employment_status: 'inactive', eligible: false, can_enroll: false, status: 'inactive_employee' });
    const transferred = employee(unit, { user_id: 43, employee_number: 'EMP-43', name: 'Chen Transferred',
      department: 'Other department', assignment_id: 77, enrollment_status: 'active',
      eligible: false, can_enroll: false, status: 'department_mismatch', future_appointments: 2 });
    const mutations: Array<{ path: string; body: Record<string, unknown> }> = [];
    await page.route(`**/api/v1/scheduling/staff/${unit}**`, (route) => {
      const url = new URL(route.request().url());
      if (route.request().method() === 'POST') {
        const body = route.request().postDataJSON() as Record<string, unknown>;
        mutations.push({ path: url.pathname, body });
        if (url.pathname.endsWith('/enroll')) {
          expect(body).toEqual({ user_ids: [41] });
          Object.assign(target, { assignment_id: 91, enrollment_status: 'active', can_enroll: false, status: 'ready' });
          return route.fulfill({ json: { success: true, data: { created: 1, skipped: 0 }, errors: [], meta: null } });
        }
        if (url.pathname.endsWith('/template')) {
          expect(body).toEqual({ user_ids: [41], days_of_week: [1, 2, 3, 4, 5],
            start_time: '08:00', end_time: '17:00', schedule_type: 'regular' });
          target.status = 'configured';
          return route.fulfill({ json: { success: true, data: { created: 4, skipped: 1 }, errors: [], meta: null } });
        }
        expect(url.pathname).toContain('/assignments/91/status');
        target.enrollment_status = body['active'] ? 'active' : 'inactive';
        target.status = body['active'] ? 'configured' : 'inactive_enrollment';
        return route.fulfill({ json: { success: true, data: target, errors: [], meta: null } });
      }
      const data = url.pathname.endsWith('/providers') ? target.enrollment_status === 'active' ? [{ id: 41, name: target.name }] : []
        : { items: [target, inactive, transferred], next_cursor: null, department_keys: [unit === 'clinic' ? 'health services' : 'guidance'] };
      return route.fulfill({ json: { success: true, data, errors: [], meta: null } });
    });
    await page.goto(screen(unit));
    const panel = page.getByRole('region', { name: panelName(unit) });
    await ready(panel).toBeVisible();
    await expect(panel.getByRole('checkbox', { name: 'Enroll Ada Directory', exact: true })).toBeEnabled();
    await expect(panel.getByRole('checkbox', { name: 'Schedule Ada Directory', exact: true })).toBeDisabled();
    await expect(panel.getByRole('checkbox', { name: 'Enroll Ben Inactive', exact: true })).toBeDisabled();
    await expect(panel.getByRole('checkbox', { name: 'Schedule Chen Transferred', exact: true })).toBeDisabled();
    await expect(panel).toContainText('Review existing commitments');
    await expect(panel).toContainText('Enrollment does not grant roles, credentials, or record access.');
    await panel.getByRole('checkbox', { name: 'Enroll Ada Directory', exact: true }).check();
    await panel.getByRole('button', { name: 'Enroll selected (1)', exact: true }).click();
    await expect(panel.getByRole('checkbox', { name: 'Schedule Ada Directory', exact: true })).toBeEnabled();
    await panel.getByRole('checkbox', { name: 'Schedule Ada Directory', exact: true }).check();
    await panel.getByRole('button', { name: 'Schedule selected (1)', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Configure 1 enrolled staff' });
    await expect(dialog).toContainText('skipped, not overwritten');
    await dialog.getByRole('button', { name: 'Add weekly hours', exact: true }).click();
    await expect(dialog).toHaveCount(0);
    await expect(panel.getByRole('button', { name: 'Schedule selected (0)', exact: true })).toBeDisabled();
    await expect(panel.getByRole('row').filter({ hasText: 'Ada Directory' })).toContainText('Has schedule records');
    const row = panel.getByRole('row').filter({ hasText: 'Ada Directory' });
    await row.getByRole('button', { name: 'Deactivate', exact: true }).click();
    const deactivate = page.getByRole('dialog', { name: 'Deactivate Ada Directory?' });
    await expect(deactivate).toContainText('retained, not cancelled');
    await deactivate.getByRole('button', { name: 'Deactivate enrollment', exact: true }).click();
    await expect(row.getByRole('button', { name: 'Reactivate', exact: true })).toBeEnabled();
    await expect(row.getByRole('checkbox', { name: 'Schedule Ada Directory', exact: true })).toBeDisabled();
    await row.getByRole('button', { name: 'Reactivate', exact: true }).click();
    await page.getByRole('dialog', { name: 'Reactivate Ada Directory?' }).getByRole('button', { name: 'Reactivate enrollment', exact: true }).click();
    await expect(row.getByRole('checkbox', { name: 'Schedule Ada Directory', exact: true })).toBeEnabled();
    expect(mutations).toHaveLength(4);
  });
}

test('department selection is page-scoped and cleared by search or pagination', async ({ page }) => {
  await session(page, 'clinic');
  await page.route('**/api/v1/scheduling/staff/clinic?**', (route) => {
    const params = new URL(route.request().url()).searchParams;
    return route.fulfill({ json: { success: true, data: { items: [employee('clinic', params.has('cursor')
      ? { user_id: 99, name: 'Next Employee' } : {})], next_cursor: params.has('cursor') ? null : 'next-page',
      department_keys: ['health services'] }, errors: [], meta: null } });
  });
  await page.goto(screen('clinic'));
  const panel = page.getByRole('region', { name: panelName('clinic') });
  await ready(panel.getByRole('checkbox', { name: 'Enroll Ada Directory', exact: true })).toBeEnabled();
  await panel.getByRole('checkbox', { name: 'Select this page for enrollment', exact: true }).check();
  await expect(panel.getByRole('button', { name: 'Enroll selected (1)', exact: true })).toBeEnabled();
  await panel.getByRole('button', { name: 'Next page', exact: true }).click();
  await expect(page).toHaveURL(/clinic-scheduling-staff-cursor=next-page/);
  await expect(panel).toContainText('Next Employee');
  await expect(panel.getByRole('button', { name: 'Enroll selected (0)', exact: true })).toBeDisabled();
  await panel.getByRole('checkbox', { name: 'Enroll Next Employee', exact: true }).check();
  await panel.getByLabel('Find department staff').fill('Ada');
  await expect(page).toHaveURL(/clinic-scheduling-staff-q=Ada/);
  await expect(panel).toContainText('Ada Directory');
  await expect(panel.getByRole('button', { name: 'Enroll selected (0)', exact: true })).toBeDisabled();
  expect(new URL(page.url()).searchParams.has('clinic-scheduling-staff-cursor')).toBe(false);
});

test('self-scheduling staff cannot discover or enroll other employees', async ({ page }) => {
  let discoveries = 0;
  await session(page, 'guidance', false);
  await page.route('**/api/v1/scheduling/staff/guidance**', (route) => {
    discoveries++;
    return route.fulfill({ status: 403, json: { success: false, data: null,
      errors: [{ code: 'rbac.permission_denied', message: 'Team management required.' }], meta: null } });
  });
  await page.goto(screen('guidance'));
  await ready(page.getByText('Availability windows', { exact: true })).toBeVisible();
  await expect(page.getByRole('region', { name: panelName('guidance') })).toHaveCount(0);
  expect(discoveries).toBe(0);
});
