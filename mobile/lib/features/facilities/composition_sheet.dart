/// CompositionSheet — the mobile equivalent of the web's Start-batch
/// dialog: load a drum with a SEGREGATED waste mix.
///
/// The drum's ETA is the weight-weighted blend of each component's
/// reference duration, so a single-category load and a mixed load behave
/// very differently downstream. The drum list's simple form could only
/// express the former, which made mixed loads impossible on mobile.
///
/// Returns the `composition` list on completion, or null if cancelled.
library;

import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../../core/models/facilities.dart';
import '../common/widgets.dart';

Future<List<Map<String, dynamic>>?> showCompositionSheet(
  BuildContext context, {
  required String unitLabel,
  required List<BmgWasteCategory> categories,
  double? capacityKg,
}) {
  return showSynapseSheet<List<Map<String, dynamic>>>(
    context,
    builder: (_) => CompositionSheet(
      unitLabel: unitLabel,
      categories: categories,
      capacityKg: capacityKg,
    ),
  );
}

class CompositionSheet extends StatefulWidget {
  const CompositionSheet({
    super.key,
    required this.unitLabel,
    required this.categories,
    this.capacityKg,
  });

  final String unitLabel;
  final List<BmgWasteCategory> categories;
  final double? capacityKg;

  @override
  State<CompositionSheet> createState() => _CompositionSheetState();
}

class _Row {
  _Row({int? categoryId}) : categoryId = categoryId ?? 0;

  int categoryId;
  double weight = 0;
}

class _CompositionSheetState extends State<CompositionSheet> {
  final _controllers = <int, TextEditingController>{};
  late final List<_Row> _rows;
  String? _error;

  @override
  void initState() {
    super.initState();
    _rows = [_Row(categoryId: widget.categories.first.id)];
  }

  @override
  void dispose() {
    for (final c in _controllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  double get _total => _rows.fold(0, (sum, r) => sum + r.weight);

  bool get _overCapacity {
    final cap = widget.capacityKg;
    return cap != null && cap > 0 && _total > cap;
  }

  /// Percentage of this component's share, shown live so the operator can
  /// see the mix they are building.
  double _ratio(_Row r) =>
      _total > 0 && r.weight > 0 ? (r.weight / _total) * 100 : 0;

  void _addRow() {
    final used = _rows.map((r) => r.categoryId).toSet();
    final next = widget.categories.firstWhere(
      (c) => !used.contains(c.id),
      orElse: () => widget.categories.first,
    );
    setState(() {
      _rows.add(_Row(categoryId: next.id));
      _error = null;
    });
  }

  void _removeRow(int i) {
    if (_rows.length <= 1) return;
    setState(() {
      final c = _controllers.remove(i);
      _renumberControllers();
      c?.dispose();
      _rows.removeAt(i);
      _error = null;
    });
  }

  void _renumberControllers() {
    // Controllers are keyed by row index; removing a row shifts every
    // later key, so the values are re-homed before the row disappears.
    final entries = _controllers.entries.toList()
      ..sort((a, b) => a.key.compareTo(b.key));
    _controllers.clear();
    for (var i = 0; i < entries.length; i++) {
      _controllers[i] = entries[i].value;
    }
  }

  void _submit() {
    final filled = _rows
        .where((r) => r.categoryId > 0 && r.weight > 0)
        .map((r) => <String, dynamic>{
              'category_id': r.categoryId,
              'weight_kg': double.parse(r.weight.toStringAsFixed(2)),
            })
        .toList();

    if (filled.isEmpty) {
      setState(() => _error = 'Add at least one component with a weight.');
      return;
    }
    if (_overCapacity) {
      setState(() => _error =
          'Total input weight (${_total.toStringAsFixed(2)} kg) exceeds this drum\'s capacity (${widget.capacityKg} kg).');
      return;
    }

    final total =
        filled.fold<double>(0, (sum, c) => sum + (c['weight_kg'] as double));
    Navigator.of(context).pop([
      ...filled,
      // The backend re-checks the sum against `total_input_weight_kg`
      // with a ±0.01 kg tolerance, so round the two consistently.
      {'__total': double.parse(total.toStringAsFixed(2))},
    ]);
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding:
          EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SheetHeader(
              title: 'Load ${widget.unitLabel}',
              subtitle: 'Record the waste mix by specific category. The weight '
                  'ratios drive this drum’s expected composting duration.',
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              child: Column(
                children: [
                  for (var i = 0; i < _rows.length; i++) _rowEditor(i),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(
                      onPressed: _addRow,
                      icon: const Icon(HugeIcons.strokeRoundedAdd01, size: 16),
                      label: const Text('Add component'),
                    ),
                  ),
                  const SizedBox(height: 4),
                  _totalBar(),
                  if (_error != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: Text(_error!,
                          style:
                              const TextStyle(color: Colors.red, fontSize: 12)),
                    ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  TextButton(
                    onPressed: () => Navigator.of(context).pop(),
                    child: const Text('Cancel'),
                  ),
                  const SizedBox(width: 8),
                  FilledButton(
                    onPressed: _submit,
                    style: FilledButton.styleFrom(
                        backgroundColor: const Color(0xFF800000)),
                    child: const Text('Start batch'),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _rowEditor(int i) {
    final row = _rows[i];
    final controller = _controllers.putIfAbsent(
      i,
      () => TextEditingController(
          text: row.weight > 0 ? row.weight.toString() : ''),
    );
    final ratio = _ratio(row);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: DropdownButtonFormField<int>(
              initialValue: row.categoryId,
              isExpanded: true,
              decoration: const InputDecoration(
                labelText: 'Waste category',
                border: OutlineInputBorder(),
                isDense: true,
              ),
              items: widget.categories
                  .map((c) => DropdownMenuItem(
                        value: c.id,
                        child: Text(c.name, overflow: TextOverflow.ellipsis),
                      ))
                  .toList(),
              onChanged: (v) => setState(() {
                row.categoryId = v ?? row.categoryId;
                _error = null;
              }),
            ),
          ),
          const SizedBox(width: 8),
          SizedBox(
            width: 96,
            child: TextField(
              controller: controller,
              keyboardType:
                  const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(
                labelText: 'Weight (kg)',
                border: OutlineInputBorder(),
                isDense: true,
              ),
              onChanged: (v) => setState(() {
                row.weight = double.tryParse(v) ?? 0;
                _error = null;
              }),
            ),
          ),
          SizedBox(
            width: 46,
            child: Text(
              ratio > 0 ? '${ratio.round()}%' : '—',
              textAlign: TextAlign.right,
              style: const TextStyle(fontSize: 11, color: Colors.black54),
            ),
          ),
          IconButton(
            onPressed: _rows.length <= 1 ? null : () => _removeRow(i),
            icon: const Icon(HugeIcons.strokeRoundedCancel01, size: 16),
            tooltip: 'Remove component',
          ),
        ],
      ),
    );
  }

  Widget _totalBar() {
    final cap = widget.capacityKg;
    final over = _overCapacity;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: over ? Colors.red : Colors.black26),
      ),
      child: Row(
        children: [
          const Expanded(
            child: Text('Total input weight',
                style: TextStyle(fontSize: 12, color: Colors.black54)),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                '${_total.toStringAsFixed(2)} kg',
                style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                  color: over ? Colors.red : Colors.black87,
                ),
              ),
              if (cap != null && cap > 0)
                Text(
                  'Drum capacity: $cap kg',
                  style: TextStyle(
                      fontSize: 10, color: over ? Colors.red : Colors.black45),
                ),
            ],
          ),
        ],
      ),
    );
  }
}
