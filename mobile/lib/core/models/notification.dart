/// In-app notification — mirrors `frontend/src/hooks/useNotifications.ts`
/// (`notificationSchema`) and the backend notification outbox row.
class AppNotification {
  AppNotification({
    required this.id,
    required this.templateCode,
    this.context,
    this.readAt,
    required this.createdAt,
  });

  factory AppNotification.fromJson(Map<String, dynamic> json) =>
      AppNotification(
        id: json['id'] as int,
        templateCode: (json['template_code'] ?? '') as String,
        context: json['context'] as Map<String, dynamic>?,
        readAt: json['read_at'] as String?,
        createdAt: (json['created_at'] ?? '') as String,
      );

  final int id;

  /// e.g. `appointment.assigned`, `referral.created`, `queue.called`.
  final String templateCode;

  final Map<String, dynamic>? context;

  /// Null while unread.
  final String? readAt;

  final String createdAt;

  bool get isRead => readAt != null && readAt!.isNotEmpty;
}
