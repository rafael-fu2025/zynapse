/// Session — mirrors `frontend/src/schemas/auth.ts` (`sessionSchema`) and
/// `backend/app/Controllers/Api/Auth/AuthController::me()`.
class Session {
  Session({
    required this.id,
    required this.email,
    required this.username,
    required this.isActive,
    required this.forceReset,
    this.personKind,
    this.personName,
    this.isTeaching,
    required this.permissions,
  });

  factory Session.fromJson(Map<String, dynamic> json) => Session(
        id: json['id'] as int,
        email: json['email'] as String,
        username: (json['username'] ?? '') as String,
        isActive: json['is_active'] as bool? ?? true,
        forceReset: json['force_reset'] as bool? ?? false,
        personKind: json['person_kind'] as String?,
        personName: json['person_name'] as String?,
        isTeaching: json['is_teaching'] as bool?,
        permissions:
            (json['permissions'] as List<dynamic>? ?? []).cast<String>(),
      );

  final int id;
  final String email;
  final String username;
  final bool isActive;
  final bool forceReset;

  /// `student` | `employee` | `contractor` | `alumni` | null.
  final String? personKind;

  /// `First Last` from `users` — null when the user has no person record.
  final String? personName;

  /// Teaching flag for employee accounts (drives referral eligibility).
  final bool? isTeaching;

  /// Effective permission codes (`*` for admin).
  final List<String> permissions;

  /// The admin wildcard collapses to `['*']`; every other permission code
  /// is checked literally — mirrors `hasPermission` in `frontend/src/store/auth.ts`.
  bool hasPermission(String code) =>
      permissions.contains('*') || permissions.contains(code);

  /// True for staff/ops surfaces (every staff role + admin holds
  /// `employee.portal.read` via groups/wildcard).
  bool get isStaff => hasPermission('employee.portal.read');

  bool get isStudent => hasPermission('student.portal.read') && !isStaff;
}
