import 'dart:async';

import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../../core/models/queue.dart';
import '../../core/services/api_service.dart';
import '../common/auto_polling.dart';
import '../common/widgets.dart';

/// Live "Your queue" card — mirrors the web portal's `YourQueueCard`.
///
/// Polls `/me/queue-status` (employee) or `/me/student-queue-status`
/// (student) every 10 seconds so the caller can track their turn without
/// refreshing — the same behaviour as the web `useMyQueueStatus` hook.
/// Hides itself entirely when the caller is not queued today.
class YourQueueSection extends StatefulWidget {
  const YourQueueSection({super.key, required this.student});

  final bool student;

  @override
  State<YourQueueSection> createState() => _YourQueueSectionState();
}

class _YourQueueSectionState extends State<YourQueueSection> {
  List<MyQueueStatus> _queues = const [];
  bool _loading = true;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _fetch();
    _timer = Timer.periodic(const Duration(seconds: 10), (_) {
      // Skip while the tab is hidden so we don't poll in the background.
      if (mounted && !TabVisibilityScope.isHidden(context)) _fetch();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _fetch() async {
    try {
      final s = await ApiService.I.myQueues();
      if (!mounted) return;
      setState(() {
        _queues = s;
        _loading = false;
      });
    } catch (_) {
      // Transient network errors: keep the last known value, stop loading.
      if (!mounted) return;
      setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading || _queues.isEmpty) {
      return const SizedBox.shrink();
    }
    return Column(children: _queues.map(_card).toList());
  }

  Widget _card(MyQueueStatus status) {
    final destination = status.destination == 'clinic' ? 'Clinic' : 'Guidance';
    final (color, icon, title, subtitle) = switch (status.status) {
      'called' => (
          const Color(0xFFB3261E),
          HugeIcons.strokeRoundedMegaphone01,
          "You're up — ${status.queueNumber}",
          'Please proceed to $destination',
        ),
      'in_session' => (
          const Color(0xFF1E6FD9),
          HugeIcons.strokeRoundedStethoscope,
          'In session — ${status.queueNumber}',
          'In session with $destination',
        ),
      _ => (
          const Color(0xFF1B7A43),
          HugeIcons.strokeRoundedHourglass,
          "You're in the queue ${status.queueNumber}",
          status.estimatedWaitMinutes != null
              ? '~${status.estimatedWaitMinutes} min · ${status.peopleAhead} ahead'
              : '${status.peopleAhead} ahead',
        ),
    };

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: SectionCard(
        title: '$destination queue',
        icon: HugeIcons.strokeRoundedUserCheck01,
        child: Row(
          children: [
            CircleAvatar(
              radius: 22,
              backgroundColor: color.withValues(alpha: 0.12),
              child: Icon(icon, color: color),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: const TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 16,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    subtitle,
                    style: const TextStyle(color: Colors.black54),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
