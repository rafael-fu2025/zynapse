/// DrumDetailSheet — the mobile counterpart of the web's
/// `/facilities/drums/:unitId` page.
///
/// Everything about ONE drum's live batch, in a single scrollable sheet:
/// identity and progress, yield/mass-reduction analytics, the process-log
/// timeline with an inline observation form, categorised loss capture, and
/// the batch's open SPC alerts with a per-alert acknowledge.
///
/// Reached by tapping a drum tile or a "Processing Drums" card. The writes
/// here (log an observation, record a loss, acknowledge an alert) all
/// mirror their web equivalents; state transitions stay in the drum list.
library;

import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../../core/api/api_client.dart';
import '../../core/models/facilities.dart';
import '../../core/services/api_service.dart';
import '../../core/utils/dates.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';
import 'turning.dart';

/// Loss categories accepted by `POST /batches/{id}/losses`. Mirrors
/// `BMG_LOSS_CATEGORIES` on the web.
const _lossCategories = <String>[
  'evaporation',
  'off_gas',
  'sampling',
  'spill',
  'cleaning',
  'mechanical_holdup',
  'other',
];

const _eventTypes = <String>[
  'observation',
  'turning',
  'aeration',
  'moisture_adjustment',
  'other',
];

const _moistureLevels = <String>['low', 'normal', 'high'];

/// Open the drum detail sheet for [unitId].
///
/// Returns true when the sheet reported a change, so the caller can
/// refresh its list on dismissal.
Future<bool?> showDrumDetailSheet(
  BuildContext context, {
  required int unitId,
  required String unitLabel,
}) {
  return showSynapseSheet<bool>(
    context,
    builder: (_) => DrumDetailSheet(unitId: unitId, unitLabel: unitLabel),
  );
}

class DrumDetailSheet extends StatefulWidget {
  const DrumDetailSheet({
    super.key,
    required this.unitId,
    required this.unitLabel,
  });

  final int unitId;
  final String unitLabel;

  @override
  State<DrumDetailSheet> createState() => _DrumDetailSheetState();
}

class _DrumDetailSheetState extends State<DrumDetailSheet> {
  late Future<_DetailData> _future;
  bool _dirty = false;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<_DetailData> _load() async {
    final active = await ApiService.I.facilityActiveBatches();
    final batch = active.forUnit(widget.unitId);
    if (batch == null) {
      return _DetailData(active: active);
    }

    // Analytics, logs and alerts are independent reads — fetch them
    // together so the sheet paints in one round-trip rather than three
    // sequential reflows. A failure in any one degrades that section
    // rather than blanking the whole sheet.
    final results = await Future.wait([
      ApiService.I
          .facilityBatchAnalytics(batch.batchId)
          .then<Object?>((v) => v)
          .catchError((Object e) => e),
      ApiService.I
          .facilityProcessLogs(batch.batchId)
          .then<Object?>((v) => v)
          .catchError((Object e) => e),
      ApiService.I
          .facilityBatchAlerts(batch.batchId)
          .then<Object?>((v) => v)
          .catchError((Object e) => e),
    ]);

    return _DetailData(
      active: active,
      batch: batch,
      analytics: results[0] is Map<String, dynamic>
          ? results[0]! as Map<String, dynamic>
          : null,
      logs: results[1] is List
          ? (results[1]! as List).whereType<Map<String, dynamic>>().toList()
          : null,
      alerts: results[2] is List
          ? (results[2]! as List).whereType<BmgAlert>().toList()
          : null,
    );
  }

  void _refresh() {
    setState(() => _future = _load());
  }

  void _markDirty() => _dirty = true;

