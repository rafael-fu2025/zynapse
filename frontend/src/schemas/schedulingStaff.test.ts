import { describe, expect, it } from 'vitest';
import { schedulingEnrollmentSchema, schedulingStaffPageSchema, schedulingStaffSchema, schedulingTemplateSchema } from './schedulingStaff';

const employee = { user_id: 41, employee_number: 'EMP-41', name: 'Directory Employee',
  department: 'Guidance', position: 'Counsellor', employment_status: 'active', assignment_id: null,
  enrollment_status: null, eligible: true, can_enroll: true, status: 'not_enrolled', future_appointments: 0 };
const template = { user_ids: [41], days_of_week: [1, 3, 5], start_time: '08:00', end_time: '17:00' };

describe('scheduling staff contracts', () => {
  it('accepts an employee who has no scheduling enrollment or login metadata', () => {
    expect(schedulingStaffSchema.parse(employee)).toEqual(employee);
  });

  it('keeps directory eligibility separate from inactive enrollment', () => {
    const row = schedulingStaffSchema.parse({ ...employee, assignment_id: 9,
      enrollment_status: 'inactive', can_enroll: false, status: 'inactive_enrollment' });
    expect(row.eligible).toBe(true);
    expect(row.enrollment_status).toBe('inactive');
  });

  it.each(['inactive_employee', 'department_mismatch', 'needs_review'])(
    'represents %s staff and commitments without losing history', (status) => {
      const row = schedulingStaffSchema.parse({ ...employee, status, assignment_id: 9,
        eligible: false, can_enroll: false, enrollment_status: 'active', future_appointments: 2 });
      expect(row.future_appointments).toBe(2);
      expect(row.eligible).toBe(false);
    },
  );

  it('keeps pagination and explicit department aliases in the response', () => {
    expect(schedulingStaffPageSchema.parse({ items: [employee], next_cursor: 'opaque',
      department_keys: ['guidance'] }).next_cursor).toBe('opaque');
  });

  it.each([[], [0], [-1], ['41'], [1.5], Array.from({ length: 101 }, (_, i) => i + 1)]
    .map((user_ids) => ({ user_ids })))(
    'rejects invalid enrollment selection %#', (input) => {
      expect(schedulingEnrollmentSchema.safeParse(input).success).toBe(false);
    },
  );

  it('accepts explicitly selected weekdays and preserves employee IDs', () => {
    expect(schedulingTemplateSchema.parse(template)).toEqual(template);
  });

  it.each([
    { days_of_week: [] }, { days_of_week: [7] }, { days_of_week: [-1] },
    { start_time: '25:00' }, { end_time: '08:00' }, { start_time: '18:00' },
    { user_ids: [] }, { start_time: '8:00' },
  ])('rejects malformed or reversed templates %#', (changes) => {
    expect(schedulingTemplateSchema.safeParse({ ...template, ...changes }).success).toBe(false);
  });
});
