/**
 * Notification copy — friendly labels + detail lines for the bell and
 * the Notifications page. Shared so the two surfaces never drift.
 *
 * Context is whitelisted server-side (resource ids, status/module codes,
 * urgency) — NEVER PII — so these renderers only ever see safe values.
 *
 * Writing rules (the audience is clinic and guidance staff and students,
 * not IT people):
 *   - Plain sentences. No dotted event codes, no snake_case, no jargon.
 *   - Never show internal record ids such as `appointment#15` or
 *     `queue#12` — they mean nothing to a reader. Human-facing
 *     references (a batch reference, a device name) are kept.
 *   - A code we have no copy for still reads as a sentence
 *     ("You have a new notification") instead of leaking the raw code.
 *   - Anything that tells two similar notifications apart (which office,
 *     when, which direction) goes in `notificationDetail`.
 */

import { hasPermission } from '@/store/auth';

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

/** Shown when a notification code has no dedicated copy yet. */
const FALLBACK_LABEL = 'You have a new notification';

/**
 * The reference a person can recognise, or '' when there is none. The
 * backend sends internal row handles like `appointment#15` / `appt#5` /
 * `queue#12` in `resource_code`; those are filtered out. A batch
 * reference or device name has no `#id` shape and passes through.
 */
function humanReference(context: NotificationContext | null): string {
  const code = typeof context?.resource_code === 'string' ? context.resource_code.trim() : '';
  if (code === '' || /^[a-z_]+#\d+$/i.test(code)) return '';
  return code;
}

/** "counselling" is the internal name; staff and students know it as Guidance. */
function moduleName(value: unknown): string {
  if (typeof value !== 'string' || value === '') return '';
  const v = value.toLowerCase();
  if (v === 'counselling' || v === 'guidance') return 'Guidance';
  if (v === 'clinic') return 'Clinic';
  return v.charAt(0).toUpperCase() + v.slice(1).replace(/_/g, ' ');
}

function sentenceCase(value: string): string {
  const v = value.replace(/_/g, ' ').trim().toLowerCase();
  return v.charAt(0).toUpperCase() + v.slice(1);
}

export function notificationLabel(
  templateCode: string,
  context: NotificationContext | null,
): string {
  const ref = humanReference(context);
  switch (templateCode) {
    // Appointments — the same event reaches the patient and the staff
    // member, so the wording is neutral; the detail line carries the
    // office and the date/time.
    case 'appointment.assigned': return 'An appointment was assigned to you';
    case 'appointment.scheduled': return 'Appointment booked';
    case 'appointment.rescheduled': return 'Appointment moved to a new time';
    case 'appointment.confirmed': return 'Appointment confirmed';
    case 'appointment.checked_in': return 'Checked in for an appointment';
    case 'appointment.completed': return 'Appointment completed';
    case 'appointment.cancelled': return 'Appointment cancelled';
    case 'appointment.no_show': return 'Appointment missed (marked as no-show)';

    case 'referral.created': return 'A new referral is waiting for you';
    case 'referral.acknowledged': return 'Your referral was received';
    case 'referral.closed': return 'A referral was closed';
    case 'referral.queue_handoff': return 'A referred patient was added to your queue';

    case 'reorder.created': return 'Supplies are running low — a purchase request was created';

    case 'queue.called':
    case 'counselling.queue_called': {
      const destination = context?.destination === 'counselling' ? 'Guidance' : 'Clinic';
      return `It's your turn — please go to ${destination}`;
    }
    // October 2026 recall window: the sweep resolved a skipped patient's
    // recall window to no-show with nobody watching the module.
    case 'queue.skip_expired': return 'A skipped patient was automatically marked as no-show';

    case 'bmg.alert_triggered':
      return ref !== '' ? `Equipment alert on batch ${ref}` : 'Equipment alert needs attention';
    case 'bmg.device_silent':
      return ref !== ''
        ? `Device ${ref} stopped sending data — please check it`
        : 'A device stopped sending data — please check it';
    // A run the board reported while its drum had no active batch — the
    // motor turned but nothing recorded it against a batch.
    case 'bmg.device_run_no_batch':
      return ref !== ''
        ? `Drum ${ref} ran with no active batch`
        : 'A drum ran with no active batch — please check it';
    // The board's DS3231 is missing or unsynced; run timestamps from it
    // cannot be trusted until it recovers.
    case 'bmg.device_rtc_invalid':
      return ref !== ''
        ? `Device ${ref} has a clock problem (RTC invalid)`
        : 'A device has a clock problem (RTC invalid)';
    // An instruction to the drum's controller timed out before the board
    // picked it up. `next_status` carries which one (start / stop / run_now).
    case 'bmg.device_command_expired': {
      const drum = ref !== '' ? `drum ${ref}` : 'a drum';
      switch (context?.next_status) {
        case 'start':
          return `The start command never reached ${drum} — the drum has not started turning`;
        case 'stop':
          return `The stop command never reached ${drum} — check that it has stopped`;
        case 'run_now':
          return `The run-now command never reached ${drum}`;
        default:
          return `A command never reached ${drum}`;
      }
    }

    case 'counselling.session_opened': return 'Guidance session started';
    case 'counselling.session_closed': return 'Guidance session ended';
    case 'counselling.session_reassigned': return 'A Guidance session was assigned to you';
    case 'counselling.appointment_booked': return 'Guidance appointment booked';

    case 'admin.user_created': return 'A new user account was created';
    case 'admin.user_groups_changed': return "A user's roles were changed";
    case 'admin.user_password_reset': return "A user's password was reset";
    case 'admin.user_status_changed': return 'A user account was turned on or off';
    case 'admin.portal_account_minted': return 'A portal account was created for a patient';
    default:
      return FALLBACK_LABEL;
  }
}

/** Optional secondary line (office + time, direction, urgency, …). */
export function notificationDetail(
  templateCode: string,
  context: NotificationContext | null,
): string | null {
  if (context === null) return null;
  if (templateCode.startsWith('appointment.') && typeof context.appointment_at === 'string') {
    const destination = context.destination === 'counselling' ? 'Guidance' : 'Clinic';
    const when = new Date(context.appointment_at);
    if (!Number.isNaN(when.getTime())) {
      return `${destination} · ${new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(when)}`;
    }
  }
  if ((templateCode === 'queue.called' || templateCode === 'counselling.queue_called') && typeof context.queue_number === 'string') {
    return `Your number: ${context.queue_number}`;
  }
  if (templateCode === 'queue.skip_expired' && typeof context.queue_number === 'string') {
    return `Number ${context.queue_number} · the time to call them back ran out`;
  }
  if (
    (templateCode === 'referral.created' || templateCode === 'referral.acknowledged') &&
    moduleName(context.source_module) !== '' &&
    moduleName(context.target_module) !== ''
  ) {
    return `From ${moduleName(context.source_module)} to ${moduleName(context.target_module)}`;
  }
  if (templateCode === 'reorder.created' && typeof context.urgency === 'string') {
    return `How urgent: ${sentenceCase(context.urgency)}`;
  }
  if (templateCode === 'queue.called' && typeof context.position === 'number') {
    return `Queue ${String(context.position).padStart(3, '0')}`;
  }
  if (
    (templateCode === 'bmg.alert_triggered' ||
      templateCode === 'bmg.device_silent' ||
      templateCode === 'bmg.device_command_expired') &&
    typeof context.urgency === 'string'
  ) {
    const sev = context.urgency.toUpperCase();
    const label = sev === 'CRITICAL' ? 'Critical' : sev === 'WARNING' ? 'Needs a check' : sentenceCase(context.urgency);
    return `How serious: ${label}`;
  }
  return null;
}

/**
 * Resolves the destination URL for a given notification based on template code,
 * context, and user permissions. Returns `null` if unrecognized or if the user
 * lacks permission for the target module.
 */
export function getNotificationDestination(
  template: string,
  context: NotificationContext | null,
  auth: Parameters<typeof hasPermission>[0],
): string | null {
  if (template.startsWith('appointment.')) {
    if (hasPermission(auth, 'portal.appointments.read')) return '/me';
    if (context?.destination === 'counselling' && hasPermission(auth, 'counselling.schedule.read')) {
      return '/counselling?tab=scheduling';
    }
    if (hasPermission(auth, 'clinic.appointments.read')) return '/appointments';
    return null;
  }
  if (template.startsWith('referral.') && hasPermission(auth, 'referrals.read')) {
    return '/referrals';
  }
  if (template.startsWith('reorder.') && hasPermission(auth, 'clinic.inventory.read')) {
    return '/inventory?tab=reorders';
  }
  if (template.startsWith('bmg.') && hasPermission(auth, 'facilities.units.read')) {
    return '/facilities';
  }
  // The auto-no-show notice is for STAFF, not the patient: it must land
  // on the clinic queue board where the resolved row lives, not the
  // patient portal. Checked BEFORE the generic `queue.` branch below —
  // that one is patient-facing and would swallow this for superadmins
  // (who hold `portal.queue.read` via the `*` wildcard).
  if (template === 'queue.skip_expired') {
    if (hasPermission(auth, 'clinic.queue.read')) return '/clinic?tab=skipped';
    return null;
  }
  if (
    (template.startsWith('queue.') || template.startsWith('counselling.queue')) &&
    hasPermission(auth, 'portal.queue.read')
  ) {
    return '/me';
  }
  if (template.startsWith('counselling.') && hasPermission(auth, 'counselling.records.read')) {
    // Sessions and notes are read on the **Queue** board now (2026-09-23,
    // third revision) — an on-going patient's row expands in place, and a
    // `?session=N` link force-opens the matching row. The Appointments book no
    // longer offers a way into a session, so pointing session notifications
    // there would land the user on a page that cannot show what they clicked.
    return '/counselling?tab=queue';
  }
  if (template.startsWith('admin.') && hasPermission(auth, 'rbac.manage')) {
    return '/admin/users';
  }
  return null;
}
