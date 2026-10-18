import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../../core/api/api_client.dart';
import '../../core/models/bmg_automation.dart';
import '../../core/services/api_service.dart';
import '../../core/utils/dates.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Manual waste-in / compost-out ledger for an active batch, including
/// append-only voiding. Mirrors the SPA drum-detail Materials tab.
Future<void> showBatchMaterialsSheet(
  BuildContext context, {
  required int batchId,
  required bool canRecord,
}) {
  return showSynapseSheet<void>(
    context,
    builder: (_) => _MaterialsSheet(
      batchId: batchId,
      canRecord: canRecord,
    ),
  );
}

class _MaterialsSheet extends StatefulWidget {
  const _MaterialsSheet({required this.batchId, required this.canRecord});

  final int batchId;
  final bool canRecord;

  @override
  State<_MaterialsSheet> createState() => _MaterialsSheetState();
}

class _MaterialsSheetState extends State<_MaterialsSheet> {
  List<BmgBatchInput> _inputs = const [];
  List<BmgBatchOutput> _outputs = const [];
  List<Map<String, dynamic>> _categories = const [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _inputs.isEmpty && _outputs.isEmpty;
      _error = null;
    });
    try {
      final results = await Future.wait([
        ApiService.I.facilityBatchInputs(widget.batchId),
        ApiService.I.facilityBatchOutputs(widget.batchId),
        ApiService.I.facilityWasteCategories(),
      ]);
      if (!mounted) return;
      setState(() {
        _inputs = results[0] as List<BmgBatchInput>;
        _outputs = results[1] as List<BmgBatchOutput>;
        _categories = results[2] as List<Map<String, dynamic>>;
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

  Future<void> _addInput() async {
    final options = [
      'None',
      for (final c in _categories) '${c['id']} · ${c['name']}',
    ];
    final payload = await showCrudForm(
      context,
      title: 'Add waste input',
      fields: [
        const CrudField.number('weight_kg', 'Weight (kg)'),
        CrudField.dropdown('category', 'Waste category', options,
            required: false, initial: 'None'),
        const CrudField.text('recorded_at', 'Recorded at (optional)',
            required: false, hint: 'YYYY-MM-DDTHH:MM'),
        const CrudField.text('cn_ratio', 'C:N ratio (optional)',
            required: false),
        const CrudField.text('ph', 'pH (optional)', required: false),
        const CrudField.text('note', 'Note (optional)',
            required: false, maxLength: 255),
      ],
      submitLabel: 'Record input',
    );
    if (payload == null || !mounted) return;
    final body = <String, dynamic>{'weight_kg': payload['weight_kg']};
    final category = payload['category'] as String?;
    if (category != null && category != 'None') {
      body['category_id'] = int.tryParse(category.split(' ').first);
    }
    for (final key in ['recorded_at', 'cn_ratio', 'ph', 'note']) {
      final value = (payload[key] as String?)?.trim();
      if (value != null && value.isNotEmpty) body[key] = value;
    }
    final ok = await runCrudAction(
      context,
      () => ApiService.I.addFacilityBatchInput(widget.batchId, body),
      successMessage: 'Waste input recorded.',
    );
    if (ok) _load();
  }

  Future<void> _addOutput() async {
    final payload = await showCrudForm(
      context,
      title: 'Add compost output',
      fields: const [
        CrudField.number('output_weight_kg', 'Output weight (kg)'),
        CrudField.date('harvest_date', 'Harvest date', required: false),
        CrudField.dropdown(
          'quality_grade',
          'Quality grade',
          ['excellent', 'good', 'fair'],
          required: false,
          initial: 'good',
        ),
        CrudField.text('note', 'Note (optional)',
            required: false, maxLength: 255),
      ],
      submitLabel: 'Record output',
    );
    if (payload == null || !mounted) return;
    final body = <String, dynamic>{
      'output_weight_kg': payload['output_weight_kg'],
    };
    for (final key in ['harvest_date', 'quality_grade', 'note']) {
      final value = (payload[key] as String?)?.trim();
      if (value != null && value.isNotEmpty) body[key] = value;
    }
    final ok = await runCrudAction(
      context,
      () => ApiService.I.addFacilityBatchOutput(widget.batchId, body),
      successMessage: 'Compost output recorded.',
    );
    if (ok) _load();
  }

  Future<void> _voidInput(BmgBatchInput input) async {
    final reason = await _promptVoidReason('Void waste input?');
    if (reason == null) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.voidFacilityBatchInput(
        widget.batchId,
        input.id,
        reason,
      ),
      successMessage: 'Waste input voided.',
    );
    if (ok) _load();
  }

  Future<void> _voidOutput(BmgBatchOutput output) async {
    final reason = await _promptVoidReason('Void compost output?');
    if (reason == null) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.voidFacilityBatchOutput(
        widget.batchId,
        output.id,
        reason,
      ),
      successMessage: 'Compost output voided.',
    );
    if (ok) _load();
  }

  Future<String?> _promptVoidReason(String title) async {
    final payload = await showCrudForm(
      context,
      title: title,
      fields: const [
        CrudField.text('reason', 'Reason'),
      ],
      submitLabel: 'Void',
    );
    final reason = (payload?['reason'] as String?)?.trim();
    return reason == null || reason.isEmpty ? null : reason;
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SheetHeader(
              title: 'Batch materials',
              subtitle: 'Waste in, compost out and voided entries',
            ),
            const SizedBox(height: 10),
            if (_loading)
              SizedBox(height: 200, child: AsyncState.loading())
            else if (_error != null)
              SizedBox(
                height: 200,
                child: AsyncState.error(_error!, onRetry: _load),
              )
            else
              Flexible(
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: _total(
                            'Waste in',
                            _inputs
                                .where((i) => !i.voided)
                                .fold<double>(
                                    0, (sum, i) => sum + i.weightKg),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: _total(
                            'Compost out',
                            _outputs
                                .where((o) => !o.voided)
                                .fold<double>(
                                    0, (sum, o) => sum + o.outputWeightKg),
                          ),
                        ),
                      ],
                    ),
                    if (widget.canRecord) ...[
                      const SizedBox(height: 10),
                      Row(
                        children: [
                          Expanded(
                            child: OutlinedButton.icon(
                              onPressed: _addInput,
                              icon: const Icon(HugeIcons.strokeRoundedAdd01,
                                  size: 17),
                              label: const Text('Add waste in'),
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: OutlinedButton.icon(
                              onPressed: _addOutput,
                              icon: const Icon(HugeIcons.strokeRoundedAdd01,
                                  size: 17),
                              label: const Text('Add compost out'),
                            ),
                          ),
                        ],
                      ),
                    ],
                    const SizedBox(height: 14),
                    _section(
                      'Waste in',
                      _inputs.isEmpty
                          ? const Text('No inputs recorded.',
                              style: TextStyle(
                                  fontSize: 12, color: Colors.black45))
                          : Column(
                              children: [
                                for (final input in _inputs)
                                  ListTile(
                                    contentPadding: EdgeInsets.zero,
                                    dense: true,
                                    title: Text(
                                      '${input.weightKg.toStringAsFixed(2)} kg'
                                      '${input.categoryName != null ? ' · ${input.categoryName}' : ''}',
                                      style: TextStyle(
                                        fontSize: 12.5,
                                        decoration: input.voided
                                            ? TextDecoration.lineThrough
                                            : null,
                                      ),
                                    ),
                                    subtitle: Text(
                                      [
                                        fmtUtcShort(input.recordedAt),
                                        if (input.cnRatio != null)
                                          'C:N ${input.cnRatio}',
                                        if (input.ph != null)
                                          'pH ${input.ph}',
                                        if (input.voided)
                                          'Voided: ${input.voidReason ?? '—'}',
                                      ].join(' · '),
                                      style: const TextStyle(fontSize: 11),
                                    ),
                                    trailing: input.voided
                                        ? const StatusBadge(
                                            label: 'Voided',
                                            color: Colors.black45,
                                          )
                                        : widget.canRecord
                                            ? IconButton(
                                                tooltip: 'Void',
                                                onPressed: () =>
                                                    _voidInput(input),
                                                icon: const Icon(
                                                  HugeIcons
                                                      .strokeRoundedCancelCircle,
                                                  size: 18,
                                                ),
                                              )
                                            : null,
                                  ),
                              ],
                            ),
                    ),
                    const SizedBox(height: 12),
                    _section(
                      'Compost out',
                      _outputs.isEmpty
                          ? const Text('No outputs recorded.',
                              style: TextStyle(
                                  fontSize: 12, color: Colors.black45))
                          : Column(
                              children: [
                                for (final output in _outputs)
                                  ListTile(
                                    contentPadding: EdgeInsets.zero,
                                    dense: true,
                                    title: Text(
                                      '${output.outputWeightKg.toStringAsFixed(2)} kg'
                                      '${output.qualityGrade != null ? ' · ${titleCaseOption(output.qualityGrade!)}' : ''}',
                                      style: TextStyle(
                                        fontSize: 12.5,
                                        decoration: output.voided
                                            ? TextDecoration.lineThrough
                                            : null,
                                      ),
                                    ),
                                    subtitle: Text(
                                      [
                                        if (output.harvestDate != null)
                                          'Harvest ${output.harvestDate}',
                                        fmtUtcShort(output.recordedAt),
                                        if (output.voided)
                                          'Voided: ${output.voidReason ?? '—'}',
                                      ].join(' · '),
                                      style: const TextStyle(fontSize: 11),
                                    ),
                                    trailing: output.voided
                                        ? const StatusBadge(
                                            label: 'Voided',
                                            color: Colors.black45,
                                          )
                                        : widget.canRecord
                                            ? IconButton(
                                                tooltip: 'Void',
                                                onPressed: () =>
                                                    _voidOutput(output),
                                                icon: const Icon(
                                                  HugeIcons
                                                      .strokeRoundedCancelCircle,
                                                  size: 18,
                                                ),
                                              )
                                            : null,
                                  ),
                              ],
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

  Widget _total(String label, double value) {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: const Color(0xFFFAFAF9),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: const Color(0xFFE7E5E4)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label,
              style: const TextStyle(fontSize: 11, color: Colors.black45)),
          const SizedBox(height: 2),
          Text('${value.toStringAsFixed(2)} kg',
              style: const TextStyle(
                  fontSize: 16, fontWeight: FontWeight.w800)),
        ],
      ),
    );
  }

  Widget _section(String title, Widget child) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title,
            style:
                const TextStyle(fontSize: 13, fontWeight: FontWeight.w700)),
        const SizedBox(height: 4),
        child,
      ],
    );
  }
}
