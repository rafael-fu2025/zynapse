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
  it('formats template codes with resource code suffix', () => {
    const context: NotificationContext = { resource_code: 'APT-100' };
    expect(notificationLabel('appointment.scheduled', context)).toBe('Appointment booked (APT-100)');
    expect(notificationLabel('referral.created', context)).toBe('New referral to handle (APT-100)');
  });

  it('formats queue.called with destination', () => {
    expect(notificationLabel('queue.called', { destination: 'counselling' })).toBe("You're up — proceed to Guidance");
    expect(notificationLabel('queue.called', { destination: 'clinic' })).toBe("You're up — proceed to Clinic");
  });

  it('falls back to raw template code for unknown codes', () => {
    expect(notificationLabel('custom.unknown_event', null)).toBe('custom.unknown_event');
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

  it('formats queue and referral details', () => {
    expect(notificationDetail('queue.called', { queue_number: 'Q-042' })).toBe('Q-042');
    expect(
      notificationDetail('referral.created', { source_module: 'Clinic', target_module: 'Guidance' }),
    ).toBe('Clinic → Guidance');
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

    it('routes counselling.* to /counselling?tab=sessions for guidance counselors', () => {
      const auth = mockAuth(['counselling.records.read']);
      expect(getNotificationDestination('counselling.session_opened', null, auth)).toBe(
        '/counselling?tab=sessions',
      );
    });

    it('routes admin.* to /admin/users when user has rbac.manage', () => {
      const auth = mockAuth(['rbac.manage']);
      expect(getNotificationDestination('admin.user_created', null, auth)).toBe('/admin/users');
    });

    it('routes kiosk.* to /admin/kiosk-settings when user has kiosk.content.manage', () => {
      const auth = mockAuth(['kiosk.content.manage']);
      expect(getNotificationDestination('kiosk.settings_updated', null, auth)).toBe(
        '/admin/kiosk-settings',
      );
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
