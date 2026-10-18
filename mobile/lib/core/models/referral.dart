/// Referral record — mirrors `frontend/src/schemas/referrals.ts`
/// (`referralSchema`).
library;

class Referral {
  Referral({
    required this.id,
    required this.patientSchoolId,
    required this.sourceModule,
    required this.targetModule,
    required this.artifactType,
    required this.status,
    this.reasonCode,
    this.priority = 'normal',
    this.providerUserId,
    this.providerName,
    this.sourceEncounterId,
    this.sourceSessionId,
    this.queueHandoffDestination,
    this.queueHandoffEntryId,
    this.queueHandoffAt,
    required this.createdAt,
    required this.updatedAt,
  });

  factory Referral.fromJson(Map<String, dynamic> json) => Referral(
        id: json['id'] as int,
        patientSchoolId: (json['patient_school_id'] ?? '') as String,
        sourceModule: (json['source_module'] ?? '') as String,
        targetModule: (json['target_module'] ?? '') as String,
        artifactType: (json['artifact_type'] ?? '') as String,
        status: (json['status'] ?? '') as String,
        reasonCode: json['reason_code'] as String?,
        priority: (json['priority'] ?? 'normal') as String,
        providerUserId: json['provider_user_id'] as int?,
        providerName: json['provider_name'] as String?,
        sourceEncounterId: (json['source_encounter_id'] as num?)?.toInt(),
        sourceSessionId: (json['source_session_id'] as num?)?.toInt(),
        queueHandoffDestination:
            json['queue_handoff_destination'] as String?,
        queueHandoffEntryId:
            (json['queue_handoff_entry_id'] as num?)?.toInt(),
        queueHandoffAt: json['queue_handoff_at'] as String?,
        createdAt: (json['created_at'] ?? '') as String,
        updatedAt: (json['updated_at'] ?? '') as String,
      );

  final int id;
  final String patientSchoolId;

  /// clinic | counselling.
  final String sourceModule;
  final String targetModule;
  final String artifactType;

  /// submitted | acknowledged | under_review | closed.
  final String status;

  final String? reasonCode;

  /// low | normal | high | urgent — set by the referrer.
  final String priority;

  final int? providerUserId;
  final String? providerName;
  final int? sourceEncounterId;
  final int? sourceSessionId;

  /// Set when the referral was handed to a destination queue.
  final String? queueHandoffDestination;
  final int? queueHandoffEntryId;
  final String? queueHandoffAt;

  final String createdAt;
  final String updatedAt;

  String get flow => '$sourceModule → $targetModule';
}