  @override
  Widget build(BuildContext context) {
    // Report back whether anything was written, so the caller can
    // refresh its drum list only when the sheet actually changed state.
    return PopScope<Object?>(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) Navigator.of(context).pop(_dirty);
      },
      child: DraggableScrollableSheet(
        initialChildSize: 0.9,
        minChildSize: 0.5,
        maxChildSize: 0.95,
        expand: false,
        builder: (context, scrollController) => FutureBuilder<_DetailData>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return AsyncState.loading();
            }
            if (snap.hasError) {
              return AsyncState.error(
                mapDioError(snap.error!).message,
                onRetry: _refresh,
              );
            }
            final data = snap.data!;
            final batch = data.batch;
            if (batch == null) {
              return Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  SheetHeader(
                    title: widget.unitLabel,
                    subtitle: 'No active batch',
                  ),
                  const Padding(
                    padding: EdgeInsets.fromLTRB(20, 0, 20, 32),
                    child: Text(
                      'This drum is not currently processing — its batch may have been finished or cancelled.',
                      textAlign: TextAlign.center,
                    ),
                  ),
                ],
              );
            }
            return _body(context, scrollController, data, batch);
          },
        ),
      ),
    );
  }

  Widget _body(
    BuildContext context,
    ScrollController controller,
    _DetailData data,
    BmgActiveBatch batch,
  ) {
    return ListView(
      controller: controller,
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
      children: [
        SheetHeader(
          title: '${batch.unitCode} · ${batch.batchCode}',
          subtitle: batch.unitName,
        ),
        _BatchCard(batch: batch, turningDueDays: data.active.turningDueDays),
        const SizedBox(height: 12),
        _AnalyticsCard(analytics: data.analytics),
        const SizedBox(height: 12),
        _AlertsCard(
          alerts: data.alerts,
          onAcknowledge: (alert) => _acknowledge(alert),
        ),
        const SizedBox(height: 12),
        _ProcessLogCard(
          logs: data.logs,
          batchId: batch.batchId,
          onLogged: () {
            _markDirty();
            _refresh();
          },
        ),
        const SizedBox(height: 12),
        _LossCard(
          batchId: batch.batchId,
          onRecorded: () {
            _markDirty();
            _refresh();
          },
        ),
      ],
    );
  }

  Future<void> _acknowledge(BmgAlert alert) async {
    final ok = await runCrudAction(
      context,
      () => ApiService.I.acknowledgeFacilityAlert(alert.id),
      successMessage: 'Alert acknowledged.',
    );
    if (ok) {
      _markDirty();
      _refresh();
    }
  }
}

/// One fetch round's worth of data. Every read is optional so a single
/// failing endpoint degrades one card instead of the whole sheet.
class _DetailData {
  _DetailData({
    required this.active,
    this.batch,
    this.analytics,
    this.logs,
    this.alerts,
  });

  final BmgActiveBatches active;
  final BmgActiveBatch? batch;
  final Map<String, dynamic>? analytics;
  final List<Map<String, dynamic>>? logs;
  final List<BmgAlert>? alerts;
}

class _BatchCard extends StatelessWidget {
  const _BatchCard({required this.batch, required this.turningDueDays});

  final BmgActiveBatch batch;

  /// Turning cadence from the batches/active envelope — null-safe input to
  /// the stale-turning readout.
  final int? turningDueDays;

  @override
  Widget build(BuildContext context) {
    final progress = batch.progressPct.clamp(0, 100) / 100;
    final turning = describeTurning(batch.daysSinceLastTurning, turningDueDays);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _kv('Waste', batch.categoryName ?? '—'),
            _kv('Input', '${batch.inputKg.toStringAsFixed(2)} kg'),
            _kv(
                'Output',
                batch.outputKg == null
                    ? '—'
                    : '${batch.outputKg!.toStringAsFixed(2)} kg'),
            _kv('Days active', '${batch.daysActive}'),
            _kv('Expected', batch.etaLabel),
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Row(
                children: [
                  const SizedBox(
                    width: 96,
                    child: Text('Last turned',
                        style:
                            TextStyle(fontSize: 12, color: Colors.black54)),
                  ),
                  Expanded(
                    child: Text(
                      turning.label,
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color:
                            turning.stale ? turningStaleColor() : null,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 10),
            ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: LinearProgressIndicator(
                value: progress,
                minHeight: 8,
                backgroundColor: Colors.black12,
              ),
            ),
            const SizedBox(height: 4),
            Align(
              alignment: Alignment.centerRight,
              child: Text(
                '${batch.progressPct.round()}% progress',
                style: const TextStyle(fontSize: 11, color: Colors.black54),
              ),
            ),
          ],
        ),
      ),
    );
  }

  static Widget _kv(String k, String v) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(
          children: [
            SizedBox(
              width: 96,
              child: Text(k,
                  style: const TextStyle(fontSize: 12, color: Colors.black54)),
            ),
            Expanded(
              child: Text(v,
                  style: const TextStyle(
                      fontSize: 13, fontWeight: FontWeight.w600)),
            ),
          ],
        ),
      );
}

class _AnalyticsCard extends StatelessWidget {
  const _AnalyticsCard({required this.analytics});

  final Map<String, dynamic>? analytics;

