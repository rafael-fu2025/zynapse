import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/facilities.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';
import 'composition_sheet.dart';
import 'drum_detail_sheet.dart';
import 'turning.dart';
import 'waste_categories_screen.dart';

/// Facilities (BMG) — `GET /facilities/units` (paged) with utilization.
class FacilitiesScreen extends StatefulWidget {
  const FacilitiesScreen({super.key});

  @override
  State<FacilitiesScreen> createState() => _FacilitiesScreenState();
}

class _FacilitiesScreenState extends State<FacilitiesScreen>
    with AutoPolling<FacilitiesScreen> {
  bool _loading = true;
  String? _error;
  List<BmgUnit> _items = [];
  String? _nextCursor;
  bool _loadingMore = false;
  bool _loadedOnce = false;
  bool _showArchived = false;

  /// Active batches + turning cadence, joined into the unit tiles for the
  /// "Last turned" readout. Best-effort: a failure here only drops that
  /// row, never the unit list. (The units payload doesn't carry turn
  /// data — same shape split the web grid makes.)
  BmgActiveBatches? _batches;

  @override
  void initState() {
    super.initState();
    _load();
    // Live updates — drum status / batch ETA refreshes on its own so the
    // operator never has to pull-to-refresh to see state changes.
    startPolling(const Duration(seconds: 30), _silentRefresh);
  }

  /// Polled live update: silently refetch the first page so drum status /
  /// progress changes appear without a manual refresh. Skipped once the user
  /// has paginated deeper (pull-to-refresh there) so the list never collapses.
  Future<void> _silentRefresh() async {
    if (_items.length > 25) return;
    try {
      final page =
          await ApiService.I.facilityUnits(includeArchived: _showArchived);
      final batches = await ApiService.I
          .facilityActiveBatches()
          .then<Object?>((v) => v)
          .catchError((Object e) {
        if (kDebugMode) debugPrint('FacilitiesScreen.poll batches failed: $e');
        return null;
      });
      if (!mounted) return;
      setState(() {
        _items = page.items;
        _batches = batches is BmgActiveBatches ? batches : _batches;
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // Keep the current list on transient errors.
      if (kDebugMode) debugPrint('FacilitiesScreen.poll failed: $e');
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final unitsFuture =
          ApiService.I.facilityUnits(includeArchived: _showArchived);
      // Fire together so the turn join adds no extra round-trip latency;
      // a batches failure must not blank the unit list.
      final batchesFuture = ApiService.I
          .facilityActiveBatches()
          .then<Object?>((v) => v)
          .catchError((Object e) {
        if (kDebugMode) debugPrint('FacilitiesScreen.batches failed: $e');
        return null;
      });
      final page = await unitsFuture;
      final batches = await batchesFuture;
      setState(() {
        _items = page.items;
        _batches = batches is BmgActiveBatches ? batches : null;
        _nextCursor = page.meta?.nextCursor;
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

  Future<void> _loadMore() async {
    if (_loadingMore || _nextCursor == null) return;
    setState(() => _loadingMore = true);
    try {
      // The archived filter must carry across pages, or turning it on
      // silently drops archived drums from page 2 onward.
      final page = await ApiService.I
          .facilityUnits(cursor: _nextCursor, includeArchived: _showArchived);
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // ignore
      if (kDebugMode) debugPrint('FacilitiesScreen.loadMore failed: $e');
    } finally {
      setState(() => _loadingMore = false);
    }
  }

  bool get _canManage =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('facilities.units.manage') ??
      false;

  bool get _canTransition =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('facilities.bmg.transition') ??
      false;

  Future<void> _createUnit() async {
    final payload = await showCrudForm(
      context,
      title: 'Add unit',
      fields: const [
        CrudField.text('code', 'Code'),
        CrudField.text('display_name', 'Display name'),
        CrudField.text('location_code', 'Location', required: false),
        CrudField.number('spec_capacity_kg', 'Capacity (kg)', required: false),
      ],
      submitLabel: 'Add',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.createFacilityUnit(payload),
      successMessage: 'Unit created.',
    );
    if (ok) _load();
  }

  /// Open the per-drum detail sheet (batch summary, analytics, process
  /// log, losses, alerts). Refreshes the list only if something was
  /// written from inside it.
  Future<void> _openDrumDetail(BmgUnit u) async {
    final changed = await showDrumDetailSheet(
      context,
      unitId: u.id,
      unitLabel: u.displayName,
    );
    if (changed ?? false) _load();
  }

  Future<void> _startBatch(BmgUnit u) async {
    List<BmgWasteCategory> categories = [];
    try {
      final raw = await ApiService.I.facilityWasteCategories();
      categories = raw.map(BmgWasteCategory.fromJson).toList();
    } catch (e) {
      if (kDebugMode) debugPrint('FacilitiesScreen.startBatch failed: $e');
    }
    if (!mounted) return;
    if (categories.isEmpty) {
      showCrudMessage(context, 'No waste categories available.', error: true);
      return;
    }

    // A segregated mix: one row per waste category with its loaded
    // weight. The weight ratios drive the drum's expected duration, so a
    // single-category form would mis-state the ETA for every mixed load.
    final result = await showCompositionSheet(
      context,
      unitLabel: u.displayName,
      categories: categories,
      capacityKg: u.specCapacityKg,
    );
    if (result == null || !mounted) return;

    final composition = result
        .where((r) => r.containsKey('category_id'))
        .map((r) => <String, dynamic>{
              'category_id': r['category_id'],
              'weight_kg': r['weight_kg'],
            })
        .toList();
    final total = (result.firstWhere((r) => r.containsKey('__total'),
            orElse: () => <String, dynamic>{})['__total'] as double? ??
        0.0);
    if (composition.isEmpty || total <= 0) return;

    final ok = await runCrudAction(
      context,
      () => ApiService.I.startFacilityBatch(u.id, {
        'total_input_weight_kg': total,
        'composition': composition,
      }),
      successMessage: 'Batch started.',
    );
    if (ok) _load();
  }

  /// 4. Cancel a batch — available any time before Finish. Requires a
  /// reason. Batch → Cancelled, drum → Available/Idle (history kept).
  Future<void> _cancelBatch(BmgUnit u) async {
    final batchId = u.activeBatchId;
    if (batchId == null) return;
    final payload = await showCrudForm(
      context,
      title: 'Cancel batch — ${u.code}',
      fields: const [
        CrudField.dropdown('reason_code', 'Reason', [
          'failed',
          'discarded',
          'contaminated',
          'no_longer_needed',
          'other',
        ]),
        CrudField.text('notes', 'Details', required: false, maxLength: 512),
      ],
      submitLabel: 'Cancel',
    );
    if (payload == null || !mounted) return;
    final confirmed = await showCrudConfirm(
      context,
      title: 'Cancel batch?',
      message: 'Cancel ${u.code}? The batch stays in history as cancelled '
          'and the drum returns to Available. This cannot be undone.',
      confirmLabel: 'Cancel',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.transitionFacilityBatch(batchId, 'cancel', body: {
        'reason_code': payload['reason_code'] ?? 'unspecified',
        if ((payload['notes'] as String?)?.isNotEmpty ?? false)
          'notes': payload['notes'],
      }),
      successMessage: 'Batch cancelled.',
    );
    if (ok) _load();
  }

  /// 5. Drum status — toggle Available / Under Maintenance / Archived.
  /// Only allowed when the drum has no active batch.
  Future<void> _drumStatus(BmgUnit u) async {
    if (u.isActive) {
      showCrudMessage(context, 'Finish or cancel the active batch first.',
          error: true);
      return;
    }
    final payload = await showCrudForm(
      context,
      title: 'Drum status — ${u.code}',
      fields: [
        CrudField.dropdown(
          'status',
          'Status',
          switch (u.status) {
            'maintenance' => ['maintenance', 'available', 'archived'],
            _ => ['available', 'maintenance', 'archived'],
          },
          initial: switch (u.status) {
            'idle' => 'available',
            'maintenance' => 'maintenance',
            'archived' => 'archived',
            _ => 'available',
          },
        ),
      ],
      submitLabel: 'Update',
    );
    if (payload == null || !mounted) return;
    final chosen = (payload['status'] as String?) ?? 'available';
    final ok = await runCrudAction(
      context,
      () async {
        switch (chosen) {
          case 'maintenance':
            await ApiService.I.setFacilityUnitMaintenance(u.id, true);
          case 'archived':
            await ApiService.I.archiveFacilityUnit(u.id);
          case 'available':
            await ApiService.I.unarchiveFacilityUnit(u.id);
            await ApiService.I.setFacilityUnitMaintenance(u.id, false);
        }
      },
      successMessage: switch (chosen) {
        'maintenance' => 'Drum marked Under Maintenance.',
        'archived' => 'Drum archived (history kept).',
        _ => 'Drum marked Available.',
      },
    );
    if (ok) _load();
  }

  /// Record mass loss (evaporation / off-gas / sampling / …) on the active
  /// batch — mirrors the web drum-detail "Record loss" form.
  Future<void> _recordLoss(BmgUnit u) async {
    final batchId = u.activeBatchId;
    if (batchId == null) return;
    final payload = await showCrudForm(
      context,
      title: 'Record loss — ${u.displayName}',
      fields: const [
        CrudField.dropdown('category_code', 'Category', [
          'evaporation',
          'off_gas',
          'sampling',
          'spill',
          'cleaning',
          'mechanical_holdup',
          'other',
        ]),
        CrudField.number('weight_kg', 'Weight (kg)'),
        CrudField.text('note', 'Note', required: false, maxLength: 255),
      ],
      submitLabel: 'Record loss',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.addFacilityBatchLoss(batchId, payload),
      successMessage: 'Loss recorded.',
    );
    if (ok) _load();
  }

  Future<void> _acknowledgeAlerts() async {
    List<Map<String, dynamic>> alerts = [];
    try {
      alerts = await ApiService.I.facilityOpenAlerts();
    } catch (e) {
      if (kDebugMode) {
        debugPrint('FacilitiesScreen.acknowledgeAlerts failed: $e');
      }
    }
    if (!mounted) return;
    if (alerts.isEmpty) {
      showCrudMessage(context, 'No open alerts.');
      return;
    }
    final options = alerts.map((a) => '${a['id']} · ${a['message']}').toList();
    final payload = await showCrudForm(
      context,
      title: 'Acknowledge alert',
      fields: [
        CrudField.dropdown('alert', 'Alert', options),
      ],
      submitLabel: 'Acknowledge',
    );
    if (payload == null || !mounted) return;
    final idx = options.indexOf(payload['alert'] as String? ?? '');
    if (idx < 0 || idx >= alerts.length) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.acknowledgeFacilityAlert(alerts[idx]['id'] as int),
      successMessage: 'Alert acknowledged.',
    );
    if (ok) _load();
  }

  /// Unified "Add update" — ONE plain-language action that appends a
  /// `log` observation (temperature / turning / aeration / moisture /
  /// notes) as an immutable ledger entry.
  /// The final output (yield) is recorded at Finish, not here.
  /// Mirrors the web `AddUpdateDialog`.
  Future<void> _addUpdate(BmgUnit u) async {
    final batchId = u.activeBatchId;
    if (batchId == null) return;
    final payload = await showCrudForm(
      context,
      title: 'Add update — ${u.code}',
      fields: const [
        // Log entry only — output (yield) is captured when finishing.
        CrudField.dropdown(
            'event_type',
            'Log type',
            [
              'observation',
              'turning',
              'aeration',
              'moisture_adjustment',
              'other',
            ],
            required: false),
        CrudField.number('temperature_celsius', 'Temperature (°C)',
            required: false),
        CrudField.dropdown(
            'moisture_level',
            'Moisture level',
            [
              'low',
              'normal',
              'high',
            ],
            required: false),
        CrudField.text('observation_note', 'Notes',
            required: false, maxLength: 1000),
      ],
      submitLabel: 'Add update',
    );
    if (payload == null || !mounted) return;

    final eventType = (payload['event_type'] as String?) ?? '';
    final temp = (payload['temperature_celsius'] as num?)?.toDouble();
    final moisture = (payload['moisture_level'] as String?) ?? '';
    final note = (payload['observation_note'] as String?)?.trim() ?? '';

    final body = <String, dynamic>{
      'update_type': 'log',
      if (eventType.isNotEmpty) 'event_type': eventType,
      if (note.isNotEmpty) 'observation_note': note,
      if (temp != null) 'temperature_celsius': temp,
      if (moisture.isNotEmpty) 'moisture_level': moisture,
    };
    if (body.length == 1) {
      showCrudMessage(context,
          'Fill in at least one log detail (type, temperature, moisture, or notes).',
          error: true);
      return;
    }

    final ok = await runCrudAction(
      context,
      () => ApiService.I.addFacilityBatchUpdate(batchId, body),
      successMessage: 'Update added.',
    );
    if (ok) _load();
  }

  /// Combined, append-only "Updates" feed for the active batch.
  Future<void> _showUpdates(BmgUnit u) async {
    final batchId = u.activeBatchId;
    if (batchId == null) return;
    List<BmgBatchUpdate> entries = [];
    String? error;
    try {
      final raw = await ApiService.I.facilityBatchUpdates(batchId);
      entries = raw.map(BmgBatchUpdate.fromJson).toList();
    } catch (e) {
      error = mapDioError(e).message;
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
            SheetHeader(title: 'Updates — ${u.code}'),
            const SizedBox(height: 12),
            if (error != null)
              Text(error,
                  style: TextStyle(
                      color: Theme.of(ctx).colorScheme.error, fontSize: 13))
            else if (entries.isEmpty)
              const Text('No updates recorded yet.',
                  style: TextStyle(color: Colors.black54))
            else
              Flexible(
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    for (final e in entries)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: CircleAvatar(
                          radius: 14,
                          backgroundColor: _updateColor(e.updateType),
                          child: Text(
                            e.typeLabel[0],
                            style: const TextStyle(
                                color: Colors.white, fontSize: 12),
                          ),
                        ),
                        title: Text(
                          '${e.typeLabel} · ${_updateSummary(e)}',
                          style: const TextStyle(
                              fontWeight: FontWeight.w600, fontSize: 13),
                        ),
                        subtitle: Text(
                          '${e.createdAt}'
                          '${e.observationNote != null && e.observationNote!.isNotEmpty ? ' — ${e.observationNote}' : ''}'
                          '${e.curingNote != null && e.curingNote!.isNotEmpty ? ' — ${e.curingNote}' : ''}',
                          style: const TextStyle(fontSize: 11),
                        ),
                      ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  Color _updateColor(String type) => switch (type) {
        'output' => const Color(0xFF2E7D32),
        'curing' => const Color(0xFF5B4BA6),
        _ => const Color(0xFF8A5A00),
      };

  String _updateSummary(BmgBatchUpdate e) {
    if (e.updateType == 'output') {
      return '${e.outputWeightKg ?? 0} kg output';
    }
    if (e.updateType == 'curing') {
      return 'Moved to curing';
    }
    final parts = <String>[
      if (e.eventType != null && e.eventType!.isNotEmpty)
        titleCaseOption(e.eventType!),
      if (e.temperatureCelsius != null) '${e.temperatureCelsius}°C',
      if (e.moistureLevel != null && e.moistureLevel!.isNotEmpty)
        titleCaseOption(e.moistureLevel!),
    ];
    return parts.isNotEmpty ? parts.join(' · ') : 'Observation';
  }

  /// Finish = GRADED RELEASE — requires quality grade + maturity.
  /// A failed/discarded run is NOT finished; route it to Cancel instead.
  Future<void> _finishBatch(BmgUnit u) async {
    final batchId = u.activeBatchId;
    if (batchId == null) return;
    final payload = await showCrudForm(
      context,
      title: 'Finish batch — ${u.code}',
      fields: const [
        // Final output (yield) is recorded at finish.
        CrudField.number('output_weight_kg', 'Output weight (kg)',
            required: false),
        CrudField.dropdown(
            'quality_grade', 'Quality grade', ['excellent', 'good', 'fair']),
        CrudField.dropdown(
            'maturity_level', 'Maturity', ['mature', 'maturing', 'immature']),
      ],
      submitLabel: 'Finish',
    );
    if (payload == null || !mounted) return;
    final confirmed = await showCrudConfirm(
      context,
      title: 'Finish batch?',
      message: 'Finish ${u.code} as a graded release with the final '
          'output? This returns the drum to Available and cannot be '
          'undone. If the run failed, Cancel it instead.',
      confirmLabel: 'Finish',
      destructive: false,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.transitionFacilityBatch(batchId, 'finish', body: {
        'quality_grade': payload['quality_grade'],
        'maturity_level': payload['maturity_level'],
        if (payload['output_weight_kg'] is num &&
            (payload['output_weight_kg'] as num) > 0)
          'output_weight_kg': (payload['output_weight_kg'] as num).toDouble(),
      }),
      successMessage: 'Batch finished (released).',
    );
    if (ok) _load();
  }

  Future<void> _editUnit(BmgUnit u) async {
    final payload = await showCrudForm(
      context,
      title: 'Edit unit — ${u.code}',
      fields: [
        // `code` is the drum's immutable identity — the web tier shows it
        // disabled and never sends it on an update.
        CrudField.text('display_name', 'Display name', initial: u.displayName),
        CrudField.text('location_code', 'Location',
            required: false, initial: u.locationCode ?? ''),
        CrudField.number('spec_capacity_kg', 'Capacity (kg)',
            required: false, initial: u.specCapacityKg?.toString()),
      ],
      submitLabel: 'Save',
    );
    if (payload == null || !mounted) return;
    payload.remove('code');
    final ok = await runCrudAction(
      context,
      () => ApiService.I.updateFacilityUnit(u.id, payload),
      successMessage: 'Unit updated.',
    );
    if (ok) _load();
  }

  Future<void> _archiveUnit(BmgUnit u) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Archive unit?',
      message: 'Archive “${u.displayName}”? It cannot be archived while it '
          'has an active batch.',
      confirmLabel: 'Archive',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.archiveFacilityUnit(u.id),
      successMessage: 'Unit archived.',
    );
    if (ok) _load();
  }

  /// Batch history — terminal + historical batches across all units.
  Future<void> _showBatchHistory() async {
    List<Map<String, dynamic>> batches = [];
    String? error;
    // Terminal states, matching the web history filter. Null = every status.
    String? statusFilter;

    Future<void> reload() async {
      batches = [];
      error = null;
      try {
        batches = await ApiService.I.facilityBatchHistory(status: statusFilter);
      } catch (e) {
        error = mapDioError(e).message;
      }
    }

    await reload();
    if (!mounted) return;

    Future<void> pickFilter() async {
      final res = await showCrudForm(
        context,
        title: 'Filter history',
        fields: [
          CrudField.dropdown(
            'status',
            'Status',
            const ['all', 'released', 'idle', 'cancelled'],
            initial: statusFilter ?? 'all',
          ),
        ],
        submitLabel: 'Apply',
      );
      if (res == null || !mounted) return;
      final chosen = res['status'] as String?;
      statusFilter = (chosen == null || chosen == 'all') ? null : chosen;
      await reload();
    }

    await showSynapseSheet<void>(
      context,
      builder: (ctx) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 14, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SheetHeader(
              title: 'Batch history',
              subtitle: statusFilter == null
                  ? 'Every finished, cancelled and released batch.'
                  : 'Filtered to ${titleCaseOption(statusFilter!)} batches.',
            ),
            const SizedBox(height: 4),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: pickFilter,
                icon: const Icon(HugeIcons.strokeRoundedFilter, size: 16),
                label: Text(statusFilter == null
                    ? 'Filter by status'
                    : 'Status: ${titleCaseOption(statusFilter!)}'),
              ),
            ),
            const SizedBox(height: 4),
            if (error != null)
              // `error` is reassigned inside `reload`, so Dart cannot
              // promote it across this null check.
              Text(error!,
                  style: TextStyle(
                      color: Theme.of(ctx).colorScheme.error, fontSize: 13))
            else if (batches.isEmpty)
              const Text('No batches on record.',
                  style: TextStyle(color: Colors.black54))
            else
              Flexible(
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    for (final b in batches)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        title: Text(
                          '${b['reference_code']} · ${titleCaseOption('${b['status'] ?? ''}')}',
                          style: const TextStyle(
                              fontWeight: FontWeight.w600, fontSize: 13),
                        ),
                        subtitle: Text(
                          '${b['unit_code']} · ${b['category_name'] ?? '—'} · '
                          'in ${b['total_input_weight_kg']} kg'
                          '${b['output_weight_kg'] != null ? ' → out ${b['output_weight_kg']} kg' : ''}'
                          '${b['quality_grade'] != null ? ' · QA ${titleCaseOption('${b['quality_grade']}')}' : ''}',
                          style: const TextStyle(fontSize: 11),
                        ),
                        isThreeLine: true,
                      ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// Certificate / compliance for the active batch (PFRP + mass balance).
  Future<void> _showCompliance(BmgUnit u) async {
    final batchId = u.activeBatchId;
    if (batchId == null) return;
    Map<String, dynamic>? c;
    String? error;
    try {
      c = await ApiService.I.facilityBatchCompliance(batchId);
    } catch (e) {
      error = mapDioError(e).message;
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
                title: 'Certificate — ${u.code}',
                subtitle: 'PFRP evidence + mass balance'),
            const SizedBox(height: 12),
            if (error != null)
              Text(error,
                  style: TextStyle(
                      color: Theme.of(ctx).colorScheme.error, fontSize: 13))
            else if (c != null) ...[
              _ComplianceRow(
                  label: 'Batch', value: c['reference_code'] as String? ?? '—'),
              _ComplianceRow(
                  label: 'Status',
                  value: titleCaseOption(c['status'] as String? ?? '—')),
              _ComplianceRow(
                  label: 'Thermophilic days',
                  value: '${c['thermophilic_days'] ?? 0}'),
              _ComplianceRow(
                  label: 'Max temp',
                  value: c['max_temperature_c'] != null
                      ? '${c['max_temperature_c']} °C'
                      : '—'),
              _ComplianceRow(
                  label: 'Consecutive PFRP days',
                  value: '${c['consecutive_pfrp_days'] ?? 0}'),
              _ComplianceRow(
                  label: 'PFRP met',
                  value: (c['pfrp_met'] == true) ? 'Yes' : 'No'),
              _ComplianceRow(
                  label: 'Input / Output / Loss',
                  value: '${c['input_kg'] ?? '—'} / '
                      '${c['output_kg'] ?? '—'} / '
                      '${c['loss_kg'] ?? '—'} kg'),
              _ComplianceRow(
                  label: 'Unaccounted',
                  value: '${c['unaccounted_kg'] ?? '—'} kg'),
              _ComplianceRow(
                  label: 'Yield',
                  value: c['yield_pct'] != null ? '${c['yield_pct']}%' : '—'),
              if (c['quality_grade'] != null)
                _ComplianceRow(
                    label: 'QA grade',
                    value:
                        titleCaseOption(c['quality_grade'] as String? ?? '—')),
              if (c['maturity_level'] != null)
                _ComplianceRow(
                    label: 'Maturity',
                    value:
                        titleCaseOption(c['maturity_level'] as String? ?? '—')),
            ],
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Facilities'),
        actions: [
          PopupMenuButton<String>(
            icon: const Icon(HugeIcons.strokeRoundedMore),
            onSelected: (v) {
              switch (v) {
                case 'archive_toggle':
                  setState(() {
                    _showArchived = !_showArchived;
                    _items = [];
                    _nextCursor = null;
                  });
                  _load();
                case 'batch_history':
                  _showBatchHistory();
                case 'waste_categories':
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => const WasteCategoriesScreen(),
                    ),
                  );
              }
            },
            itemBuilder: (_) => [
              CheckedPopupMenuItem(
                value: 'archive_toggle',
                checked: _showArchived,
                child: const Text('Show archived'),
              ),
              const PopupMenuItem(
                  value: 'batch_history', child: Text('Batch history')),
              const PopupMenuItem(
                  value: 'waste_categories', child: Text('Waste categories')),
            ],
          ),
        ],
      ),
      body: RefreshIndicator(onRefresh: _load, child: _buildBody()),
      floatingActionButton: _canManage
          ? FloatingActionButton.extended(
              backgroundColor: const Color(0xFF800000),
              foregroundColor: Colors.white,
              onPressed: _createUnit,
              icon: const Icon(HugeIcons.strokeRoundedFactory01),
              label: const Text('Add unit'),
            )
          : null,
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) return AsyncState.empty('No BMG units.');

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
        return _UnitTile(
          unit: _items[i],
          canManage: _canManage,
          canTransition: _canTransition,
          daysSinceLastTurning:
              _batches?.forUnit(_items[i].id)?.daysSinceLastTurning,
          turningDueDays: _batches?.turningDueDays,
          onOpenDetail: () => _openDrumDetail(_items[i]),
          onStart: () => _startBatch(_items[i]),
          onUpdate: () => _addUpdate(_items[i]),
          onFinish: () => _finishBatch(_items[i]),
          onCancel: () => _cancelBatch(_items[i]),
          onUpdates: () => _showUpdates(_items[i]),
          onStatus: () => _drumStatus(_items[i]),
          onHistory: _showBatchHistory,
          onAlerts: _acknowledgeAlerts,
          onEdit: _canManage ? () => _editUnit(_items[i]) : null,
          onArchive: _canManage ? () => _archiveUnit(_items[i]) : null,
          onCompliance: () => _showCompliance(_items[i]),
          onLoss: () => _recordLoss(_items[i]),
        );
      },
    );
  }
}

Color _unitStatusColor(String status) => switch (status) {
      'idle' => const Color(0xFF1B7A43),
      'processing' => const Color(0xFF1E6FD9),
      'awaiting_output' => const Color(0xFF8A5A00),
      'maintenance' => const Color(0xFFB3261E),
      'cancelled' => Colors.grey,
      _ => Colors.grey,
    };

class _UnitTile extends StatelessWidget {
  const _UnitTile({
    required this.unit,
    required this.canManage,
    required this.canTransition,
    this.daysSinceLastTurning,
    this.turningDueDays,
    this.onOpenDetail,
    this.onStart,
    this.onUpdate,
    this.onFinish,
    this.onCancel,
    this.onUpdates,
    this.onStatus,
    this.onHistory,
    this.onAlerts,
    this.onEdit,
    this.onArchive,
    this.onCompliance,
    this.onLoss,
  });

  final BmgUnit unit;
  final bool canManage;
  final bool canTransition;

  /// Turn-session join from the batches/active payload — null when the
  /// drum has no active batch or the envelope failed to load.
  final int? daysSinceLastTurning;
  final int? turningDueDays;
  final VoidCallback? onOpenDetail;
  final VoidCallback? onStart;
  final VoidCallback? onUpdate;
  final VoidCallback? onFinish;
  final VoidCallback? onCancel;
  final VoidCallback? onUpdates;
  final VoidCallback? onStatus;
  final VoidCallback? onHistory;
  final VoidCallback? onAlerts;
  final VoidCallback? onEdit;
  final VoidCallback? onArchive;
  final VoidCallback? onCompliance;
  final VoidCallback? onLoss;

  /// "Last turned" line under the ETA — mirrors the web drum card's
  /// FactRow, amber when the turning cadence is blown.
  Widget _turningRow() {
    final turning = describeTurning(daysSinceLastTurning, turningDueDays);
    return Padding(
      padding: const EdgeInsets.only(top: 2),
      child: Text(
        turning.label,
        style: TextStyle(
          fontSize: 11,
          color: turning.stale ? turningStaleColor() : Colors.black54,
        ),
      ),
    );
  }

  /// The operator's most frequent action, surfaced as a visible button
  /// instead of being buried in the overflow menu: "Start batch" when the
  /// drum is idle, "Add update" while a batch is active.
  Widget _primaryActionButton() {
    final style = FilledButton.styleFrom(
      backgroundColor: const Color(0xFF800000),
      foregroundColor: Colors.white,
      padding: const EdgeInsets.symmetric(horizontal: 12),
      minimumSize: const Size(0, 32),
      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
      textStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
    );
    if (unit.status == 'idle' && canTransition) {
      return FilledButton(
        style: style,
        onPressed: onStart,
        child: const Text('Start batch'),
      );
    }
    if (unit.isActive) {
      return FilledButton(
        style: style,
        onPressed: onUpdate,
        child: const Text('Add update'),
      );
    }
    return const SizedBox.shrink();
  }

  @override
  Widget build(BuildContext context) {
    final utilization = unit.utilizationPct ?? 0;
    return Card(
      // Tapping the card opens the drum's detail sheet — the mobile
      // counterpart of the web's `/facilities/drums/:unitId` page. The
      // overflow menu's own taps are absorbed by the popup, so the two
      // don't fight.
      clipBehavior: Clip.antiAlias,
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
      child: InkWell(
        onTap: onOpenDetail,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      unit.displayName,
                      style: const TextStyle(
                        fontWeight: FontWeight.w700,
                        fontSize: 15,
                      ),
                    ),
                  ),
                  StatusBadge(
                    label: titleCaseOption(unit.status),
                    color: _unitStatusColor(unit.status),
                  ),
                  // The overflow renders for EVERYONE — a read-only
                  // operator still needs the read-only items (history,
                  // certificate, updates). Permissions gate each ITEM, not
                  // the menu, so hiding the menu also hides the things the
                  // viewer is actually allowed to do.
                  const SizedBox(width: 4),
                  if (canManage || canTransition) _primaryActionButton(),
                  PopupMenuButton<String>(
                    icon: const Icon(HugeIcons.strokeRoundedMore),
                    onSelected: (v) {
                      switch (v) {
                        case 'start':
                          onStart?.call();
                        case 'update':
                          onUpdate?.call();
                        case 'finish':
                          onFinish?.call();
                        case 'cancel':
                          onCancel?.call();
                        case 'updates':
                          onUpdates?.call();
                        case 'status':
                          onStatus?.call();
                        case 'history':
                          onHistory?.call();
                        case 'compliance':
                          onCompliance?.call();
                        case 'alerts':
                          onAlerts?.call();
                        case 'edit':
                          onEdit?.call();
                        case 'archive':
                          onArchive?.call();
                        case 'loss':
                          onLoss?.call();
                      }
                    },
                    itemBuilder: (_) => [
                      // 1. Start a batch (drum must be Available/Idle).
                      if (canTransition && unit.status == 'idle')
                        const PopupMenuItem(
                            value: 'start', child: Text('Start batch')),
                      // 2. Add update (active batch) — appends an immutable log entry.
                      if (canTransition && unit.isActive)
                        const PopupMenuItem(
                            value: 'update', child: Text('Add update')),
                      // 6a. Updates feed (active batch) — read-only.
                      if (unit.isActive)
                        const PopupMenuItem(
                            value: 'updates', child: Text('View updates')),
                      // 6b. View history (read-only, any state).
                      const PopupMenuItem(
                          value: 'history', child: Text('View history')),
                      // 3. Finish = graded release (active batch).
                      if (canTransition && unit.isActive)
                        const PopupMenuItem(
                            value: 'finish', child: Text('Finish batch')),
                      // 4. Cancel a batch (before Finish).
                      if (canTransition && unit.isActive)
                        const PopupMenuItem(
                            value: 'cancel', child: Text('Cancel batch')),
                      // 5. Drum status toggle (no active batch only).
                      if (canTransition && !unit.isActive)
                        const PopupMenuItem(
                            value: 'status', child: Text('Drum status')),
                      if (unit.isActive)
                        const PopupMenuItem(
                            value: 'compliance',
                            child: Text('Certificate / compliance')),
                      if (canTransition && unit.isActive)
                        const PopupMenuItem(
                            value: 'loss', child: Text('Record loss')),
                      if (canManage) ...[
                        const PopupMenuItem(
                            value: 'edit', child: Text('Edit unit')),
                        const PopupMenuItem(
                            value: 'archive', child: Text('Archive')),
                      ],
                      // Acknowledge is a WRITE (facilities.bmg.logs.record).
                      if (canTransition)
                        const PopupMenuItem(
                            value: 'alerts', child: Text('Acknowledge alert')),
                    ],
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                '${unit.code}'
                '${unit.locationCode != null && unit.locationCode!.isNotEmpty ? ' · ${unit.locationCode}' : ''}'
                '${unit.defaultCategoryName != null ? ' · ${unit.defaultCategoryName}' : ''}',
                style: const TextStyle(color: Colors.black54, fontSize: 12),
              ),
              if (unit.isActive) ...[
                // Progress toward expected completion (In Use indicator).
                const SizedBox(height: 10),
                ClipRRect(
                  borderRadius: BorderRadius.circular(4),
                  child: LinearProgressIndicator(
                    value: ((unit.activeBatchProgressPct ?? 0) / 100)
                        .clamp(0.0, 1.0),
                    minHeight: 6,
                    backgroundColor:
                        Theme.of(context).colorScheme.surfaceContainerHighest,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  '${unit.activeBatchProgressPct ?? 0}% toward expected completion'
                  '${unit.activeBatchExpectedCompletionDate != null ? ' · ETA ${unit.activeBatchExpectedCompletionDate!.substring(0, 10)}' : ''}',
                  style: const TextStyle(fontSize: 11, color: Colors.black54),
                ),
                _turningRow(),
              ],
              if (unit.isActive && unit.specCapacityKg != null) ...[
                const SizedBox(height: 6),
                Text(
                  'Drum $utilization% full '
                  '(${unit.activeBatchWeightKg?.toStringAsFixed(0) ?? '?'}/${unit.specCapacityKg!.toStringAsFixed(0)} kg)',
                  style: const TextStyle(fontSize: 11, color: Colors.black54),
                ),
              ],
              if (onOpenDetail != null) ...[
                const SizedBox(height: 8),
                const Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    Text('View details',
                        style:
                            TextStyle(fontSize: 11, color: Color(0xFF800000))),
                    Icon(HugeIcons.strokeRoundedArrowRight01,
                        size: 13, color: Color(0xFF800000)),
                  ],
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// A single label/value line in the compliance certificate sheet.
class _ComplianceRow extends StatelessWidget {
  const _ComplianceRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 150,
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
