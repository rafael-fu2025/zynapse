import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_envelope.dart';
import '../../core/models/session.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../appointments/appointments_screen.dart';
import '../common/auto_polling.dart';
import '../modules/module_hub_screen.dart';
import '../notifications/notifications_screen.dart';
import '../portal/portal_screen.dart';
import '../queue/queue_screen.dart';
import '../common/widgets.dart';
import 'home_tab.dart';

/// A single bottom-nav destination.
class _Tab {
  const _Tab(this.label, this.icon, this.screen,
      [this.permissions, this.hideForAdmin = false]);
  final String label;
  final IconData icon;
  final Widget screen;

  /// Any-of permission gate (mirrors the SPA sidebar's `string | string[]`
  /// `permission` field): when non-null the tab is hidden unless the
  /// session holds at least one of these codes.
  final List<String>? permissions;

  /// Hide this tab from the admin `*` wildcard holder (mirrors the SPA's
  /// `hideForAdmin` — e.g. "My portal" is empty for admin, who has no
  /// student/employee record).
  final bool hideForAdmin;
}

/// The authenticated app shell — a permission-gated bottom navigation bar.
///
/// Mirrors `frontend/src/components/AppSidebar.tsx`: tabs are filtered by
/// the same permission codes the SPA uses for its sidebar entries.
///
/// Student-specific layout: "My portal" replaces Home (their Home already
/// routed to the portal, so the two were duplicates) and the Modules tab is
/// dropped — a student's only module is Notifications, which lives in the
/// header bell. Student nav = My portal / Appointments / Queue.
class HomeShell extends StatelessWidget {
  const HomeShell({super.key});

  @override
  Widget build(BuildContext context) {
    final session = context.watch<AuthController>().session;
    final isAdmin = session?.hasPermission('*') ?? false;
    final isStudent = session?.isStudent ?? false;

    final tabs = <_Tab>[
      if (isStudent)
        const _Tab(
            'My Portal', HugeIcons.strokeRoundedUserCircle, PortalScreen())
      else
        const _Tab('Home', HugeIcons.strokeRoundedHome01, HomeTab()),
      const _Tab(
        'Appointments',
        HugeIcons.strokeRoundedCalendar01,
        AppointmentsScreen(),
        // Staff see the clinic list; students see their own + can self-book.
        ['clinic.appointments.read', 'student.portal.read'],
      ),
      if (isStudent) ...[
        // Student nav = My Portal (their Home) / Appointments / Queue.
        const _Tab('Queue', HugeIcons.strokeRoundedUserGroup, QueueScreen()),
      ] else ...[
        // Staff: My portal comes BEFORE Queue (product request), then
        // Modules. My portal mirrors the SPA sidebar's
        // `['employee.portal.read', 'student.portal.read']` gate and is
        // hidden from the admin `*` holder (no portal row).
        const _Tab('My Portal', HugeIcons.strokeRoundedUserCircle,
            PortalScreen(), ['employee.portal.read', 'student.portal.read'],
            true),
        const _Tab('Queue', HugeIcons.strokeRoundedUserGroup, QueueScreen()),
        // All other modules (kiosk stays web-only).
        const _Tab('Modules', HugeIcons.strokeRoundedLayout02, ModuleHubScreen()),
      ],
    ];

    final visible = tabs.where((t) {
      if (t.hideForAdmin && isAdmin) return false;
      final perms = t.permissions;
      if (perms == null) return true;
      return perms.any((p) => session?.hasPermission(p) ?? false);
    }).toList();

    return _Shell(session: session, tabs: visible);
  }
}

class _Shell extends StatefulWidget {
  const _Shell({required this.session, required this.tabs});

  final Session? session;
  final List<_Tab> tabs;

  @override
  State<_Shell> createState() => _ShellState();
}

class _ShellState extends State<_Shell> {
  int _index = 0;

  // Unread notification count shown on the header bell — mirrors the web
  // `NotificationBell` (`frontend/src/components/NotificationBell.tsx`),
  // which fetches the first page and counts `read_at === null`, polling
  // every 60 s and capping the badge at "9+". We do the same here: fetch a
  // small page, count unread, and re-poll on a 60 s timer + when the
  // notifications screen is closed (so marking read refreshes the badge).
  int _unread = 0;
  Timer? _unreadTimer;

