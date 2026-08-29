/// Profile + clinic-visit models for the "My portal" surface.
///
/// Mirrors `frontend/src/schemas/employeePortal.ts` /
/// `frontend/src/schemas/studentPortal.ts` and the backend `UserDto`
/// (`backend/app/Modules/Clinic/DTOs/UserDto.php`) plus
/// `EmployeeSelfService`/`StudentSelfService` clinic-visit rows.
library;

/// A patient allergy row (student detail).
class PatientAllergy {
  const PatientAllergy({
    required this.id,
    required this.allergen,
    required this.severity,
    this.reaction,
  });

  factory PatientAllergy.fromJson(Map<String, dynamic> json) => PatientAllergy(
        id: (json['id'] ?? 0) as int,
        allergen: (json['allergen'] ?? '') as String,
        severity: (json['severity'] ?? 'mild') as String,
        reaction: json['reaction'] as String?,
      );

  final int id;
  final String allergen;
  final String severity;
  final String? reaction;
}

/// A patient emergency-contact row (student detail).
class PatientContact {
  const PatientContact({
    required this.id,
    required this.contactName,
    required this.relationship,
    required this.phone,
    required this.isPrimary,
  });

  factory PatientContact.fromJson(Map<String, dynamic> json) => PatientContact(
        id: (json['id'] ?? 0) as int,
        contactName: (json['contact_name'] ?? '') as String,
        relationship: (json['relationship'] ?? '') as String,
        phone: (json['phone'] ?? '') as String,
        isPrimary: (json['is_primary'] ?? false) as bool,
      );

  final int id;
  final String contactName;
  final String relationship;
  final String phone;
  final bool isPrimary;
}

/// A user profile row — the same `UserDto` shape is returned by both
/// `/me/employee-profile` and `/me/student-profile` (student/employee
/// fields are merged on `users`).
class UserProfile {
  UserProfile({
    required this.id,
    this.kind,
    this.firstName = '',
    this.lastName = '',
    this.middleName,
    this.dateOfBirth,
    this.gender,
    this.address,
    required this.hasQr,
    required this.hasRfid,
    required this.archived,
    this.createdAt = '',
    this.updatedAt = '',
    this.studentNumber,
    this.course,
    this.yearLevel,
    this.section,
    this.bloodType,
    this.consecutiveNoShows = 0,
    this.employeeNumber,
    this.department,
    this.position,
    this.dateHired,
    this.employmentStatus,
    this.hrSyncedAt,
    this.emergencyContactName,
    this.emergencyContactPhone,
    this.isTeaching,
    this.kioskIdentifier,
    this.allergies = const [],
    this.contacts = const [],
  });

  factory UserProfile.fromJson(Map<String, dynamic> json) => UserProfile(
        id: (json['id'] ?? 0) as int,
        kind: json['kind'] as String?,
        firstName: (json['first_name'] ?? '') as String,
        lastName: (json['last_name'] ?? '') as String,
        middleName: json['middle_name'] as String?,
        dateOfBirth: json['date_of_birth'] as String?,
        gender: json['gender'] as String?,
        address: json['address'] as String?,
        hasQr: (json['has_qr'] ?? false) as bool,
        hasRfid: (json['has_rfid'] ?? false) as bool,
        archived: (json['archived'] ?? false) as bool,
        createdAt: (json['created_at'] ?? '') as String,
        updatedAt: (json['updated_at'] ?? '') as String,
        studentNumber: json['student_number'] as String?,
        course: json['course'] as String?,
        yearLevel: json['year_level'] as int?,
        section: json['section'] as String?,
        bloodType: json['blood_type'] as String?,
        consecutiveNoShows: (json['consecutive_no_shows'] ?? 0) as int,
        employeeNumber: json['employee_number'] as String?,
        department: json['department'] as String?,
        position: json['position'] as String?,
        dateHired: json['date_hired'] as String?,
        employmentStatus: json['employment_status'] as String?,
        hrSyncedAt: json['hr_synced_at'] as String?,
        emergencyContactName: json['emergency_contact_name'] as String?,
        emergencyContactPhone: json['emergency_contact_phone'] as String?,
        isTeaching: json['is_teaching'] as bool?,
        kioskIdentifier: json['kiosk_identifier'] as String?,
        allergies: (json['allergies'] as List? ?? [])
            .whereType<Map<String, dynamic>>()
            .map(PatientAllergy.fromJson)
            .toList(),
        contacts: (json['contacts'] as List? ?? [])
            .whereType<Map<String, dynamic>>()
            .map(PatientContact.fromJson)
            .toList(),
      );

