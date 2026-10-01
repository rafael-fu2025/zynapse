import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/data/taxonomy.dart';
import '../../core/models/inventory.dart';
import '../../core/models/medicine.dart';
import '../../core/models/reorder.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Purchases — the mobile counterpart of the web's ReordersTab: the
/// reorder/procurement workflow feeding the medicines and supplies tabs.
/// Lifecycle mirrors `ReorderService` (pending → approved → ordered
/// ("Purchased") → received ("Delivered") → completed, cancellable from
/// the first three); at most one open request exists per item.
class PurchasesScreen extends StatefulWidget {
  const PurchasesScreen({super.key});

  @override
  State<PurchasesScreen> createState() => _PurchasesScreenState();
}

class _PurchasesScreenState extends State<PurchasesScreen>
    with AutoPolling<PurchasesScreen> {
  bool _loading = true;
  String? _error;
  List<Reorder> _items = [];
  String? _nextCursor;
  bool _loadingMore = false;
  bool _loadedOnce = false;
  String? _status;

  bool get _canManage =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('clinic.reorders.manage') ??
      false;

  @override
  void initState() {
    super.initState();
    _load();
    startPolling(const Duration(seconds: 60), _silentRefresh);
  }

  Future<void> _silentRefresh() async {
    if (_items.length > 25) return;
    try {
      final page = await ApiService.I.reorders(status: _status);
      if (!mounted) return;
      setState(() {
        _items = page.items;
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // Keep the current list on transient errors.
      if (kDebugMode) debugPrint('PurchasesScreen.poll failed: $e');
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final page = await ApiService.I.reorders(status: _status);
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
      final page = await ApiService.I.reorders(
        status: _status,
        cursor: _nextCursor,
      );
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      if (kDebugMode) debugPrint('PurchasesScreen.loadMore failed: $e');
    } finally {
      setState(() => _loadingMore = false);
    }
  }

  Future<void> _transition(Reorder r, String action,
      {String? expectedDeliveryDate}) async {
    final ok = await runCrudAction(
      context,
      () => ApiService.I.transitionReorder(r.id, action,
          expectedDeliveryDate: expectedDeliveryDate),
      successMessage: switch (action) {
        'approve' => 'Request approved.',
        'order' => 'Marked purchased.',
        'receive' => 'Marked delivered.',
        'cancel' => 'Request cancelled.',
        _ => 'Updated.',
      },
    );
    if (ok && mounted) _load();
  }

  Future<void> _order(Reorder r) async {
    final payload = await showCrudForm(
      context,
      title: 'Mark purchased — ${r.itemLabel}',
      fields: const [
        CrudField.date('expected_delivery_date', 'Expected delivery',
            required: false),
        CrudField.text('note', 'Note', required: false),
      ],
      submitLabel: 'Mark purchased',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.transitionReorder(
        r.id,
        'order',
        expectedDeliveryDate: payload['expected_delivery_date'] as String?,
        note: payload['note'] as String?,
      ),
      successMessage: 'Marked purchased.',
    );
    if (ok && mounted) _load();
  }

  Future<void> _cancel(Reorder r) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Cancel purchase request?',
      message: 'Cancel purchase request #${r.id} — ${r.itemLabel}?',
      confirmLabel: 'Cancel request',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    await _transition(r, 'cancel');
  }

  Future<void> _create() async {
    // Item picker entries are built at open time from both catalogs —
    // the value encodes the catalog so submit can route the id.
    final results = await Future.wait([
      ApiService.I.medicines(limit: 200).then<Object?>((v) => v),
      ApiService.I.inventoryItems(limit: 200).then<Object?>((v) => v),
    ]);
    if (!mounted) return;
    final medicines =
        results[0] is ApiPage<Medicine> ? results[0]! as ApiPage<Medicine> : null;
    final supplies = results[1] is ApiPage<InventoryItem>
        ? results[1]! as ApiPage<InventoryItem>
        : null;
    final entries = <TaxonomyEntry>[
      for (final m in medicines?.items ?? const <Medicine>[])
        TaxonomyEntry(
          'medicine:${m.id}',
          '${m.genericName} — ${m.quantityOnHand} ${m.unit} on hand',
        ),
      for (final it in supplies?.items ?? const <InventoryItem>[])
        TaxonomyEntry(
          'supply:${it.id}',
          '${it.name} (${it.sku}) — ${it.quantityOnHand} ${it.unit} on hand',
        ),
    ];
    if (entries.isEmpty) {
      showCrudMessage(context, 'No catalog items available.', error: true);
      return;
    }
    final payload = await showCrudForm(
      context,
      title: 'Request purchase',
      fields: [
        CrudField.picker('item', 'Item', entries),
        const CrudField.number('quantity', 'Quantity'),
        const CrudField.dropdown(
            'urgency', 'Urgency', ['low', 'medium', 'high', 'critical'],
            initial: 'medium'),
        const CrudField.text('note', 'Note', required: false),
      ],
      submitLabel: 'Submit request',
    );
    if (payload == null || !mounted) return;
    final item = (payload['item'] as String? ?? '').split(':');
    if (item.length != 2) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.createReorder(
        itemType: item[0],
        itemId: int.tryParse(item[1]) ?? 0,
        quantity: (payload['quantity'] as num?)?.toInt() ?? 0,
        urgency: payload['urgency'] as String? ?? 'medium',
        note: payload['note'] as String?,
      ),
      successMessage: 'Purchase request submitted.',
    );
    if (ok && mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Purchases')),
      body: RefreshIndicator(onRefresh: _load, child: _buildBody()),
      floatingActionButton: _canManage
          ? FloatingActionButton.extended(
              backgroundColor: const Color(0xFF800000),
              foregroundColor: Colors.white,
              onPressed: _create,
              icon: const Icon(HugeIcons.strokeRoundedShoppingBag01),
              label: const Text('Request purchase'),
            )
          : null,
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty('No purchase requests.');
    }
    return Column(
      children: [
        SizedBox(
          height: 40,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 16),
            children: [
              for (final (value, label) in const [
                (null, 'All'),
                ('pending', 'Pending'),
                ('approved', 'Approved'),
                ('ordered', 'Purchased'),
                ('received', 'Delivered'),
                ('completed', 'Completed'),
                ('cancelled', 'Cancelled'),
              ])
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: FilterChip(
                    label: Text(label),
                    selected: _status == value,
                    onSelected: (_) {
                      setState(() {
                        _status = value;
                        _items = [];
                        _nextCursor = null;
                      });
                      _load();
                    },
                  ),
                ),
            ],
          ),
        ),
        Expanded(
          child: ListView.builder(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 88),
            itemCount: _items.length + (_nextCursor != null ? 1 : 0),
            scrollCacheExtent: const ScrollCacheExtent.pixels(600),
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
              return _ReorderCard(
                reorder: _items[i],
                canManage: _canManage,
                onApprove: () => _transition(_items[i], 'approve'),
                onOrder: () => _order(_items[i]),
                onReceive: () => _transition(_items[i], 'receive'),
                onCancel: () => _cancel(_items[i]),
              );
            },
          ),
        ),
      ],
    );
  }
}

