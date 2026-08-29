/// Counselling sessions — mirrors `frontend/src/schemas/counselling.ts`
/// (`sessionSchema`).
library;

import 'referral.dart';

class CounsellingSession {
  CounsellingSession({
    required this.id,
    required this.patientSchoolId,
    required this.counsellorUserId,
    required this.startedAt,
    this.endedAt,
    this.patientDisplayName,
    this.queueEntryId,
    this.queueNumber,
    this.queueStatus,
    this.purpose,
    this.appointmentId,
    this.incomingReferralId,
    this.noteCount = 0,
    this.outgoingReferral,
  });

  factory CounsellingSession.fromJson(Map<String, dynamic> json) =>
      CounsellingSession(
        id: json['id'] as int,
        patientSchoolId: (json['patient_school_id'] ?? '') as String,
        counsellorUserId: (json['counsellor_user_id'] ?? 0) as int,
        startedAt: (json['started_at'] ?? '') as String,
        endedAt: json['ended_at'] as String?,
        patientDisplayName: json['patient_display_name'] as String?,
        queueEntryId: json['queue_entry_id'] as int?,
        queueNumber: json['queue_number'] as String?,
        queueStatus: json['queue_status'] as String?,
        purpose: json['purpose'] as String?,
        appointmentId: json['appointment_id'] as int?,
        incomingReferralId: json['incoming_referral_id'] as int?,
        noteCount: (json['note_count'] ?? 0) as int,
        outgoingReferral: json['outgoing_referral'] is Map<String, dynamic>
            ? Referral.fromJson(
                json['outgoing_referral'] as Map<String, dynamic>)
            : null,
      );

  final int id;
  final String patientSchoolId;
  final int counsellorUserId;
  final String startedAt;
  final String? endedAt;
  final String? patientDisplayName;
  final int? queueEntryId;
  final String? queueNumber;
  final String? queueStatus;
  final String? purpose;
  final int? appointmentId;
  final int? incomingReferralId;
  final int noteCount;
  final Referral? outgoingReferral;

  bool get isOpen => endedAt == null || endedAt!.isEmpty;
}

class CounsellingNote {
  CounsellingNote({
    required this.sessionId,
    required this.plaintext,
    required this.keyVersion,
    required this.createdAt,
  });

  factory CounsellingNote.fromJson(Map<String, dynamic> json) =>
      CounsellingNote(
        sessionId: (json['session_id'] ?? 0) as int,
        plaintext: (json['plaintext'] ?? '') as String,
        keyVersion: (json['key_version'] ?? 0) as int,
        createdAt: (json['created_at'] ?? '') as String,
      );

  final int sessionId;
  final String plaintext;
  final int keyVersion;
  final String createdAt;
}
