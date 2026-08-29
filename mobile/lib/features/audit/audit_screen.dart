import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:intl/intl.dart';
import 'package:path_provider/path_provider.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/audit_event.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../common/auto_polling.dart';
import '../common/widgets.dart';

/// Human-friendly label for an audit action code (e.g. `clinic.encounter_closed`
/// → "Encounter closed"). Falls back to the last code segment, title-cased,
/// so unknown/new codes still read as words instead of raw `foo.bar_baz`.
String auditActionLabel(String code) {
  const known = <String, String>{
    'auth.login': 'Sign in',
    'auth.failed_login': 'Failed sign in',
    'auth.logout': 'Sign out',
    'auth.refresh_succeeded': 'Session refreshed',
    'auth.password_changed': 'Password changed',
    'auth.locked': 'Account locked',
    'clinic.encounter_created': 'Encounter created',
    'clinic.encounter_closed': 'Encounter closed',
    'clinic.appointment_booked': 'Appointment booked',
    'clinic.appointment_completed': 'Appointment completed',
    'clinic.checkin': 'Kiosk check-in',
    'clinic.reorder_created': 'Reorder created',
    'referral.created': 'Referral created',
    'referral.acknowledged': 'Referral acknowledged',
    'referral.closed': 'Referral closed',
    'referral.qr_issued': 'QR issued',
    'referral.qr_revoked': 'QR revoked',
    'bmg.batch_started': 'Batch started',
    'bmg.batch_cancelled': 'Batch cancelled',
    'bmg.batch_released': 'Batch released',
    'bmg.output_recorded': 'Output recorded',
    'bmg.log_recorded': 'Log recorded',
    'audit.log_cleared': 'Audit log cleared',
  };
  final knownLabel = known[code];
  if (knownLabel != null) return knownLabel;
  final last = code.split('.').last;
  return last
      .split('_')
      .where((w) => w.isNotEmpty)
      .map((w) => w[0].toUpperCase() + w.substring(1))
      .join(' ');
}

/// Audit evidence — mirrors the SPA Audit page (`frontend/src/pages/AuditPage.tsx`).
///
/// * `GET /audit/events` paged stream (search + filters).
/// * `GET /audit/events/{id}` inspect dialog with redacted payload.
/// * `GET /audit/verify` hash-chain check (audit.read).
/// * `GET /audit/export` CSV export saved to documents (audit.export).
class AuditScreen extends StatefulWidget {
  const AuditScreen({super.key});

  @override
  State<AuditScreen> createState() => _AuditScreenState();
}

