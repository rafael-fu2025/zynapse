import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_envelope.dart';
import '../../core/models/queue.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';
import 'your_queue_section.dart';

/// Format a queue position as `C-001` (zero-padded to 3 digits) — matches the
/// web's `formatQueueNumber` (lib/queueFormat.ts) so the mobile Queue screen
/// shows the same queue number as the web staff queue.
String formatQueueNumber(int position) {
  if (position <= 0) return 'C-???';
  return 'C-${position.toString().padLeft(3, '0')}';
}

/// Queue — the caller's own queue status, plus the staff today's-queue
/// board for `clinic.queue.manage` holders.
///
/// My status: `GET /me/queue-status` (employee) or
/// `/me/student-queue-status` (student) — the portal "Your queue" card.
class QueueScreen extends StatefulWidget {
  const QueueScreen({super.key});

  @override
  State<QueueScreen> createState() => _QueueScreenState();
}

class _QueueScreenState extends State<QueueScreen>
    with AutoPolling<QueueScreen> {
  bool _loading = true;
  String? _error;
  List<QueueEntry>? _today;
  bool _staffMode = false;
  bool _canQueue = false;
  bool _isStudent = false;
  bool _canWriteEncounters = false;
  bool _bootstrapped = false;
  bool _loadedOnce = false;

  @override
  void initState() {
    super.initState();
    // Live updates — mirrors the SPA's 10s `refetchInterval` on the queue.
    startPolling(const Duration(seconds: 10), _silentRefresh);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final session = context.read<AuthController>().session;
    _canQueue = session != null &&
        (session.hasPermission('employee.portal.read') ||
            session.hasPermission('student.portal.read'));
    if (_canQueue) _isStudent = session!.isStudent;
    _canWriteEncounters =
        session?.hasPermission('clinic.encounters.write') ?? false;
    final staff = session?.hasPermission('clinic.queue.manage') ?? false;
    if (staff != _staffMode) {
      _staffMode = staff;
      if (_bootstrapped) _load();
    }
    if (!_bootstrapped) {
      _bootstrapped = true;
      _load();
    }
  }

  Future<void> _load() async {
    if (!_staffMode) {
      if (!mounted) return;
      setState(() {
        _loadedOnce = true;
        _loading = false;
      });
      return;
    }
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final today = await ApiService.I.queueToday();
      if (!mounted) return;
      setState(() {
        _today = today;
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

  /// Polled live update (mirrors the SPA's 10s queue polling): silently swap
  /// today's staff queue so new patients / transitions appear without a
  /// manual refresh.
  Future<void> _silentRefresh() async {
    if (!_staffMode) return;
    try {
      final today = await ApiService.I.queueToday();
      if (!mounted) return;
      setState(() {
        _today = today;
      });
    } catch (e) {
      // Keep the last known queue state.
      if (kDebugMode) debugPrint('QueueScreen.poll failed: $e');
    }
  }

  void _reload() {
    _load();
  }

  /// Calls the next waiting patient into the consultation room.
  Future<void> _callNext() async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      await ApiService.I.queueCallNext();
      messenger.showSnackBar(
        const SnackBar(content: Text('Next patient called.')),
      );
      _reload();
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(mapDioError(e).message)));
    }
  }

  /// Applies a queue transition (start | skip | complete).
  Future<void> _queueAction(QueueEntry entry, String action) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      await ApiService.I.queueTransition(id: entry.id, action: action);
      messenger.showSnackBar(
        SnackBar(
            content: Text(
                'Queue ${formatQueueNumber(entry.position)} ${action}ed.')),
      );
      _reload();
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(mapDioError(e).message)));
    }
  }

  /// Archives a completed encounter directly from the queue board.
  Future<void> _archiveEncounter(QueueEntry entry) async {
    final messenger = ScaffoldMessenger.of(context);
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Archive Encounter?'),
        content: const Text(
          'Are you sure you want to archive this completed encounter?',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Archive'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;

    try {
      await ApiService.I.archiveEncounter(entry.encounterId);
      messenger.showSnackBar(
        const SnackBar(content: Text('Encounter archived.')),
      );
      _reload();
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(mapDioError(e).message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_staffMode) ...[
            if (_loading)
              const Padding(
                padding: EdgeInsets.only(bottom: 12),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (_error != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: AsyncState.error(_error!, onRetry: _load),
              )
            else
              _StaffQueueCard(
                entries: _today ?? const [],
                onCallNext: _callNext,
                onAction: _queueAction,
                onArchive: _canWriteEncounters ? _archiveEncounter : null,
                onRefresh: _reload,
              ),
          ],
          if (_canQueue) YourQueueSection(student: _isStudent),
        ],
      ),
    );
  }
}

