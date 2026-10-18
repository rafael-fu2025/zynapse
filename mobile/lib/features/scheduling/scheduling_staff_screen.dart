import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_envelope.dart';
import '../../core/models/scheduling_staff.dart';
import '../../core/services/api_service.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Scheduling staff enrollment — mirrors the SPA's `DepartmentStaffScheduling`
/// / `useSchedulingStaff`.
///
/// Enrollment is the source of truth for who can own a shift or availability
/// window. Deactivating an enrollment keeps its schedule history and existing
/// appointments; it only blocks new writes.
class SchedulingStaffScreen extends StatefulWidget {
  const SchedulingStaffScreen({super.key, required this.unit});

  /// clinic | guidance.
  final String unit;

  @override
  State<SchedulingStaffScreen> createState() => _SchedulingStaffScreenState();
}

class _SchedulingStaffScreenState extends State<SchedulingStaffScreen> {
  final _searchController = TextEditingController();
  Timer? _debounce;
  String _query = '';
  List<SchedulingStaff> _items = const [];
  String? _nextCursor;
  bool _loading = true;
  bool _loadingMore = false;
  bool _busy = false;
  bool _loadedOnce = false;
  String? _error;
  final _selected = <int>{};

  String get _unitLabel =>
      widget.unit == 'clinic' ? 'Clinic' : 'Guidance';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final page = await ApiService.I.schedulingStaff(
        widget.unit,
        q: _query,
      );
      if (!mounted) return;
      setState(() {
        _items = page.items;
        _nextCursor = page.nextCursor;
        _loading = false;
        _loadedOnce = true;
        _selected.removeWhere(
          (id) => !page.items.any((row) => row.userId == id && row.selectable),
        );
      });
    } catch (e) {
      if (!mounted) return;
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
      final page = await ApiService.I.schedulingStaff(
        widget.unit,
        q: _query,
        cursor: _nextCursor,
      );
      if (!mounted) return;
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.nextCursor;
      });
    } catch (e) {
      if (kDebugMode) debugPrint('SchedulingStaffScreen.loadMore failed: $e');
    } finally {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  void _onSearchChanged(String value) {
    setState(() => _query = value.trim());
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), _load);
  }

  void _toggleSelection(SchedulingStaff row) {
    if (!row.selectable) return;
    setState(() {
      if (!_selected.add(row.userId)) _selected.remove(row.userId);
    });
  }

  Future<void> _enrollSelected() async {
    if (_selected.isEmpty || _busy) return;
    setState(() => _busy = true);
    try {
      final ids = _selected.toList()..sort();
      final result = await ApiService.I.enrollSchedulingStaff(
        widget.unit,
        ids,
      );
      if (!mounted) return;
      setState(() => _selected.clear());
      _showMessage(
        'Enrollment: ${result.created} created, ${result.skipped} skipped.',
      );
      await _load();
    } on ApiException catch (e) {
      _showMessage(e.message, error: true);
    } catch (e) {
      _showMessage(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _toggleEnrollment(SchedulingStaff row, bool active) async {
    final assignmentId = row.assignmentId;
    if (assignmentId == null || _busy) return;
    setState(() => _busy = true);
    try {
      await ApiService.I.setSchedulingStaffStatus(
        widget.unit,
        assignmentId,
        active,
      );
      if (!mounted) return;
      _showMessage(
        active
            ? 'Enrollment re-enabled. Existing schedules are retained.'
            : 'Enrollment disabled. Existing schedules and appointments are retained.',
      );
      await _load();
    } on ApiException catch (e) {
      _showMessage(e.message, error: true);
    } catch (e) {
      _showMessage(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _applyTemplate(SchedulingStaff row) async {
    final payload = await showSynapseSheet<Map<String, dynamic>>(
      context,
      builder: (_) => _TemplateSheet(unit: widget.unit, name: row.name),
    );
    if (payload == null || !mounted) return;
    setState(() => _busy = true);
    try {
      final result = await ApiService.I.applySchedulingTemplate(
        widget.unit,
        {...payload, 'user_ids': [row.userId]},
      );
      if (!mounted) return;
      _showMessage(
        'Schedule windows: ${result.created} created, '
        '${result.skipped} existing days preserved.',
      );
    } on ApiException catch (e) {
      _showMessage(e.message, error: true);
    } catch (e) {
      _showMessage(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _showMessage(String message, {bool error = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: error ? const Color(0xFFB3261E) : null,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('$_unitLabel scheduling staff'),
        actions: [
          IconButton(
            onPressed: _load,
            tooltip: 'Refresh',
            icon: const Icon(HugeIcons.strokeRoundedRefresh),
          ),
        ],
      ),
      bottomNavigationBar: _selected.isEmpty
          ? null
          : SafeArea(
              top: false,
              child: Container(
                padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
                decoration: const BoxDecoration(
                  color: Colors.white,
                  border: Border(top: BorderSide(color: Color(0xFFE7E5E4))),
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        '${_selected.length} selected',
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                    ),
                    FilledButton.icon(
                      onPressed: _busy ? null : _enrollSelected,
                      icon: const Icon(HugeIcons.strokeRoundedUserAdd01,
                          size: 18),
                      label: const Text('Enroll selected'),
                    ),
                  ],
                ),
              ),
            ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: TextField(
              controller: _searchController,
              onChanged: _onSearchChanged,
              decoration: InputDecoration(
                hintText: 'Search name, number, department…',
                prefixIcon: const Icon(HugeIcons.strokeRoundedSearch01),
                isDense: true,
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
                suffixIcon: _query.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(HugeIcons.strokeRoundedCancel01),
                        onPressed: () {
                          _searchController.clear();
                          _onSearchChanged('');
                        },
                      ),
              ),
            ),
          ),
          Expanded(
            child: RefreshIndicator(onRefresh: _load, child: _buildBody()),
          ),
        ],
      ),
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty(
        _query.isEmpty
            ? 'No staff are available for this unit.'
            : 'No staff match this search.',
      );
    }
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 20),
      itemCount: _items.length + (_nextCursor != null ? 1 : 0),
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
        final row = _items[i];
        return _StaffTile(
          row: row,
          unit: widget.unit,
          selected: _selected.contains(row.userId),
          busy: _busy,
          onSelect: () => _toggleSelection(row),
          onToggle: (active) => _toggleEnrollment(row, active),
          onTemplate: () => _applyTemplate(row),
        );
      },
    );
  }
}