  @override
  Widget build(BuildContext context) {
    if (analytics == null) {
      return const _InfoCard(
          title: 'Analytics', body: 'Analytics unavailable for this batch.');
    }
    final a = analytics!;
    final composition = (a['composition'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Analytics',
                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
            const SizedBox(height: 8),
            _BatchCard._kv('Yield',
                '${a['yield_pct'] ?? '—'}%  (${a['yield_class'] ?? '—'})'),
            _BatchCard._kv(
                'Mass reduction', '${a['mass_reduction_pct'] ?? '—'}%'),
            if (a['expected_yield_pct'] != null)
              _BatchCard._kv('Expected yield', '${a['expected_yield_pct']}%'),
            if (a['expected_days'] != null)
              _BatchCard._kv('Expected days', '${a['expected_days']}'),
            if (composition.isNotEmpty) ...[
              const Divider(height: 20),
              const Text('Waste composition',
                  style: TextStyle(fontSize: 12, color: Colors.black54)),
              const SizedBox(height: 4),
              ...composition.map((c) => _BatchCard._kv(
                    (c['category_name'] ?? '—') as String,
                    '${c['weight_kg']} kg'
                    '${c['ratio_pct'] != null ? ' · ${c['ratio_pct']}%' : ''}'
                    '${c['expected_days'] != null ? ' · ~${c['expected_days']}d' : ''}',
                  )),
            ],
          ],
        ),
      ),
    );
  }
}

class _AlertsCard extends StatelessWidget {
  const _AlertsCard({required this.alerts, required this.onAcknowledge});

  final List<BmgAlert>? alerts;
  final void Function(BmgAlert) onAcknowledge;

  @override
  Widget build(BuildContext context) {
    if (alerts == null) {
      return const _InfoCard(title: 'Alerts', body: 'Alerts unavailable.');
    }
    final open = alerts!.where((a) => a.isOpen).toList();
    if (open.isEmpty) {
      return const _InfoCard(
          title: 'Alerts', body: 'No open alerts on this batch.');
    }
    return Card(
      color: const Color(0xFFFFF8E6),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('${open.length} open alert${open.length == 1 ? '' : 's'}',
                style: const TextStyle(
                    fontWeight: FontWeight.w700,
                    fontSize: 14,
                    color: Color(0xFF8A5A00))),
            const SizedBox(height: 6),
            ...open.map((a) => Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('${a.severity.toUpperCase()} · ${a.code}',
                                style: const TextStyle(
                                    fontSize: 11, fontWeight: FontWeight.w600)),
                            Text(a.message,
                                style: const TextStyle(fontSize: 12)),
                          ],
                        ),
                      ),
                      TextButton(
                        onPressed: () => onAcknowledge(a),
                        style: TextButton.styleFrom(
                            visualDensity: VisualDensity.compact,
                            padding: const EdgeInsets.symmetric(horizontal: 8)),
                        child: const Text('Acknowledge',
                            style: TextStyle(fontSize: 11)),
                      ),
                    ],
                  ),
                )),
          ],
        ),
      ),
    );
  }
}

class _ProcessLogCard extends StatelessWidget {
  const _ProcessLogCard({
    required this.logs,
    required this.batchId,
    required this.onLogged,
  });

