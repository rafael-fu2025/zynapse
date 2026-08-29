import 'dart:io';

import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:intl/intl.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/report.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';
import 'report_pdf.dart';

/// Institution reports — mirrors the SPA Reports page.
///
/// * Overview tab: `GET /reports/summary` stat grid (trailing 7 days).
/// * Analytics module tabs (Clinic / Counselling / Inventory / Referrals /
///   Facilities): `GET /reports/{module}` KPI + breakdown views.
/// * Export per module (gated by `reports.export`): `GET /reports/export/
///   {module}` CSV saved to the app documents directory, or a client-built
///   PDF (ReportPdfGenerator) that can be saved or opened in the OS share
///   sheet via share_plus.
class ReportsScreen extends StatefulWidget {
  const ReportsScreen({super.key});

  @override
  State<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends State<ReportsScreen>
    with SingleTickerProviderStateMixin {
  late DateTime _start;
  late DateTime _end;
  bool _loading = true;
  String? _error;
  ReportSummary? _summary;
  bool _loadedOnce = false;
  late final TabController _tabController;

  bool get _canExport =>
      context.read<AuthController>().session?.hasPermission('reports.export') ??
      false;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _end = now;
    _start = now.subtract(const Duration(days: 6));
    _tabController = TabController(length: 6, vsync: this);
    _load();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final summary = await ApiService.I.reportSummary(
        start: DateFormat('yyyy-MM-dd').format(_start),
        end: DateFormat('yyyy-MM-dd').format(_end),
      );
      setState(() {
        _summary = summary;
        _loadedOnce = true;
        _loading = false;
      });
    } catch (e) {
      setState(() {
        _error = mapDioError(e).message;
        _loading = false;
      });
    }
  }

  Future<void> _pickStart() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _start,
      firstDate: DateTime.now().subtract(const Duration(days: 365)),
      lastDate: _end,
    );
    if (picked != null) {
      setState(() => _start = picked);
      _load();
    }
  }

  Future<void> _pickEnd() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _end,
      firstDate: _start,
      lastDate: DateTime.now(),
    );
    if (picked != null) {
      setState(() => _end = picked);
      _load();
    }
  }

  (DateTime, DateTime) _namedRange(String preset) {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    return switch (preset) {
      'daily' => (today, today),
      'weekly' => (today.subtract(Duration(days: today.weekday - 1)), today),
      'monthly' => (DateTime(today.year, today.month), today),
      _ => (DateTime(today.month >= 8 ? today.year : today.year - 1, 8), today),
    };
  }

  void _applyNamedRange(String preset) {
    final (start, end) = _namedRange(preset);
    setState(() {
      _start = start;
      _end = end;
    });
    _load();
  }

  bool _isNamedRange(String preset) {
    final (start, end) = _namedRange(preset);
    return DateUtils.isSameDay(_start, start) && DateUtils.isSameDay(_end, end);
  }

  @override
  Widget build(BuildContext context) {
    final start = DateFormat('yyyy-MM-dd').format(_start);
    final end = DateFormat('yyyy-MM-dd').format(_end);
    final rangeKey = ValueKey('$start-$end');
    return Scaffold(
      appBar: AppBar(
        title: const Text('Reports'),
        bottom: TabBar(
          controller: _tabController,
          isScrollable: true,
          tabs: const [
            Tab(text: 'Overview'),
            Tab(text: 'Clinic'),
            Tab(text: 'Counselling'),
            Tab(text: 'Inventory'),
            Tab(text: 'Referrals'),
            Tab(text: 'Facilities'),
          ],
        ),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
            child: Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _pickStart,
                    icon:
                        const Icon(HugeIcons.strokeRoundedCalendar01, size: 16),
                    label: Text(DateFormat('MMM d, y').format(_start)),
                  ),
                ),
                const Padding(
                  padding: EdgeInsets.symmetric(horizontal: 8),
                  child: Text('→'),
                ),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _pickEnd,
                    icon:
                        const Icon(HugeIcons.strokeRoundedCalendar01, size: 16),
                    label: Text(DateFormat('MMM d, y').format(_end)),
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Wrap(
                spacing: 8,
                children: [
                  for (final preset in const [
                    ('daily', 'Daily'),
                    ('weekly', 'Weekly'),
                    ('monthly', 'Monthly'),
                    ('academic', 'Academic year'),
                  ])
                    ChoiceChip(
                      label: Text(preset.$2),
                      selected: _isNamedRange(preset.$1),
                      onSelected: (_) => _applyNamedRange(preset.$1),
                    ),
                ],
              ),
            ),
          ),
          Expanded(
            child: TabBarView(
              controller: _tabController,
              children: [
                RefreshIndicator(onRefresh: _load, child: _buildOverview()),
                _ModuleReportTab(
                    key: ValueKey('clinic-$rangeKey'),
                    module: 'clinic',
                    start: start,
                    end: end,
                    canExport: _canExport),
                _ModuleReportTab(
                    key: ValueKey('counselling-$rangeKey'),
                    module: 'counselling',
                    start: start,
                    end: end,
                    canExport: _canExport),
                _ModuleReportTab(
                    key: ValueKey('inventory-$rangeKey'),
                    module: 'inventory',
                    start: start,
                    end: end,
                    canExport: _canExport),
                _ModuleReportTab(
                    key: ValueKey('referrals-$rangeKey'),
                    module: 'referrals',
                    start: start,
                    end: end,
                    canExport: _canExport),
                _ModuleReportTab(
                    key: ValueKey('facilities-$rangeKey'),
                    module: 'facilities',
                    start: start,
                    end: end,
                    canExport: _canExport),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildOverview() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    final s = _summary;
    if (s == null) return AsyncState.empty('No report data.');

    final tiles = <Widget>[
      if (s.clinic != null) ...[
        StatTile(
            label: 'Clinic encounters',
            value: '${s.clinic!.encounters ?? 0}',
            icon: HugeIcons.strokeRoundedStethoscope),
        StatTile(
            label: 'Clinic check-ins',
            value: '${s.clinic!.checkins ?? 0}',
            icon: HugeIcons.strokeRoundedLogin02,
            color: const Color(0xFF1E6FD9)),
      ],
      if (s.counselling != null) ...[
        StatTile(
            label: 'Counselling appointments',
            value: '${s.counselling!.appointments ?? 0}',
            icon: HugeIcons.strokeRoundedMessageMultiple01,
            color: const Color(0xFF5B4BA6)),
        StatTile(
            label: 'Sessions opened',
            value: '${s.counselling!.sessions ?? 0}',
            icon: HugeIcons.strokeRoundedMessage01,
            color: const Color(0xFF8A5A00)),
      ],
      if (s.inventory != null) ...[
        StatTile(
            label: 'Dispensed qty',
            value: '${s.inventory!.dispensedQty ?? 0}',
            icon: HugeIcons.strokeRoundedPackage02,
            color: const Color(0xFFB45309)),
        StatTile(
            label: 'Active batches',
            value: '${s.inventory!.activeBatches ?? 0}',
            icon: HugeIcons.strokeRoundedLayers01,
            color: const Color(0xFF0F766E)),
      ],
      if (s.referrals != null) ...[
        StatTile(
            label: 'Referrals created',
            value: '${s.referrals!.created ?? 0}',
            icon: HugeIcons.strokeRoundedShare01,
            color: const Color(0xFF1B7A43)),
      ],
      if (s.facilities != null) ...[
        StatTile(
            label: 'Batches completed',
            value: '${s.facilities!.completedBatches ?? 0}',
            icon: HugeIcons.strokeRoundedFactory01,
            color: const Color(0xFF37474F)),
      ],
    ];

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text(
          'Institution overview',
          style: Theme.of(context)
              .textTheme
              .titleMedium
              ?.copyWith(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 4),
        Text(
          '${DateFormat('MMM d, y').format(_start)} → '
          '${DateFormat('MMM d, y').format(_end)}'
          '${s.snapshotAt != null ? ' · snapshot ${fmtSnapshot(s.snapshotAt!)}' : ''}',
          style: Theme.of(context)
              .textTheme
              .bodySmall
              ?.copyWith(color: Colors.black54),
        ),
        const SizedBox(height: 12),
        if (tiles.isEmpty)
          AsyncState.empty('No report data for this range.')
        else
          GridView.count(
            crossAxisCount: 2,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 12,
            crossAxisSpacing: 12,
            childAspectRatio: 1.5,
            children: tiles,
          ),
      ],
    );
  }

  String fmtSnapshot(String raw) {
    // Backend returns a UTC timestamp; strip to date+time for display.
    final m =
        RegExp(r'^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})').firstMatch(raw);
    if (m == null) return raw;
    return '${m[2]}/${m[3]} ${m[4]}:${m[5]}';
  }
}

