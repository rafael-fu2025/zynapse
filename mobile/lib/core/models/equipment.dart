/// Clinic equipment (durable assets) — mirrors
/// `frontend/src/schemas/equipment.ts` and `EquipmentService::itemShape`.
library;

/// Unit status lifecycle: all transitions are permitted; the append-only
/// status log is the control.
enum EquipmentStatus {
  working('working'),
  forRepair('for_repair'),
  forReplacement('for_replacement'),
  retired('retired');

  const EquipmentStatus(this.wire);

  /// The API wire value ('for_repair' etc.).
  final String wire;

  static EquipmentStatus fromWire(String? wire) => switch (wire) {
        'for_repair' => forRepair,
        'for_replacement' => forReplacement,
        'retired' => retired,
        _ => working,
      };

  String get label => switch (this) {
        working => 'Working',
        forRepair => 'For repair',
        forReplacement => 'For replacement',
        retired => 'Retired',
      };
}

class EquipmentUnit {
  EquipmentUnit({
    required this.id,
    required this.status,
    this.conditionNote,
    this.acquiredDate,
    required this.statusChangedAt,
    required this.createdAt,
  });

  factory EquipmentUnit.fromJson(Map<String, dynamic> json) => EquipmentUnit(
        id: (json['id'] ?? 0) as int,
        status: EquipmentStatus.fromWire(json['status'] as String?),
        conditionNote: json['condition_note'] as String?,
        acquiredDate: json['acquired_date'] as String?,
        statusChangedAt: (json['status_changed_at'] ?? '') as String,
        createdAt: (json['created_at'] ?? '') as String,
      );

  final int id;
  final EquipmentStatus status;
  final String? conditionNote;
  final String? acquiredDate;
  final String statusChangedAt;
  final String createdAt;
}

/// One from→to entry in the append-only per-unit trail.
class EquipmentStatusLogEntry {
  EquipmentStatusLogEntry({
    required this.id,
    required this.unitId,
    this.fromStatus,
    required this.toStatus,
    this.note,
    this.userEmail,
    required this.createdAt,
  });

  factory EquipmentStatusLogEntry.fromJson(Map<String, dynamic> json) =>
      EquipmentStatusLogEntry(
        id: (json['id'] ?? 0) as int,
        unitId: (json['unit_id'] ?? 0) as int,
        fromStatus: json['from_status'] == null
            ? null
            : EquipmentStatus.fromWire(json['from_status'] as String?),
        toStatus: EquipmentStatus.fromWire(json['to_status'] as String?),
        note: json['note'] as String?,
        userEmail: json['user_email'] as String?,
        createdAt: (json['created_at'] ?? '') as String,
      );

  final int id;
  final int unitId;
  final EquipmentStatus? fromStatus;
  final EquipmentStatus toStatus;
  final String? note;
  final String? userEmail;
  final String createdAt;
}

/// Catalog row with its per-status unit counts (list shape).
class Equipment {
  Equipment({
    required this.id,
    required this.name,
    this.category,
    this.location,
    this.notes,
    required this.archived,
    required this.working,
    required this.forRepair,
    required this.forReplacement,
    required this.retired,
    required this.totalUnits,
    required this.createdAt,
  });

  factory Equipment.fromJson(Map<String, dynamic> json) => Equipment(
        id: (json['id'] ?? 0) as int,
        name: (json['name'] ?? '') as String,
        category: json['category'] as String?,
        location: json['location'] as String?,
        notes: json['notes'] as String?,
        archived: (json['archived'] ?? false) as bool,
        working: (json['working'] ?? 0) as int,
        forRepair: (json['for_repair'] ?? 0) as int,
        forReplacement: (json['for_replacement'] ?? 0) as int,
        retired: (json['retired'] ?? 0) as int,
        totalUnits: (json['total_units'] ?? 0) as int,
        createdAt: (json['created_at'] ?? '') as String,
      );

  final int id;
  final String name;
  final String? category;
  final String? location;
  final String? notes;
  final bool archived;
  final int working;
  final int forRepair;
  final int forReplacement;
  final int retired;
  final int totalUnits;
  final String createdAt;

  /// Units needing a decision — feeds the attention chip in the list.
  int get attentionUnits => forRepair + forReplacement;
}

/// Detail shape — catalog + units + the equipment's status log.
class EquipmentDetail {
  EquipmentDetail({
    required this.equipment,
    required this.units,
    required this.statusLog,
  });

  factory EquipmentDetail.fromJson(Map<String, dynamic> json) =>
      EquipmentDetail(
        equipment: Equipment.fromJson(json),
        units: ((json['units'] ?? const []) as List<dynamic>)
            .map((u) => EquipmentUnit.fromJson(u as Map<String, dynamic>))
            .toList(),
        statusLog: ((json['status_log'] ?? const []) as List<dynamic>)
            .map((l) => EquipmentStatusLogEntry.fromJson(l as Map<String, dynamic>))
            .toList(),
      );

  final Equipment equipment;
  final List<EquipmentUnit> units;
  final List<EquipmentStatusLogEntry> statusLog;

  /// The unit's trail, oldest → newest (the API returns newest first).
  List<EquipmentStatusLogEntry> logFor(int unitId) =>
      statusLog.where((l) => l.unitId == unitId).toList().reversed.toList();
}
