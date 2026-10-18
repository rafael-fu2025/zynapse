/// Guidance follow-up — mirrors the SPA `useGuidanceFollowups` schema and
/// `GET /counselling/followups`.
library;

class GuidanceFollowup {
  GuidanceFollowup({
    required this.id,
    required this.studentUserId,
    required this.studentName,
    required this.surveyTitle,
    required this.riskReason,
    this.who5Score,
    required this.status,
    this.assignedCounsellorUserId,
    this.assignedCounsellor,
    this.outcomeNote,
    this.dueAt,
    required this.createdAt,
  });

  factory GuidanceFollowup.fromJson(Map<String, dynamic> json) =>
      GuidanceFollowup(
        id: (json['id'] as num?)?.toInt() ?? 0,
        studentUserId: (json['student_user_id'] as num?)?.toInt() ?? 0,
        studentName: (json['student_name'] ?? '') as String,
        surveyTitle: (json['survey_title'] ?? '') as String,
        riskReason: (json['risk_reason'] ?? '') as String,
        who5Score: (json['who5_score'] as num?)?.toInt(),
        status: (json['status'] ?? 'new') as String,
        assignedCounsellorUserId:
            (json['assigned_counsellor_user_id'] as num?)?.toInt(),
        assignedCounsellor: json['assigned_counsellor'] as String?,
        outcomeNote: json['outcome_note'] as String?,
        dueAt: json['due_at'] as String?,
        createdAt: (json['created_at'] ?? '') as String,
      );

  final int id;
  final int studentUserId;
  final String studentName;
  final String surveyTitle;
  final String riskReason;
  final int? who5Score;

  /// new | in_review | addressed | closed.
  final String status;
  final int? assignedCounsellorUserId;
  final String? assignedCounsellor;
  final String? outcomeNote;
  final String? dueAt;
  final String createdAt;
}
