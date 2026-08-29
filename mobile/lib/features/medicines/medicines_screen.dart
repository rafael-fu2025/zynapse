import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/medicine.dart';
import '../../core/services/api_service.dart';
import '../../core/data/taxonomy.dart';
import '../../core/services/auth_controller.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/stock_transactions_sheet.dart';
import '../common/widgets.dart';

/// Medicines catalogue — `GET /clinic/medicines` (paged) with stock-status
/// and expiry chips (from `stock_status` / `earliest_expiry` on each row).
/// Role-gated CRUD mirrors the web SPA (`clinic.inventory.write`).
class MedicinesScreen extends StatefulWidget {
  const MedicinesScreen({super.key});

  @override
  State<MedicinesScreen> createState() => _MedicinesScreenState();
}

class _MedicinesScreenState extends State<MedicinesScreen>
    with AutoPolling<MedicinesScreen> {
  bool _loading = true;
  String? _error;
  List<Medicine> _items = [];
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
    // Live updates — mirrors the SPA's 30s `refetchInterval`.
    startPolling(const Duration(seconds: 30), _silentRefresh);
  }

  /// Polled live update: silently refetch the first page so new/updated
  /// medicines appear without a manual refresh. Skipped once the user has
  /// paginated deeper (pull-to-refresh there) so the list never collapses.
  Future<void> _silentRefresh() async {
    if (_items.length > 25) return;
    try {
      final page = await ApiService.I.medicines();
      if (!mounted) return;
      setState(() {
        _items = page.items;
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // Keep the current list on transient errors.
      if (kDebugMode) debugPrint('MedicinesScreen.poll failed: $e');
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final page = await ApiService.I.medicines();
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
      final page = await ApiService.I.medicines(cursor: _nextCursor);
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // ignore
      if (kDebugMode) debugPrint('MedicinesScreen.loadMore failed: $e');
    } finally {
      setState(() => _loadingMore = false);
    }
  }

  Future<void> _create() async {
    final payload = await showCrudForm(
      context,
      title: 'Add medicine',
      fullScreen: true,
      fields: [
        const CrudField.text('generic_name', 'Generic name'),
        const CrudField.text('brand_name', 'Brand name', required: false),
        const CrudField.picker('category', 'Category', medicineCategories,
            required: false),
        const CrudField.picker(
            'dosage_form', 'Dosage form', medicineDosageForms,
            required: false),
        const CrudField.text('dosage_strength', 'Dosage strength',
            required: false),
        const CrudField.picker('unit', 'Unit', medicineUnits, initial: 'pc'),
        const CrudField.number('reorder_threshold', 'Reorder threshold',
            initial: '10'),
        const CrudField.number('target_stock', 'Target stock', initial: '20'),
        const CrudField.text('description', 'Description',
            required: false, maxLength: 2000),
      ],
      submitLabel: 'Add',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.createMedicine(payload),
      successMessage: 'Medicine added.',
    );
    if (ok) _load();
  }

  Future<void> _edit(Medicine m) async {
    final payload = await showCrudForm(
      context,
      title: 'Edit ${m.displayName}',
      fields: [
        CrudField.number('reorder_threshold', 'Reorder threshold',
            initial: '${m.reorderThreshold}'),
        CrudField.number('target_stock', 'Target stock',
            initial: '${m.targetStock ?? m.reorderThreshold * 2}'),
      ],
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.updateMedicine(m.id, payload),
      successMessage: '${m.displayName} updated.',
    );
    if (ok) _load();
  }

  Future<void> _addBatch(Medicine m) async {
    final payload = await showCrudForm(
      context,
      title: 'Add batch — ${m.displayName}',
      fullScreen: true,
      fields: [
        const CrudField.text('batch_number', 'Batch number'),
        const CrudField.date('expiration_date', 'Expiration date'),
        const CrudField.text('supplier', 'Supplier', required: false),
        const CrudField.number('quantity', 'Quantity received'),
        const CrudField.text('shortage_note', 'Shortage note', required: false),
      ],
      submitLabel: 'Receive',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.addMedicineBatch(m.id, payload),
      successMessage: 'Batch received — on hand updated.',
    );
    if (ok) _load();
  }

  Future<void> _dispense(Medicine m) async {
    List<Map<String, dynamic>> encounters = [];
    try {
      encounters = await ApiService.I.openEncounters();
    } catch (e) {
      // fall through with empty list
      if (kDebugMode) debugPrint('MedicinesScreen.dispense failed: $e');
    }
    if (!mounted) return;
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetContext) => _DispenseSheet(
        medicine: m,
        encounters: encounters,
      ),
    );
    if (ok == true) _load();
  }

  Future<void> _archive(Medicine m) async {
    final confirmed = await showCrudConfirm(
      context,
      title: m.archived ? 'Restore medicine?' : 'Archive medicine?',
      message: m.archived
          ? 'Restore "${m.displayName}" to the catalogue?'
          : 'Archive "${m.displayName}"? It will be hidden from the catalogue.',
      confirmLabel: m.archived ? 'Restore' : 'Archive',
      destructive: !m.archived,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.archiveMedicine(m.id, archived: !m.archived),
      successMessage: m.archived ? 'Medicine restored.' : 'Medicine archived.',
    );
    if (ok) _load();
  }

  Future<void> _transactions(Medicine medicine) => showStockTransactionsSheet(
        context,
        title: '${medicine.displayName} transactions',
        load: () => ApiService.I.medicineTransactions(medicine.id),
      );

  Future<void> _writeOffBatch(Medicine m, String action) async {
    List<Map<String, dynamic>> batches = [];
    try {
      batches = await ApiService.I.medicineBatches(m.id);
    } catch (e) {
      if (kDebugMode) debugPrint('MedicinesScreen.writeOffBatch failed: $e');
    }
    if (!mounted) return;
    final active = batches
        .where((b) => b['status'] == 'active' || b['status'] == null)
        .toList();
    if (active.isEmpty) {
      showCrudMessage(context, 'No active batches to $action.', error: true);
      return;
    }
    final options = active
        .map((b) =>
            '${b['batch_number']} · exp ${b['expiration_date']} · ${b['quantity_remaining']} left')
        .toList();
    final payload = await showCrudForm(
      context,
      title: action == 'expire' ? 'Expire a batch' : 'Recall a batch',
      fields: [
        CrudField.dropdown('batch_key', 'Batch', options),
        const CrudField.text('note', 'Note', required: false),
      ],
      submitLabel: action == 'expire' ? 'Expire' : 'Recall',
    );
    if (payload == null || !mounted) return;
    final idx = options.indexOf(payload['batch_key'] as String? ?? '');
    if (idx < 0 || idx >= active.length) return;
    final batchId = active[idx]['id'] as int;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.writeOffMedicineBatch(m.id, batchId, action,
          note: payload['note'] as String?),
      successMessage: action == 'expire' ? 'Batch expired.' : 'Batch recalled.',
    );
    if (ok) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Medicines')),
      body: RefreshIndicator(onRefresh: _load, child: _buildBody()),
      floatingActionButton: _canWrite
          ? FloatingActionButton.extended(
              backgroundColor: const Color(0xFF800000),
              foregroundColor: Colors.white,
              onPressed: _create,
              icon: const Icon(HugeIcons.strokeRoundedMedicine01),
              label: const Text('Add medicine'),
            )
          : null,
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty('No medicines in the catalogue.');
    }

    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 88),
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
        return _MedicineTile(
          medicine: _items[i],
          canWrite: _canWrite,
          onEdit: () => _edit(_items[i]),
          onAddBatch: () => _addBatch(_items[i]),
          onDispense: () => _dispense(_items[i]),
          onArchive: () => _archive(_items[i]),
          onExpire: () => _writeOffBatch(_items[i], 'expire'),
          onRecall: () => _writeOffBatch(_items[i], 'recall'),
          onTransactions: () => _transactions(_items[i]),
        );
      },
    );
  }
}