  final int id;
  final String? kind;
  final String firstName;
  final String lastName;
  final String? middleName;
  final String? dateOfBirth;
  final String? gender;
  final String? address;
  final bool hasQr;
  final bool hasRfid;
  final bool archived;
  final String createdAt;
  final String updatedAt;

  // Student fields
  final String? studentNumber;
  final String? course;
  final int? yearLevel;
  final String? section;
  final String? bloodType;
  final int consecutiveNoShows;

  // Employee fields
  final String? employeeNumber;
  final String? department;
  final String? position;
  final String? dateHired;
  final String? employmentStatus;
  final String? hrSyncedAt;
  final String? emergencyContactName;
  final String? emergencyContactPhone;
  final bool? isTeaching;

  /// `qr:<num>` / `rfid:<num>` / `emp:<num>` / `stu:<num>` — the identity
  /// value the kiosk scanner understands.
  final String? kioskIdentifier;

  /// Student detail only: allergies (safety-critical health data).
  final List<PatientAllergy> allergies;

  /// Student detail only: emergency contacts.
  final List<PatientContact> contacts;

  String get fullName {
    final middle = (middleName != null && middleName!.isNotEmpty)
        ? ' ${middleName![0]}.'
        : '';
    return '$firstName$middle $lastName'.trim();
  }

  bool get isStudent => kind == 'student';
}

/// A clinic encounter on the caller's own record (portal visits list).
class ClinicVisit {
  ClinicVisit({
    required this.id,
    required this.chiefComplaint,
    this.triagePriority,
    required this.status,
    this.attendingUsername,
    required this.startedAt,
    this.closedAt,
    required this.createdAt,
  });

  factory ClinicVisit.fromJson(Map<String, dynamic> json) => ClinicVisit(
        id: (json['id'] ?? 0) as int,
        chiefComplaint: (json['chief_complaint'] ?? '') as String,
        triagePriority: json['triage_priority'] as String?,
        status: (json['status'] ?? '') as String,
        attendingUsername: json['attending_username'] as String?,
        startedAt: (json['started_at'] ?? '') as String,
        closedAt: json['closed_at'] as String?,
        createdAt: (json['created_at'] ?? '') as String,
      );

  final int id;
  final String chiefComplaint;

  /// low | medium | high | urgent | null.
  final String? triagePriority;

  /// open | closed | referred.
  final String status;

  final String? attendingUsername;
  final String startedAt;
  final String? closedAt;
  final String createdAt;
}

/// Minimal clinic-provider row for the self-booking picker
/// (`AppointmentService::providers()` returns `{id, name}`).
class ProviderRef {
  ProviderRef({required this.id, required this.name});

  factory ProviderRef.fromJson(Map<String, dynamic> json) => ProviderRef(
        id: (json['id'] ?? 0) as int,
        name: (json['name'] ?? 'Provider') as String,
      );

  final int id;
  final String name;
}

/// A combined student/employee lookup hit from `/clinic/patients/lookup`
/// (mirrors `KioskLookupResult` in the web `usePatientLookup`).
class PatientLookupResult {
  PatientLookupResult({
    required this.id,
    required this.kind,
    required this.name,
    required this.schoolId,
  });

  factory PatientLookupResult.fromJson(Map<String, dynamic> json) =>
      PatientLookupResult(
        id: (json['id'] ?? 0) as int,
        kind: (json['kind'] ?? 'student') as String,
        name: (json['name'] ?? '') as String,
        schoolId: (json['school_id'] ?? '') as String,
      );

  final int id;

  /// student | employee.
  final String kind;
  final String name;
  final String schoolId;
}
