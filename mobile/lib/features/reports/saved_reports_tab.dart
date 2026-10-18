import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/api/api_client.dart';
import '../../core/models/report_config.dart';
import '../../core/services/api_service.dart';
import '../../core/utils/dates.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Saved report configurations + the generated-report archive.
/// Mirrors the SPA `SavedReportsSection`.
class SavedReportsTab extends StatefulWidget {
  const SavedReportsTab({
    super.key,
    required this.start,
    required this.end,
    required this.canConfigure,
    required this.canExport,
  });

  final String start;
  final String end;
  final bool canConfigure;
  final bool canExport;

  @override
  State<SavedReportsTab> createState() => _SavedReportsTabState();
}

class _SavedReportsTabState extends State<SavedReportsTab> {
  static const _modules = [
    'clinic',
    'counselling',
    'inventory',
    'referrals',
    'facilities',
  ];

  List<ReportConfig> _configs = const [];
  List<GeneratedReport> _generated = const [];
  ReportConfigPage? _configPage;
  GeneratedReportPage? _generatedPage;
  bool _loading = true;
  bool _busy = false;
  bool _archived = false;
  String? _error;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    _load();
    _poll = Timer.periodic(const Duration(seconds: 3), (_) {
      if (mounted && _generated.any((r) => r.pending)) {
        _loadGenerated(silent: true);
      }
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _configs.isEmpty && _generated.isEmpty;
      _error = null;
    });
    try {
      final results = await Future.wait([
        ApiService.I.reportConfigs(archived: _archived),
        ApiService.I.generatedReports(),
      ]);
      if (!mounted) return;
      final configPage = results[0] as ReportConfigPage;
      final generatedPage = results[1] as GeneratedReportPage;
      setState(() {
        _configPage = configPage;
        _configs = configPage.items;
        _generatedPage = generatedPage;
        _generated = generatedPage.items;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _loading = false;
      });
    }
  }

  Future<void> _loadGenerated({bool silent = false}) async {
    try {
      final page = await ApiService.I.generatedReports();
      if (!mounted) return;
      setState(() {
        _generatedPage = page;
        _generated = page.items;
      });
    } catch (e) {
      if (!silent && mounted) {
        _snack(mapDioError(e).message, error: true);
      }
    }
  }

  Future<void> _loadMoreConfigs() async {
    final page = _configPage;
    if (page == null || !page.hasMore || _busy) return;
    setState(() => _busy = true);
    try {
      final next = await ApiService.I.reportConfigs(
        page: page.page + 1,
        archived: _archived,
      );
      if (!mounted) return;
      setState(() {
        _configPage = next;
        _configs = [..._configs, ...next.items];
      });
    } catch (e) {
      _snack(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _loadMoreGenerated() async {
    final page = _generatedPage;
    if (page == null || !page.hasMore || _busy) return;
    setState(() => _busy = true);
    try {
      final next = await ApiService.I.generatedReports(page: page.page + 1);
      if (!mounted) return;
      setState(() {
        _generatedPage = next;
        _generated = [..._generated, ...next.items];
      });
    } catch (e) {
      _snack(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _edit([ReportConfig? config]) async {
    final payload = await showCrudForm(
      context,
      title: config == null ? 'Save report' : 'Edit saved report',
      fields: [
        CrudField.text('name', 'Name', initial: config?.name),
        CrudField.dropdown(
          'module',
          'Module',
          _modules,
          initial: config?.module ?? 'clinic',
        ),
        CrudField.date(
          'start',
          'Start date',
          initial: config?.start ?? widget.start,
        ),
        CrudField.date(
          'end',
          'End date',
          initial: config?.end ?? widget.end,
        ),
        CrudField.bool(
          'summarize',
          'Include AI summary',
          boolInitial: config?.summarize ?? false,
        ),
      ],
      submitLabel: config == null ? 'Save' : 'Update',
    );
    if (payload == null || !mounted) return;
    final body = <String, dynamic>{
      'name': payload['name'],
      'module': payload['module'],
      'parameters': {
        'range_mode': 'fixed',
        'start': payload['start'],
        'end': payload['end'],
        'summarize': payload['summarize'] == true,
      },
    };
    final ok = await runCrudAction(
      context,
      () => config == null
          ? ApiService.I.createReportConfig(body)
          : ApiService.I.updateReportConfig(config.id, body),
      successMessage:
          config == null ? 'Report configuration saved.' : 'Configuration updated.',
    );
    if (ok) _load();
  }

  Future<void> _run(ReportConfig config) async {
    setState(() => _busy = true);
    try {
      final report = await ApiService.I.runReportConfig(config.id);
      if (!mounted) return;
      _snack('Report #${report.id} queued for generation.');
      await _loadGenerated(silent: true);
    } catch (e) {
      _snack(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _toggleArchive(ReportConfig config) async {
    final ok = await runCrudAction(
      context,
      () => ApiService.I.archiveReportConfig(
        config.id,
        archived: config.isActive,
      ),
      successMessage:
          config.isActive ? 'Configuration archived.' : 'Configuration restored.',
    );
    if (ok) _load();
  }

  Future<void> _deletePermanently(ReportConfig config) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Delete permanently?',
      message: 'Permanently delete “${config.name}”? This cannot be undone. '
          'Configurations with generated report history cannot be deleted.',
      confirmLabel: 'Delete permanently',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.deletePermanently('report-configs', config.id),
      successMessage: 'Configuration permanently deleted.',
    );
    if (ok) _load();
  }

  Future<void> _download(GeneratedReport report) async {
    setState(() => _busy = true);
    try {
      final csv = await ApiService.I.downloadGeneratedReportCsv(report.id);
      final dir = await getApplicationDocumentsDirectory();
      final file = File('${dir.path}/synapse-report-${report.id}.csv');
      await file.writeAsString(csv);
      await SharePlus.instance.share(
        ShareParams(
          files: [XFile(file.path, mimeType: 'text/csv')],
          text: 'SYNAPSE report #${report.id}',
        ),
      );
      if (mounted) _snack('Report downloaded.');
    } catch (e) {
      _snack(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _snack(String message, {bool error = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: error ? const Color(0xFFB3261E) : null,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Row(
            children: [
              const Expanded(
                child: Text(
                  'Saved configurations',
                  style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
                ),
              ),
              FilterChip(
                label: const Text('Archived'),
                selected: _archived,
                onSelected: (value) {
                  setState(() => _archived = value);
                  _load();
                },
              ),
              if (widget.canConfigure) ...[
                const SizedBox(width: 8),
                FilledButton.icon(
                  onPressed: _busy ? null : () => _edit(),
                  icon: const Icon(HugeIcons.strokeRoundedAdd01, size: 17),
                  label: const Text('New'),
                ),
              ],
            ],
          ),
          const SizedBox(height: 8),
          if (_configs.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 10),
              child: Text(
                'No saved configurations.',
                style: TextStyle(fontSize: 12.5, color: Colors.black45),
              ),
            )
          else
            for (final config in _configs)
              _ConfigCard(
                config: config,
                busy: _busy,
                canConfigure: widget.canConfigure,
                onRun: () => _run(config),
                onEdit: () => _edit(config),
                onArchive: () => _toggleArchive(config),
                onDelete: () => _deletePermanently(config),
              ),
          if (_configPage?.hasMore == true)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                onPressed: _busy ? null : _loadMoreConfigs,
                child: const Text('Load more configurations'),
              ),
            ),
          const SizedBox(height: 20),
          const Text(
            'Generated reports',
            style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          if (_generated.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 10),
              child: Text(
                'No generated reports yet.',
                style: TextStyle(fontSize: 12.5, color: Colors.black45),
              ),
            )
          else
            for (final report in _generated)
              _GeneratedCard(
                report: report,
                busy: _busy,
                canExport: widget.canExport,
                onDownload: () => _download(report),
              ),
          if (_generatedPage?.hasMore == true)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                onPressed: _busy ? null : _loadMoreGenerated,
                child: const Text('Load more reports'),
              ),
            ),
        ],
      ),
    );
  }
}

class _ConfigCard extends StatelessWidget {
  const _ConfigCard({
    required this.config,
    required this.busy,
    required this.canConfigure,
    required this.onRun,
    required this.onEdit,
    required this.onArchive,
    required this.onDelete,
  });

  final ReportConfig config;
  final bool busy;
  final bool canConfigure;
  final VoidCallback onRun;
  final VoidCallback onEdit;
  final VoidCallback onArchive;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 8),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color: Theme.of(context)
              .colorScheme
              .outlineVariant
              .withValues(alpha: 0.5),
        ),
      ),
      child: ListTile(
        title: Row(
          children: [
            Expanded(
              child: Text(
                config.name,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
            StatusBadge(
              label: titleCaseOption(config.module),
              color: const Color(0xFF1E6FD9),
            ),
            if (!config.isActive)
              const Padding(
                padding: EdgeInsets.only(left: 6),
                child: StatusBadge(
                  label: 'Archived',
                  color: Colors.black45,
                ),
              ),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Text(
            '${config.start ?? '—'} → ${config.end ?? '—'}'
            '${config.summarize ? ' · AI summary' : ''}',
            style: const TextStyle(fontSize: 11.5),
          ),
        ),
        trailing: canConfigure
            ? PopupMenuButton<String>(
                enabled: !busy,
                icon: const Icon(HugeIcons.strokeRoundedMore),
                onSelected: (action) {
                  switch (action) {
                    case 'run':
                      onRun();
                    case 'edit':
                      onEdit();
                    case 'archive':
                      onArchive();
                    case 'delete':
                      onDelete();
                  }
                },
                itemBuilder: (_) => [
                  if (config.isActive)
                    const PopupMenuItem(
                        value: 'run', child: Text('Run now')),
                  if (config.isActive)
                    const PopupMenuItem(
                        value: 'edit', child: Text('Edit')),
                  const PopupMenuItem(
                    value: 'archive',
                    child: Text('Archive / restore'),
                  ),
                  if (!config.isActive)
                    const PopupMenuItem(
                        value: 'delete', child: Text('Delete permanently')),
                ],
              )
            : null,
      ),
    );
  }
}

