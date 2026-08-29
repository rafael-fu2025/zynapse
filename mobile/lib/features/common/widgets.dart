import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../../core/api/api_envelope.dart';
import '../../core/models/session.dart';

/// Shared building blocks used across feature screens.

/// Avatar placeholder crest — the mobile-only FU / FUHS brand split.
///
/// Clinic & counselling staff roles (clinic_staff, counsellor,
/// clinical_supervisor) show the FUHS crest; everyone else — employees
/// (teaching & non-teaching), students, admin, and other staff — shows the
/// FU crest. The web app keeps initials; this crest split is mobile-only.
String avatarAssetFor(Session? session) {
  if (session == null) return 'assets/FU.png';
  // Admin (`*`) is not a clinic/counselling role — falls through to FU.
  if (session.hasPermission('*')) return 'assets/FU.png';
  final clinicCounselling = session.hasPermission('clinic.encounters.read') ||
      session.hasPermission('counselling.records.read') ||
      session.hasPermission('counselling.schedule.read');
  return clinicCounselling ? 'assets/FUHS.png' : 'assets/FU.png';
}

/// Drag handle + title + optional subtitle for a modal bottom sheet.
class SheetHeader extends StatelessWidget {
  const SheetHeader({super.key, required this.title, this.subtitle});

  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Center(
          child: Container(
            width: 40,
            height: 5,
            margin: const EdgeInsets.only(bottom: 12),
            decoration: BoxDecoration(
              color: const Color(0xFF800000),
              borderRadius: BorderRadius.circular(999),
            ),
          ),
        ),
        Text(
          title,
          style: Theme.of(context)
              .textTheme
              .titleLarge
              ?.copyWith(fontWeight: FontWeight.w700),
        ),
        if (subtitle != null) ...[
          const SizedBox(height: 2),
          Text(
            subtitle!,
            style: const TextStyle(color: Colors.black54, fontSize: 13),
          ),
        ],
      ],
    );
  }
}

/// Opens a white, top-rounded, keyboard-aware modal bottom sheet.
Future<T?> showSynapseSheet<T>(
  BuildContext context, {
  required WidgetBuilder builder,
}) {
  return showModalBottomSheet<T>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.white,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
    ),
    builder: builder,
  );
}

/// Standard async-state widgets: loading spinner, error + retry, empty.
class AsyncState {
  static Widget loading() => const Center(
        child: Padding(
          padding: EdgeInsets.all(32),
          child: CircularProgressIndicator(),
        ),
      );

  static Widget error(Object error, {VoidCallback? onRetry}) {
    final message = error is ApiException
        ? error.message
        : (error is Exception ? '$error' : 'Something went wrong');
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(HugeIcons.strokeRoundedWifiOff01, size: 40),
            const SizedBox(height: 12),
            Text(
              message,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.black54),
            ),
            if (onRetry != null) ...[
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: onRetry,
                icon: const Icon(HugeIcons.strokeRoundedRefresh),
                label: const Text('Retry'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  static Widget empty(String message,
      {IconData icon = HugeIcons.strokeRoundedInbox}) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 40, color: Colors.black26),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
          ],
        ),
      ),
    );
  }
}

/// A titled section card (mirrors the shadcn `bg-card` sections in the SPA).
class SectionCard extends StatelessWidget {
  const SectionCard({
    super.key,
    required this.title,
    this.icon,
    required this.child,
    this.trailing,
  });

  final String title;
  final IconData? icon;
  final Widget child;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Card(
      elevation: 0,
      color: scheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: BorderSide(color: scheme.outlineVariant.withValues(alpha: 0.5)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                if (icon != null) ...[
                  Icon(icon, size: 18, color: scheme.primary),
                  const SizedBox(width: 8),
                ],
                Expanded(
                  child: Text(
                    title,
                    style: Theme.of(context).textTheme.titleSmall,
                  ),
                ),
                if (trailing != null) trailing!,
              ],
            ),
            const SizedBox(height: 12),
            child,
          ],
        ),
      ),
    );
  }
}

/// A small stat tile used on the dashboard.
class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.label,
    required this.value,
    this.icon,
    this.color,
  });

  final String label;
  final String value;
  final IconData? icon;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final accent = color ?? Theme.of(context).colorScheme.primary;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: accent.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 18, color: accent),
            const SizedBox(height: 6),
          ],
          Text(
            value,
            style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: accent,
                ),
          ),
          const SizedBox(height: 2),
          Text(
            label,
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

/// A colored status pill (mirrors the SPA's status badges).
class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withValues(alpha: 0.4)),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: color,
        ),
      ),
    );
  }
}

/// Maps an appointment status to a badge color (mirrors STATUS_VARIANT).
Color appointmentStatusColor(String status) => switch (status) {
      'scheduled' => const Color(0xFF1E6FD9), // info blue
      'checked_in' => const Color(0xFF8A5A00), // amber
      'completed' => const Color(0xFF1B7A43), // green
      'cancelled' => Colors.grey,
      'no_show' => const Color(0xFFB3261E), // destructive
      _ => Colors.grey,
    };
