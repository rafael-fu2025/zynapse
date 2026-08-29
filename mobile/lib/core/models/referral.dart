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
    this.providerUserId,
    this.providerName,
    required this.createdAt,
    required this.updatedAt,
    this.qrExpiresAt,
    this.qrRevokedAt,
  });

  factory Referral.fromJson(Map<String, dynamic> json) => Referral(
        id: json['id'] as int,
        patientSchoolId: (json['patient_school_id'] ?? '') as String,
        sourceModule: (json['source_module'] ?? '') as String,
        targetModule: (json['target_module'] ?? '') as String,
        artifactType: (json['artifact_type'] ?? '') as String,
        status: (json['status'] ?? '') as String,
        reasonCode: json['reason_code'] as String?,
        providerUserId: json['provider_user_id'] as int?,
        providerName: json['provider_name'] as String?,
        createdAt: (json['created_at'] ?? '') as String,
        updatedAt: (json['updated_at'] ?? '') as String,
        qrExpiresAt: json['qr_expires_at'] as String?,
        qrRevokedAt: json['qr_revoked_at'] as String?,
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
  final int? providerUserId;
  final String? providerName;
  final String createdAt;
  final String updatedAt;
  final String? qrExpiresAt;
  final String? qrRevokedAt;

  String get flow => '$sourceModule → $targetModule';

  bool get hasQr => qrExpiresAt != null && qrExpiresAt!.isNotEmpty;
  bool get qrRevoked => qrRevokedAt != null && qrRevokedAt!.isNotEmpty;
}
