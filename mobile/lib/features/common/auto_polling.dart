import 'dart:async';

import 'package:flutter/widgets.dart';

/// Periodic auto-refresh ("polling") that mirrors the SPA's TanStack
/// `refetchInterval` so lists stay in sync with the web without a manual
/// pull-to-refresh.
///
/// The screen provides an `onTick` that performs a SILENT refresh — it must
/// swap the data in place WITHOUT toggling the full-screen loading state
/// (otherwise the list would blank/spinner every poll). Transient errors
/// inside `onTick` are ignored (the last known data stays on screen).
///
/// Usage:
/// ```dart
/// class _MyScreenState extends State<MyScreen> with AutoPolling<MyScreen> {
///   @override
///   void initState() {
///     super.initState();
///     _load();
///     startPolling(const Duration(seconds: 30), _silentRefresh);
///   }
/// }
/// ```
/// Marks a subtree as hidden — e.g. a non-selected bottom-nav tab inside the
/// shell's `IndexedStack`. Screens under a hidden scope pause their
/// `AutoPolling` timer so hidden tabs don't keep hammering the API.
///
/// Screens pushed via `Navigator` (module screens) have no scope above them,
/// so `isHidden` defaults to false and they keep polling while alive.
class TabVisibilityScope extends InheritedWidget {
  const TabVisibilityScope({
    super.key,
    required this.hidden,
    required super.child,
  });

  /// True when this subtree is a hidden (non-selected) tab.
  final bool hidden;

  /// Reads the nearest scope; returns false when none exists (visible).
  static bool isHidden(BuildContext context) {
    return context
            .getInheritedWidgetOfExactType<TabVisibilityScope>()
            ?.hidden ??
        false;
  }

  @override
  bool updateShouldNotify(TabVisibilityScope oldWidget) =>
      oldWidget.hidden != hidden;
}

/// The timer is cancelled automatically in `dispose()`.
mixin AutoPolling<T extends StatefulWidget> on State<T> {
  Timer? _pollTimer;

  /// Start (or restart) polling every [interval], invoking [onTick] on each
  /// tick. The previous timer (if any) is cancelled first so re-entry is
  /// idempotent. Ticks are skipped while this subtree is hidden (a
  /// non-selected bottom-nav tab).
  void startPolling(Duration interval, Future<void> Function() onTick) {
    stopPolling();
    _pollTimer = Timer.periodic(interval, (_) {
      if (mounted && !TabVisibilityScope.isHidden(context)) {
        onTick();
      }
    });
  }

  /// Cancel any active polling timer.
  void stopPolling() {
    _pollTimer?.cancel();
    _pollTimer = null;
  }

  @override
  void dispose() {
    stopPolling();
    super.dispose();
  }
}