Color _reorderStatusColor(String status) => switch (status) {
      'pending' => const Color(0xFF8A5A00), // amber
      'approved' => const Color(0xFF1E6FD9), // info blue
      'ordered' => const Color(0xFF5B4BA6), // purchased
      'received' => const Color(0xFF1B7A43), // green
      'completed' => const Color(0xFF1B7A43),
      'cancelled' => Colors.grey,
      _ => Colors.grey,
    };

class _ReorderCard extends StatelessWidget {
  const _ReorderCard({
    required this.reorder,
    required this.canManage,
    required this.onApprove,
    required this.onOrder,
    required this.onReceive,
    required this.onCancel,
  });

  final Reorder reorder;
  final bool canManage;
  final VoidCallback onApprove;
  final VoidCallback onOrder;
  final VoidCallback onReceive;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    final r = reorder;
    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color:
              Theme.of(context).colorScheme.outlineVariant.withValues(alpha: 0.5),
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 10, 8, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${r.itemLabel} · ${r.requestedQuantity} ${r.unit ?? ''}'
                        .trim(),
                    style: const TextStyle(
                        fontSize: 14, fontWeight: FontWeight.w700),
                  ),
                ),
                StatusBadge(
                  label: r.statusLabel,
                  color: _reorderStatusColor(r.status),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              '#${r.id} · on hand ${r.currentStock} · reorder ${r.reorderLevel}'
              '${r.targetStock == null ? '' : ' · target ${r.targetStock}'}'
              '${r.autoTriggered ? ' · auto' : ''}',
              style: const TextStyle(fontSize: 11, color: Colors.black54),
            ),
            if (r.expectedDeliveryDate != null)
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Text(
                  'Expected delivery ${r.expectedDeliveryDate!.substring(0, 10)}',
                  style: const TextStyle(fontSize: 11, color: Colors.black54),
                ),
              ),
            if (r.procurementNote != null && r.procurementNote!.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Text(
                  r.procurementNote!,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 12),
                ),
              ),
            if (canManage) ...[
              const SizedBox(height: 8),
              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  if (r.status == 'pending')
                    FilledButton.tonal(
                      onPressed: onApprove,
                      child: const Text('Approve'),
                    ),
                  if (r.status == 'approved')
                    FilledButton.tonal(
                      onPressed: onOrder,
                      child: const Text('Mark purchased'),
                    ),
                  if (r.status == 'ordered')
                    FilledButton.tonal(
                      onPressed: onReceive,
                      child: const Text('Mark delivered'),
                    ),
                  if (r.status == 'received') ...[
                    const Expanded(
                      child: Text(
                        'Awaiting stock entry on the Medicines/Supplies tab.',
                        style: TextStyle(fontSize: 11, color: Colors.black45),
                      ),
                    ),
                  ],
                  if (r.isOpen)
                    TextButton(
                      onPressed: onCancel,
                      child: const Text('Cancel',
                          style: TextStyle(color: Color(0xFFB3261E))),
                    ),
                ],
              ),
            ] else if (r.status == 'received') ...[
              const SizedBox(height: 4),
              const Text(
                'Awaiting stock entry on the Medicines/Supplies tab.',
                style: TextStyle(fontSize: 11, color: Colors.black45),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
