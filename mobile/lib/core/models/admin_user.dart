/// Admin user (RBAC) — mirrors `frontend/src/hooks/useAdminUsers.ts`
/// (`adminUserSchema`).
library;

class AdminUser {
  AdminUser({
    required this.id,
    this.username,
    this.email,
    required this.active,
    required this.status,
    required this.groups,
    this.personKind,
    this.personName,
    required this.createdAt,
    required this.updatedAt,
    this.lastActive,
    required this.forceReset,
  });

  factory AdminUser.fromJson(Map<String, dynamic> json) => AdminUser(
        id: json['id'] as int,
        username: json['username'] as String?,
        email: json['email'] as String?,
        active: (json['active'] ?? true) as bool,
        status: (json['status'] ?? 'active') as String,
        groups: (json['groups'] as List<dynamic>? ?? []).cast<String>(),
        personKind: json['person_kind'] as String?,
        personName: json['person_name'] as String?,
        createdAt: (json['created_at'] ?? '') as String,
        updatedAt: (json['updated_at'] ?? '') as String,
        lastActive: json['last_active'] as String?,
        forceReset: (json['force_reset'] ?? false) as bool,
      );

  final int id;
  final String? username;
  final String? email;
  final bool active;
  final String status;
  final List<String> groups;
  final String? personKind;
  final String? personName;
  final String createdAt;
  final String updatedAt;
  final String? lastActive;
  final bool forceReset;

  String get label => personName ?? email ?? username ?? '#$id';
}
