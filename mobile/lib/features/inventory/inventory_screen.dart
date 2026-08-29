import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/inventory.dart';
import '../../core/services/api_service.dart';
import '../../core/data/taxonomy.dart';
import '../../core/services/auth_controller.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/stock_transactions_sheet.dart';
import '../common/widgets.dart';

/// Clinic inventory (supplies) — `GET /clinic/inventory`.
/// Role-gated CRUD mirrors the web SPA (`clinic.inventory.write`).
class InventoryScreen extends StatefulWidget {
  const InventoryScreen({super.key});

  @override
  State<InventoryScreen> createState() => _InventoryScreenState();
}

class _InventoryScreenState extends State<InventoryScreen>
    with AutoPolling<InventoryScreen> {
  bool _loading = true;
  String? _error;
  List<InventoryItem> _items = [];
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
    // Live updates — mirrors the SPA's 60s `refetchInterval`.
    startPolling(const Duration(seconds: 60), _silentRefresh);
  }

  /// Polled live update: silently refetch the first page so new/updated
  /// supplies appear without a manual refresh. Skipped once the user has
  /// paginated deeper (pull-to-refresh there) so the list never collapses.
  Future<void> _silentRefresh() async {
    if (_items.length > 25) return;
    try {
      final page = await ApiService.I.inventoryItems();
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
      final page = await ApiService.I.inventoryItems();
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
      final page = await ApiService.I.inventoryItems(cursor: _nextCursor);
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (_) {
      // ignore
    } finally {
      setState(() => _loadingMore = false);
    }
  }

  Future<void> _create() async {
    final payload = await showCrudForm(
      context,
      title: 'Add inventory item',
      fields: const [
        CrudField.text('sku', 'SKU', hint: 'e.g. ALCOHOL-500'),
        CrudField.text('name', 'Name'),
        CrudField.picker('unit', 'Unit', inventoryUnits, initial: 'pc'),
        CrudField.number('reorder_level', 'Reorder level', initial: '0'),
        CrudField.number('target_stock', 'Target stock', initial: '1'),
      ],
      submitLabel: 'Add',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.createInventoryItem(payload),
      successMessage: 'Inventory item added.',
    );
    if (ok) _load();
  }

  Future<void> _edit(InventoryItem item) async {
    final payload = await showCrudForm(
      context,
      title: 'Edit ${item.name}',
      fields: [
        CrudField.text('name', 'Name', initial: item.name),
        CrudField.text('unit', 'Unit', initial: item.unit),
        CrudField.number('reorder_level', 'Reorder level',
            initial: '${item.reorderLevel}'),
        CrudField.number('target_stock', 'Target stock',
            initial: '${item.targetStock ?? item.reorderLevel * 2}'),
      ],
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.updateInventoryItem(item.id, payload),
      successMessage: '${item.name} updated.',
    );
    if (ok) _load();
  }

  Future<void> _move(InventoryItem item, {required bool receive}) async {
    final fields = <CrudField>[
      if (receive)
        const CrudField.number('quantity', 'Quantity received')
      else
        const CrudField.number('qty_delta', 'Quantity change (+/-)'),
      if (receive)
        const CrudField.text('shortage_note', 'Shortage note', required: false)
      else
        const CrudField.text('note', 'Note', required: false),
    ];
    final payload = await showCrudForm(
      context,
      title: receive ? 'Receive — ${item.name}' : 'Adjust — ${item.name}',
      fields: fields,
      submitLabel: receive ? 'Receive' : 'Adjust',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => receive
          ? ApiService.I.receiveInventoryStock(item.id, payload)
          : ApiService.I.moveInventoryStock(item.id, payload),
      successMessage: receive ? 'Stock received.' : 'Stock adjusted.',
    );
    if (ok) _load();
  }

  Future<void> _archive(InventoryItem item) async {
    final confirmed = await showCrudConfirm(
      context,
      title: item.archived ? 'Restore item?' : 'Archive item?',
      message: item.archived
          ? 'Restore "${item.name}"?'
          : 'Archive "${item.name}"? It will be hidden from the list.',
      confirmLabel: item.archived ? 'Restore' : 'Archive',
      destructive: !item.archived,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () =>
          ApiService.I.archiveInventoryItem(item.id, archived: !item.archived),
      successMessage: item.archived ? 'Item restored.' : 'Item archived.',
    );
    if (ok) _load();
  }

  Future<void> _transactions(InventoryItem item) => showStockTransactionsSheet(
        context,
        title: '${item.name} transactions',
        load: () => ApiService.I.inventoryTransactions(item.id),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Inventory')),
      body: RefreshIndicator(onRefresh: _load, child: _buildBody()),
      floatingActionButton: _canWrite
          ? FloatingActionButton.extended(
              backgroundColor: const Color(0xFF800000),
              foregroundColor: Colors.white,
              onPressed: _create,
              icon: const Icon(HugeIcons.strokeRoundedPackage02),
              label: const Text('Add item'),
            )
          : null,
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty('No inventory items.');
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 88),
      itemCount: _items.length + (_nextCursor != null ? 1 : 0),
      // Pre-build rows ahead of the viewport so fast flings don't hitch on
      // widget builds — keeps long lists smooth at the 120Hz vsync.
      scrollCacheExtent: const ScrollCacheExtent.pixels(600),
      // Inventory tiles are single-line ListTiles = 72dp + 8dp gap. A fixed
      // itemExtent lets the viewport skip layout of off-screen rows
      // (ListView.separated lost itemExtent in Flutter 3.44).
      itemExtent: 80,
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
              'SKU ${item.sku} · ${item.unit}\n'
              '${item.stockStatusLabel} · reorder ${item.reorderLevel}'
              '${item.targetStock == null ? '' : ' · target ${item.targetStock}'}',
            ),
            trailing: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  '${item.quantityOnHand}',
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                    color: item.stockStatus != 'in_stock'
                        ? const Color(0xFFB3261E)
                        : const Color(0xFF1B7A43),
                  ),
                ),
                const SizedBox(width: 4),
                PopupMenuButton<String>(
                  icon: const Icon(HugeIcons.strokeRoundedMore),
                  onSelected: (v) {
                    switch (v) {
                      case 'transactions':
                        _transactions(item);
                      case 'edit':
                        _edit(item);
                      case 'receive':
                        _move(item, receive: true);
                      case 'adjust':
                        _move(item, receive: false);
                      case 'archive':
                        _archive(item);
                    }
                  },
                  itemBuilder: (_) => [
                    const PopupMenuItem(
                      value: 'transactions',
                      child: Text('Transactions'),
                    ),
                    if (_canWrite) ...[
                      const PopupMenuItem(value: 'edit', child: Text('Edit')),
                      const PopupMenuItem(
                          value: 'receive', child: Text('Receive stock')),
                      const PopupMenuItem(
                          value: 'adjust', child: Text('Adjust quantity')),
                      PopupMenuItem(
                        value: 'archive',
                        child: Text(item.archived ? 'Restore' : 'Archive'),
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