  bool get _canReadNotifications =>
      widget.session?.hasPermission('notifications.read') ?? false;

  @override
  void initState() {
    super.initState();
    if (_canReadNotifications) {
      _refreshUnread();
      _unreadTimer = Timer.periodic(
        const Duration(seconds: 60),
        (_) => _refreshUnread(),
      );
    }
  }

  @override
  void dispose() {
    _unreadTimer?.cancel();
    super.dispose();
  }

  Future<void> _refreshUnread() async {
    try {
      final page = await ApiService.I.notifications(limit: 25);
      if (!mounted) return;
      final unread =
          page.items.where((n) => n.readAt == null || n.readAt!.isEmpty).length;
      if (unread != _unread) setState(() => _unread = unread);
    } catch (e) {
      // Best effort — the next 60 s poll will retry. A 401/expiry here is
      // handled by the auth layer (silent refresh), so don't spam.
      if (kDebugMode) debugPrint('HomeShell.refreshUnread failed: $e');
    }
  }

  Future<void> _openNotifications() async {
    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const NotificationsScreen()),
    );
    // Marking rows read inside the screen should refresh the badge.
    if (mounted) _refreshUnread();
  }

  @override
  Widget build(BuildContext context) {
    // Guard against an empty list (shouldn't happen — Queue is always
    // visible — but keep it safe). int.clamp returns num, so a plain
    // ternary keeps the index an int.
    final last = widget.tabs.length - 1;
    final index = _index > last ? last : _index;

    return Scaffold(
      appBar: AppBar(
        // Subtle maroon gradient so the header feels smooth, matching the
        // login page fade.
        flexibleSpace: Container(
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [Color(0xFF8A1226), Color(0xFF6E0000)],
            ),
          ),
        ),
        title: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Image.asset(
              'assets/synapse-white.png',
              height: 36,
              fit: BoxFit.contain,
            ),
            const SizedBox(width: 8),
            const Text(
              'SYNAPSE',
              style: TextStyle(
                color: Colors.white,
                fontSize: 20,
                fontWeight: FontWeight.w800,
                letterSpacing: 1.5,
              ),
            ),
          ],
        ),
        actions: [
          if (_canReadNotifications)
            IconButton(
              tooltip: _unread > 0
                  ? 'Notifications ($_unread unread)'
                  : 'Notifications',
              icon: Badge(
                isLabelVisible: _unread > 0,
                // Matches the web's red `bg-destructive` badge and its
                // "9+" cap (`unread > 9 ? '9+' : unread`).
                backgroundColor: Theme.of(context).colorScheme.error,
                label: Text(_unread > 9 ? '9+' : '$_unread'),
                child: const Icon(HugeIcons.strokeRoundedNotification03),
              ),
              onPressed: _openNotifications,
            ),
          _IdentityChip(session: widget.session),
        ],
      ),
      body: IndexedStack(
        index: index,
        children: [
          // Only the selected tab polls: hidden tabs get a visibility scope so
          // their AutoPolling timers skip ticks (no background API churn).
          for (var i = 0; i < widget.tabs.length; i++)
            TabVisibilityScope(
              hidden: i != index,
              child: widget.tabs[i].screen,
            ),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: index,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          for (final t in widget.tabs)
            NavigationDestination(
              icon: Icon(t.icon),
              label: t.label,
            ),
        ],
      ),
    );
  }
}

/// Identity pill in the app bar — mirrors the web `UserMenu` design
/// (frontend/src/components/UserMenu.tsx): a white rounded pill with a
/// maroon border, a round school-crest avatar (FUHS for clinic/counselling
/// staff, FU for everyone else), and the user's email. Tapping it opens the
/// sign-out confirmation.
class _IdentityChip extends StatelessWidget {
  const _IdentityChip({required this.session});

