/// Clinic appointment — mirrors `frontend/src/schemas/appointments.ts`
/// (`appointmentSchema`) and `AppointmentDto` in the backend.
class Appointment {
  Appointment({
    required this.id,
    required this.patientSchoolId,
    this.patientName,
    this.patientKind,
    required this.providerUserId,
    this.providerName,
    required this.scheduledAt,
    required this.status,
    this.reason,
    this.encounterId,
    this.qrToken,
    required this.createdAt,
  });

  factory Appointment.fromJson(Map<String, dynamic> json) => Appointment(
        id: json['id'] as int,
        patientSchoolId: (json['patient_school_id'] ?? '') as String,
        patientName: json['patient_name'] as String?,
        patientKind: json['patient_kind'] as String?,
        providerUserId: json['provider_user_id'] as int,
        providerName: json['provider_name'] as String?,
        scheduledAt: (json['scheduled_at'] ?? '') as String,
        status: (json['status'] ?? 'scheduled') as String,
        reason: json['reason'] as String?,
        encounterId: json['encounter_id'] as int?,
        qrToken: json['qr_token'] as String?,
        createdAt: (json['created_at'] ?? '') as String,
      );

  final int id;

  /// Free-text display identifier (`student_number` / `employee_number`).
  final String patientSchoolId;

  /// `First Last` — decorated by the backend; null when the patient row is
  /// missing/archived.
  final String? patientName;

  /// `student` | `employee` | null.
  final String? patientKind;

  final int providerUserId;

  /// `First Last` of the provider; falls back to username.
  final String? providerName;

  /// UTC wall-clock `YYYY-MM-DD HH:mm:ss`.
  final String scheduledAt;

  /// scheduled | checked_in | completed | cancelled | no_show.
  final String status;

  final String? reason;

  /// Non-null once the encounter was auto-opened at check-in.
  final int? encounterId;

  /// Plaintext QR proof-of-booking token (present only when the backend
  /// returned it — at booking time or via the `issueQr` endpoint). The DB
  /// stores only its HMAC hash; this is what the patient shows at check-in.
  final String? qrToken;

  final String createdAt;

  String get patientLabel =>
      (patientName != null && patientName!.isNotEmpty)
          ? '$patientName (#$patientSchoolId)'
          : 'Patient #$patientSchoolId';

  String get providerLabel => (providerName != null && providerName!.isNotEmpty)
      ? providerName!
      : 'Provider #$providerUserId';
}

/// Result of the PUBLIC `/appointments/verify` endpoint — minimum
/// disclosure: validity + status + scheduled time, never PII.
class AppointmentQrVerify {
  AppointmentQrVerify({
    required this.valid,
    this.status,
    this.scheduledAt,
  });

  final bool valid;
  final String? status;
  final String? scheduledAt;
}
