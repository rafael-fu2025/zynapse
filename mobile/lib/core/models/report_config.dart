/// Saved report configurations and generated reports — mirrors
/// `frontend/src/schemas/reports.ts`.
library;

class ReportConfig {
  ReportConfig({
    required this.id,
    required this.name,
    required this.module,
    required this.reportType,
    required this.parameters,
    required this.isActive,
    required this.createdAt,
    required this.updatedAt,
  });

  factory ReportConfig.fromJson(Map<String, dynamic> json) => ReportConfig(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: (json['name'] ?? '') as String,
        module: (json['module'] ?? '') as String,
        reportType: (json['report_type'] ?? '') as String,
        parameters:
            json['parameters'] as Map<String, dynamic>? ?? const {},
        isActive: (json['is_active'] ?? true) as bool,
        createdAt: (json['created_at'] ?? '') as String,
        updatedAt: (json['updated_at'] ?? '') as String,
      );

  final int id;
  final String name;
  final String module;
  final String reportType;
  final Map<String, dynamic> parameters;
  final bool isActive;
  final String createdAt;
  final String updatedAt;

  String? get start => parameters['start'] as String?;
  String? get end => parameters['end'] as String?;
  bool get summarize => parameters['summarize'] == true;
}

class ReportConfigPage {
  ReportConfigPage({
    required this.items,
    required this.page,
    required this.totalPages,
    required this.total,
  });

  factory ReportConfigPage.fromJson(Map<String, dynamic> json) {
    final pagination =
        json['pagination'] as Map<String, dynamic>? ?? const {};
    return ReportConfigPage(
      items: (json['items'] as List? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(ReportConfig.fromJson)
          .toList(),
      page: (pagination['page'] as num?)?.toInt() ?? 1,
      totalPages: (pagination['total_pages'] as num?)?.toInt() ?? 1,
      total: (pagination['total'] as num?)?.toInt() ?? 0,
    );
  }

  final List<ReportConfig> items;
  final int page;
  final int totalPages;
  final int total;

  bool get hasMore => page < totalPages;
}

class GeneratedReport {
  GeneratedReport({
    required this.id,
    this.configId,
    required this.module,
    required this.format,
    required this.status,
    this.rowCount,
    this.aiSummary,
    this.errorMessage,
    required this.generatedAt,
    this.startedAt,
    this.completedAt,
    this.expiresAt,
  });

  factory GeneratedReport.fromJson(Map<String, dynamic> json) =>
      GeneratedReport(
        id: (json['id'] as num?)?.toInt() ?? 0,
        configId: (json['config_id'] as num?)?.toInt(),
        module: (json['module'] ?? '') as String,
        format: (json['format'] ?? '') as String,
        status: (json['status'] ?? 'queued') as String,
        rowCount: (json['row_count'] as num?)?.toInt(),
        aiSummary: json['ai_summary'] as String?,
        errorMessage: json['error_message'] as String?,
        generatedAt: (json['generated_at'] ?? '') as String,
        startedAt: json['started_at'] as String?,
        completedAt: json['completed_at'] as String?,
        expiresAt: json['expires_at'] as String?,
      );

  final int id;
  final int? configId;
  final String module;
  final String format;

  /// queued | processing | completed | failed | expired.
  final String status;
  final int? rowCount;
  final String? aiSummary;
  final String? errorMessage;
  final String generatedAt;
  final String? startedAt;
  final String? completedAt;
  final String? expiresAt;

  bool get pending => status == 'queued' || status == 'processing';
}

class GeneratedReportPage {
  GeneratedReportPage({
    required this.items,
    required this.page,
    required this.totalPages,
    required this.total,
  });

  factory GeneratedReportPage.fromJson(Map<String, dynamic> json) {
    final pagination =
        json['pagination'] as Map<String, dynamic>? ?? const {};
    return GeneratedReportPage(
      items: (json['items'] as List? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(GeneratedReport.fromJson)
          .toList(),
      page: (pagination['page'] as num?)?.toInt() ?? 1,
      totalPages: (pagination['total_pages'] as num?)?.toInt() ?? 1,
      total: (pagination['total'] as num?)?.toInt() ?? 0,
    );
  }

  final List<GeneratedReport> items;
  final int page;
  final int totalPages;
  final int total;

  bool get hasMore => page < totalPages;
}