class _StaffTile extends StatelessWidget {
  const _StaffTile({
    required this.row,
    required this.unit,
    required this.selected,
    required this.busy,
    required this.onSelect,
    required this.onToggle,
    required this.onTemplate,
  });

  final SchedulingStaff row;
  final String unit;
  final bool selected;
  final bool busy;
  final VoidCallback onSelect;
  final ValueChanged<bool> onToggle;
  final VoidCallback onTemplate;

  @override
  Widget build(BuildContext context) {
    final contextParts = [
      if (row.employeeNumber?.isNotEmpty ?? false) row.employeeNumber!,
      if (row.department?.isNotEmpty ?? false) row.department!,
      if (row.position?.isNotEmpty ?? false) row.position!,
    ];
    final activity = unit == 'clinic'
        ? '${row.futureAppointments} active shift(s)'
        : '${row.futureAppointments} upcoming appointment(s)';
    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 8),
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
        contentPadding: const EdgeInsets.fromLTRB(8, 4, 8, 4),
        leading: row.selectable
            ? Checkbox(
                value: selected,
                onChanged: busy ? null : (_) => onSelect(),
              )
            : CircleAvatar(
                radius: 18,
                backgroundColor: const Color(0xFFF1F0EE),
                child: Text(
                  _initials(row.name),
                  style: const TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: Colors.black54,
                  ),
                ),
              ),
        title: Text(
          row.name,
          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700),
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 3),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                contextParts.isEmpty ? '—' : contextParts.join(' · '),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 11.5),
              ),
              const SizedBox(height: 2),
              Text(
                activity,
                style: const TextStyle(fontSize: 11, color: Colors.black45),
              ),
            ],
          ),
        ),
        isThreeLine: true,
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            StatusBadge(
              label: _statusLabel(row.status),
              color: _statusColor(row.status),
            ),
            if (row.enrolled)
              PopupMenuButton<String>(
                enabled: !busy,
                icon: const Icon(HugeIcons.strokeRoundedMore),
                onSelected: (action) {
                  switch (action) {
                    case 'toggle':
                      onToggle(!row.enrollmentActive);
                    case 'template':
                      onTemplate();
                  }
                },
                itemBuilder: (_) => [
                  PopupMenuItem(
                    value: 'toggle',
                    child: Text(
                      row.enrollmentActive ? 'Disable enrollment'
                          : 'Re-enable enrollment',
                    ),
                  ),
                  if (row.enrollmentActive)
                    const PopupMenuItem(
                      value: 'template',
                      child: Text('Apply weekly template'),
                    ),
                ],
              ),
          ],
        ),
      ),
    );
  }

  static String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+'));
    if (parts.isEmpty || parts.first.isEmpty) return '?';
    final first = parts.first[0].toUpperCase();
    final last = parts.length > 1 && parts.last.isNotEmpty
        ? parts.last[0].toUpperCase()
        : '';
    return '$first$last';
  }

  static String _statusLabel(String status) => switch (status) {
        'not_enrolled' => 'Not enrolled',
        'ready' => 'Ready',
        'configured' => 'Configured',
        'inactive_employee' => 'Inactive employee',
        'inactive_enrollment' => 'Enrollment off',
        'department_mismatch' => 'Dept mismatch',
        'needs_review' => 'Needs review',
        _ => titleCaseOption(status),
      };

  static Color _statusColor(String status) => switch (status) {
        'ready' => const Color(0xFF1E6FD9),
        'configured' => const Color(0xFF1B7A43),
        'inactive_employee' => const Color(0xFFB3261E),
        'inactive_enrollment' => const Color(0xFFB45309),
        'department_mismatch' || 'needs_review' => const Color(0xFF5B4BA6),
        _ => Colors.black45,
      };
}

