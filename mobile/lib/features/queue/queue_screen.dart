import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_envelope.dart';
import '../../core/models/queue.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';
import 'your_queue_section.dart';

/// Format a queue position as `C-001` (zero-padded to 3 digits) — matches the
/// web's `formatQueueNumber` (KioskCheckin.tsx) so the mobile Queue screen
/// shows the same queue number as the web queue display.
String formatQueueNumber(int position) {
  if (position <= 0) return 'C-???';
  return 'C-${position.toString().padLeft(3, '0')}';
}

/// Queue — the public waiting-room feed plus the caller's own queue status.
///
/// * Public state: `GET /clinic/queue/state` (no auth — the same feed the
///   lobby TV / kiosk polls, so this tab works for every account).
/// * My status: `GET /me/queue-status` (employee) or
///   `/me/student-queue-status` (student) — the portal "Your queue" card.
class QueueScreen extends StatefulWidget {
  const QueueScreen({super.key});

  @override
  State<QueueScreen> createState() => _QueueScreenState();
}

class _QueueScreenState extends State<QueueScreen>
    with AutoPolling<QueueScreen> {
  bool _loading = true;
  String? _error;
  PublicQueueState? _public;
  List<QueueEntry>? _today;
  bool _staffMode = false;
  bool _canQueue = false;
  bool _isStudent = false;
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
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final public = await ApiService.I.publicQueueState();
      List<QueueEntry>? today;
      if (_staffMode) today = await ApiService.I.queueToday();
      if (!mounted) return;
      setState(() {
        _public = public;
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
  /// both the public waiting room and (for staff) today's queue so new
  /// patients / transitions appear without a manual refresh.
  Future<void> _silentRefresh() async {
    try {
      final public = await ApiService.I.publicQueueState();
      List<QueueEntry>? today;
      if (_staffMode) today = await ApiService.I.queueToday();
      if (!mounted) return;
      setState(() {
        _public = public;
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
        SnackBar(content: Text('Queue ${formatQueueNumber(entry.position)} ${action}ed.')),
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
                onRefresh: _reload,
              ),
          ],
          if (_canQueue) YourQueueSection(student: _isStudent),
          const SizedBox(height: 12),
          if (_loading)
            SizedBox(height: 320, child: AsyncState.loading())
          else if (_error != null)
            SizedBox(
              height: 320,
              child: AsyncState.error(_error!, onRetry: _load),
            )
          else
            _PublicQueueView(state: _public ?? PublicQueueState(waiting: [])),
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
    required this.onRefresh,
  });

  final List<QueueEntry> entries;
  final VoidCallback onCallNext;
  final void Function(QueueEntry entry, String action) onAction;
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
                _QueueRow(entry: entry, onAction: onAction),
          ],
        ),
      ),
    );
  }
}

class _QueueRow extends StatelessWidget {
  const _QueueRow({required this.entry, required this.onAction});

  final QueueEntry entry;
  final void Function(QueueEntry entry, String action) onAction;

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
          else
            StatusBadge(label: titleCaseOption(entry.status), color: color),
        ],
      ),
    );
  }
}


class _PublicQueueView extends StatelessWidget {
  const _PublicQueueView({required this.state});

  final PublicQueueState state;

  @override
  Widget build(BuildContext context) {
    final nowServing = state.nowServing;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Waiting room',
          style: Theme.of(context)
              .textTheme
              .titleMedium
              ?.copyWith(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 4),
        Text(
          'Updated ${state.updatedAt != null ? fmtUtcShort(state.updatedAt) : ''}'
          ' · feeds the lobby display',
          style: Theme.of(context)
              .textTheme
              .bodySmall
              ?.copyWith(color: Colors.black45),
        ),
        const SizedBox(height: 12),

        if (nowServing != null)
          SectionCard(
            title: 'Now serving',
            icon: HugeIcons.strokeRoundedMegaphone01,
            child: Row(
              children: [
                Text(
                  formatQueueNumber(nowServing.position),
                  style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: Theme.of(context).colorScheme.primary,
                      ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        nowServing.displayName,
                        style: const TextStyle(
                          fontWeight: FontWeight.w600,
                          fontSize: 16,
                        ),
                      ),
                      Text(
                        'ID ${nowServing.patientSchoolId}',
                        style: const TextStyle(color: Colors.black45),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),

        const SizedBox(height: 12),

        if (state.waiting.isEmpty)
          AsyncState.empty('The waiting room is empty.',
              icon: HugeIcons.strokeRoundedCheckmarkCircle01)
        else
          Card(
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
            child: Column(
              children: [
                for (final (i, w) in state.waiting.indexed) ...[
                  if (i > 0)
                    const Divider(height: 1, indent: 16, endIndent: 16),
                  ListTile(
                    leading: Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: Theme.of(context)
                            .colorScheme
                            .primary
                            .withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Text(
                        formatQueueNumber(w.position),
                        style: const TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                          color: Color(0xFF800000),
                        ),
                      ),
                    ),
                    title: Text(w.displayName),
                    subtitle: Text('ID ${w.patientSchoolId}'),
                    trailing: w.estWaitMinutes != null
                        ? Text(
                            '~${w.estWaitMinutes} min',
                            style: const TextStyle(
                              fontWeight: FontWeight.w600,
                              color: Colors.black54,
                            ),
                          )
                        : null,
                  ),
                ],
              ],
            ),
          ),
      ],
    );
  }
}
