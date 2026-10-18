/// BMG automation and batch materials — mirrors the SPA
/// `bmgUnitMonitorSchema`, `bmgUnitScheduleSchema`, command/assignment
/// schemas and batch I/O rows.
library;

double _num(dynamic value) => (value as num?)?.toDouble() ?? 0;
int _int(dynamic value) => (value as num?)?.toInt() ?? 0;
String _str(dynamic value) => value?.toString() ?? '';

class BmgSchedule {
  BmgSchedule({
    this.unitId,
    required this.times,
    required this.durationSeconds,
    required this.pwm,
    required this.enabled,
    required this.version,
    this.updatedAt,
  });

  factory BmgSchedule.fromJson(Map<String, dynamic> json) => BmgSchedule(
        unitId: (json['unit_id'] as num?)?.toInt(),
        times: (json['times'] as List? ?? const [])
            .whereType<String>()
            .toList(),
        durationSeconds: _int(json['duration_seconds']),
        pwm: _int(json['pwm']),
        enabled: (json['enabled'] ?? false) as bool,
        version: _int(json['version']),
        updatedAt: json['updated_at'] as String?,
      );

  final int? unitId;
  final List<String> times;
  final int durationSeconds;
  final int pwm;
  final bool enabled;
  final int version;
  final String? updatedAt;
}

class BmgUnitSchedule {
  BmgUnitSchedule({
    required this.unitId,
    required this.unitCode,
    required this.schedule,
    this.nextRunAt,
    required this.history,
  });

  factory BmgUnitSchedule.fromJson(Map<String, dynamic> json) =>
      BmgUnitSchedule(
        unitId: _int(json['unit_id']),
        unitCode: _str(json['unit_code']),
        schedule: BmgSchedule.fromJson(
          json['schedule'] as Map<String, dynamic>? ?? const {},
        ),
        nextRunAt: json['next_run_at'] as String?,
        history: (json['history'] as List? ?? const [])
            .whereType<Map<String, dynamic>>()
            .toList(),
      );

  final int unitId;
  final String unitCode;
  final BmgSchedule schedule;
  final String? nextRunAt;
  final List<Map<String, dynamic>> history;
}

class BmgMonitorDevice {
  BmgMonitorDevice({
    required this.id,
    required this.code,
    required this.displayName,
    required this.status,
    this.firmware,
    required this.online,
    required this.offlineAfterMinutes,
    this.lastSeenAt,
    this.lastHeartbeatAt,
    this.heartbeatAgeMinutes,
    this.lastRssiDbm,
    this.lastUptimeSeconds,
    this.rtcValid,
    this.rtcOffsetSeconds,
    this.lastRunResult,
    this.motorState,
  });

  factory BmgMonitorDevice.fromJson(Map<String, dynamic> json) =>
      BmgMonitorDevice(
        id: _int(json['id']),
        code: _str(json['code']),
        displayName: _str(json['display_name']),
        status: _str(json['status']),
        firmware: json['firmware'] as String?,
        online: (json['online'] ?? false) as bool,
        offlineAfterMinutes: _int(json['offline_after_minutes']),
        lastSeenAt: json['last_seen_at'] as String?,
        lastHeartbeatAt: json['last_heartbeat_at'] as String?,
        heartbeatAgeMinutes:
            (json['heartbeat_age_minutes'] as num?)?.toInt(),
        lastRssiDbm: (json['last_rssi_dbm'] as num?)?.toInt(),
        lastUptimeSeconds: (json['last_uptime_seconds'] as num?)?.toInt(),
        rtcValid: json['rtc_valid'] as bool?,
        rtcOffsetSeconds: (json['rtc_offset_seconds'] as num?)?.toInt(),
        lastRunResult: json['last_run_result'] as String?,
        motorState: json['motor_state'] as String?,
      );

