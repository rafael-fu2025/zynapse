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

  /// idle | processing | awaiting_output | cancelled | maintenance.
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
        'processing' || 'awaiting_output' => true,
        _ => false,
      };
}

/// An immutable, timestamped entry in a batch's append-only "Updates"
/// feed — output / log, plus historical `curing` rows predating that
/// state's retirement. Mirrors `BmgService::listBatchUpdates`.
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
        temperatureCelsius: (json['temperature_celsius'] as num?)?.toDouble(),
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
        'log' => 'Log',
        _ => updateType,
      };
}

/// One drum's live batch — mirrors the web's `activeBatchSchema` and the
/// payload of `GET /facilities/batches/active`. Powers the Processing
/// Drums card and the drum detail sheet.
class BmgActiveBatch {
  BmgActiveBatch({
    required this.batchId,
    required this.batchCode,
    required this.batchStatus,
    required this.unitId,
    required this.unitCode,
    required this.unitName,
    this.unitLocation,
    this.categoryName,
    required this.inputKg,
    required this.startedAt,
    required this.daysActive,
    required this.progressPct,
    this.outputKg,
    this.referenceDurationDays,
    this.expectedDays,
    this.expectedCompletionDate,
    this.daysUntilExpected,
    this.lastTurnedAt,
    this.daysSinceLastTurning,
  });

  factory BmgActiveBatch.fromJson(Map<String, dynamic> json) => BmgActiveBatch(
        batchId: json['batch_id'] as int,
        batchCode: (json['batch_code'] ?? '') as String,
        batchStatus: (json['batch_status'] ?? '') as String,
        unitId: json['unit_id'] as int,
        unitCode: (json['unit_code'] ?? '') as String,
        unitName: (json['unit_name'] ?? '') as String,
        unitLocation: json['unit_location'] as String?,
        categoryName: json['category_name'] as String?,
        inputKg: (json['input_kg'] as num?)?.toDouble() ?? 0,
        outputKg: (json['output_kg'] as num?)?.toDouble(),
        startedAt: (json['started_at'] ?? '') as String,
        daysActive: (json['days_active'] as num?)?.toInt() ?? 0,
        referenceDurationDays:
            (json['reference_duration_days'] as num?)?.toInt(),
        expectedDays: (json['expected_days'] as num?)?.toInt(),
        expectedCompletionDate: json['expected_completion_date'] as String?,
        daysUntilExpected: (json['days_until_expected'] as num?)?.toInt(),
        progressPct: (json['progress_pct'] as num?)?.toDouble() ?? 0,
        lastTurnedAt: json['last_turned_at'] as String?,
        daysSinceLastTurning:
            (json['days_since_last_turning'] as num?)?.toInt(),
      );

  final int batchId;
  final String batchCode;
  final String batchStatus;
  final int unitId;
  final String unitCode;
  final String unitName;
  final String? unitLocation;
  final String? categoryName;
  final double inputKg;
  final double? outputKg;
  final String startedAt;
  final int daysActive;
  final int? referenceDurationDays;
  final int? expectedDays;
  final String? expectedCompletionDate;
  final int? daysUntilExpected;
  final double progressPct;
  final String? lastTurnedAt;
  final int? daysSinceLastTurning;

  bool get isOverdue {
    final d = daysUntilExpected;
    return d != null && d < 0;
  }

  /// Human phrasing for the expected-completion line, shared by the card
  /// and the detail sheet so the two never word it differently.
  String get etaLabel {
    final d = daysUntilExpected;
    if (expectedCompletionDate == null || expectedCompletionDate!.isEmpty) {
      return '—';
    }
    if (d == null) return expectedCompletionDate!;
    if (d < 0) return '${d.abs()} day${d.abs() == 1 ? '' : 's'} overdue';
    if (d == 0) return 'Due today';
    return 'in $d day${d == 1 ? '' : 's'}';
  }
}

/// Active batches plus the server's turning-cadence threshold. Mirrors
/// the web's `useActiveBatches` response envelope.
class BmgActiveBatches {
  BmgActiveBatches({required this.batches, required this.turningDueDays});