/// A single analytics module tab — fetches `GET /reports/{module}` for the
/// current range and renders KPI tiles + breakdown bars, plus a CSV Export
/// action (gated by `reports.export`).
class _ModuleReportTab extends StatefulWidget {
  const _ModuleReportTab({
    super.key,
    required this.module,
    required this.start,
    required this.end,
    required this.canExport,
  });

  final String module;
  final String start;
  final String end;
  final bool canExport;

  @override
  State<_ModuleReportTab> createState() => _ModuleReportTabState();
}

class _ModuleReportTabState extends State<_ModuleReportTab> {
  bool _loading = true;
  String? _error;
  Map<String, dynamic>? _data;
  bool _exporting = false;
  bool _pdfExporting = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await ApiService.I.reportModule(
        widget.module,
        start: widget.start,
        end: widget.end,
      );
      if (!mounted) return;
      setState(() {
        _data = data;
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

  String get _baseName =>
      'synapse-report-${widget.module}-${widget.start}_${widget.end}';

  Future<File> _writeCsv() async {
    final csv = await ApiService.I.reportExportCsv(
      widget.module,
      start: widget.start,
      end: widget.end,
    );
    final dir = await getApplicationDocumentsDirectory();
    final file = File('${dir.path}/$_baseName.csv');
    await file.writeAsString(csv);
    return file;
  }

  Future<File> _writePdf() async {
    final data = _data;
    if (data == null) {
      throw StateError('Report data is not loaded yet.');
    }
    final bytes = await ReportPdfGenerator.build(
      module: widget.module,
      start: widget.start,
      end: widget.end,
      data: data,
    );
    final dir = await getApplicationDocumentsDirectory();
    final file = File('${dir.path}/$_baseName.pdf');
    await file.writeAsBytes(bytes);
    return file;
  }

  Future<void> _exportCsv() async {
    setState(() => _exporting = true);
    try {
      final file = await _writeCsv();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Exported ${file.path}')),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Export failed: ${mapDioError(e).message}')),
      );
    } finally {
      if (mounted) setState(() => _exporting = false);
    }
  }

  Future<void> _exportPdf() async {
    setState(() => _pdfExporting = true);
    try {
      final file = await _writePdf();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Exported ${file.path}')),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('PDF export failed: ${mapDioError(e).message}')),
      );
    } finally {
      if (mounted) setState(() => _pdfExporting = false);
    }
  }

  Future<void> _sharePdf() async {
    setState(() => _pdfExporting = true);
    try {
      final file = await _writePdf();
      if (!mounted) return;
      final box = context.findRenderObject() as RenderBox?;
      final result = await SharePlus.instance.share(
        ShareParams(
          files: [XFile(file.path, mimeType: 'application/pdf')],
          subject: _baseName,
          sharePositionOrigin:
              box != null ? box.localToGlobal(Offset.zero) & box.size : null,
        ),
      );
      if (!mounted) return;
      if (result.status == ShareResultStatus.dismissed) {
        // User dismissed the share sheet — nothing further to do.
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Share failed: ${mapDioError(e).message}')),
      );
    } finally {
      if (mounted) setState(() => _pdfExporting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final moduleLabel =
        widget.module[0].toUpperCase() + widget.module.substring(1);
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                moduleLabel,
                style: Theme.of(context)
                    .textTheme
                    .titleMedium
                    ?.copyWith(fontWeight: FontWeight.w700),
              ),
            ),
            if (widget.canExport)
              PopupMenuButton<String>(
                tooltip: 'Export report',
                enabled: !_exporting && !_pdfExporting,
                onSelected: (action) {
                  switch (action) {
                    case 'csv':
                      _exportCsv();
                    case 'pdf':
                      _exportPdf();
                    case 'share':
                      _sharePdf();
                  }
                },
                itemBuilder: (context) => [
                  const PopupMenuItem(
                    value: 'csv',
                    child: ListTile(
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      leading:
                          Icon(HugeIcons.strokeRoundedDownload02, size: 18),
                      title: Text('Export CSV'),
                    ),
                  ),
                  const PopupMenuItem(
                    value: 'pdf',
                    child: ListTile(
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      leading: Icon(HugeIcons.strokeRoundedFile01, size: 18),
                      title: Text('Export PDF'),
                    ),
                  ),
                  const PopupMenuItem(
                    value: 'share',
                    child: ListTile(
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      leading: Icon(HugeIcons.strokeRoundedShare01, size: 18),
                      title: Text('Share as PDF'),
                    ),
                  ),
                ],
                child: OutlinedButton.icon(
                  onPressed: null,
                  icon: _exporting || _pdfExporting
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(HugeIcons.strokeRoundedDownload02, size: 16),
                  label: Text(_exporting
                      ? 'Exporting…'
                      : _pdfExporting
                          ? 'Building…'
                          : 'Export'),
                ),
              ),
          ],
        ),
        const SizedBox(height: 12),
        if (_loading)
          const Padding(
            padding: EdgeInsets.all(32),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_error != null)
          AsyncState.error(_error!, onRetry: _load)
        else if (_data == null)
          AsyncState.empty('No report data for this range.')
        else
          _buildModule(_data!),
      ],
    );
  }

  Widget _buildModule(Map<String, dynamic> d) {
    return switch (widget.module) {
      'clinic' => _buildClinic(d),
      'counselling' => _buildCounselling(d),
      'inventory' => _buildInventory(d),
      'referrals' => _buildReferrals(d),
      'facilities' => _buildFacilities(d),
      _ => AsyncState.empty('Unknown module.'),
    };
  }

  Widget _kpis(List<(String, String)> tiles) {
    return GridView.count(
      crossAxisCount: 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 12,
      crossAxisSpacing: 12,
      childAspectRatio: 1.6,
      children: [
        for (final (label, value) in tiles)
          Card(
            elevation: 0,
            margin: EdgeInsets.zero,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
              side: BorderSide(
                color: Theme.of(context)
                    .colorScheme
                    .outlineVariant
                    .withValues(alpha: 0.5),
              ),
            ),
            child: Padding(
              padding: const EdgeInsets.all(10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    value,
                    style: const TextStyle(
                        fontSize: 20, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    label,
                    style: const TextStyle(color: Colors.black54, fontSize: 11),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }

  Widget _breakdown(String title, List<Map<String, dynamic>> rows,
      {String valueKey = 'cnt', String labelKey = 'label'}) {
    if (rows.isEmpty) return const SizedBox.shrink();
    final total = rows.fold<int>(
        0, (acc, r) => acc + ((r[valueKey] as num?)?.toInt() ?? 0));
    final max = total > 0
        ? total
        : (rows
            .map((r) => (r[valueKey] as num?)?.toInt() ?? 0)
            .fold<int>(0, (a, b) => a > b ? a : b));
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SizedBox(height: 16),
        Text(title,
            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
        const SizedBox(height: 8),
        for (final r in rows)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 3),
            child: Row(
              children: [
                SizedBox(
                  width: 120,
                  child: Text(
                    '${r[labelKey] ?? '—'}',
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 12),
                  ),
                ),
                Expanded(
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(3),
                    child: LinearProgressIndicator(
                      value: max > 0
                          ? ((r[valueKey] as num?)?.toDouble() ?? 0) / max
                          : 0,
                      minHeight: 8,
                      backgroundColor:
                          Theme.of(context).colorScheme.surfaceContainerHighest,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                SizedBox(
                  width: 32,
                  child: Text(
                    '${r[valueKey] ?? 0}',
                    textAlign: TextAlign.right,
                    style: const TextStyle(
                        fontSize: 12, fontWeight: FontWeight.w600),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }

  Widget _breakdownLabeled(String title, List<Map<String, dynamic>> rows,
      {String valueKey = 'cnt', String Function(Map<String, dynamic>)? label}) {
    if (rows.isEmpty) return const SizedBox.shrink();
    final labeled = rows
        .map((r) => {
              ...r,
              'label': label != null
                  ? label(r)
                  : titleCaseOption(
                      '${r['status'] ?? r['category'] ?? r['type'] ?? r['kind'] ?? r['outcome'] ?? r['month'] ?? '—'}'),
            })
        .toList();
    return _breakdown(title, labeled, valueKey: valueKey);
  }

  Widget _buildClinic(Map<String, dynamic> d) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _kpis([
          ('Total visits', '${d['total_encounters'] ?? 0}'),
          ('Unique patients', '${d['unique_patients'] ?? 0}'),
          ('Avg / patient', '${d['avg_visits_per_patient'] ?? 0}'),
          ('Avg / day', '${d['avg_per_day'] ?? 0}'),
        ]),
        _breakdownLabeled(
            'Status',
            (d['status_breakdown'] as List? ?? [])
                .cast<Map<String, dynamic>>()),
        _breakdownLabeled(
            'Complaint categories',
            (d['complaint_categories'] as List? ?? [])
                .cast<Map<String, dynamic>>(),
            label: (r) => '${r['category']}'),
        _breakdownLabeled(
            'Patient type',
            (d['patient_type_breakdown'] as List? ?? [])
                .cast<Map<String, dynamic>>(),
            label: (r) => titleCaseOption('${r['kind']}')),
        _breakdownLabeled('Monthly visits',
            (d['monthly_visits'] as List? ?? []).cast<Map<String, dynamic>>(),
            label: (r) => '${r['month']}'),
        _medList(
            'Most dispensed medicines',
            (d['most_common_medications'] as List? ?? [])
                .cast<Map<String, dynamic>>()),
      ],
    );
  }

  Widget _buildCounselling(Map<String, dynamic> d) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _kpis([
          ('Appointments', '${d['total_appointments'] ?? 0}'),
          ('No-shows', '${d['no_show_count'] ?? 0}'),
          ('No-show rate', '${d['no_show_rate'] ?? 0}%'),
          ('Sessions opened', '${d['sessions_opened'] ?? 0}'),
        ]),
        _breakdownLabeled(
            'Status',
            (d['status_breakdown'] as List? ?? [])
                .cast<Map<String, dynamic>>()),
        _breakdownLabeled('Type',
            (d['type_breakdown'] as List? ?? []).cast<Map<String, dynamic>>(),
            label: (r) => titleCaseOption('${r['type']}')),
      ],
    );
  }

  Widget _buildInventory(Map<String, dynamic> d) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _kpis([
          ('Medicines', '${d['total_medicines'] ?? 0}'),
          ('Dispensed', '${d['total_dispensed'] ?? 0}'),
          ('Needs to reorder', '${(d['low_stock'] as List? ?? []).length}'),
          ('Expired', '${(d['expired'] as List? ?? []).length}'),
        ]),
        _medList('Needs to reorder',
            (d['low_stock'] as List? ?? []).cast<Map<String, dynamic>>()),
        _medList('Expired batches',
            (d['expired'] as List? ?? []).cast<Map<String, dynamic>>(),
            valueKey: 'quantity_remaining'),
        _medList('Expiring ≤90d',
            (d['expiring'] as List? ?? []).cast<Map<String, dynamic>>(),
            valueKey: 'quantity_remaining'),
        _medList('Top dispensed',
            (d['top_dispensed'] as List? ?? []).cast<Map<String, dynamic>>(),
            valueKey: 'qty'),
      ],
    );
  }

  Widget _buildReferrals(Map<String, dynamic> d) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _kpis([
          ('Total', '${d['total_referrals'] ?? 0}'),
          ('Closed', '${d['closed_count'] ?? 0}'),
          ('Closed rate', '${d['closed_rate'] ?? 0}%'),
        ]),
        _breakdownLabeled(
            'Status',
            (d['status_breakdown'] as List? ?? [])
                .cast<Map<String, dynamic>>()),
        _breakdownLabeled('Flow',
            (d['flow_breakdown'] as List? ?? []).cast<Map<String, dynamic>>(),
            label: (r) =>
                '${titleCaseOption('${r['source_module'] ?? ''}')}→${titleCaseOption('${r['target_module'] ?? ''}')}'),
      ],
    );
  }

  Widget _buildFacilities(Map<String, dynamic> d) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _kpis([
          ('Batches', '${d['total_batches'] ?? 0}'),
          ('Input (kg)', '${d['input_kg'] ?? 0}'),
          ('Output (kg)', '${d['output_kg'] ?? 0}'),
        ]),
        _breakdownLabeled(
            'Status',
            (d['status_breakdown'] as List? ?? [])
                .cast<Map<String, dynamic>>()),
        _breakdownLabeled(
            'Categories',
            (d['category_breakdown'] as List? ?? [])
                .cast<Map<String, dynamic>>(),
            label: (r) => '${r['category']}'),
      ],
    );
  }

  Widget _medList(String title, List<Map<String, dynamic>> rows,
      {String valueKey = 'qty'}) {
    if (rows.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SizedBox(height: 16),
        Text(title,
            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
        const SizedBox(height: 4),
        for (final r in rows)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Text(
              '${r['generic_name'] ?? r['name'] ?? '—'}'
              '${r['brand_name'] != null ? ' (${r['brand_name']})' : ''}'
              ' · ${r[valueKey] ?? 0}'
              '${r['unit'] != null ? ' ${r['unit']}' : ''}'
              '${r['batch_number'] != null ? ' · ${r['batch_number']}' : ''}'
              '${r['expiration_date'] != null ? ' · exp ${r['expiration_date']}' : ''}',
              style: const TextStyle(color: Colors.black87, fontSize: 12),
            ),
          ),
      ],
    );
  }
}