  final int id;
  final String code;
  final String displayName;
  final String status;
  final String? firmware;
  final bool online;
  final int offlineAfterMinutes;
  final String? lastSeenAt;
  final String? lastHeartbeatAt;
  final int? heartbeatAgeMinutes;
  final int? lastRssiDbm;
  final int? lastUptimeSeconds;
  final bool? rtcValid;
  final int? rtcOffsetSeconds;
  final String? lastRunResult;
  final String? motorState;
}

class BmgDeviceRun {
  BmgDeviceRun({
    required this.id,
    required this.runUid,
    required this.origin,
    required this.result,
    this.startedAt,
    this.endedAt,
    required this.durationSeconds,
    this.turnsCount,
    this.batchId,
    required this.createdAt,
  });

  factory BmgDeviceRun.fromJson(Map<String, dynamic> json) => BmgDeviceRun(
        id: _int(json['id']),
        runUid: _str(json['run_uid']),
        origin: _str(json['origin']),
        result: _str(json['result']),
        startedAt: json['started_at'] as String?,
        endedAt: json['ended_at'] as String?,
        durationSeconds: _int(json['duration_seconds']),
        turnsCount: (json['turns_count'] as num?)?.toInt(),
        batchId: (json['batch_id'] as num?)?.toInt(),
        createdAt: _str(json['created_at']),
      );

  final int id;
  final String runUid;
  final String origin;
  final String result;
  final String? startedAt;
  final String? endedAt;
  final int durationSeconds;
  final int? turnsCount;
  final int? batchId;
  final String createdAt;
}

class BmgUnitMonitor {
  BmgUnitMonitor({
    required this.unit,
    this.device,
    required this.schedule,
    this.nextRunAt,
    required this.recentRuns,
    required this.failedCount,
    required this.missedTimes,
    required this.missedCount,
    this.activeBatch,
    required this.inputKg,
    required this.outputKg,
    required this.openAlerts,
  });

