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
      return `You're up — please proceed to the clinic ${suffix}`.trim();
    case 'bmg.alert_triggered':
      return `BMG alert on batch ${suffix}`.trim();
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
