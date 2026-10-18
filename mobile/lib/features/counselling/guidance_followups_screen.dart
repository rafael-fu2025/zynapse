import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_envelope.dart';
import '../../core/models/guidance_followup.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Guidance follow-ups — the RA 11036 §24 aftercare caseload.
/// Mirrors the SPA `FollowupsTab` and `useGuidanceFollowups`.
class GuidanceFollowupsScreen extends StatefulWidget {
  const GuidanceFollowupsScreen({super.key});

  @override
  State<GuidanceFollowupsScreen> createState() =>
      _GuidanceFollowupsScreenState();
}

class _GuidanceFollowupsScreenState extends State<GuidanceFollowupsScreen> {
  String _status = 'all';
  bool _mineOnly = false;
  bool _loading = true;
  bool _busy = false;
  String? _error;
  List<GuidanceFollowup> _items = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = _items.isEmpty;
      _error = null;
    });
    try {
      final items = await ApiService.I.guidanceFollowups(
        status: _status,
        mineOnly: _mineOnly,
      );
      if (!mounted) return;
      setState(() {
        _items = items;
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

  Future<void> _transition(GuidanceFollowup item, String action) async {
    String? note;
    if (action == 'mark_addressed' || action == 'close') {
      final payload = await showCrudForm(
        context,
        title: action == 'mark_addressed'
            ? 'Mark follow-up addressed'
            : 'Close the loop',
        fields: const [
          CrudField.text('outcome_note', 'Outcome note'),
        ],
        submitLabel: action == 'mark_addressed' ? 'Mark addressed' : 'Close',
      );
      if (payload == null || !mounted) return;
      note = (payload['outcome_note'] as String?)?.trim();
    }
    setState(() => _busy = true);
    try {
      await ApiService.I.transitionGuidanceFollowup(
        item.id,
        action,
        outcomeNote: note,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Follow-up updated.')),
      );
      await _load();
    } on ApiException catch (e) {
      _snack(e.message, error: true);
    } catch (e) {
      _snack(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _assignToMe(GuidanceFollowup item) async {
    final session = context.read<AuthController>().session;
    if (session == null || _busy) return;
    setState(() => _busy = true);
    try {
      await ApiService.I.assignGuidanceFollowup(item.id, session.id);
      if (!mounted) return;
      _snack('Follow-up assigned to you.');
      await _load();
    } on ApiException catch (e) {
      _snack(e.message, error: true);
    } catch (e) {
      _snack(mapDioError(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _snack(String message, {bool error = false}) {
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
        title: const Text('Guidance follow-ups'),
        actions: [
          IconButton(
            onPressed: _load,
            tooltip: 'Refresh',
            icon: const Icon(HugeIcons.strokeRoundedRefresh),
          ),
        ],
      ),
      body: Column(
        children: [
          SizedBox(
            height: 44,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              children: [
                for (final (value, label) in const [
                  ('all', 'All'),
                  ('new', 'New'),
                  ('in_review', 'In review'),
                  ('addressed', 'Addressed'),
                  ('closed', 'Closed'),
                ])
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: FilterChip(
                      label: Text(label),
                      selected: _status == value,
                      onSelected: (_) {
                        setState(() => _status = value);
                        _load();
                      },
                    ),
                  ),
                FilterChip(
                  label: const Text('Assigned to me'),
                  selected: _mineOnly,
                  onSelected: (value) {
                    setState(() => _mineOnly = value);
                    _load();
                  },
                ),
              ],
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
      return AsyncState.empty('No follow-ups in this view.');
    }
    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: _items.length,
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      itemBuilder: (context, i) {
        final item = _items[i];
        return _FollowupCard(
          item: item,
          busy: _busy,
          onStart: () => _transition(item, 'start_review'),
          onAddressed: () => _transition(item, 'mark_addressed'),
          onClose: () => _transition(item, 'close'),
          onAssignToMe: () => _assignToMe(item),
        );
      },
    );
  }
}

class _FollowupCard extends StatelessWidget {
  const _FollowupCard({
    required this.item,
    required this.busy,
    required this.onStart,
    required this.onAddressed,
    required this.onClose,
    required this.onAssignToMe,
  });

  final GuidanceFollowup item;
  final bool busy;
  final VoidCallback onStart;
  final VoidCallback onAddressed;
  final VoidCallback onClose;
  final VoidCallback onAssignToMe;

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
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 8, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    item.studentName,
                    style: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                StatusBadge(
                  label: titleCaseOption(item.status),
                  color: _statusColor(item.status),
                ),
                PopupMenuButton<String>(
                  enabled: !busy,
                  icon: const Icon(HugeIcons.strokeRoundedMore),
                  onSelected: (action) {
                    if (action == 'assign') onAssignToMe();
                    if (action == 'start') onStart();
                    if (action == 'addressed') onAddressed();
                    if (action == 'close') onClose();
                  },
                  itemBuilder: (_) => [
                    if (item.assignedCounsellorUserId == null)
                      const PopupMenuItem(
                        value: 'assign',
                        child: Text('Assign to me'),
                      ),
                    if (item.status == 'new')
                      const PopupMenuItem(
                        value: 'start',
                        child: Text('Start review'),
                      ),
                    if (item.status == 'in_review')
                      const PopupMenuItem(
                        value: 'addressed',
                        child: Text('Mark addressed'),
                      ),
                    if (item.status == 'addressed')
                      const PopupMenuItem(
                        value: 'close',
                        child: Text('Close loop'),
                      ),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              item.surveyTitle,
              style: const TextStyle(fontSize: 12.5, color: Colors.black54),
            ),
            const SizedBox(height: 4),
            Text(
              item.riskReason,
              style: const TextStyle(fontSize: 12.5),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 6,
              children: [
                if (item.who5Score != null)
                  StatusBadge(
                    label: 'WHO-5 ${item.who5Score}',
                    color: const Color(0xFF8A5A00),
                  ),
                if (item.dueAt != null)
                  StatusBadge(
                    label: 'Due ${fmtUtcShort(item.dueAt)}',
                    color: const Color(0xFF1E6FD9),
                  ),
                StatusBadge(
                  label: item.assignedCounsellor == null
                      ? 'Unassigned'
                      : 'Assigned: ${item.assignedCounsellor}',
                  color: item.assignedCounsellor == null
                      ? Colors.black45
                      : const Color(0xFF5B4BA6),
                ),
              ],
            ),
            if (item.outcomeNote != null &&
                item.outcomeNote!.trim().isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(
                'Outcome: ${item.outcomeNote}',
                style: const TextStyle(fontSize: 12, color: Colors.black54),
              ),
            ],
          ],
        ),
      ),
    );
  }

  static Color _statusColor(String status) => switch (status) {
        'new' => const Color(0xFFB3261E),
        'in_review' => const Color(0xFFB45309),
        'addressed' => const Color(0xFF1B7A43),
        'closed' => Colors.black45,
        _ => Colors.black45,
      };
}