  final Session? session;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final email = session?.email ?? '';
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: InkWell(
        onTap: () => _openMenu(context),
        borderRadius: BorderRadius.circular(999),
        child: Container(
          padding: const EdgeInsets.fromLTRB(3, 3, 10, 3),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(999),
            border: Border.all(
              color: scheme.primary.withValues(alpha: 0.55),
              width: 1,
            ),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              // Round school-crest avatar — FUHS for clinic/counselling
              // staff, FU for everyone else (mobile-only; web keeps the
              // initials circle). Maroon ring around the crest so the logo
              // reads clearly against the white pill + white crest canvas.
              Container(
                width: 26,
                height: 26,
                clipBehavior: Clip.antiAlias,
                decoration: BoxDecoration(
                  color: Colors.white,
                  shape: BoxShape.circle,
                  border: Border.all(
                    color: scheme.primary.withValues(alpha: 0.9),
                    width: 1,
                  ),
                ),
                child: Image.asset(
                  avatarAssetFor(session),
                  width: 26,
                  height: 26,
                  fit: BoxFit.cover,
                ),
              ),
              const SizedBox(width: 7),
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 150),
                child: Text(
                  email,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    fontSize: 12,
                    color: Color(0xFF5F5A58), // muted foreground
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// Opens the identity menu (change password / sign out).
  Future<void> _openMenu(BuildContext context) async {
    final action = await showModalBottomSheet<String>(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 10),
            Container(
              width: 40,
              height: 5,
              decoration: const BoxDecoration(
                color: Color(0xFF800000),
                borderRadius: BorderRadius.all(Radius.circular(999)),
              ),
            ),
            const SizedBox(height: 6),
            ListTile(
              leading: const Icon(HugeIcons.strokeRoundedLock),
              title: const Text('Change password'),
              onTap: () => Navigator.pop(ctx, 'password'),
            ),
            ListTile(
              leading: const Icon(HugeIcons.strokeRoundedLogout01),
              title: const Text('Sign out'),
              onTap: () => Navigator.pop(ctx, 'logout'),
            ),
          ],
        ),
      ),
    );
    if (!context.mounted) return;
    if (action == 'password') {
      await _openChangePassword(context);
    } else if (action == 'logout') {
      await _confirmLogout(context);
    }
  }

  /// Self-service password change (the backend rotates the token pair and
  /// revokes all other sessions).
  Future<void> _openChangePassword(BuildContext context) async {
    final auth = context.read<AuthController>();
    final currentController = TextEditingController();
    final newController = TextEditingController();
    final confirmController = TextEditingController();
    String? error;
    bool busy = false;

    await showSynapseSheet<void>(
      context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.of(ctx).viewInsets.bottom,
          ),
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 14, 20, 20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const SheetHeader(title: 'Change password'),
                const SizedBox(height: 16),
              TextField(
                controller: currentController,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'Current password',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: newController,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'New password (min 12)',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: confirmController,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'Confirm new password',
                  border: OutlineInputBorder(),
                ),
              ),
              if (error != null) ...[const SizedBox(height: 8), Text(error!)],
              const SizedBox(height: 12),
              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  TextButton(
                    onPressed: busy ? null : () => Navigator.pop(ctx),
                    child: const Text('Cancel'),
                  ),
                  const SizedBox(width: 8),
                  FilledButton(
                    onPressed: busy
                        ? null
                        : () async {
                            if (newController.text != confirmController.text) {
                              setDialogState(
                                () => error = 'Passwords do not match.',
                              );
                              return;
                            }
                            if (newController.text.length < 12) {
                              setDialogState(
                                () => error =
                                    'New password must be at least 12 characters.',
                              );
                              return;
                            }
                            setDialogState(() {
                              busy = true;
                              error = null;
                            });
                            try {
                              await auth.changePassword(
                                currentPassword: currentController.text,
                                newPassword: newController.text,
                              );
                              if (ctx.mounted) {
                                Navigator.pop(ctx);
                                ScaffoldMessenger.of(context).showSnackBar(
                                  const SnackBar(
                                    content: Text('Password changed.'),
                                  ),
                                );
                              }
                            } on ApiException catch (e) {
                              setDialogState(() {
                                busy = false;
                                error = e.message;
                              });
                            } catch (e) {
                              setDialogState(() {
                                busy = false;
                                error = '$e';
                              });
                            }
                          },
                    child: busy
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('Change'),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
      ),
    );
  }

  Future<void> _confirmLogout(BuildContext context) async {
    final auth = context.read<AuthController>();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Sign out?'),
        content: Text('You are signed in as ${session?.email}.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Sign out'),
          ),
        ],
      ),
    );
    if (ok == true) {
      await auth.logout();
    }
  }
}
