/// Workstation dashboard — mirrors
/// `frontend/src/schemas/workstationDashboard.ts` and
/// `GET /dashboard/{unit}`.
library;

class WorkstationMetric {
  WorkstationMetric({
    required this.key,
    required this.label,
    required this.value,
    this.unit,
    required this.description,
  });

  factory WorkstationMetric.fromJson(Map<String, dynamic> json) =>
      WorkstationMetric(
        key: (json['key'] ?? '') as String,
        label: (json['label'] ?? '') as String,
        value: (json['value'] as num?)?.toDouble() ?? 0,
        unit: json['unit'] as String?,
        description: (json['description'] ?? '') as String,
      );

  final String key;
  final String label;
  final double value;
  final String? unit;
  final String description;
}

class WorkstationTrendPoint {
  WorkstationTrendPoint({required this.day, required this.value});

  factory WorkstationTrendPoint.fromJson(Map<String, dynamic> json) =>
      WorkstationTrendPoint(
        day: (json['day'] ?? '') as String,
        value: (json['value'] as num?)?.toInt() ?? 0,
      );

  final String day;
  final int value;
}

class WorkstationTrend {
  WorkstationTrend({
    required this.title,
    required this.description,
    required this.points,
  });

  factory WorkstationTrend.fromJson(Map<String, dynamic> json) =>
      WorkstationTrend(
        title: (json['title'] ?? '') as String,
        description: (json['description'] ?? '') as String,
        points: (json['points'] as List? ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(WorkstationTrendPoint.fromJson)
            .toList(),
      );

  final String title;
  final String description;
  final List<WorkstationTrendPoint> points;
}

class WorkstationBreakdownItem {
  WorkstationBreakdownItem({
    required this.key,
    required this.label,
    required this.value,
  });

  factory WorkstationBreakdownItem.fromJson(Map<String, dynamic> json) =>
      WorkstationBreakdownItem(
        key: (json['key'] ?? '') as String,
        label: (json['label'] ?? '') as String,
        value: (json['value'] as num?)?.toInt() ?? 0,
      );

  final String key;
  final String label;
  final int value;
}

class WorkstationBreakdown {
  WorkstationBreakdown({
    required this.title,
    required this.description,
    required this.items,
  });

  factory WorkstationBreakdown.fromJson(Map<String, dynamic> json) =>
      WorkstationBreakdown(
        title: (json['title'] ?? '') as String,
        description: (json['description'] ?? '') as String,
        items: (json['items'] as List? ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(WorkstationBreakdownItem.fromJson)
            .toList(),
      );

  final String title;
  final String description;
  final List<WorkstationBreakdownItem> items;
}

class WorkstationRecentRow {
  WorkstationRecentRow({
    required this.id,
    required this.code,
    required this.title,
    this.subtitle,
    required this.status,
    this.occurredAt,
    this.owner,
  });

  factory WorkstationRecentRow.fromJson(Map<String, dynamic> json) =>
      WorkstationRecentRow(
        id: (json['id'] as num?)?.toInt() ?? 0,
        code: (json['code'] ?? '') as String,
        title: (json['title'] ?? '') as String,
        subtitle: json['subtitle'] as String?,
        status: (json['status'] ?? '') as String,
        occurredAt: json['occurred_at'] as String?,
        owner: json['owner'] as String?,
      );

  final int id;
  final String code;
  final String title;
  final String? subtitle;
  final String status;
  final String? occurredAt;
  final String? owner;
}

class WorkstationRecent {
  WorkstationRecent({
    required this.kind,
    required this.title,
    required this.rows,
  });

  factory WorkstationRecent.fromJson(Map<String, dynamic> json) =>
      WorkstationRecent(
        kind: (json['kind'] ?? '') as String,
        title: (json['title'] ?? '') as String,
        rows: (json['rows'] as List? ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(WorkstationRecentRow.fromJson)
            .toList(),
      );

  final String kind;
  final String title;
  final List<WorkstationRecentRow> rows;
}

class WorkstationDashboard {
  WorkstationDashboard({
    required this.unit,
    required this.day,
    required this.generatedAt,
    required this.scope,
    required this.metrics,
    required this.trend,
    required this.breakdown,
    required this.recent,
  });

  factory WorkstationDashboard.fromJson(Map<String, dynamic> json) =>
      WorkstationDashboard(
        unit: (json['unit'] ?? '') as String,
        day: (json['day'] ?? '') as String,
        generatedAt: (json['generated_at'] ?? '') as String,
        scope: (json['scope'] ?? 'tenant') as String,
        metrics: (json['metrics'] as List? ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(WorkstationMetric.fromJson)
            .toList(),
        trend: WorkstationTrend.fromJson(
          json['trend'] as Map<String, dynamic>? ?? const {},
        ),
        breakdown: WorkstationBreakdown.fromJson(
          json['breakdown'] as Map<String, dynamic>? ?? const {},
        ),
        recent: WorkstationRecent.fromJson(
          json['recent'] as Map<String, dynamic>? ?? const {},
        ),
      );

  final String unit;
  final String day;
  final String generatedAt;

  /// `tenant`, or `own` for a guidance counsellor without `read_any`.
  final String scope;
  final List<WorkstationMetric> metrics;
  final WorkstationTrend trend;
  final WorkstationBreakdown breakdown;
  final WorkstationRecent recent;
}
