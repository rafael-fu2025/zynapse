class PortalAppointment {
  PortalAppointment.fromJson(Map<String, dynamic> j)
      : department = j['department'] as String,
        id = j['id'] as int,
        providerUserId = j['provider_user_id'] as int?,
        providerName = j['provider_name'] as String?,
        startsAt = DateTime.parse(j['starts_at'] as String).toLocal(),
        status = j['status'] as String,
        reason = j['reason'] as String?;
  final String department, status;
  final int id;
  final int? providerUserId;
  final String? providerName, reason;
  final DateTime startsAt;
}

class PortalAppointmentSlot {
  PortalAppointmentSlot.fromJson(Map<String, dynamic> j)
      : department = j['department'] as String,
        startsAt = DateTime.parse(j['starts_at'] as String),
        endsAt = DateTime.parse(j['ends_at'] as String),
        durationMinutes = (j['duration_minutes'] as num?)?.toInt() ?? 60,
        remaining = (j['remaining'] as num?)?.toInt() ?? 1;
  final String department;
  final DateTime startsAt, endsAt;
  final int durationMinutes, remaining;
}
