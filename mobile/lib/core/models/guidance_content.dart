/// Guidance announcements and services — mirrors
/// `GuidanceContentController` and the SPA `AnnouncementsTab` /
/// `ServicesTab` schemas.
library;

class GuidanceAnnouncement {
  GuidanceAnnouncement({
    required this.id,
    required this.title,
    required this.body,
    required this.audience,
    this.actionUrl,
    this.actionLabel,
    required this.isRequired,
    required this.severity,
    this.publishAt,
    this.unpublishAt,
    required this.createdAt,
    required this.updatedAt,
    this.archivedAt,
    required this.status,
  });

  factory GuidanceAnnouncement.fromJson(Map<String, dynamic> json) =>
      GuidanceAnnouncement(
        id: (json['id'] as num?)?.toInt() ?? 0,
        title: (json['title'] ?? '') as String,
        body: (json['body'] ?? '') as String,
        audience: (json['audience'] ?? 'all') as String,
        actionUrl: json['action_url'] as String?,
        actionLabel: json['action_label'] as String?,
        isRequired: (json['is_required'] ?? false) as bool,
        severity: (json['severity'] ?? 'normal') as String,
        publishAt: json['publish_at'] as String?,
        unpublishAt: json['unpublish_at'] as String?,
        createdAt: (json['created_at'] ?? '') as String,
        updatedAt: (json['updated_at'] ?? '') as String,
        archivedAt: json['archived_at'] as String?,
        status: (json['status'] ?? 'live') as String,
      );

  final int id;
  final String title;
  final String body;
  final String audience;
  final String? actionUrl;
  final String? actionLabel;
  final bool isRequired;
  final String severity;
  final String? publishAt;
  final String? unpublishAt;
  final String createdAt;
  final String updatedAt;
  final String? archivedAt;

  /// live | scheduled | expired.
  final String status;

  bool get archived => archivedAt != null;
}

class GuidanceService {
  GuidanceService({
    required this.id,
    required this.code,
    required this.name,
    this.description,
    this.cmoReference,
    required this.sortOrder,
    this.queueDestination,
    required this.isActive,
    required this.createdAt,
    this.archivedAt,
  });

  factory GuidanceService.fromJson(Map<String, dynamic> json) =>
      GuidanceService(
        id: (json['id'] as num?)?.toInt() ?? 0,
        code: (json['code'] ?? '') as String,
        name: (json['name'] ?? '') as String,
        description: json['description'] as String?,
        cmoReference: json['cmo_reference'] as String?,
        sortOrder: (json['sort_order'] as num?)?.toInt() ?? 0,
        queueDestination: json['queue_destination'] as String?,
        isActive: (json['is_active'] ?? true) as bool,
        createdAt: (json['created_at'] ?? '') as String,
        archivedAt: json['archived_at'] as String?,
      );

  final int id;
  final String code;
  final String name;
  final String? description;
  final String? cmoReference;
  final int sortOrder;
  final String? queueDestination;
  final bool isActive;
  final String createdAt;
  final String? archivedAt;

  bool get archived => archivedAt != null;
}
