import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';
import 'clinic_session_workspace.dart';

/// Clinic — encounters anchor clinic actions (mirrors the web `/clinic`
/// page's Queue / Closed tabs). The bottom-nav Queue tab already covers
/// "today's queue"; this screen covers the full encounter list (open /
/// closed / all), a read-only View dialog (details + vitals + treatments),
/// and the staff-schedules register.
class ClinicScreen extends StatefulWidget {
  const ClinicScreen({super.key});

  @override
  State<ClinicScreen> createState() => _ClinicScreenState();
}

class _ClinicScreenState extends State<ClinicScreen>
    with AutoPolling<ClinicScreen> {
  String? _status; // null=all, open, closed
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _items = [];
  String? _nextCursor;
  bool _loadingMore = false;
  bool _loadedOnce = false;

  @override
  void initState() {
    super.initState();
    _load();
    // Live updates — mirrors the SPA's 30s `refetchInterval`.
    startPolling(const Duration(seconds: 30), _silentRefresh);
  }

  /// Polled live update: silently refetch the first page so new/updated
  /// encounters appear without a manual refresh. Skipped once the user has
  /// paginated deeper (pull-to-refresh there) so the list never collapses.
  Future<void> _silentRefresh() async {
    if (_items.length > 25) return;
    try {
      final page = await ApiService.I.encounters(status: _status);
      if (!mounted) return;
      setState(() {
        _items = page.items;
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (_) {
      // Keep the current list on transient errors.
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final page = await ApiService.I.encounters(status: _status);
      if (!mounted) return;
      setState(() {
        _items = page.items;
        _nextCursor = page.meta?.nextCursor;
        _loadedOnce = true;
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

  Future<void> _loadMore() async {
    if (_loadingMore || _nextCursor == null) return;
    setState(() => _loadingMore = true);
    try {
      final page =
          await ApiService.I.encounters(status: _status, cursor: _nextCursor);
      if (!mounted) return;
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (_) {
      // ignore
    } finally {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  Future<void> _view(Map<String, dynamic> e) async {
    final id = e['id'] as int? ?? 0;
    List<Map<String, dynamic>> vitals = [];
    List<Map<String, dynamic>> treatments = [];
    try {
      final results = await Future.wait([
        ApiService.I.encounterVitals(id),
        ApiService.I.encounterTreatments(id),
      ]);
      vitals = results[0];
      treatments = results[1];
    } catch (_) {
      // vitals/treatments are best-effort in the view
    }
    if (!mounted) return;

    await showSynapseSheet<void>(
      context,
      builder: (ctx) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 14, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SheetHeader(
              title: 'Encounter #$id',
              subtitle: titleCaseOption(e['status'] as String? ?? ''),
            ),
            const SizedBox(height: 12),
            _Detail(
                label: 'Patient',
                value: e['patient_name'] != null
                    ? '${e['patient_name']} (${e['patient_school_id'] ?? '—'})'
                    : '${e['patient_school_id'] ?? '—'}'),
            _Detail(
                label: 'Complaint',
                value: e['chief_complaint'] as String? ?? '—'),
            _Detail(
                label: 'Triage',
                value: titleCaseOption(e['triage_priority'] as String? ?? '—')),
            _Detail(
                label: 'Diagnosis', value: e['diagnosis'] as String? ?? '—'),
            _Detail(
                label: 'Outcome',
                value: titleCaseOption(e['outcome'] as String? ?? '—')),
            _Detail(
                label: 'Started',
                value: fmtUtcToApp(e['started_at'] as String? ?? '')),
            _Detail(
                label: 'Closed',
                value: e['closed_at'] != null
                    ? fmtUtcToApp(e['closed_at'] as String)
                    : '—'),
            if (e['station_id'] != null)
              _Detail(label: 'Station', value: e['station_id'] as String),
            const SizedBox(height: 8),
            const Text('Vitals',
                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            if (vitals.isEmpty)
              const Text('No vitals recorded.',
                  style: TextStyle(color: Colors.black54, fontSize: 12))
            else
              for (final v in vitals.reversed)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2),
                  child: Text(
                    '${fmtUtcToApp(v['recorded_at'] as String? ?? '')} · '
                    '${v['temp_c'] != null ? '${v['temp_c']}°C' : '—temp'} · '
                    'BP ${v['bp_systolic'] ?? '—'}/${v['bp_diastolic'] ?? '—'} · '
                    'Pulse ${v['pulse_bpm'] ?? '—'} · '
                    'SpO₂ ${v['spo2_pct'] ?? '—'}% · '
                    '${v['weight_kg'] != null ? '${v['weight_kg']}kg' : ''}',
                    style: const TextStyle(color: Colors.black54, fontSize: 12),
                  ),
                ),
            const SizedBox(height: 8),
            const Text('Treatments',
                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            if (treatments.isEmpty)
              const Text('No treatments recorded.',
                  style: TextStyle(color: Colors.black54, fontSize: 12))
            else
              for (final t in treatments)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2),
                  child: Text(
                    '${t['treatment_type']} · '
                    '${t['medicine_name'] ?? t['description'] ?? ''}'
                    '${t['quantity_used'] != null ? ' ×${t['quantity_used']}' : ''}'
                    ' · ${fmtUtcToApp(t['administered_at'] as String? ?? '')}',
                    style: const TextStyle(color: Colors.black54, fontSize: 12),
                  ),
                ),
          ],
        ),
      ),
    );
  }

  Future<void> _openWorkspace(Map<String, dynamic> encounter) async {
    final id = encounter['id'] as int? ?? 0;
    if (id <= 0) return;
    await Navigator.of(context).push<void>(
      MaterialPageRoute(
        builder: (_) => ClinicSessionWorkspace(encounterId: id),
      ),
    );
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Clinic'),
        actions: [
          IconButton(
            tooltip: 'Staff schedules',
            icon: const Icon(HugeIcons.strokeRoundedCalendar02),
            onPressed: _showStaffSchedules,
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: SizedBox(
              height: 40,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: _statusOptions.length,
                separatorBuilder: (_, __) => const SizedBox(width: 8),
                itemBuilder: (context, i) {
                  final (value, label) = _statusOptions[i];
                  return FilterChip(
                    label: Text(label),
                    selected: _status == value,
                    onSelected: (_) {
                      setState(() => _status = value);
                      _load();
                    },
                  );
                },
              ),
            ),
          ),
          const SizedBox(height: 4),
          Expanded(
            child: RefreshIndicator(onRefresh: _load, child: _buildBody()),
          ),
        ],
      ),
    );
  }

  static const _statusOptions = <(String?, String)>[
    (null, 'All'),
    ('open', 'Open'),
    ('closed', 'Closed'),
  ];

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) return AsyncState.empty('No encounters in this view.');

    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: _items.length + (_nextCursor != null ? 1 : 0),
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      // Pre-build rows ahead of the viewport so fast flings don't hitch on
      // widget builds — keeps long lists smooth at the 120Hz vsync.
      scrollCacheExtent: const ScrollCacheExtent.pixels(600),
      itemBuilder: (context, i) {
        if (i >= _items.length) {
          // Load-more sentinel — defer so we never setState during build.
          WidgetsBinding.instance.addPostFrameCallback((_) {
            if (mounted) _loadMore();
          });
          return const Padding(
            padding: EdgeInsets.all(8),
            child: Center(
              child: SizedBox(
                width: 22,
                height: 22,
                child: CircularProgressIndicator(strokeWidth: 2),
              ),
            ),
          );
        }
        final e = _items[i];
        return _EncounterTile(
          encounter: e,
          onView: () => _openWorkspace(e),
          onQuickView: () => _view(e),
        );
      },
    );
  }

  /// Staff schedules — list + add/edit shifts (mirrors the web
  /// `StaffSchedulesTab`). Uses the employee roster for the user picker.
  Future<void> _showStaffSchedules() async {
    List<Map<String, dynamic>>? schedules;
    String? error;
    try {
      schedules = await ApiService.I.staffSchedules();
    } catch (e) {
      error = mapDioError(e).message;
    }
    if (!mounted) return;

    final canManage = context.read<AuthController>().session?.hasPermission(
              'clinic.schedules.manage',
            ) ??
        false;

    Future<void> upsert([Map<String, dynamic>? existing]) async {
      List<Map<String, dynamic>> staff = [];
      try {
        final page = await ApiService.I.employees(limit: 100);
        staff = page.items
            .map((p) => {
                  'id': p.id,
                  'name': p.fullName,
                })
            .toList();
      } catch (_) {}
      if (!mounted) return;
      final options = staff.map((s) => '${s['id']} · ${s['name']}').toList();
      final payload = await showCrudForm(
        context,
        title: existing == null ? 'Add staff shift' : 'Edit staff shift',
        fullScreen: true,
        fields: [
          if (options.isNotEmpty)
            CrudField.dropdown(
              'user_id',
              'Staff',
              options,
              initial: existing != null
                  ? '${existing['user_id']} · ${existing['user_name']}'
                  : null,
            ),
          CrudField.dropdown(
            'day_of_week',
            'Day',
            const [
              'Monday',
              'Tuesday',
              'Wednesday',
              'Thursday',
              'Friday',
              'Saturday',
              'Sunday'
            ],
            initial: existing != null
                ? _dowName(existing['day_of_week'] as int? ?? 1)
                : null,
          ),
          CrudField.text('shift_start', 'Start (HH:MM)',
              initial: existing?['shift_start'] as String? ?? '08:00'),
          CrudField.text('shift_end', 'End (HH:MM)',
              initial: existing?['shift_end'] as String? ?? '17:00'),
          CrudField.dropdown(
            'schedule_type',
            'Type',
            const ['regular', 'on_call', 'special'],
            initial: existing?['schedule_type'] as String? ?? 'regular',
          ),
          CrudField.text('effective_from', 'Effective from (YYYY-MM-DD)',
              required: false,
              initial: existing?['effective_from'] as String? ?? ''),
          CrudField.text('effective_to', 'Effective to (YYYY-MM-DD)',
              required: false,
              initial: existing?['effective_to'] as String? ?? ''),
        ],
        submitLabel: existing == null ? 'Add' : 'Save',
      );
      if (payload == null || !mounted) return;
      final body = <String, dynamic>{
        ...payload,
      };
      if (body['user_id'] != null) {
        final raw = body['user_id'] as String? ?? '';
        final idx = options.indexOf(raw);
        if (idx >= 0) {
          body['user_id'] = staff[idx]['id'];
        } else {
          body['user_id'] = int.tryParse(raw.split(' ').first) ?? 0;
        }
      }
      if (body['day_of_week'] != null) {
        body['day_of_week'] = _dowIndex(body['day_of_week'] as String? ?? '');
      }
      final ok = await runCrudAction(
        context,
        () => existing == null
            ? ApiService.I.createStaffSchedule(body)
            : ApiService.I.updateStaffSchedule(existing['id'] as int, body),
        successMessage: existing == null ? 'Shift added.' : 'Shift updated.',
      );
      if (ok && mounted) {
        Navigator.of(context).pop(); // close the schedules sheet
        _showStaffSchedules();
      }
    }

    await showSynapseSheet<void>(
      context,
      builder: (ctx) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 14, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SheetHeader(title: 'Staff schedules'),
            const SizedBox(height: 8),
            if (error != null)
              Text(error,
                  style: TextStyle(
                      color: Theme.of(ctx).colorScheme.error, fontSize: 13))
            else if (schedules == null || schedules.isEmpty)
              const Text('No staff schedules on record.',
                  style: TextStyle(color: Colors.black54))
            else
              Flexible(
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    for (final s in schedules)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        title: Text(
                          '${s['user_name'] ?? '—'} · ${_dowName(s['day_of_week'] as int? ?? 1)}',
                          style: const TextStyle(
                              fontWeight: FontWeight.w600, fontSize: 13),
                        ),
                        subtitle: Text(
                          '${s['shift_start']}–${s['shift_end']} · '
                          '${s['schedule_type']}'
                          '${s['effective_from'] != null ? ' · from ${s['effective_from']}' : ''}',
                          style: const TextStyle(fontSize: 11),
                        ),
                        trailing: canManage
                            ? IconButton(
                                icon: const Icon(HugeIcons.strokeRoundedEdit01,
                                    size: 18),
                                onPressed: () => upsert(s),
                              )
                            : null,
                      ),
                  ],
                ),
              ),
            if (canManage) ...[
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: () => upsert(),
                icon: const Icon(HugeIcons.strokeRoundedAddCircle),
                label: const Text('Add staff shift'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  static const _days = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
  ];

  static String _dowName(int dow) =>
      (dow >= 1 && dow <= 7) ? _days[dow - 1] : 'Day $dow';

  static int _dowIndex(String name) {
    final i = _days.indexOf(name);
    return i >= 0 ? i + 1 : 1;
  }
}