  final List<Map<String, dynamic>>? logs;
  final int batchId;
  final VoidCallback onLogged;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Process log',
                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
            const SizedBox(height: 8),
            if (logs == null)
              const Text('Process log unavailable.',
                  style: TextStyle(fontSize: 12, color: Colors.black54))
            else if (logs!.isEmpty)
              const Text('No observations yet.',
                  style: TextStyle(fontSize: 12, color: Colors.black54))
            else
              ...logs!.take(20).map(_logRow),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: OutlinedButton.icon(
                onPressed: () => _addObservation(context),
                icon: const Icon(HugeIcons.strokeRoundedAdd01, size: 16),
                label: const Text('Log observation'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _logRow(Map<String, dynamic> l) {
    final badges = <String>[];
    final event = l['event_type'] as String?;
    if (event != null && event.isNotEmpty && event != 'observation') {
      badges.add(titleCaseOption(event));
    }
    final temp = (l['temperature_celsius'] as num?)?.toDouble();
    if (temp != null) badges.add('$temp°C');
    final moisture = l['moisture_level'] as String?;
    if (moisture != null && moisture.isNotEmpty) {
      badges.add(titleCaseOption(moisture));
    }
    final o2 = (l['oxygen_pct'] as num?)?.toDouble();
    if (o2 != null) badges.add('O₂ $o2%');
    final turns = (l['turns_count'] as num?)?.toInt();
    final duration = (l['duration_seconds'] as num?)?.toInt();
    if (turns != null) {
      // Web badge shape: `⚙ {n} rotations · {seconds}s`.
      badges.add(duration != null
          ? '⚙ $turns rotations · ${duration}s'
          : '⚙ $turns rotations');
    }
    final sessionStarted = l['session_started_at'] as String?;
    if (sessionStarted != null && sessionStarted.isNotEmpty) {
      badges.add('started ${fmtUtcToApp(sessionStarted)}');
    }
    final deviceId = l['device_id'] as String?;
    if (deviceId != null && deviceId.isNotEmpty) {
      badges.add(deviceId);
    }

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('${l['log_date'] ?? ''}',
              style: const TextStyle(fontSize: 10, color: Colors.black45)),
          if (badges.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Text(badges.join(' · '),
                  style: const TextStyle(
                      fontSize: 11, fontWeight: FontWeight.w600)),
            ),
          if ((l['observation_note'] as String?)?.isNotEmpty ?? false)
            Text(l['observation_note'] as String,
                style: const TextStyle(fontSize: 12)),
        ],
      ),
    );
  }

  /// Posts to `/batches/{id}/logs` — the richer surface than the drum
  /// list's "Add update", carrying the O₂ / calibration fields that drive
  /// the OXYGEN_OUT alert.
  Future<void> _addObservation(BuildContext context) async {
    final payload = await showCrudForm(
      context,
      title: 'Log observation',
      fields: [
        const CrudField.dropdown('event_type', 'Event type', _eventTypes,
            initial: 'observation'),
        const CrudField.text('observation_note', 'Note',
            required: false, maxLength: 1000),
        const CrudField.number('temperature_celsius', 'Temperature (°C)',
            required: false),
        const CrudField.dropdown('moisture_level', 'Moisture', _moistureLevels,
            required: false),
        const CrudField.number('oxygen_pct', 'O₂ (%)', required: false),
        const CrudField.dropdown(
            'calibration_status', 'Calibration', ['ok', 'due', 'overdue'],
            required: false),
        const CrudField.text('device_id', 'Device ID',
            required: false, maxLength: 64),
      ],
      submitLabel: 'Log',
    );
    if (payload == null || !context.mounted) return;

    // Drop the empty optionals so the server sees nulls, not ''.
    final body = <String, dynamic>{};
    payload.forEach((k, v) {
      if (v == null) return;
      if (v is String && v.trim().isEmpty) return;
      body[k] = v is String && (k == 'temperature_celsius' || k == 'oxygen_pct')
          ? double.tryParse(v)
          : v;
    });
    if (body['event_type'] == 'observation') {
      // The server defaults to observation; sending it is noise.
      body.remove('event_type');
    }

    final ok = await runCrudAction(
      context,
      () => ApiService.I.addFacilityProcessLog(batchId, body),
      successMessage: 'Observation logged.',
    );
    if (ok) onLogged();
  }
}

class _LossCard extends StatelessWidget {
  const _LossCard({required this.batchId, required this.onRecorded});

  final int batchId;
  final VoidCallback onRecorded;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Record loss',
                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
            const SizedBox(height: 4),
            const Text(
              'Mass that leaves the batch for reasons other than finished output. The total is summed server-side.',
              style: TextStyle(fontSize: 11, color: Colors.black54),
            ),
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: OutlinedButton.icon(
                onPressed: () => _record(context),
                icon: const Icon(HugeIcons.strokeRoundedAlert02, size: 16),
                label: const Text('Record loss'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _record(BuildContext context) async {
    final payload = await showCrudForm(
      context,
      title: 'Record loss',
      fields: [
        const CrudField.dropdown('category_code', 'Category', _lossCategories),
        const CrudField.number('weight_kg', 'Weight (kg)'),
        const CrudField.text('note', 'Note', required: false, maxLength: 255),
      ],
      submitLabel: 'Record',
    );
    if (payload == null || !context.mounted) return;
    final body = <String, dynamic>{
      'category_code': payload['category_code'],
      'weight_kg': double.tryParse('${payload['weight_kg']}') ?? 0,
      if ((payload['note'] as String?)?.isNotEmpty ?? false)
        'note': payload['note'],
    };
    final ok = await runCrudAction(
      context,
      () => ApiService.I.addFacilityBatchLoss(batchId, body),
      successMessage: 'Loss recorded.',
    );
    if (ok) onRecorded();
  }
}

class _InfoCard extends StatelessWidget {
  const _InfoCard({required this.title, required this.body});

  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title,
                style:
                    const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
            const SizedBox(height: 6),
            Text(body,
                style: const TextStyle(fontSize: 12, color: Colors.black54)),
          ],
        ),
      ),
    );
  }
}
