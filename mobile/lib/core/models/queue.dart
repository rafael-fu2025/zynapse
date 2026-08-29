/// Queue models.
///
/// * [MyQueueStatus] — mirrors `QueueService::myStatus()` (the portal
///   "Your queue" card). Null when the caller is not queued today.
/// * [PublicQueueState] — mirrors the public waiting-room feed
///   `GET /clinic/queue/state` (no auth — same feed as the lobby TV).
class MyQueueStatus {
  MyQueueStatus({
    this.destination = 'clinic',
    required this.queueEntryId,
    required this.encounterId,
    required this.position,
    required this.queueNumber,
    required this.status,
    this.calledAt,
    this.startedAt,
    required this.peopleAhead,
    this.estimatedWaitMinutes,
  });

  factory MyQueueStatus.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return MyQueueStatus(
        destination: 'clinic',
        queueEntryId: 0,
        encounterId: 0,
        position: 0,
        queueNumber: '',
        status: '',
        peopleAhead: 0,
      );
    }
    return MyQueueStatus(
      destination: (json['destination'] ?? 'clinic') as String,
      queueEntryId: (json['queue_entry_id'] ?? 0) as int,
      encounterId: (json['encounter_id'] ?? 0) as int,
      position: (json['position'] ?? 0) as int,
      queueNumber: (json['queue_number'] ?? '') as String,
      status: (json['status'] ?? '') as String,
      calledAt: json['called_at'] as String?,
      startedAt: json['started_at'] as String?,
      peopleAhead: (json['people_ahead'] ?? 0) as int,
      estimatedWaitMinutes: json['estimated_wait_minutes'] as int?,
    );
  }

  final int queueEntryId;
  final String destination;
  final int encounterId;
  final int position;

  /// `C-001`-style display number.
  final String queueNumber;

  /// waiting | called | in_session.
  final String status;

  final String? calledAt;
  final String? startedAt;
  final int peopleAhead;
  final int? estimatedWaitMinutes;

  bool get isQueued => queueEntryId > 0;
}

class GuidanceQueueEntry {
  GuidanceQueueEntry.fromJson(Map<String, dynamic> json)
      : id = json['id'] as int,
        queueNumber = (json['queue_number'] ?? '') as String,
        displayName = (json['display_name'] ?? '') as String,
        purpose = (json['purpose'] ?? '') as String,
        status = (json['status'] ?? '') as String,
        assignedCounsellorUserId = json['assigned_counsellor_user_id'] as int?,
        counsellingSessionId = json['counselling_session_id'] as int?;
  final int id;
  final String queueNumber, displayName, purpose, status;
  final int? assignedCounsellorUserId, counsellingSessionId;
}

class NowServing {
  NowServing({
    required this.position,
    required this.displayName,
    required this.patientSchoolId,
  });

  factory NowServing.fromJson(Map<String, dynamic> json) => NowServing(
        position: (json['position'] ?? 0) as int,
        displayName: (json['display_name'] ?? '') as String,
        patientSchoolId: (json['patient_school_id'] ?? '') as String,
      );

  final int position;
  final String displayName;
  final String patientSchoolId;
}

class QueueWaiter {
  QueueWaiter({
    required this.position,
    required this.displayName,
    required this.patientSchoolId,
    this.estWaitMinutes,
  });

  factory QueueWaiter.fromJson(Map<String, dynamic> json) => QueueWaiter(
        position: (json['position'] ?? 0) as int,
        displayName: (json['display_name'] ?? '') as String,
        patientSchoolId: (json['patient_school_id'] ?? '') as String,
        estWaitMinutes: json['est_wait_minutes'] as int?,
      );

  final int position;
  final String displayName;
  final String patientSchoolId;
  final int? estWaitMinutes;
}

class PublicQueueState {
  PublicQueueState({this.nowServing, required this.waiting, this.updatedAt});

  factory PublicQueueState.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return PublicQueueState(waiting: const []);
    }
    return PublicQueueState(
      nowServing: json['now_serving'] is Map<String, dynamic>
          ? NowServing.fromJson(json['now_serving'] as Map<String, dynamic>)
          : null,
      waiting: (json['waiting'] as List<dynamic>? ?? [])
          .whereType<Map<String, dynamic>>()
          .map(QueueWaiter.fromJson)
          .toList(),
      updatedAt: json['updated_at'] as String?,
    );
  }

  final NowServing? nowServing;
  final List<QueueWaiter> waiting;
  final String? updatedAt;

  bool get isEmpty => nowServing == null && waiting.isEmpty;
}

/// A staff-facing today's-queue row — mirrors `queueEntrySchema` in the
/// web (`frontend/src/schemas/queue.ts`) and `QueueService::todayRows()`.
class QueueEntry {
  QueueEntry({
    required this.id,
    required this.encounterId,
    required this.position,
    required this.status,
    required this.displayName,
    required this.patientSchoolId,
    this.patientName,
    required this.chiefComplaint,
    this.stationId,
    this.calledAt,
    this.startedAt,
    this.finishedAt,
    required this.encounterStatus,
  });

  factory QueueEntry.fromJson(Map<String, dynamic> json) => QueueEntry(
        id: json['id'] as int,
        encounterId: (json['encounter_id'] ?? 0) as int,
        position: (json['position'] ?? 0) as int,
        status: (json['status'] ?? '') as String,
        displayName: (json['display_name'] ?? '') as String,
        patientSchoolId: (json['patient_school_id'] ?? '') as String,
        patientName: json['patient_name'] as String?,
        chiefComplaint: (json['chief_complaint'] ?? '') as String,
        stationId: json['station_id'] as String?,
        calledAt: json['called_at'] as String?,
        startedAt: json['started_at'] as String?,
        finishedAt: json['finished_at'] as String?,
        encounterStatus: (json['encounter_status'] ?? 'open') as String,
      );

  final int id;
  final int encounterId;
  final int position;

  /// waiting | called | in_session | done | skipped.
  final String status;

  final String displayName;
  final String patientSchoolId;
  final String? patientName;
  final String chiefComplaint;
  final String? stationId;
  final String? calledAt;
  final String? startedAt;
  final String? finishedAt;

  /// open | closed | referred (linked encounter).
  final String encounterStatus;

  bool get canStart => status == 'called';
  bool get canComplete => status == 'in_session';
  bool get canSkip => status == 'called' || status == 'waiting';
}
