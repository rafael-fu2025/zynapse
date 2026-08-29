import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Waste categories — mirrors the web `WasteCategoryPage`
/// (`/facilities/waste-categories`): add/edit/archive categories, the
/// active list, and the per-category yield & duration deviation report.
class WasteCategoriesScreen extends StatefulWidget {
  const WasteCategoriesScreen({super.key});

  @override
  State<WasteCategoriesScreen> createState() => _WasteCategoriesScreenState();
}

class _WasteCategoriesScreenState extends State<WasteCategoriesScreen> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _items = [];
  List<Map<String, dynamic>> _deviation = [];
  bool _loadedOnce = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final items = await ApiService.I.facilityWasteCategories();
      List<Map<String, dynamic>> deviation = [];
      try {
        deviation = await ApiService.I.wasteCategoryDeviation();
      } catch (e) {
        // Deviation is read-only bonus info — don't block the page.
        if (kDebugMode) debugPrint('WasteCategoriesScreen.loadDeviation failed: $e');
      }
      if (!mounted) return;
      setState(() {
        _items = items;
        _deviation = deviation;
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

  bool get _canManage =>
      context.read<AuthController>().session?.hasPermission(
            'facilities.categories.manage',
          ) ??
      false;

  Future<void> _create() async {
    final payload = await showCrudForm(
      context,
      title: 'Add category',
      fields: const [
        CrudField.text('code', 'Code'),
        CrudField.text('name', 'Name'),
        CrudField.number('expected_yield_pct', 'Expected yield %',
            required: false),
        CrudField.number('reference_duration_days', 'Reference days',
            required: false),
      ],
      submitLabel: 'Add',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.createWasteCategory(payload),
      successMessage: 'Waste category added.',
    );
    if (ok) _load();
  }

  Future<void> _edit(Map<String, dynamic> c) async {
    final payload = await showCrudForm(
      context,
      title: 'Edit category',
      fields: [
        CrudField.text('name', 'Name', initial: c['name'] as String? ?? ''),
        CrudField.number(
          'expected_yield_pct',
          'Expected yield %',
          required: false,
          initial: c['expected_yield_pct']?.toString(),
        ),
        CrudField.number(
          'reference_duration_days',
          'Reference days',
          required: false,
          initial: c['reference_duration_days']?.toString(),
        ),
      ],
      submitLabel: 'Save',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.updateWasteCategory(c['id'] as int, payload),
      successMessage: 'Waste category updated.',
    );
    if (ok) _load();
  }

  Future<void> _archive(Map<String, dynamic> c) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Archive category?',
      message: 'Archive “${c['name']}”? It will stop appearing in new '
          'batch starters.',
      confirmLabel: 'Archive',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.archiveWasteCategory(c['id'] as int),
      successMessage: 'Waste category archived.',
    );
    if (ok) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Waste categories')),
      floatingActionButton: _canManage
          ? FloatingActionButton.extended(
              backgroundColor: const Color(0xFF800000),
              foregroundColor: Colors.white,
              onPressed: _create,
              icon: const Icon(HugeIcons.strokeRoundedAddCircle),
              label: const Text('Add category'),
            )
          : null,
      body: RefreshIndicator(onRefresh: _load, child: _buildBody()),
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        SectionCard(
          title: 'Active categories (${_items.length})',
          icon: HugeIcons.strokeRoundedRecycle01,
          child: _items.isEmpty
              ? const Padding(
                  padding: EdgeInsets.symmetric(vertical: 12),
                  child: Text(
                    'No waste categories.',
                    style: TextStyle(color: Colors.black54),
                  ),
                )
              : Column(
                  children: [
                    for (final c in _items) _CategoryRow(c: c, onEdit: _canManage ? () => _edit(c) : null, onArchive: _canManage ? () => _archive(c) : null),
                  ],
                ),
        ),
        const SizedBox(height: 12),
        SectionCard(
          title: 'Yield & duration deviation',
          icon: HugeIcons.strokeRoundedChart02,
          child: _deviation.isEmpty
              ? const Padding(
                  padding: EdgeInsets.symmetric(vertical: 12),
                  child: Text(
                    'No finished batches to compare yet.',
                    style: TextStyle(color: Colors.black54),
                  ),
                )
              : Column(
                  children: [
                    for (final d in _deviation)
                      _DeviationRow(dev: d),
                  ],
                ),
        ),
      ],
    );
  }
}

class _CategoryRow extends StatelessWidget {
  const _CategoryRow({required this.c, this.onEdit, this.onArchive});

  final Map<String, dynamic> c;
  final VoidCallback? onEdit;
  final VoidCallback? onArchive;

  @override
  Widget build(BuildContext context) {
    final yield = c['expected_yield_pct'];
    final days = c['expected_days'] ?? c['reference_duration_days'];
    final samples = (c['sample_count'] as num?)?.toInt() ?? 0;
    final hist = c['historical_avg_days'];
    return ListTile(
      contentPadding: EdgeInsets.zero,
      title: Text(
        '${c['name']} (${c['code']})',
        style: const TextStyle(fontWeight: FontWeight.w600),
      ),
      subtitle: Text(
        '${yield != null ? '$yield% yield' : '— yield'} · '
        '${days != null ? '$days expected days' : '— days'}'
        '${samples > 0 ? ' · avg ${hist ?? 0}d from $samples trial${samples == 1 ? '' : 's'}' : ' (reference)'}'
      ),
      trailing: (onEdit != null || onArchive != null)
          ? PopupMenuButton<String>(
              icon: const Icon(HugeIcons.strokeRoundedMore),
              onSelected: (v) {
                if (v == 'edit') onEdit?.call();
                if (v == 'archive') onArchive?.call();
              },
              itemBuilder: (_) => [
                if (onEdit != null)
                  const PopupMenuItem(value: 'edit', child: Text('Edit')),
                if (onArchive != null)
                  const PopupMenuItem(value: 'archive', child: Text('Archive')),
              ],
            )
          : null,
    );
  }
}

class _DeviationRow extends StatelessWidget {
  const _DeviationRow({required this.dev});

  final Map<String, dynamic> dev;

  String _signed(num? v, {String suffix = ''}) {
    if (v == null) return '—';
    final n = v.toDouble();
    final s = n > 0 ? '+' : '';
    return '$s${n.toStringAsFixed(n == n.roundToDouble() ? 0 : 1)}$suffix';
  }

  @override
  Widget build(BuildContext context) {
    final actualYield = dev['actual_yield_pct'];
    final expYield = dev['expected_yield_pct'];
    final actualDays = dev['actual_days'];
    final expDays = dev['expected_days'];
    final dYield = dev['yield_delta_pp'];
    final dDays = dev['days_delta'];

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '${dev['name']}',
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 2),
          Text(
            '${dev['batch_count'] ?? 0} batches · '
            'Yield ${actualYield != null ? '$actualYield%' : '—'} / '
            '${expYield != null ? '$expYield%' : '—'} '
            '(${_signed(dYield, suffix: ' pp')}) · '
            'Duration ${actualDays != null ? '${actualDays}d' : '—'} / '
            '${expDays != null ? '${expDays}d' : '—'} '
            '(${_signed(dDays, suffix: 'd')})',
            style: const TextStyle(color: Colors.black54, fontSize: 12),
          ),
        ],
      ),
    );
  }
}
