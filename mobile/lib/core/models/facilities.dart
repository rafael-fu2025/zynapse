/// Facilities (BMG) — mirrors `frontend/src/schemas/facilities.ts`
/// (`bmgUnitSchema`) and `BmgUnitDto`.
library;

class BmgUnit {
  BmgUnit({
    required this.id,
    required this.code,
    required this.displayName,
    required this.status,
    this.locationCode,
    this.specCapacityKg,
    this.defaultCategoryId,
    this.defaultCategoryName,
    this.notes,
    this.createdAt = '',
    this.updatedAt,
    this.archivedAt,
    this.activeBatchId,
    this.activeBatchWeightKg,
    this.activeBatchExpectedCompletionDate,
    this.activeBatchProgressPct,
    this.utilizationPct,
  });

  factory BmgUnit.fromJson(Map<String, dynamic> json) => BmgUnit(
        id: json['id'] as int,
        code: (json['code'] ?? '') as String,
        displayName: (json['display_name'] ?? '') as String,
        status: (json['status'] ?? 'idle') as String,
        locationCode: json['location_code'] as String?,
        specCapacityKg: (json['spec_capacity_kg'] as num?)?.toDouble(),
        defaultCategoryId: json['default_category_id'] as int?,
        defaultCategoryName: json['default_category_name'] as String?,
        notes: json['notes'] as String?,
        createdAt: (json['created_at'] ?? '') as String,
        updatedAt: json['updated_at'] as String?,
        archivedAt: json['archived_at'] as String?,
        activeBatchId: json['active_batch_id'] as int?,
        activeBatchWeightKg:
            (json['active_batch_weight_kg'] as num?)?.toDouble(),
        activeBatchExpectedCompletionDate:
            json['active_batch_expected_completion_date'] as String?,
        activeBatchProgressPct: json['active_batch_progress_pct'] as int?,
        utilizationPct: json['utilization_pct'] as int?,
      );

  final int id;
  final String code;
  final String displayName;

  /// idle | processing | awaiting_output | curing | cancelled | maintenance.
  final String status;

  final String? locationCode;
  final double? specCapacityKg;
  final int? defaultCategoryId;
  final String? defaultCategoryName;
  final String? notes;
  final String createdAt;
  final String? updatedAt;
  final String? archivedAt;
  final int? activeBatchId;
  final double? activeBatchWeightKg;

  /// Expected completion date of the active batch (ISO datetime).
  final String? activeBatchExpectedCompletionDate;

  /// 0-100 progress of the active batch vs its expected duration.
  final int? activeBatchProgressPct;

  /// 0-100 how full the drum is vs spec capacity (Audit #8).
  final int? utilizationPct;

  bool get isActive => switch (status) {
        'processing' || 'awaiting_output' || 'curing' => true,
        _ => false,
      };
}

/// An immutable, timestamped entry in a batch's append-only "Updates"
/// feed — output / curing / log. Mirrors `BmgService::listBatchUpdates`.
class BmgBatchUpdate {
  BmgBatchUpdate({
    required this.id,
    required this.updateType,
    this.outputWeightKg,
    this.curingNote,
    this.eventType,
    this.observationNote,
    this.temperatureCelsius,
    this.moistureLevel,
    this.recordedByUserId,
    required this.createdAt,
  });

  factory BmgBatchUpdate.fromJson(Map<String, dynamic> json) => BmgBatchUpdate(
        id: json['id'] as int,
        updateType: (json['update_type'] ?? '') as String,
        outputWeightKg: (json['output_weight_kg'] as num?)?.toDouble(),
        curingNote: json['curing_note'] as String?,
        eventType: json['event_type'] as String?,
        observationNote: json['observation_note'] as String?,
        temperatureCelsius:
            (json['temperature_celsius'] as num?)?.toDouble(),
        moistureLevel: json['moisture_level'] as String?,
        recordedByUserId: json['recorded_by_user_id'] as int?,
        createdAt: (json['created_at'] ?? '') as String,
      );

  final int id;
  final String updateType;
  final double? outputWeightKg;
  final String? curingNote;
  final String? eventType;
  final String? observationNote;
  final double? temperatureCelsius;
  final String? moistureLevel;
  final int? recordedByUserId;
  final String createdAt;

  /// Human label for the combined feed.
  String get typeLabel => switch (updateType) {
        'output' => 'Output',
        'curing' => 'Curing',
        'log' => 'Log',
        _ => updateType,
      };
}
