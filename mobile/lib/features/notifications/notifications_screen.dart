import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/notification.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/notifications_copy.dart';
import '../common/auto_polling.dart';
import '../common/widgets.dart';
import '../portal/portal_screen.dart';
import '../appointments/appointments_screen.dart';
import '../counselling/counselling_screen.dart';

/// Notifications — self-scoped in-app notifications with keyset pagination.
///
/// Mirrors `frontend/src/pages/NotificationsPage.tsx` +
/// `useNotificationsPage` (`GET /notifications?limit=&cursor=`,
/// `POST /notifications/{id}/read`, `POST /notifications/read-all`).
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen>
    with AutoPolling<NotificationsScreen> {
  bool _loading = true;
  String? _error;
  List<AppNotification> _items = [];
  String? _nextCursor;
  bool _loadingMore = false;
  bool _loadedOnce = false;

  @override
  void initState() {
    super.initState();
    _load();
    // Live updates — mirrors the SPA's 60s `refetchInterval`.
    startPolling(const Duration(seconds: 60), _silentRefresh);
  }

  /// Polled live update: silently refetch the first page so new/read-state
  /// notifications appear without a manual refresh. Skipped once the user has
  /// paginated deeper (pull-to-refresh there) so the list never collapses.
  Future<void> _silentRefresh() async {
    if (_items.length > 25) return;
    try {
      final page = await ApiService.I.notifications(limit: 25);
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
      final page = await ApiService.I.notifications(limit: 25);
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
      final page = await ApiService.I.notifications(limit: 25, cursor: _nextCursor);
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (_) {
      // ignore; pull-to-refresh recovers.
    } finally {
      setState(() => _loadingMore = false);
    }
  }

  Future<void> _markRead(AppNotification n) async {
    if (n.isRead) return;
    // Optimistic local update.
    setState(() {
      _items = [
        for (final item in _items)
          item.id == n.id
              ? AppNotification(
                  id: item.id,
                  templateCode: item.templateCode,
                  context: item.context,
                  readAt: DateTime.now().toIso8601String(),
                  createdAt: item.createdAt,
                )
              : item,
      ];
    });
    try {
      await ApiService.I.markNotificationRead(n.id);
    } catch (_) {
      // ignore errors for the demo.
    }
  }

  Future<void> _open(AppNotification n) async {
    await _markRead(n);
    if (!n.templateCode.startsWith('appointment.') || !mounted) return;
    final session=context.read<AuthController>().session;
    final Widget page;
    if(session?.hasPermission('portal.appointments.read')??false){page=const PortalScreen();}
    else if(n.context?['destination']=='counselling'&&(session?.hasPermission('counselling.schedule.read')??false)){page=const CounsellingScreen();}
    else if(session?.hasPermission('clinic.appointments.read')??false){page=const AppointmentsScreen();}
    else{return;}
    await Navigator.of(context).push(MaterialPageRoute(builder:(_)=>page));
  }

  Future<void> _markAllRead() async {
    try {
      await ApiService.I.markAllNotificationsRead();
    } catch (_) {
      // ignore
    }
    setState(() {
      _items = [
        for (final item in _items)
          AppNotification(
            id: item.id,
            templateCode: item.templateCode,
            context: item.context,
            readAt: item.readAt ?? DateTime.now().toIso8601String(),
            createdAt: item.createdAt,
          ),
      ];
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          if (_items.any((n) => !n.isRead))
            TextButton(
              onPressed: _markAllRead,
              style: TextButton.styleFrom(foregroundColor: Colors.white),
              child: const Text('Mark all read'),
            ),
          const SizedBox(width: 8),
        ],
      ),
      body: Column(
        children: [
          Expanded(
            child: RefreshIndicator(
              onRefresh: _load,
              child: _buildBody(),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) return AsyncState.empty('No notifications yet.');

    return ListView.separated(
      padding: const EdgeInsets.all(16),
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
        final n = _items[i];
        return _NotificationCard(notification: n, onTap: () => _open(n));
      },
    );
  }
}

class _NotificationCard extends StatelessWidget {
  const _NotificationCard({required this.notification, required this.onTap});

  final AppNotification notification;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final label = notificationLabel(notification.templateCode);
    final detail = notificationDetail(
      notification.templateCode,
      notification.context,
      notification.createdAt,
    );

    return Card(
      elevation: 0,
      margin: EdgeInsets.zero,
      color: notification.isRead
          ? scheme.surface
          : scheme.primary.withValues(alpha: 0.06),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color: scheme.outlineVariant.withValues(alpha: 0.5),
        ),
      ),
      child: ListTile(
        onTap: onTap,
        leading: CircleAvatar(
          backgroundColor: notification.isRead
              ? scheme.surfaceContainerHighest
              : scheme.primary.withValues(alpha: 0.15),
          child: Icon(
            notification.isRead
                ? HugeIcons.strokeRoundedNotification01
                : HugeIcons.strokeRoundedNotification02,
            color: notification.isRead ? Colors.black45 : scheme.primary,
          ),
        ),
        title: Row(
          children: [
            Expanded(
              child: Text(
                label,
                style: TextStyle(
                  fontWeight:
                      notification.isRead ? FontWeight.w500 : FontWeight.w700,
                ),
              ),
            ),
            if (!notification.isRead)
              Container(
                width: 10,
                height: 10,
                decoration: BoxDecoration(
                  color: scheme.primary,
                  shape: BoxShape.circle,
                ),
              ),
          ],
        ),
        subtitle: Text(detail, style: const TextStyle(color: Colors.black54)),
      ),
    );
  }
}
