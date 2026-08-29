/// Friendly labels for notification `template_code`s — mirrors
/// `frontend/src/utils/notifications.ts` (`notificationLabel` /
/// `notificationDetail`).
library;

import 'package:intl/intl.dart';

import 'dates.dart';

/// Map of backend notification codes to human labels.
const Map<String, String> _labels = {
  'appointment.assigned': 'New appointment assigned',
  'appointment.scheduled': 'Appointment booked',
  'appointment.rescheduled': 'Appointment rescheduled',
  'appointment.confirmed': 'Appointment confirmed',
  'appointment.cancelled': 'Appointment cancelled',
  'appointment.no_show': 'Patient marked no-show',
  'referral.created': 'New referral to handle',
  'referral.acknowledged': 'Referral acknowledged',
  'referral.closed': 'Referral closed',
  'reorder.created': 'New reorder suggested',
  'bmg.alert_triggered': 'Facilities alert',
  'queue.called': 'Queue called',
  'counselling.queue_called': 'Guidance queue called',
};

String notificationLabel(String templateCode) =>
    _labels[templateCode] ?? templateCode.replaceAll('.', ' ');

/// Builds a one-line detail from the notification's `context` map.
/// Mirrors the SPA's `notificationDetail` (resource codes, module arrows,
/// etc.). Falls back to a human timestamp.
String notificationDetail(
  String templateCode,
  Map<String, dynamic>? context,
  String createdAt,
) {
  if (context != null && context.isNotEmpty) {
    final appointmentAt = context['appointment_at']?.toString();
    if (appointmentAt != null) {
      final dt = DateTime.tryParse(appointmentAt)?.toLocal();
      final destination = context['destination'] == 'counselling' ? 'Guidance' : 'Clinic';
      if (dt != null) return '$destination · ${DateFormat('MMM d, h:mm a').format(dt)}';
    }
    final queueNumber = context['queue_number']?.toString();
    if (queueNumber != null) return queueNumber;
    final resource = context['resource_code']?.toString();
    final module = context['source_module']?.toString();
    if (resource != null && module != null) {
      final target = context['target_module']?.toString();
      final arrow = (target != null && target != module)
          ? '$module → $target'
          : module;
      return '#$resource · $arrow';
    }
    if (resource != null) return '#$resource';
    if (module != null) return module;
  }
  final dt = utcToManila(createdAt);
  if (dt != null) return DateFormat('MMM d, h:mm a').format(dt);
  return createdAt;
}