  factory BmgActiveBatches.fromJson(Map<String, dynamic> json) =>
      BmgActiveBatches(
        batches: (json['data'] as List<dynamic>? ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(BmgActiveBatch.fromJson)
            .toList(),
        turningDueDays: (json['turning_due_days'] as num?)?.toInt() ?? 4,
      );

  final List<BmgActiveBatch> batches;

  /// Served by the API so the stale-turning styling can't drift from the
  /// rule that raises the TURNING_DUE alert.
  final int turningDueDays;

  BmgActiveBatch? forUnit(int unitId) {
    for (final b in batches) {
      if (b.unitId == unitId) return b;
    }
    return null;
  }
}

/// An SPC alert on a batch. Mirrors the web's `bmgAlertSchema`.
class BmgAlert {
  BmgAlert({
    required this.id,
    required this.batchId,
    required this.code,
    required this.severity,
    required this.message,
    required this.triggeredAt,
    this.acknowledgedAt,
  });

  factory BmgAlert.fromJson(Map<String, dynamic> json) => BmgAlert(
        id: json['id'] as int,
        batchId: (json['batch_id'] ?? 0) as int,
        code: (json['code'] ?? '') as String,
        severity: (json['severity'] ?? 'info') as String,
        message: (json['message'] ?? '') as String,
        triggeredAt: (json['triggered_at'] ?? '') as String,
        acknowledgedAt: json['acknowledged_at'] as String?,
      );

  final int id;
  final int batchId;
  final String code;
  final String severity;
  final String message;
  final String triggeredAt;
  final String? acknowledgedAt;

  bool get isOpen => acknowledgedAt == null;
}

/// An automated tumbler. Mirrors the web's `bmgDeviceSchema`.
///
/// The ingest token is returned exactly once, at mint time — only the
/// [tokenPrefix] ever comes back on a read.
class BmgDevice {
  BmgDevice({
    required this.id,
    required this.code,
    required this.displayName,
    required this.status,
    this.unitId,
    this.unitName,
    this.unitCode,
    this.tokenPrefix,
    this.firmware,
    this.lastSeenAt,
    this.silenceNotifiedAt,
    this.archivedAt,
  });

  factory BmgDevice.fromJson(Map<String, dynamic> json) => BmgDevice(
        id: json['id'] as int,
        code: (json['code'] ?? '') as String,
        displayName: (json['display_name'] ?? '') as String,
        status: (json['status'] ?? '') as String,
        unitId: (json['unit_id'] as num?)?.toInt(),
        unitName: json['unit_name'] as String?,
        unitCode: json['unit_code'] as String?,
        tokenPrefix: json['token_prefix'] as String?,
        firmware: json['firmware'] as String?,
        lastSeenAt: json['last_seen_at'] as String?,
        silenceNotifiedAt: json['silence_notified_at'] as String?,
        archivedAt: json['archived_at'] as String?,
      );

  final int id;
  final String code;
  final String displayName;
  final String status;
  final int? unitId;
  final String? unitName;
  final String? unitCode;
  final String? tokenPrefix;
  final String? firmware;
  final String? lastSeenAt;

  /// When the device-silence watchdog last flagged this device. The
  /// watchdog fires one notification per silence episode; this is the
  /// standing on-screen signal that outlives it.
  final String? silenceNotifiedAt;
  final String? archivedAt;

  bool get isArchived => archivedAt != null && archivedAt!.isNotEmpty;
  bool get isBound => unitId != null;
  bool get isSilent => silenceNotifiedAt != null && !isArchived;
  bool get isActive => status == 'active' && !isArchived;
}

/// A BMG waste category. Mirrors the web's `wasteCategorySchema`.
class BmgWasteCategory {
  BmgWasteCategory({
    required this.id,
    required this.code,
    required this.name,
    this.expectedYieldPct,
    this.referenceDurationDays,
    this.expectedDays,
    this.historicalAvgDays,
    this.sampleCount = 0,
    this.isActive = true,
  });

  factory BmgWasteCategory.fromJson(Map<String, dynamic> json) =>
      BmgWasteCategory(
        id: json['id'] as int,
        code: (json['code'] ?? '') as String,
        name: (json['name'] ?? '') as String,
        expectedYieldPct: (json['expected_yield_pct'] as num?)?.toDouble(),
        referenceDurationDays:
            (json['reference_duration_days'] as num?)?.toInt(),
        expectedDays: (json['expected_days'] as num?)?.toInt(),
        historicalAvgDays: (json['historical_avg_days'] as num?)?.toDouble(),
        sampleCount: (json['sample_count'] as num?)?.toInt() ?? 0,
        isActive: (json['is_active'] as bool?) ?? true,
      );

  final int id;
  final String code;
  final String name;
  final double? expectedYieldPct;
  final int? referenceDurationDays;

  /// Drives batch ETAs; identical to `referenceDurationDays`, with the
  /// historical average exposed separately as context.
  final int? expectedDays;
  final double? historicalAvgDays;
  final int sampleCount;
  final bool isActive;
}