class _TemplateSheet extends StatefulWidget {
  const _TemplateSheet({required this.unit, required this.name});

  final String unit;
  final String name;

  @override
  State<_TemplateSheet> createState() => _TemplateSheetState();
}

class _TemplateSheetState extends State<_TemplateSheet> {
  static const _days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  final _selectedDays = <int>{};
  final _startController = TextEditingController(text: '08:00');
  final _endController = TextEditingController(text: '17:00');
  String _type = 'regular';
  String? _error;

  @override
  void dispose() {
    _startController.dispose();
    _endController.dispose();
    super.dispose();
  }

  void _submit() {
    final start = _startController.text.trim();
    final end = _endController.text.trim();
    final time = RegExp(r'^(?:[01]\d|2[0-3]):[0-5]\d$');
    if (_selectedDays.isEmpty) {
      setState(() => _error = 'Select at least one weekday.');
      return;
    }
    if (!time.hasMatch(start) || !time.hasMatch(end)) {
      setState(() => _error = 'Use a valid HH:mm time.');
      return;
    }
    if (start.compareTo(end) >= 0) {
      setState(() => _error = 'End time must be after start time.');
      return;
    }
    Navigator.of(context).pop(<String, dynamic>{
      'days_of_week': (_selectedDays.toList()..sort()),
      'start_time': start,
      'end_time': end,
      if (widget.unit == 'clinic') 'schedule_type': _type,
    });
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 14, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SheetHeader(
              title: 'Weekly template',
              subtitle: widget.name,
            ),
            const SizedBox(height: 12),
            const Text(
              'Creates recurring windows only for days without an existing '
              'schedule; existing rows are preserved.',
              style: TextStyle(fontSize: 12, color: Colors.black54),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (var i = 0; i < _days.length; i++)
                  FilterChip(
                    label: Text(_days[i]),
                    selected: _selectedDays.contains(i),
                    onSelected: (value) => setState(() {
                      if (value) {
                        _selectedDays.add(i);
                      } else {
                        _selectedDays.remove(i);
                      }
                    }),
                  ),
              ],
            ),
            const SizedBox(height: 14),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _startController,
                    decoration: const InputDecoration(
                      labelText: 'Start (HH:mm)',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: TextField(
                    controller: _endController,
                    decoration: const InputDecoration(
                      labelText: 'End (HH:mm)',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                  ),
                ),
              ],
            ),
            if (widget.unit == 'clinic') ...[
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                initialValue: _type,
                decoration: const InputDecoration(
                  labelText: 'Schedule type',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
                items: const [
                  DropdownMenuItem(value: 'regular', child: Text('Regular')),
                  DropdownMenuItem(value: 'on_call', child: Text('On call')),
                  DropdownMenuItem(value: 'leave', child: Text('Leave')),
                ],
                onChanged: (v) => setState(() => _type = v ?? 'regular'),
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(
                _error!,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 13,
                ),
              ),
            ],
            const SizedBox(height: 14),
            FilledButton(
              onPressed: _submit,
              child: const Text('Apply template'),
            ),
          ],
        ),
      ),
    );
  }
}