/// Staff today's-queue management card — call-next + start/skip/complete.
class _StaffQueueCard extends StatelessWidget {
  const _StaffQueueCard({
    required this.entries,
    required this.onCallNext,
    required this.onAction,
    this.onArchive,
    required this.onRefresh,
  });

  final List<QueueEntry> entries;
  final VoidCallback onCallNext;
  final void Function(QueueEntry entry, String action) onAction;
  final void Function(QueueEntry entry)? onArchive;
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    final waitingCount = entries.where((e) => e.status == 'waiting').length;
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: SectionCard(
        title: "Today's queue ($waitingCount waiting)",
        icon: HugeIcons.strokeRoundedPlaylist01,
        trailing: IconButton(
          tooltip: 'Refresh',
          icon: const Icon(HugeIcons.strokeRoundedRefresh),
          onPressed: onRefresh,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            FilledButton.icon(
              onPressed: onCallNext,
              icon: const Icon(HugeIcons.strokeRoundedMegaphone01),
              label: const Text('Call next'),
            ),
            const SizedBox(height: 12),
            if (entries.isEmpty)
              const Text(
                'The queue is empty.',
                style: TextStyle(color: Colors.black54),
              )
            else
              for (final entry in entries)
                _QueueRow(
                  entry: entry,
                  onAction: onAction,
                  onArchive: onArchive,
                ),
          ],
        ),
      ),
    );
  }
}

class _QueueRow extends StatelessWidget {
  const _QueueRow({
    required this.entry,
    required this.onAction,
    this.onArchive,
  });

  final QueueEntry entry;
  final void Function(QueueEntry entry, String action) onAction;
  final void Function(QueueEntry entry)? onArchive;

  @override
  Widget build(BuildContext context) {
    final color = switch (entry.status) {
      'called' => const Color(0xFFB3261E),
      'in_session' => const Color(0xFF1E6FD9),
      'done' || 'skipped' => Colors.grey,
      _ => const Color(0xFF1B7A43),
    };
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
            decoration: BoxDecoration(
              color: color.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(999),
            ),
            child: Text(
              formatQueueNumber(entry.position),
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w700,
                color: color,
              ),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  entry.displayName,
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
                Text(
                  '#${entry.patientSchoolId} · ${entry.chiefComplaint}',
                  style: const TextStyle(fontSize: 11, color: Colors.black54),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
          if (entry.canStart)
            TextButton(
              onPressed: () => onAction(entry, 'start'),
              child: const Text('Start'),
            )
          else if (entry.canComplete)
            TextButton(
              onPressed: () => onAction(entry, 'complete'),
              child: const Text('Complete'),
            )
          else if (entry.canSkip)
            TextButton(
              onPressed: () => onAction(entry, 'skip'),
              child: const Text('Skip'),
            )
          else if (entry.canArchive && onArchive != null) ...[
            StatusBadge(label: titleCaseOption(entry.status), color: color),
            const SizedBox(width: 4),
            TextButton.icon(
              style: TextButton.styleFrom(
                visualDensity: VisualDensity.compact,
                padding: const EdgeInsets.symmetric(horizontal: 8),
              ),
              onPressed: () => onArchive!(entry),
              icon: const Icon(Icons.archive_outlined, size: 16),
              label: const Text('Archive'),
            ),
          ]
          else
            StatusBadge(label: titleCaseOption(entry.status), color: color),
        ],
      ),
    );
  }
}