class _AuditScreenState extends State<AuditScreen>
    with AutoPolling<AuditScreen> {
  final _searchController = TextEditingController();
  final _entityIdController = TextEditingController();
  final _requestIdController = TextEditingController();

  String _query = '';
  Timer? _searchDebounce;
  bool _loading = true;
  String? _error;
  List<AuditEvent> _items = [];
  String? _nextCursor;
  bool _loadingMore = false;
  bool _loadedOnce = false;

  // Filters.
  bool _showAdvanced = false;
  AuditFacets? _facets;
  String? _action;
  String? _entityType;
  int? _actorUserId;
  String? _entityId;
  String? _requestId;
  DateTime? _from;
  DateTime? _to;

  // Verify chain.
  bool _verifying = false;
  AuditVerification? _verification;
  bool _verifyFailed = false;

  // Export.
  bool _exporting = false;

  // Detail.
  int? _selectedId;

  bool get _canExport =>
      context.read<AuthController>().session?.hasPermission('audit.export') ??
      false;

  @override
  void initState() {
    super.initState();
    _load();
    _loadFacets();
    // Live updates — mirrors the SPA's 15s `refetchInterval`.
    startPolling(const Duration(seconds: 15), _silentRefresh);
  }

  /// Polled live update: silently refetch the first page so new audit events
  /// appear without a manual refresh. Skipped while a search query is active
  /// or once the user has paginated deeper (pull-to-refresh there).
  Future<void> _silentRefresh() async {
    if (_query.isNotEmpty || _items.length > 50) return;
    try {
      final page = await ApiService.I.auditEvents(
        q: _query,
        action: _action,
        entityType: _entityType,
        entityId: int.tryParse((_entityId ?? '').trim()),
        actorUserId: _actorUserId,
        requestId: _requestId,
        from: _from == null ? null : toDateInput(_from!),
        to: _to == null ? null : toDateInput(_to!),
      );
      if (!mounted) return;
      setState(() {
        _items = page.items;
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (_) {
      // Keep the current list on transient errors.
    }
  }

  @override
  void dispose() {
    _searchDebounce?.cancel();
    _searchController.dispose();
    _entityIdController.dispose();
    _requestIdController.dispose();
    super.dispose();
  }

  Future<void> _loadFacets() async {
    try {
      final facets = await ApiService.I.auditFacets();
      if (!mounted) return;
      setState(() => _facets = facets);
    } catch (_) {
      // Non-fatal: dropdowns fall back to free text.
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final page = await ApiService.I.auditEvents(
        q: _query,
        action: _action,
        entityType: _entityType,
        entityId: int.tryParse((_entityId ?? '').trim()),
        actorUserId: _actorUserId,
        requestId: _requestId,
        from: _from == null ? null : toDateInput(_from!),
        to: _to == null ? null : toDateInput(_to!),
      );
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
      final page = await ApiService.I.auditEvents(
        cursor: _nextCursor,
        q: _query,
        action: _action,
        entityType: _entityType,
        entityId: int.tryParse((_entityId ?? '').trim()),
        actorUserId: _actorUserId,
        requestId: _requestId,
        from: _from == null ? null : toDateInput(_from!),
        to: _to == null ? null : toDateInput(_to!),
      );
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

  Future<void> _verifyChain() async {
    setState(() {
      _verifying = true;
      _verification = null;
      _verifyFailed = false;
    });
    try {
      final result = await ApiService.I.verifyAuditChain();
      if (!mounted) return;
      setState(() {
        _verification = result;
        _verifying = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _verifyFailed = true;
        _verifying = false;
      });
    }
  }

  Future<void> _export() async {
    setState(() => _exporting = true);
    try {
      final csv = await ApiService.I.auditExport(
        q: _query,
        action: _action,
        entityType: _entityType,
        entityId: int.tryParse((_entityId ?? '').trim()),
        actorUserId: _actorUserId,
        requestId: _requestId,
        from: _from == null ? null : toDateInput(_from!),
        to: _to == null ? null : toDateInput(_to!),
      );
      final dir = await getApplicationDocumentsDirectory();
      final from = _from == null ? 'all' : toDateInput(_from!);
      final to = _to == null ? 'all' : toDateInput(_to!);
      final file = File('${dir.path}/synapse-audit-${from}_$to.csv');
      await file.writeAsString(csv);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Exported ${file.path}')),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Export failed: ${mapDioError(e).message}')),
      );
    } finally {
      if (mounted) setState(() => _exporting = false);
    }
  }

  void _applyFilters() {
    setState(() {
      _entityId = _entityIdController.text.trim();
      _requestId = _requestIdController.text.trim();
    });
    _load();
  }

  void _clearFilters() {
    _searchController.clear();
    _entityIdController.clear();
    _requestIdController.clear();
    setState(() {
      _query = '';
      _action = null;
      _entityType = null;
      _actorUserId = null;
      _entityId = null;
      _requestId = null;
      _from = null;
      _to = null;
    });
    _load();
  }

  Future<void> _pickDateRange() async {
    final now = DateTime.now();
    final initial = _from != null && _to != null
        ? DateTimeRange(start: _from!, end: _to!)
        : DateTimeRange(
            start: now.subtract(const Duration(days: 6)), end: now);
    final picked = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2024),
      lastDate: now,
      initialDateRange: initial,
      helpText: 'Select audit date range',
    );
    if (picked != null) {
      setState(() {
        _from = picked.start;
        _to = picked.end;
      });
      _load();
    }
  }

  void _inspect(int id) {
    setState(() => _selectedId = id);
    showSynapseSheet(
      context,
      builder: (_) => _EventDetailSheet(
        eventId: id,
        onClose: () => setState(() => _selectedId = null),
      ),
    ).whenComplete(() {
      if (mounted) setState(() => _selectedId = null);
    });
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Audit evidence'),
        actions: [
          IconButton(
            tooltip: 'Verify chain',
            icon: const Icon(HugeIcons.strokeRoundedShield01),
            onPressed: _verifying ? null : _verifyChain,
          ),
        ],
      ),
      body: Column(
        children: [
          _buildHeader(scheme),
          if (_verifying || _verification != null || _verifyFailed)
            _buildVerificationBanner(scheme),
          _buildFilters(scheme),
          Expanded(
            child: RefreshIndicator(onRefresh: _load, child: _buildBody()),
          ),
          _buildFooter(scheme),
        ],
      ),
    );
  }

  Widget _buildHeader(ColorScheme scheme) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              Icon(HugeIcons.strokeRoundedShield01, size: 20, color: scheme.primary),
              const SizedBox(width: 8),
              Text(
                'Audit evidence',
                style: Theme.of(context)
                    .textTheme
                    .titleLarge
                    ?.copyWith(fontWeight: FontWeight.w700),
              ),
              const Spacer(),
              if (_canExport)
                OutlinedButton.icon(
                  onPressed: _exporting ? null : _export,
                  icon: _exporting
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(HugeIcons.strokeRoundedDownload01, size: 16),
                  label: const Text('Export'),
                ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'Inspect immutable, hash-chained administrative events. '
            'Detail payloads are redacted before display.',
            style: Theme.of(context)
                .textTheme
                .bodySmall
                ?.copyWith(color: Colors.black54),
          ),
        ],
      ),
    );
  }

  Widget _buildVerificationBanner(ColorScheme scheme) {
    String message;
    IconData icon;
    Color color;
    if (_verifying) {
      return const Padding(
        padding: EdgeInsets.fromLTRB(16, 0, 16, 8),
        child: Row(
          children: [
            SizedBox(
              width: 16,
              height: 16,
              child: CircularProgressIndicator(strokeWidth: 2),
            ),
            SizedBox(width: 8),
            Expanded(
              child: Text('Verifying hash chain…'),
            ),
          ],
        ),
      );
    }
    if (_verifyFailed) {
      message = 'Chain verification could not be completed. '
          'Try again or inspect the server logs.';
      icon = HugeIcons.strokeRoundedTriangle01;
      color = const Color(0xFFB3261E);
    } else if (_verification?.ok ?? false) {
      message = 'Chain verified. ${_verification!.checked} events checked '
          'through event ${_verification!.verifiedUpTo ?? 'genesis'}.';
      icon = HugeIcons.strokeRoundedCheckmarkCircle01;
      color = const Color(0xFF1B7A43);
    } else {
      message = 'Chain divergence detected. First affected event: '
          '${_verification?.firstDivergence?.id ?? 'unknown'}.';
      icon = HugeIcons.strokeRoundedTriangle01;
      color = const Color(0xFFB3261E);
    }
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withValues(alpha: 0.35)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: TextStyle(color: color, fontSize: 13),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildFilters(ColorScheme scheme) {
    final activeCount = [
      if (_action != null) 1,
      if (_entityType != null) 1,
      if (_actorUserId != null) 1,
      if (_entityId != null && _entityId!.isNotEmpty) 1,
      if (_requestId != null && _requestId!.isNotEmpty) 1,
      if (_from != null) 1,
      if (_to != null) 1,
      if (_query.isNotEmpty) 1,
    ].length;

    return Container(
      margin: const EdgeInsets.fromLTRB(16, 4, 16, 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: scheme.surface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: scheme.outlineVariant.withValues(alpha: 0.5),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(
                'Evidence filters',
                style: Theme.of(context)
                    .textTheme
                    .titleSmall
                    ?.copyWith(fontWeight: FontWeight.w600),
              ),
              if (activeCount > 0) ...[
                const SizedBox(width: 8),
                StatusBadge(
                  label: '$activeCount active',
                  color: scheme.primary,
                ),
              ],
              const Spacer(),
              TextButton.icon(
                onPressed: () => setState(() => _showAdvanced = !_showAdvanced),
                icon: Icon(
                  _showAdvanced ? Icons.expand_less : Icons.expand_more,
                  size: 18,
                ),
                label: Text(_showAdvanced ? 'Hide advanced' : 'Advanced'),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: _facetsDropdown<String?>(
                  label: 'Action',
                  value: _action,
                  items: [
                    const DropdownMenuItem<String?>(
                        value: null, child: Text('All actions')),
                    for (final code in _facets?.actionCodes ?? <String>[])
                      DropdownMenuItem<String?>(
                          value: code, child: Text(code)),
                  ],
                  onChanged: (v) {
                    setState(() => _action = v);
                    _load();
                  },
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _pickDateRange,
                  icon: const Icon(HugeIcons.strokeRoundedCalendar01, size: 16),
                  label: Text(_rangeLabel),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          TextField(
            controller: _searchController,
            onChanged: (v) {
              setState(() => _query = v.trim());
              // Debounce like the SPA so we don't hit the API per keystroke.
              _searchDebounce?.cancel();
              _searchDebounce =
                  Timer(const Duration(milliseconds: 300), _load);
            },
            decoration: const InputDecoration(
              hintText: 'Search by action, actor, or event context',
              prefixIcon: Icon(HugeIcons.strokeRoundedSearch01, size: 18),
              isDense: true,
              border: OutlineInputBorder(
                borderRadius: BorderRadius.all(Radius.circular(10)),
              ),
              contentPadding: EdgeInsets.symmetric(vertical: 10),
            ),
          ),
          if (_showAdvanced) ...[
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: _facetsDropdown<String?>(
                    label: 'Entity type',
                    value: _entityType,
                    items: [
                      const DropdownMenuItem<String?>(
                          value: null, child: Text('All entity types')),
                      for (final t in _facets?.entityTypes ?? <String>[])
                        DropdownMenuItem<String?>(value: t, child: Text(t)),
                    ],
                    onChanged: (v) {
                      setState(() => _entityType = v);
                      _load();
                    },
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _facetsDropdown<int?>(
                    label: 'Actor',
                    value: _actorUserId,
                    items: [
                      const DropdownMenuItem<int?>(
                          value: null, child: Text('All actors')),
                      for (final actor in _facets?.actors ?? <AuditActor>[])
                        DropdownMenuItem<int?>(
                          value: actor.id,
                          child: Text(actor.label,
                              overflow: TextOverflow.ellipsis),
                        ),
                    ],
                    onChanged: (v) {
                      setState(() => _actorUserId = v);
                      _load();
                    },
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _entityIdController,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'Entity ID',
                      hintText: 'Exact numeric ID',
                      isDense: true,
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.all(Radius.circular(10)),
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: TextField(
                    controller: _requestIdController,
                    decoration: const InputDecoration(
                      labelText: 'Request ID',
                      hintText: '32-char ID or UUID',
                      isDense: true,
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.all(Radius.circular(10)),
                      ),
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton.icon(
                  onPressed: _clearFilters,
                  icon: const Icon(Icons.close, size: 16),
                  label: const Text('Clear all'),
                ),
                const SizedBox(width: 8),
                FilledButton.icon(
                  onPressed: _applyFilters,
                  icon: const Icon(HugeIcons.strokeRoundedSearch01, size: 16),
                  label: const Text('Apply filters'),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Widget _facetsDropdown<T>(
      {required String label,
      required T value,
      required List<DropdownMenuItem<T>> items,
      required ValueChanged<T?> onChanged}) {
    return DropdownButtonFormField<T>(
      initialValue: value,
      isExpanded: true,
      decoration: InputDecoration(
        labelText: label,
        isDense: true,
        contentPadding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        border: const OutlineInputBorder(
          borderRadius: BorderRadius.all(Radius.circular(10)),
        ),
      ),
      items: items,
      onChanged: onChanged,
    );
  }

  String get _rangeLabel {
    if (_from == null || _to == null) return 'Any date';
    final f = DateFormat('MMM d').format(_from!);
    final t = DateFormat('MMM d').format(_to!);
    return _from!.year == _to!.year ? '$f – $t' : '$f, ${_from!.year} – $t';
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty(
        _query.isEmpty ? 'No audit events match these filters.' : 'No matches.',
        icon: HugeIcons.strokeRoundedFileSearch,
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
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
        final event = _items[i];
        return _AuditTile(
          event: event,
          selected: event.id == _selectedId,
          onInspect: () => _inspect(event.id),
        );
      },
    );
  }

  Widget _buildFooter(ColorScheme scheme) {
    if (_loading && _items.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
      child: Row(
        children: [
          Text(
            '${_items.length} events · newest first',
            style: Theme.of(context)
                .textTheme
                .bodySmall
                ?.copyWith(color: Colors.black54),
          ),
        ],
      ),
    );
  }
}

class _AuditTile extends StatelessWidget {
  const _AuditTile({
    required this.event,
    required this.selected,
    required this.onInspect,
  });

  final AuditEvent event;
  final bool selected;
  final VoidCallback onInspect;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Card(
      elevation: 0,
      margin: EdgeInsets.zero,
      color: selected ? scheme.primary.withValues(alpha: 0.06) : null,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color: selected
              ? scheme.primary.withValues(alpha: 0.6)
              : scheme.outlineVariant.withValues(alpha: 0.5),
        ),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onInspect,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(
                radius: 16,
                backgroundColor: scheme.primary.withValues(alpha: 0.1),
                child: Icon(HugeIcons.strokeRoundedAudit01,
                    size: 16, color: scheme.primary),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      auditActionLabel(event.actionCode),
                      style: const TextStyle(fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      _entityLabel,
                      style: const TextStyle(fontSize: 12),
                    ),
                    const SizedBox(height: 1),
                    Text(
                      _actorLabel,
                      style: const TextStyle(
                        fontSize: 12,
                        color: Colors.black54,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Row(
                      children: [
                        Flexible(
                          child: Text(
                            fmtUtcToApp(event.occurredAt),
                            style: const TextStyle(
                              fontSize: 12,
                              color: Colors.black54,
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        Flexible(
                          child: Text(
                            _shortCode(event.requestId),
                            style: const TextStyle(
                              fontSize: 12,
                              color: Colors.black54,
                              fontFamily: 'monospace',
                            ),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              IconButton(
                tooltip: 'Inspect event ${event.id}',
                icon: const Icon(HugeIcons.strokeRoundedEye, size: 18),
                onPressed: onInspect,
              ),
            ],
          ),
        ),
      ),
    );
  }

  String get _entityLabel {
    final id = event.entityId != null ? 'ID ${event.entityId}' : 'not assigned';
    return '${event.entityType} · $id';
  }

  String get _actorLabel {
    final actor = event.actor;
    if (actor == null) return 'System process';
    return actor.displayName ?? actor.email ?? 'User ${actor.id}';
  }

  String _shortCode(String? value) {
    if (value == null || value.isEmpty) return 'req not captured';
    return value.length > 10 ? '${value.substring(0, 10)}…' : value;
  }
}

/// Inspect dialog — `GET /audit/events/{id}` redacted payload.
class _EventDetailSheet extends StatefulWidget {
  const _EventDetailSheet({required this.eventId, required this.onClose});

  final int eventId;
  final VoidCallback onClose;

  @override
  State<_EventDetailSheet> createState() => _EventDetailSheetState();
}

class _EventDetailSheetState extends State<_EventDetailSheet> {
  AuditEvent? _event;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final event = await ApiService.I.auditEventDetail(widget.eventId);
      if (!mounted) return;
      setState(() {
        _event = event;
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

  Future<void> _copy(String value, String label) async {
    await Clipboard.setData(ClipboardData(text: value));
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('$label copied')),
    );
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SheetHeader(
              title: 'Evidence record ${widget.eventId}',
              subtitle: 'Immutable event metadata and redacted context.',
            ),
            const SizedBox(height: 12),
            Flexible(
              child: _buildContent(scheme),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildContent(ColorScheme scheme) {
    if (_loading) return AsyncState.loading();
    if (_error != null) {
      return AsyncState.error(_error!, onRetry: _load);
    }
    final event = _event!;
    return SingleChildScrollView(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              StatusBadge(
                label: event.actionCode.replaceAll('.', ' · '),
                color: const Color(0xFF1E6FD9),
              ),
              Text(
                fmtUtcToApp(event.occurredAt),
                style: const TextStyle(fontSize: 12, color: Colors.black54),
              ),
            ],
          ),
          const SizedBox(height: 16),
          _DetailRow(
            label: 'Entity',
            child: Text(
              '${event.entityType} / ${event.entityId ?? 'not assigned'}',
              style: const TextStyle(fontSize: 13),
            ),
          ),
          _DetailRow(
            label: 'Actor',
            child: Text(
              event.actor?.label ?? 'System process',
              style: const TextStyle(fontSize: 13),
            ),
          ),
          _DetailRow(
            label: 'Previous event',
            child: Text(
              '${event.prevId ?? 'genesis'}',
              style: const TextStyle(
                fontSize: 13,
                fontFamily: 'monospace',
              ),
            ),
          ),
          _DetailRow(
            label: 'Request ID',
            child: _CopyableCode(
              value: event.requestId,
              onCopy: (v) => _copy(v, 'Request ID'),
            ),
          ),
          _DetailRow(
            label: 'Commit hash',
            child: _CopyableCode(
              value: event.commitHash.isEmpty ? null : event.commitHash,
              onCopy: (v) => _copy(v, 'Commit hash'),
            ),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: Text(
                  'Redacted payload',
                  style: Theme.of(context)
                      .textTheme
                      .titleSmall
                      ?.copyWith(fontWeight: FontWeight.w600),
                ),
              ),
              if (event.payload != null)
                TextButton.icon(
                  onPressed: () => _copy(
                    const JsonEncoder.withIndent('  ')
                        .convert(event.payload),
                    'Payload',
                  ),
                  icon: const Icon(HugeIcons.strokeRoundedCopy01, size: 16),
                  label: const Text('Copy'),
                ),
            ],
          ),
          const SizedBox(height: 4),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: scheme.surfaceContainerHighest.withValues(alpha: 0.4),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: scheme.outlineVariant.withValues(alpha: 0.4)),
            ),
            child: SelectableText(
              event.payload == null
                  ? 'No payload captured.'
                  : const JsonEncoder.withIndent('  ').convert(event.payload),
              style: TextStyle(
                fontSize: 11,
                height: 1.5,
                fontFamily: 'monospace',
                color: scheme.onSurface,
              ),
            ),
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }
}

class _DetailRow extends StatelessWidget {
  const _DetailRow({required this.label, required this.child});

  final String label;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 110,
            child: Text(
              label,
              style: const TextStyle(fontSize: 12, color: Colors.black54),
            ),
          ),
          Expanded(child: child),
        ],
      ),
    );
  }
}

class _CopyableCode extends StatelessWidget {
  const _CopyableCode({required this.value, required this.onCopy});

  final String? value;
  final void Function(String) onCopy;

  @override
  Widget build(BuildContext context) {
    if (value == null || value!.isEmpty) {
      return const Text(
        'Not captured',
        style: TextStyle(fontSize: 13, color: Colors.black45),
      );
    }
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Flexible(
          child: SelectableText(
            value!,
            style: const TextStyle(
              fontSize: 12,
              fontFamily: 'monospace',
            ),
          ),
        ),
        const SizedBox(width: 4),
        InkWell(
          onTap: () => onCopy(value!),
          borderRadius: BorderRadius.circular(6),
          child: const Padding(
            padding: EdgeInsets.all(4),
            child: Icon(
              HugeIcons.strokeRoundedCopy01,
              size: 14,
              color: Colors.black45,
            ),
          ),
        ),
      ],
    );
  }
}