class _EncounterTile extends StatelessWidget {
  const _EncounterTile({
    required this.encounter,
    required this.onView,
    required this.onQuickView,
  });

  final Map<String, dynamic> encounter;
  final VoidCallback onView;
  final VoidCallback onQuickView;

  Color get _statusColor => switch (encounter['status']) {
        'open' => const Color(0xFF1E6FD9),
        'closed' => const Color(0xFF1B7A43),
        'referred' => const Color(0xFF0F766E),
        _ => Colors.grey,
      };

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      margin: EdgeInsets.zero,
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
        onTap: onView,
        title: Text(
          encounter['chief_complaint'] as String? ??
              'Encounter #${encounter['id']}',
          style: const TextStyle(fontWeight: FontWeight.w600),
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${encounter['patient_name'] ?? encounter['patient_school_id'] ?? '—'}'
                ' · ${fmtUtcToApp(encounter['started_at'] as String? ?? '')}',
                style: const TextStyle(color: Colors.black54, fontSize: 12),
              ),
              if (encounter['diagnosis'] != null)
                Text(
                  'Dx: ${encounter['diagnosis']}',
                  style: const TextStyle(color: Colors.black54, fontSize: 12),
                ),
            ],
          ),
        ),
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            StatusBadge(
              label: titleCaseOption(encounter['status'] as String? ?? '—'),
              color: _statusColor,
            ),
            IconButton(
              tooltip: 'Quick record view',
              onPressed: onQuickView,
              icon: const Icon(Icons.info_outline, size: 20),
            ),
          ],
        ),
      ),
    );
  }
}

class _Detail extends StatelessWidget {
  const _Detail({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 110,
            child: Text(
              label,
              style: const TextStyle(color: Colors.black54, fontSize: 13),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }
}
