/// Report summary — mirrors `frontend/src/schemas/reports.ts`
/// (`reportSummarySchema`) and `GET /reports/summary`.
library;

class ModuleCounts {
  ModuleCounts.fromJson(Map<String, dynamic>? json)
      : encounters = _int(json, 'encounters'),
        checkins = _int(json, 'checkins'),
        appointments = _int(json, 'appointments'),
        sessions = _int(json, 'sessions'),
        activeBatches = _int(json, 'active_batches'),
        dispensedQty = _int(json, 'dispensed_qty'),
        created = _int(json, 'created'),
        completedBatches = _int(json, 'completed_batches');

  final int? encounters;
  final int? checkins;
  final int? appointments;
  final int? sessions;
  final int? activeBatches;
  final int? dispensedQty;
  final int? created;
  final int? completedBatches;

  static int? _int(Map<String, dynamic>? json, String key) =>
      json == null ? null : (json[key] as num?)?.toInt();
}

class ReportSummary {
  ReportSummary({this.snapshotAt, this.clinic, this.counselling, this.inventory, this.referrals, this.facilities});

  factory ReportSummary.fromJson(Map<String, dynamic> json) => ReportSummary(
        snapshotAt: json['snapshot_at'] as String?,
        clinic: ModuleCounts.fromJson(_map(json, 'clinic')),
        counselling: ModuleCounts.fromJson(_map(json, 'counselling')),
        inventory: ModuleCounts.fromJson(_map(json, 'inventory')),
        referrals: ModuleCounts.fromJson(_map(json, 'referrals')),
        facilities: ModuleCounts.fromJson(_map(json, 'facilities')),
      );

  static Map<String, dynamic>? _map(Map<String, dynamic> json, String key) {
    final v = json[key];
    return v is Map<String, dynamic> ? v : null;
  }

  final String? snapshotAt;
  final ModuleCounts? clinic;
  final ModuleCounts? counselling;
  final ModuleCounts? inventory;
  final ModuleCounts? referrals;
  final ModuleCounts? facilities;
}
