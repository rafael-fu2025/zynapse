/// Scheduling staff — mirrors `frontend/src/schemas/schedulingStaff.ts`
/// and `/scheduling/staff/{unit}`.
library;

class SchedulingStaff {
  SchedulingStaff({
    required this.userId,
    this.employeeNumber,
    required this.name,
    this.department,
    this.position,
    this.employmentStatus,
    this.assignmentId,
    this.enrollmentStatus,
    required this.eligible,
    required this.canEnroll,
    required this.status,
    required this.futureAppointments,
  });

  factory SchedulingStaff.fromJson(Map<String, dynamic> json) =>
      SchedulingStaff(
        userId: (json['user_id'] as num?)?.toInt() ?? 0,
        employeeNumber: json['employee_number'] as String?,
        name: (json['name'] ?? '') as String,
        department: json['department'] as String?,
        position: json['position'] as String?,
        employmentStatus: json['employment_status'] as String?,
        assignmentId: (json['assignment_id'] as num?)?.toInt(),
        enrollmentStatus: json['enrollment_status'] as String?,
        eligible: (json['eligible'] ?? false) as bool,
        canEnroll: (json['can_enroll'] ?? false) as bool,
        status: (json['status'] ?? 'not_enrolled') as String,
        futureAppointments:
            (json['future_appointments'] as num?)?.toInt() ?? 0,
      );

  final int userId;
  final String? employeeNumber;
  final String name;
  final String? department;
  final String? position;
  final String? employmentStatus;
  final int? assignmentId;

  /// active | inactive | null.
  final String? enrollmentStatus;

  final bool eligible;
  final bool canEnroll;

  /// not_enrolled | ready | configured | inactive_employee |
  /// inactive_enrollment | department_mismatch | needs_review.
  final String status;
  final int futureAppointments;

  bool get enrolled => assignmentId != null;
  bool get enrollmentActive => enrollmentStatus == 'active';
  bool get selectable => canEnroll;
}

class SchedulingStaffPage {
  SchedulingStaffPage({
    required this.items,
    this.nextCursor,
    required this.departmentKeys,
  });

  factory SchedulingStaffPage.fromJson(Map<String, dynamic> json) =>
      SchedulingStaffPage(
        items: (json['items'] as List? ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(SchedulingStaff.fromJson)
            .toList(),
        nextCursor: json['next_cursor'] as String?,
        departmentKeys: (json['department_keys'] as List? ?? const [])
            .whereType<String>()
            .toList(),
      );

  final List<SchedulingStaff> items;
  final String? nextCursor;
  final List<String> departmentKeys;

  bool get hasMore => nextCursor != null && nextCursor!.isNotEmpty;
}

class SchedulingBatchResult {
  SchedulingBatchResult({required this.created, required this.skipped});

  factory SchedulingBatchResult.fromJson(Map<String, dynamic> json) =>
      SchedulingBatchResult(
        created: (json['created'] as num?)?.toInt() ?? 0,
        skipped: (json['skipped'] as num?)?.toInt() ?? 0,
      );

  final int created;
  final int skipped;
}