class _GeneratedCard extends StatelessWidget {
  const _GeneratedCard({
    required this.report,
    required this.busy,
    required this.canExport,
    required this.onDownload,
  });

  final GeneratedReport report;
  final bool busy;
  final bool canExport;
  final VoidCallback onDownload;

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 8),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color: Theme.of(context)
              .colorScheme
              .outlineVariant
              .withValues(alpha: 0.5),
        ),
      ),
      child: ListTile(
        title: Row(
          children: [
            Expanded(
              child: Text(
                'Report #${report.id} · ${titleCaseOption(report.module)}',
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
            StatusBadge(
              label: titleCaseOption(report.status),
              color: _statusColor(report.status),
            ),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                [
                  fmtUtcShort(report.generatedAt),
                  if (report.rowCount != null) '${report.rowCount} rows',
                  if (report.errorMessage != null) report.errorMessage!,
                ].join(' · '),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 11.5),
              ),
              if (report.aiSummary != null &&
                  report.aiSummary!.trim().isNotEmpty)
                Text(
                  report.aiSummary!,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style:
                      const TextStyle(fontSize: 11, color: Colors.black45),
                ),
            ],
          ),
        ),
        trailing: report.status == 'completed' && canExport
            ? IconButton(
                onPressed: busy ? null : onDownload,
                tooltip: 'Download CSV',
                icon: const Icon(HugeIcons.strokeRoundedDownload01),
              )
            : report.pending
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : null,
      ),
    );
  }

  static Color _statusColor(String status) => switch (status) {
        'completed' => const Color(0xFF1B7A43),
        'queued' || 'processing' => const Color(0xFFB45309),
        'failed' => const Color(0xFFB3261E),
        'expired' => Colors.black45,
        _ => Colors.black45,
      };
}
