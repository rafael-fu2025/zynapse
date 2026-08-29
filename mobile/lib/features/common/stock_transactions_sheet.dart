import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/models/inventory.dart';
import '../../core/utils/dates.dart';

Future<void> showStockTransactionsSheet(
  BuildContext context, {
  required String title,
  required Future<List<StockTransaction>> Function() load,
}) =>
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => _StockTransactionsSheet(title: title, load: load),
    );

class _StockTransactionsSheet extends StatefulWidget {
  const _StockTransactionsSheet({required this.title, required this.load});

  final String title;
  final Future<List<StockTransaction>> Function() load;

  @override
  State<_StockTransactionsSheet> createState() =>
      _StockTransactionsSheetState();
}

class _StockTransactionsSheetState extends State<_StockTransactionsSheet> {
  late Future<List<StockTransaction>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.load();
  }

  void _retry() => setState(() => _future = widget.load());

  @override
  Widget build(BuildContext context) => SizedBox(
        height: MediaQuery.sizeOf(context).height * .82,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 16, 8, 12),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(widget.title,
                            style: Theme.of(context).textTheme.titleLarge),
                        const Text(
                          'In, out, and running stock after each transaction.',
                        ),
                      ],
                    ),
                  ),
                  IconButton(
                    tooltip: 'Close',
                    onPressed: () => Navigator.of(context).pop(),
                    icon: const Icon(Icons.close),
                  ),
                ],
              ),
            ),
            const Divider(height: 1),
            Expanded(
              child: FutureBuilder<List<StockTransaction>>(
                future: _future,
                builder: (context, snapshot) {
                  if (snapshot.connectionState != ConnectionState.done) {
                    return const Center(child: CircularProgressIndicator());
                  }
                  if (snapshot.hasError) {
                    return Center(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(mapDioError(snapshot.error!).message),
                          const SizedBox(height: 8),
                          FilledButton(
                            onPressed: _retry,
                            child: const Text('Retry'),
                          ),
                        ],
                      ),
                    );
                  }
                  final rows = snapshot.data ?? [];
                  if (rows.isEmpty) {
                    return const Center(
                        child: Text('No stock transactions yet.'));
                  }
                  return ListView.separated(
                    padding: const EdgeInsets.all(16),
                    itemCount: rows.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 8),
                    itemBuilder: (context, index) {
                      final row = rows[index];
                      final change = row.qtyIn != null
                          ? '+${row.qtyIn}'
                          : row.qtyOut != null
                              ? '−${row.qtyOut}'
                              : '—';
                      final positive = row.qtyIn != null;
                      return Card(
                        margin: EdgeInsets.zero,
                        child: ListTile(
                          leading: CircleAvatar(
                            backgroundColor: (positive
                                    ? const Color(0xFF1B7A43)
                                    : const Color(0xFFB3261E))
                                .withValues(alpha: .12),
                            child: Text(
                              change,
                              style: TextStyle(
                                color: positive
                                    ? const Color(0xFF1B7A43)
                                    : const Color(0xFFB3261E),
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                          ),
                          title: Text(row.type.replaceAll('_', ' ')),
                          subtitle: Text(
                            '${fmtUtcToApp(row.createdAt)}'
                            '${row.userEmail == null ? '' : ' · ${row.userEmail}'}'
                            '${row.note?.isNotEmpty == true ? '\n${row.note}' : ''}',
                          ),
                          trailing: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              const Text(
                                'Stock after',
                                style: TextStyle(fontSize: 10),
                              ),
                              Text(
                                '${row.stockAfter ?? '—'}',
                                style: const TextStyle(
                                  fontSize: 18,
                                  fontWeight: FontWeight.w800,
                                ),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  );
                },
              ),
            ),
          ],
        ),
      );
}
