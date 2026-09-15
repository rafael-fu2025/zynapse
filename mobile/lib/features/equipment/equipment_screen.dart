import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/equipment.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Clinic equipment (durable assets) — `GET /clinic/equipment`.
/// Each catalog item owns per-unit rows whose status (working / for
/// repair / for replacement / retired) changes one tap at a time, with
/// every change recorded in the append-only status log.
class EquipmentScreen extends StatefulWidget {
  const EquipmentScreen({super.key});

  @override
  State<EquipmentScreen> createState() => _EquipmentScreenState();
}

class _EquipmentScreenState extends State<EquipmentScreen>
    with AutoPolling<EquipmentScreen> {
  bool _loading = true;
  String? _error;
  List<Equipment> _items = [];
  String? _nextCursor;
  bool _loadingMore = false;
  bool _loadedOnce = false;

  bool get _canWrite =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('clinic.inventory.write') ??
      false;

  @override
  void initState() {
    super.initState();
    _load();
    // Live updates — mirrors the SPA's 60s `refetchInterval` so the
    // status chips stay honest.
    startPolling(const Duration(seconds: 60), _silentRefresh);
  }

  Future<void> _silentRefresh() async {
    if (_items.length > 25) return;
    try {
      final page = await ApiService.I.equipmentItems();
      if (!mounted) return;
      setState(() {
        _items = page.items;
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      if (kDebugMode) debugPrint('EquipmentScreen.poll failed: $e');
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final page = await ApiService.I.equipmentItems();
      setState(() {
        _items = page.items;
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
      final page = await ApiService.I.equipmentItems(cursor: _nextCursor);
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      if (kDebugMode) debugPrint('EquipmentScreen.loadMore failed: $e');
    } finally {
      setState(() => _loadingMore = false);
    }
  }

  Future<void> _create() async {
    final payload = await showCrudForm(
      context,
      title: 'Add equipment',
      fields: const [
        CrudField.text('name', 'Name', hint: 'e.g. BP apparatus'),
        CrudField.text('category', 'Category',
            hint: 'e.g. Diagnostic', required: false),
        CrudField.text('location', 'Location',
            hint: 'e.g. Consultation Room 1', required: false),
        CrudField.text('notes', 'Notes', required: false),
      ],
      submitLabel: 'Add',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.createEquipment(payload),
      successMessage: 'Equipment added.',
    );
    if (ok) _load();
  }

  Future<void> _edit(Equipment item) async {
    final payload = await showCrudForm(
      context,
      title: 'Edit ${item.name}',
      fields: [
        CrudField.text('name', 'Name', initial: item.name),
        CrudField.text('category', 'Category',
            initial: item.category ?? '', required: false),
        CrudField.text('location', 'Location',
            initial: item.location ?? '', required: false),
        CrudField.text('notes', 'Notes',
            initial: item.notes ?? '', required: false),
      ],
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.updateEquipment(item.id, payload),
      successMessage: '${item.name} updated.',
    );
    if (ok) _load();
  }

  Future<void> _archive(Equipment item) async {
    final confirmed = await showCrudConfirm(
      context,
      title: item.archived ? 'Restore equipment?' : 'Archive equipment?',
      message: item.archived
          ? 'Restore "${item.name}"?'
          : 'Archive "${item.name}"? Units and history are kept.',
      confirmLabel: item.archived ? 'Restore' : 'Archive',
      destructive: !item.archived,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.archiveEquipment(item.id, archived: !item.archived),
      successMessage: item.archived ? 'Equipment restored.' : 'Equipment archived.',
    );
    if (ok) _load();
  }

  Future<void> _addUnits(Equipment item) async {
    final payload = await showCrudForm(
      context,
      title: 'Add units — ${item.name}',
      fields: const [
        CrudField.number('quantity', 'Quantity', initial: '1'),
        CrudField.date('acquired_date', 'Acquired date'),
        CrudField.text('note', 'Note', required: false),
      ],
      submitLabel: 'Add units',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.addEquipmentUnits(item.id, payload),
      successMessage: 'Units added.',
    );
    if (ok) _load();
  }

  Future<void> _changeUnitStatus(EquipmentDetail detail, EquipmentUnit unit) async {
    final payload = await showCrudForm(
      context,
      title: 'Unit #${unit.id} — ${detail.equipment.name}',
      fields: [
        CrudField.dropdown(
          'status',
          'Status',
          EquipmentStatus.values.map((s) => s.label).toList(),
          initial: unit.status.label,
        ),
        const CrudField.text('note', 'Note', required: false),
      ],
      submitLabel: 'Update',
    );
    if (payload == null || !mounted) return;
    final label = payload['status'] as String?;
    final status = EquipmentStatus.values
        .firstWhere((s) => s.label == label, orElse: () => unit.status);
    final ok = await runCrudAction(
      context,
      () => ApiService.I.changeEquipmentUnitStatus(unit.id, {
        'status': status.wire,
        if ((payload['note'] as String?)?.isNotEmpty == true)
          'note': payload['note'],
      }),
      successMessage: 'Unit status updated.',
    );
    if (ok) _load();
  }

  /// Units bottom sheet — lazily fetches the detail (units + status log),
  /// the same pattern the medicines screen uses for batch write-offs.
  Future<void> _units(Equipment item) async {
    final detail = await showModalBottomSheet<EquipmentDetail>(
      context: context,
      isScrollControlled: true,
      builder: (context) => DraggableScrollableSheet(
        expand: false,
        builder: (context, scrollController) => FutureBuilder<EquipmentDetail>(
          future: ApiService.I.equipmentDetail(item.id),
          builder: (context, snapshot) {
            if (snapshot.hasError) {
              return Padding(
                padding: const EdgeInsets.all(24),
                child: AsyncState.error(
                  mapDioError(snapshot.error ?? 'Failed to load units').message,
                  onRetry: () => Navigator.of(context).pop(),
                ),
              );
            }
            if (!snapshot.hasData) {
              return const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              );
            }
            final detail = snapshot.data!;
            return ListView(
              controller: scrollController,
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
              children: [
                Text(
                  '${item.name} — ${item.totalUnits} unit(s)',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 4),
                Text(
                  'Every status change is recorded in the unit history.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: 12),
                for (final unit in detail.units) ...[
                  _UnitCard(
                    unit: unit,
                    log: detail.logFor(unit.id),
                    canWrite: _canWrite,
                    onChange: () {
                      Navigator.of(context).pop();
                      _changeUnitStatus(detail, unit);
                    },
                  ),
                  const SizedBox(height: 8),
                ],
                if (detail.units.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 24),
                    child: Text('No units yet. Use Add units on the equipment row.'),
                  ),
              ],
            );
          },
        ),
      ),
    );
    // Status changes reload the list so the chips stay accurate.
    if (detail == null) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Equipment')),
      body: RefreshIndicator(onRefresh: _load, child: _buildBody()),
      floatingActionButton: _canWrite
          ? FloatingActionButton.extended(
              backgroundColor: const Color(0xFF800000),
              foregroundColor: Colors.white,
              onPressed: _create,
              icon: const Icon(HugeIcons.strokeRoundedHospitalBed01),
              label: const Text('Add equipment'),
            )
          : null,
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty('No equipment.');
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 88),
      itemCount: _items.length + (_nextCursor != null ? 1 : 0),
      scrollCacheExtent: const ScrollCacheExtent.pixels(600),
      itemExtent: 92,
      itemBuilder: (context, i) {
        if (i >= _items.length) {
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
        final item = _items[i];
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
            title: Text(item.name),
            subtitle: Text(
              '${item.category ?? '—'} · ${item.location ?? '—'}\n'
              '${_statusLine(item)}',
            ),
            isThreeLine: true,
            trailing: PopupMenuButton<String>(
              icon: const Icon(HugeIcons.strokeRoundedMore),
              onSelected: (v) {
                switch (v) {
                  case 'units':
                    _units(item);
                  case 'add_units':
                    _addUnits(item);
                  case 'edit':
                    _edit(item);
                  case 'archive':
                    _archive(item);
                }
              },
              itemBuilder: (_) => [
                const PopupMenuItem(value: 'units', child: Text('Units')),
                if (_canWrite) ...[
                  const PopupMenuItem(
                      value: 'add_units', child: Text('Add units')),
                  const PopupMenuItem(value: 'edit', child: Text('Edit')),
                  PopupMenuItem(
                    value: 'archive',
                    child: Text(item.archived ? 'Restore' : 'Archive'),
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  /// Compact status mix for the row subtitle, attention statuses first.
  String _statusLine(Equipment item) {
    if (item.totalUnits == 0) return 'No units yet';
    final parts = <String>[
      if (item.forReplacement > 0) '${item.forReplacement} to replace',
      if (item.forRepair > 0) '${item.forRepair} for repair',
      if (item.working > 0) '${item.working} working',
      if (item.retired > 0) '${item.retired} retired',
    ];
    return parts.join(' · ');
  }
}

/// One unit card inside the units sheet: status badge, condition note,
/// and the expandable from→to history from the append-only log.
class _UnitCard extends StatelessWidget {
  const _UnitCard({
    required this.unit,
    required this.log,
    required this.canWrite,
    required this.onChange,
  });

  final EquipmentUnit unit;
  final List<EquipmentStatusLogEntry> log;
  final bool canWrite;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return Card(
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color:
              Theme.of(context).colorScheme.outlineVariant.withValues(alpha: 0.5),
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                StatusBadge(
                  label: unit.status.label,
                  color: switch (unit.status) {
                    EquipmentStatus.working => const Color(0xFF1B7A43),
                    EquipmentStatus.forRepair => const Color(0xFFB26A00),
                    EquipmentStatus.forReplacement => const Color(0xFFB3261E),
                    EquipmentStatus.retired => const Color(0xFF6B7280),
                  },
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    unit.conditionNote ?? 'No condition note',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ),
                if (canWrite)
                  IconButton(
                    icon: const Icon(HugeIcons.strokeRoundedEdit02, size: 18),
                    tooltip: 'Change status',
                    onPressed: onChange,
                  ),
              ],
            ),
            if (log.isNotEmpty)
              Theme(
                data: Theme.of(context)
                    .copyWith(dividerColor: Colors.transparent),
                child: ExpansionTile(
                  tilePadding: EdgeInsets.zero,
                  dense: true,
                  title: Text(
                    'History (${log.length})',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  children: [
                    for (final entry in log)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 4),
                        child: Text(
                          '${entry.fromStatus?.label ?? 'added'} → '
                          '${entry.toStatus.label}'
                          '${entry.note != null ? ' — ${entry.note}' : ''}'
                          '${entry.userEmail != null ? ' (${entry.userEmail})' : ''}',
                          style: Theme.of(context).textTheme.bodySmall,
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
}
