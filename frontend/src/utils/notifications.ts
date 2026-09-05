/**
 * Notification copy — friendly labels + detail lines for the bell and
 * the Notifications page. Shared so the two surfaces never drift.
 *
 * Context is whitelisted server-side (resource ids, status/module codes,
 * urgency) — NEVER PII — so these renderers only ever see safe values.
 */

export interface NotificationContext {
  resource_code?: unknown;
  next_status?: unknown;
  scheduled_at?: unknown;
  source_module?: unknown;
  target_module?: unknown;
  urgency?: unknown;
  position?: unknown;
  destination?: unknown;
  appointment_at?: unknown;
  appointment_status?: unknown;
  queue_number?: unknown;
}

function resourceCode(context: NotificationContext | null): string {
  return typeof context?.resource_code === 'string' ? context.resource_code : '';
}

export function notificationLabel(
  templateCode: string,
  context: NotificationContext | null,
): string {
  const res = resourceCode(context);
  const suffix = res !== '' ? `(${res})` : '';
  switch (templateCode) {
    case 'appointment.assigned':
      return `New appointment assigned ${suffix}`.trim();
    case 'appointment.scheduled': return `Appointment booked ${suffix}`.trim();
    case 'appointment.rescheduled': return `Appointment rescheduled ${suffix}`.trim();
    case 'appointment.confirmed': return `Appointment confirmed ${suffix}`.trim();
    case 'appointment.cancelled': return `Appointment cancelled ${suffix}`.trim();
    case 'appointment.no_show':
      return `Appointment marked no-show ${suffix}`.trim();
    case 'referral.created':
      return `New referral to handle ${suffix}`.trim();
    case 'referral.acknowledged':
      return `Referral acknowledged ${suffix}`.trim();
    case 'referral.closed':
      return `Referral closed ${suffix}`.trim();
    case 'reorder.created':
      return `Low stock — reorder created ${suffix}`.trim();
    case 'queue.called':
    case 'counselling.queue_called': {
      const destination = context?.destination === 'counselling' ? 'Guidance' : 'Clinic';
      return `You're up — proceed to ${destination} ${suffix}`.trim();
    }
    case 'bmg.alert_triggered':
      return `BMG alert on batch ${suffix}`.trim();
    case 'referral.queue_handoff':
      return `Referral moved to queue ${suffix}`.trim();
    case 'referral.qr_issued':
      return `Referral QR issued ${suffix}`.trim();
    case 'referral.qr_revoked':
      return `Referral QR revoked ${suffix}`.trim();
    case 'counselling.session_opened':
      return `Guidance session opened ${suffix}`.trim();
    case 'counselling.session_closed':
      return `Guidance session closed ${suffix}`.trim();
    case 'counselling.session_reassigned':
      return `Guidance session reassigned to you ${suffix}`.trim();
    case 'counselling.appointment_booked':
      return `Guidance appointment booked ${suffix}`.trim();
    case 'kiosk.media_uploaded':
      return `Kiosk media uploaded ${suffix}`.trim();
    case 'kiosk.media_archived':
      return `Kiosk media archived ${suffix}`.trim();
    case 'kiosk.media_restored':
      return `Kiosk media restored ${suffix}`.trim();
    case 'kiosk.settings_updated':
      return `Kiosk settings updated ${suffix}`.trim();
    case 'admin.user_created':
      return 'New user account created';
    case 'admin.user_groups_changed':
      return 'User roles changed';
    case 'admin.user_password_reset':
      return 'User password reset';
    case 'admin.user_status_changed':
      return 'User account status changed';
    case 'admin.portal_account_minted':
      return 'Portal account minted for patient';
    default:
      return templateCode;
  }
}

/** Optional secondary line (module direction, urgency, …). */
export function notificationDetail(
  templateCode: string,
  context: NotificationContext | null,
): string | null {
  if (context === null) return null;
  if (templateCode.startsWith('appointment.') && typeof context.appointment_at === 'string') {
    const destination = context.destination === 'counselling' ? 'Guidance' : 'Clinic';
    return `${destination} · ${new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(context.appointment_at))}`;
  }
  if ((templateCode === 'queue.called' || templateCode === 'counselling.queue_called') && typeof context.queue_number === 'string') {
    return context.queue_number;
  }
  if (
    (templateCode === 'referral.created' || templateCode === 'referral.acknowledged') &&
    typeof context.source_module === 'string' &&
    typeof context.target_module === 'string'
  ) {
    return `${context.source_module} → ${context.target_module}`;
  }
  if (templateCode === 'reorder.created' && typeof context.urgency === 'string') {
    return `Urgency: ${context.urgency}`;
  }
  if (templateCode === 'queue.called' && typeof context.position === 'number') {
    return `Queue ${String(context.position).padStart(3, '0')}`;
  }
  if (templateCode === 'bmg.alert_triggered' && typeof context.urgency === 'string') {
    const sev = context.urgency.toUpperCase();
    const label = sev === 'CRITICAL' ? 'Critical' : sev === 'WARNING' ? 'Warning' : sev;
    return `Severity: ${label}`;
  }
  return null;
}
