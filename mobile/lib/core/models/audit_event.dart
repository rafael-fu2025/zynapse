/// Audit trail events — mirrors `frontend/src/schemas/audit.ts`
/// (`auditEventSchema`) and the backend hash-chained audit reader.
library;

class AuditActor {
  AuditActor({required this.id, this.email, this.displayName});

  factory AuditActor.fromJson(Map<String, dynamic>? json) {
    if (json == null) return AuditActor(id: 0);
    return AuditActor(
      id: (json['id'] ?? 0) as int,
      email: json['email'] as String?,
      displayName: json['display_name'] as String?,
    );
  }

  final int id;
  final String? email;
  final String? displayName;

  String get label => displayName ?? email ?? 'system';
}

class AuditEvent {
  AuditEvent({
    required this.id,
    this.prevId,
    required this.actionCode,
    required this.entityType,
    this.entityId,
    this.actor,
    this.requestId,
    this.occurredAt,
    required this.committedAt,
    required this.commitHash,
    this.payload,
  });

  factory AuditEvent.fromJson(Map<String, dynamic> json) => AuditEvent(
        id: json['id'] as int,
        prevId: json['prev_id'] as int?,
        actionCode: (json['action_code'] ?? '') as String,
        entityType: (json['entity_type'] ?? '') as String,
        entityId: json['entity_id'] as int?,
        actor: AuditActor.fromJson(json['actor'] as Map<String, dynamic>?),
        requestId: json['request_id'] as String?,
        occurredAt: json['occurred_at'] as String?,
        committedAt: (json['committed_at'] ?? '') as String,
        commitHash: (json['commit_hash'] ?? '') as String,
        payload: json['payload'] as Map<String, dynamic>?,
      );

  final int id;
  final int? prevId;
  final String actionCode;
  final String entityType;
  final int? entityId;
  final AuditActor? actor;
  final String? requestId;
  final String? occurredAt;
  final String committedAt;
  final String commitHash;

  /// Redacted payload — only present on `GET /audit/events/{id}`.
  final Map<String, dynamic>? payload;
}

/// Distinct values already present in the evidence store — drives the
/// filter dropdowns (mirrors `GET /audit/facets`).
class AuditFacets {
  AuditFacets({
    required this.actionCodes,
    required this.entityTypes,
    required this.actors,
  });

  factory AuditFacets.fromJson(Map<String, dynamic>? json) => AuditFacets(
        actionCodes: (json?['action_codes'] as List? ?? [])
            .whereType<String>()
            .toList(),
        entityTypes: (json?['entity_types'] as List? ?? [])
            .whereType<String>()
            .toList(),
        actors: (json?['actors'] as List? ?? [])
            .whereType<Map<String, dynamic>>()
            .map(AuditActor.fromJson)
            .toList(),
      );

  final List<String> actionCodes;
  final List<String> entityTypes;
  final List<AuditActor> actors;
}

/// Result of `GET /audit/verify` — hash-chain integrity check.
class AuditVerification {
  AuditVerification({
    required this.ok,
    required this.checked,
    this.verifiedUpTo,
    this.firstDivergence,
  });

  factory AuditVerification.fromJson(Map<String, dynamic> json) =>
      AuditVerification(
        ok: (json['ok'] ?? false) as bool,
        checked: (json['checked'] ?? 0) as int,
        verifiedUpTo: json['verified_up_to'] as int?,
        firstDivergence: AuditDivergence.fromJson(
            json['first_divergence'] as Map<String, dynamic>?),
      );

  final bool ok;
  final int checked;
  final int? verifiedUpTo;
  final AuditDivergence? firstDivergence;
}

class AuditDivergence {
  AuditDivergence({required this.id, this.reason, this.expected, this.actual});

  factory AuditDivergence.fromJson(Map<String, dynamic>? json) {
    if (json == null) return AuditDivergence(id: 0);
    return AuditDivergence(
      id: (json['id'] ?? 0) as int,
      reason: json['reason'] as String?,
      expected: json['expected'] as String?,
      actual: json['actual'] as String?,
    );
  }

  final int id;
  final String? reason;
  final String? expected;
  final String? actual;
}