class _MedicineTile extends StatelessWidget {
  const _MedicineTile({
    required this.medicine,
    required this.canWrite,
    this.onEdit,
    this.onAddBatch,
    this.onDispense,
    this.onArchive,
    this.onExpire,
    this.onRecall,
    this.onTransactions,
  });

  final Medicine medicine;
  final bool canWrite;
  final VoidCallback? onEdit;
  final VoidCallback? onAddBatch;
  final VoidCallback? onDispense;
  final VoidCallback? onArchive;
  final VoidCallback? onExpire;
  final VoidCallback? onRecall;
  final VoidCallback? onTransactions;

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
        title: Text(
          medicine.displayName,
          style: const TextStyle(fontWeight: FontWeight.w600),
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Wrap(
            spacing: 6,
            runSpacing: 4,
            children: [
              Text(
                '${medicine.quantityOnHand} ${medicine.unit}',
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
              StatusBadge(
                label: medicine.stockStatusLabel,
                color: medicine.stockStatus == 'in_stock'
                    ? const Color(0xFF1B7A43)
                    : const Color(0xFFB3261E),
              ),
              Text(
                'Reorder ${medicine.reorderThreshold}'
                '${medicine.targetStock == null ? '' : ' / target ${medicine.targetStock}'}',
              ),
              if (medicine.earliestExpiry != null &&
                  medicine.earliestExpiry!.isNotEmpty)
                StatusBadge(
                  label: 'Exp: ${medicine.earliestExpiry}',
                  color: const Color(0xFFB45309),
                ),
              if (medicine.category != null && medicine.category!.isNotEmpty)
                StatusBadge(
                    label: titleCaseOption(medicine.category!),
                    color: Colors.grey),
            ],
          ),
        ),
        isThreeLine: false,
        trailing: PopupMenuButton<String>(
          icon: const Icon(HugeIcons.strokeRoundedMore),
          onSelected: (v) {
            switch (v) {
              case 'transactions':
                onTransactions?.call();
              case 'edit':
                onEdit?.call();
              case 'batch':
                onAddBatch?.call();
              case 'dispense':
                onDispense?.call();
              case 'expire':
                onExpire?.call();
              case 'recall':
                onRecall?.call();
              case 'archive':
                onArchive?.call();
            }
          },
          itemBuilder: (_) => [
            const PopupMenuItem(
                value: 'transactions', child: Text('Transactions')),
            if (canWrite) ...[
              const PopupMenuItem(
                  value: 'edit', child: Text('Edit stock levels')),
              const PopupMenuItem(
                  value: 'batch', child: Text('Add batch / receive')),
              const PopupMenuItem(value: 'dispense', child: Text('Dispense')),
              const PopupMenuItem(
                  value: 'expire', child: Text('Expire a batch')),
              const PopupMenuItem(
                  value: 'recall', child: Text('Recall a batch')),
              PopupMenuItem(
                value: 'archive',
                child: Text(medicine.archived ? 'Restore' : 'Archive'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Dedicated dispense sheet — quantity + open-encounter picker (the backend
/// anchors every dispense to a live clinic encounter).
class _DispenseSheet extends StatefulWidget {
  const _DispenseSheet({required this.medicine, required this.encounters});

  final Medicine medicine;
  final List<Map<String, dynamic>> encounters;

  @override
  State<_DispenseSheet> createState() => _DispenseSheetState();
}

class _DispenseSheetState extends State<_DispenseSheet> {
  final _qty = TextEditingController();
  String? _encounter;
  String? _error;

  @override
  void dispose() {
    _qty.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final quantity = int.tryParse(_qty.text.trim());
    if (quantity == null || quantity < 1) {
      setState(() => _error = 'Quantity must be at least 1');
      return;
    }
    if (_encounter == null) {
      setState(() => _error = 'Select the open encounter');
      return;
    }
    final encounterId = int.tryParse(_encounter!.split(' ').first) ?? 0;
    Navigator.of(context).pop();
    try {
      await ApiService.I.dispenseMedicine(widget.medicine.id, {
        'quantity': quantity,
        'encounter_id': encounterId,
      });
      if (mounted) {
        showCrudMessage(context,
            'Dispensed — ${widget.medicine.quantityOnHand - quantity} ${widget.medicine.unit} remaining.');
      }
    } catch (e) {
      if (mounted) {
        showCrudMessage(context, mapDioError(e).message, error: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        child: SafeArea(
          top: false,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'Dispense — ${widget.medicine.displayName}',
                style:
                    const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 4),
              Text(
                '${widget.medicine.quantityOnHand} ${widget.medicine.unit} on hand',
                style: const TextStyle(color: Colors.black54),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: _qty,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: 'Quantity',
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.all(Radius.circular(12)),
                  ),
                  isDense: true,
                ),
              ),
              const SizedBox(height: 12),
              if (widget.encounters.isEmpty)
                const Text(
                  'No open encounters. Start one in the clinic first.',
                  style: TextStyle(color: Color(0xFFB3261E)),
                )
              else
                DropdownButtonFormField<String>(
                  initialValue: _encounter,
                  decoration: const InputDecoration(
                    labelText: 'Open encounter',
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.all(Radius.circular(12)),
                    ),
                    isDense: true,
                  ),
                  items: [
                    for (final e in widget.encounters)
                      DropdownMenuItem(
                        value: '${e['id']} · ${e['patient_school_id']}',
                        child: Text(
                          '${e['id']} · ${e['patient_school_id']}',
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                  ],
                  onChanged: (v) => setState(() => _encounter = v),
                ),
              if (_error != null) ...[
                const SizedBox(height: 8),
                Text(
                  _error!,
                  style: const TextStyle(color: Color(0xFFB3261E)),
                ),
              ],
              const SizedBox(height: 16),
              FilledButton(
                onPressed: _submit,
                style: FilledButton.styleFrom(
                  backgroundColor: const Color(0xFF800000),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                child: const Text('Dispense'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
