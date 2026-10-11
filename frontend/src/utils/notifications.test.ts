import { describe, expect, it } from 'vitest';
import {
  getNotificationDestination,
  notificationDetail,
  notificationLabel,
  type NotificationContext,
} from './notifications';

type AuthParam = Parameters<typeof getNotificationDestination>[2];

function mockAuth(permissions: string[]): AuthParam {
  return {
    permissions,
  } as unknown as AuthParam;
}

describe('notificationLabel', () => {
  it('reads as a plain sentence and never shows internal record ids', () => {
    const context: NotificationContext = { resource_code: 'appointment#15' };
    expect(notificationLabel('appointment.scheduled', context)).toBe('Appointment booked');
    expect(notificationLabel('appointment.confirmed', context)).toBe('Appointment confirmed');
    expect(notificationLabel('referral.created', { resource_code: 'referral#2' })).toBe(
      'A new referral is waiting for you',
    );
    expect(notificationLabel('referral.queue_handoff', { resource_code: 'referral#2' })).toBe(
      'A referred patient was added to your queue',
    );
  });

  it('has copy for every appointment status the backend can send', () => {
    // `checked_in` and `completed` used to fall through and show the raw code.
    for (const status of ['assigned', 'scheduled', 'rescheduled', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show']) {
      const label = notificationLabel(`appointment.${status}`, null);
      expect(label).not.toContain('appointment.');
      expect(label).not.toContain('_');
    }
    expect(notificationLabel('appointment.checked_in', null)).toBe('Checked in for an appointment');
  });

  it('formats queue.called with destination', () => {
    expect(notificationLabel('queue.called', { destination: 'counselling' })).toBe("It's your turn — please go to Guidance");
    expect(notificationLabel('queue.called', { destination: 'clinic' })).toBe("It's your turn — please go to Clinic");
  });

  it('formats the skip-window auto no-show notice without the queue id', () => {
    expect(notificationLabel('queue.skip_expired', { resource_code: 'queue#12' })).toBe(
      'A skipped patient was automatically marked as no-show',
    );
  });

  it('keeps recognisable references (batch, device) but drops internal ids', () => {
    expect(notificationLabel('bmg.alert_triggered', { resource_code: 'BATCH-2026-014' })).toBe(
      'Equipment alert on batch BATCH-2026-014',
    );
    expect(notificationLabel('bmg.alert_triggered', { resource_code: 'batch#9' })).toBe(
      'Equipment alert needs attention',
    );
    expect(notificationLabel('bmg.device_silent', { resource_code: 'TUMBLER-01' })).toBe(
      'Device TUMBLER-01 stopped sending data — please check it',
    );
  });

  it('says which command never reached the drum, without leaking the raw code', () => {
    expect(notificationLabel('bmg.device_command_expired', { resource_code: 'drum-01', next_status: 'start' })).toBe(
      'The start command never reached drum drum-01 — the drum has not started turning',
    );
    expect(notificationLabel('bmg.device_command_expired', { resource_code: 'drum-01', next_status: 'stop' })).toBe(
      'The stop command never reached drum drum-01 — check that it has stopped',
    );
    expect(notificationLabel('bmg.device_command_expired', { resource_code: 'drum-01', next_status: 'run_now' })).toBe(
      'The run-now command never reached drum drum-01',
    );
    expect(notificationLabel('bmg.device_command_expired', { resource_code: 'unit#4', next_status: 'start' })).toBe(
      'The start command never reached a drum — the drum has not started turning',
    );
    expect(notificationLabel('bmg.device_command_expired', null)).toBe('A command never reached a drum');
  });

  it('falls back to a plain sentence, never a raw code, for unknown codes', () => {
    expect(notificationLabel('custom.unknown_event', null)).toBe('You have a new notification');
  });
});

describe('notificationDetail', () => {
  it('returns null when context is null', () => {
    expect(notificationDetail('appointment.scheduled', null)).toBeNull();
  });

  it('formats appointment details with Manila timezone', () => {
    const detail = notificationDetail('appointment.scheduled', {
      destination: 'clinic',
      appointment_at: '2026-09-15T01:30:00.000Z',
    });
    expect(detail).toContain('Clinic');
    expect(detail).toContain('Sep 15, 2026');
  });

  it('shows the Guidance office for counselling appointments', () => {
    const detail = notificationDetail('appointment.checked_in', {
      destination: 'counselling',
      appointment_at: '2026-09-15T01:30:00.000Z',
    });
    expect(detail).toContain('Guidance');
    expect(detail).not.toContain('counselling');
  });

  it('formats queue and referral details', () => {
    expect(notificationDetail('queue.called', { queue_number: 'Q-042' })).toBe('Your number: Q-042');
    expect(
      notificationDetail('referral.created', { source_module: 'counselling', target_module: 'clinic' }),
    ).toBe('From Guidance to Clinic');
  });

  it('formats the skip-window detail with the queue number', () => {
    expect(notificationDetail('queue.skip_expired', { queue_number: 'C-004' })).toBe(
      'Number C-004 · the time to call them back ran out',
    );
  });

  it('turns urgency and severity codes into plain words', () => {
    expect(notificationDetail('reorder.created', { urgency: 'high' })).toBe('How urgent: High');
    expect(notificationDetail('bmg.alert_triggered', { urgency: 'critical' })).toBe('How serious: Critical');
    expect(notificationDetail('bmg.device_silent', { urgency: 'warning' })).toBe('How serious: Needs a check');
    expect(notificationDetail('bmg.device_command_expired', { urgency: 'critical' })).toBe('How serious: Critical');
    expect(notificationDetail('bmg.device_command_expired', { urgency: 'warning' })).toBe('How serious: Needs a check');
  });
});

describe('getNotificationDestination', () => {
  describe('appointment.*', () => {
    it('routes to /me for portal patients with portal.appointments.read', () => {
      const auth = mockAuth(['portal.appointments.read']);
      expect(getNotificationDestination('appointment.scheduled', null, auth)).toBe('/me');
    });

    it('routes to /counselling?tab=scheduling for guidance staff', () => {
      const auth = mockAuth(['counselling.schedule.read']);
      const context: NotificationContext = { destination: 'counselling' };
      expect(getNotificationDestination('appointment.assigned', context, auth)).toBe(
        '/counselling?tab=scheduling',
      );
    });

    it('routes to /appointments for clinic staff with clinic.appointments.read', () => {
      const auth = mockAuth(['clinic.appointments.read']);
      expect(getNotificationDestination('appointment.assigned', null, auth)).toBe('/appointments');
    });

    it('returns null if appointment permissions are missing', () => {
      const auth = mockAuth(['notifications.read']);
      expect(getNotificationDestination('appointment.assigned', null, auth)).toBeNull();
    });
  });

  describe('module routing with permissions', () => {
    it('routes referral.* to /referrals when user has referrals.read', () => {
      const auth = mockAuth(['referrals.read']);
      expect(getNotificationDestination('referral.created', null, auth)).toBe('/referrals');
    });

    it('routes reorder.* to /inventory?tab=reorders when user has clinic.inventory.read', () => {
      const auth = mockAuth(['clinic.inventory.read']);
      expect(getNotificationDestination('reorder.created', null, auth)).toBe('/inventory?tab=reorders');
    });

    it('routes bmg.* to /facilities when user has facilities.units.read', () => {
      const auth = mockAuth(['facilities.units.read']);
      expect(getNotificationDestination('bmg.alert_triggered', null, auth)).toBe('/facilities');
    });

    it('routes queue.* and counselling.queue.* to /me for portal users', () => {
      const auth = mockAuth(['portal.queue.read']);
      expect(getNotificationDestination('queue.called', null, auth)).toBe('/me');
      expect(getNotificationDestination('counselling.queue_called', null, auth)).toBe('/me');
    });

    it('routes the skip-window auto no-show to the clinic board for staff, never the portal', () => {
      // A clinic manager sees the board where the resolved row lives …
      const staff = mockAuth(['clinic.queue.read', 'clinic.queue.manage']);
      expect(getNotificationDestination('queue.skip_expired', null, staff)).toBe('/clinic?tab=skipped');

      // … and even a superadmin (wildcard * includes portal.queue.read)
      // must land on the clinic board, not the patient portal.
      expect(getNotificationDestination('queue.skip_expired', null, mockAuth(['*']))).toBe(
        '/clinic?tab=skipped',
      );

      // A patient without clinic permissions gets no destination at all
      // (this template is never sent to patients, but the mapping must
      // not fall through to the portal branch).
      expect(getNotificationDestination('queue.skip_expired', null, mockAuth(['portal.queue.read']))).toBeNull();
    });

    it('routes counselling.* to the Queue board for guidance counselors', () => {
      // Sessions are Queue-only as of 2026-09-23 (third revision): an on-going
      // patient's row expands in place, and `?session=N` force-opens its row.
      // The Appointments book can no longer show a session, so it is the wrong
      // target for anything session-shaped.
      const auth = mockAuth(['counselling.records.read']);
      expect(getNotificationDestination('counselling.session_opened', null, auth)).toBe(
        '/counselling?tab=queue',
      );
    });

    it('routes admin.* to /admin/users when user has rbac.manage', () => {
      const auth = mockAuth(['rbac.manage']);
      expect(getNotificationDestination('admin.user_created', null, auth)).toBe('/admin/users');
    });

    it('returns null for templates with no reachable destination', () => {
      expect(getNotificationDestination('kiosk.settings_updated', null, mockAuth(['*']))).toBeNull();
    });

    it('supports superadmin wildcard * permission', () => {
      const auth = mockAuth(['*']);
      expect(getNotificationDestination('referral.created', null, auth)).toBe('/referrals');
      expect(getNotificationDestination('admin.user_created', null, auth)).toBe('/admin/users');
    });

    it('returns null when permission is missing', () => {
      const auth = mockAuth([]);
      expect(getNotificationDestination('referral.created', null, auth)).toBeNull();
      expect(getNotificationDestination('bmg.alert_triggered', null, auth)).toBeNull();
    });

    it('returns null for unmapped templates', () => {
      const auth = mockAuth(['*']);
      expect(getNotificationDestination('unknown.template', null, auth)).toBeNull();
    });
  });
});