  factory BmgUnitMonitor.fromJson(Map<String, dynamic> json) {
    final runs = json['runs'] as Map<String, dynamic>? ?? const {};
    final totals = json['totals'] as Map<String, dynamic>? ?? const {};
    final schedule = json['schedule'] as Map<String, dynamic>? ?? const {};
    return BmgUnitMonitor(
      unit: json['unit'] as Map<String, dynamic>? ?? const {},
      device: json['device'] is Map<String, dynamic>
          ? BmgMonitorDevice.fromJson(json['device'] as Map<String, dynamic>)
          : null,
      schedule: BmgSchedule.fromJson(schedule),
      nextRunAt: schedule['next_run_at'] as String?,
      recentRuns: (runs['recent'] as List? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(BmgDeviceRun.fromJson)
          .toList(),
      failedCount: _int(runs['failed_count']),
      missedTimes: (runs['missed_times'] as List? ?? const [])
          .whereType<String>()
          .toList(),
      missedCount: _int(runs['missed_count']),
      activeBatch: json['active_batch'] is Map<String, dynamic>
          ? json['active_batch'] as Map<String, dynamic>
          : null,
      inputKg: _num(totals['input_kg']),
      outputKg: _num(totals['output_kg']),
      openAlerts: (json['open_alerts'] as List? ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList(),
    );
  }

  final Map<String, dynamic> unit;
  final BmgMonitorDevice? device;
  final BmgSchedule schedule;
  final String? nextRunAt;
  final List<BmgDeviceRun> recentRuns;
  final int failedCount;
  final List<String> missedTimes;
  final int missedCount;
  final Map<String, dynamic>? activeBatch;
  final double inputKg;
  final double outputKg;
  final List<Map<String, dynamic>> openAlerts;
}

class BmgDeviceCommand {
  BmgDeviceCommand({
    required this.id,
    required this.command,
    required this.status,
    this.deliveredAt,
    this.acknowledgedAt,
    this.expiresAt,
    required this.createdAt,
  });

  factory BmgDeviceCommand.fromJson(Map<String, dynamic> json) =>
      BmgDeviceCommand(
        id: _int(json['id']),
        command: _str(json['command']),
        status: _str(json['status']),
        deliveredAt: json['delivered_at'] as String?,
        acknowledgedAt: json['acknowledged_at'] as String?,
        expiresAt: json['expires_at'] as String?,
        createdAt: _str(json['created_at']),
      );

  final int id;
  final String command;
  final String status;
  final String? deliveredAt;
  final String? acknowledgedAt;
  final String? expiresAt;
  final String createdAt;
}

class BmgDeviceAssignment {
  BmgDeviceAssignment({
    required this.id,
    required this.deviceId,
    required this.unitId,
    this.unitCode,
    this.unitDisplayName,
    required this.assignedAt,
    this.unassignedAt,
    required this.isActive,
  });

  factory BmgDeviceAssignment.fromJson(Map<String, dynamic> json) =>
      BmgDeviceAssignment(
        id: _int(json['id']),
        deviceId: _int(json['device_id']),
        unitId: _int(json['unit_id']),
        unitCode: json['unit_code'] as String?,
        unitDisplayName: json['unit_display_name'] as String?,
        assignedAt: _str(json['assigned_at']),
        unassignedAt: json['unassigned_at'] as String?,
        isActive: (json['is_active'] ?? false) as bool,
      );

  final int id;
  final int deviceId;
  final int unitId;
  final String? unitCode;
  final String? unitDisplayName;
  final String assignedAt;
  final String? unassignedAt;
  final bool isActive;
}

class BmgBatchInput {
  BmgBatchInput({
    required this.id,
    required this.batchId,
    required this.weightKg,
    this.categoryId,
    this.categoryName,
    this.cnRatio,
    this.bulkDensity,
    this.ph,
    this.note,
    required this.recordedAt,
    this.voidedAt,
    this.voidReason,
    required this.runningTotalKg,
  });

  factory BmgBatchInput.fromJson(Map<String, dynamic> json) => BmgBatchInput(
        id: _int(json['id']),
        batchId: _int(json['batch_id']),
        weightKg: _num(json['weight_kg']),
        categoryId: (json['category_id'] as num?)?.toInt(),
        categoryName: json['category_name'] as String?,
        cnRatio: (json['cn_ratio'] as num?)?.toDouble(),
        bulkDensity: (json['bulk_density_kg_per_m3'] as num?)?.toDouble(),
        ph: (json['ph'] as num?)?.toDouble(),
        note: json['note'] as String?,
        recordedAt: _str(json['recorded_at']),
        voidedAt: json['voided_at'] as String?,
        voidReason: json['void_reason'] as String?,
        runningTotalKg: _num(json['running_total_kg']),
      );

  final int id;
  final int batchId;
  final double weightKg;
  final int? categoryId;
  final String? categoryName;
  final double? cnRatio;
  final double? bulkDensity;
  final double? ph;
  final String? note;
  final String recordedAt;
  final String? voidedAt;
  final String? voidReason;
  final double runningTotalKg;

  bool get voided => voidedAt != null;
}

class BmgBatchOutput {
  BmgBatchOutput({
    required this.id,
    required this.batchId,
    required this.outputWeightKg,
    this.harvestDate,
    this.qualityGrade,
    this.note,
    required this.recordedAt,
    this.voidedAt,
    this.voidReason,
    required this.runningTotalKg,
  });

  factory BmgBatchOutput.fromJson(Map<String, dynamic> json) =>
      BmgBatchOutput(
        id: _int(json['id']),
        batchId: _int(json['batch_id']),
        outputWeightKg: _num(json['output_weight_kg']),
        harvestDate: json['harvest_date'] as String?,
        qualityGrade: json['quality_grade'] as String?,
        note: json['note'] as String?,
        recordedAt: _str(json['recorded_at']),
        voidedAt: json['voided_at'] as String?,
        voidReason: json['void_reason'] as String?,
        runningTotalKg: _num(json['running_total_kg']),
      );

  final int id;
  final int batchId;
  final double outputWeightKg;
  final String? harvestDate;
  final String? qualityGrade;
  final String? note;
  final String recordedAt;
  final String? voidedAt;
  final String? voidReason;
  final double runningTotalKg;

  bool get voided => voidedAt != null;
}
