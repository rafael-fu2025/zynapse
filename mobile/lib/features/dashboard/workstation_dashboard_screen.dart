import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/workstation_dashboard.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../../core/workstations.dart';
import '../appointments/appointments_screen.dart';
import '../clinic/clinic_screen.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';
import '../counselling/counselling_screen.dart';
import '../facilities/devices_screen.dart';
import '../facilities/facilities_screen.dart';
import '../facilities/waste_categories_screen.dart';
import '../inventory/inventory_screen.dart';
import '../patients/patients_screen.dart';
import '../referrals/referrals_screen.dart';
import '../reports/reports_screen.dart';

/// Workstation dashboard — mirrors `WorkstationDashboardPage.tsx` and
/// `GET /dashboard/{clinic|guidance|facilities}`.
///
/// When [embedded] is true it renders as a tab body (no Scaffold), which is
/// how the Home tab shows a single-workstation user. Otherwise it is pushed
/// with its own app bar.
class WorkstationDashboardScreen extends StatefulWidget {
  const WorkstationDashboardScreen({
    super.key,
    required this.unit,
    this.embedded = false,
  });

  final String unit;
  final bool embedded;

  @override
  State<WorkstationDashboardScreen> createState() =>
      _WorkstationDashboardScreenState();
}

class _WorkstationDashboardScreenState
    extends State<WorkstationDashboardScreen>
    with AutoPolling<WorkstationDashboardScreen> {
  late final WorkstationDef _def = workstationFor(widget.unit);
  bool _loading = true;
  String? _error;
  WorkstationDashboard? _data;

  @override
  void initState() {
    super.initState();
    _load();
    startPolling(const Duration(seconds: 60), _silentRefresh);
  }

  Future<void> _load() async {
    setState(() {
      _loading = _data == null;
      _error = null;
    });
    try {
      final data =
          await ApiService.I.workstationDashboard(widget.unit);
      if (!mounted) return;
      setState(() {
        _data = data;
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

  Future<void> _silentRefresh() async {
    try {
      final data =
          await ApiService.I.workstationDashboard(widget.unit);
      if (!mounted) return;
      setState(() => _data = data);
    } catch (e) {
      if (kDebugMode) {
        debugPrint('WorkstationDashboardScreen.poll failed: $e');
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final body = RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
        children: [
          _WelcomeCard(def: _def, data: _data, onRefresh: _load),
          const SizedBox(height: 14),
          if (_error != null) ...[
            _ErrorBanner(message: _error!, onRetry: _load),
            const SizedBox(height: 14),
          ],
          if (_loading)
            SizedBox(height: 280, child: AsyncState.loading())
          else if (_data == null)
            SizedBox(
              height: 280,
              child: AsyncState.error(
                _error ?? 'No snapshot loaded.',
                onRetry: _load,
              ),
            )
          else ...[
            if (_data!.scope == 'own') ...[
              const _OwnScopeNote(),
              const SizedBox(height: 14),
            ],
            _MetricsGrid(metrics: _data!.metrics),
            const SizedBox(height: 18),
            _TrendPanel(trend: _data!.trend),
            const SizedBox(height: 18),
            _BreakdownPanel(breakdown: _data!.breakdown),
            const SizedBox(height: 18),
            _RecentPanel(
              recent: _data!.recent,
              onTap: (row) => _openRecent(row),
            ),
            const SizedBox(height: 18),
            _QuickActions(def: _def),
          ],
        ],
      ),
    );

    if (widget.embedded) return body;
    return Scaffold(
      appBar: AppBar(
        title: Text('${_def.label} dashboard'),
        actions: [
          IconButton(
            onPressed: _load,
            tooltip: 'Refresh',
            icon: const Icon(HugeIcons.strokeRoundedRefresh),
          ),
        ],
      ),
      body: body,
    );
  }

  void _openRecent(WorkstationRecentRow row) {
    final Widget screen = switch (widget.unit) {
      'clinic' => const ClinicScreen(),
      'guidance' => const CounsellingScreen(),
      _ => const FacilitiesScreen(),
    };
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
  }
}

class _WelcomeCard extends StatelessWidget {
  const _WelcomeCard({
    required this.def,
    required this.data,
    required this.onRefresh,
  });

  final WorkstationDef def;
  final WorkstationDashboard? data;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    final updated = data == null
        ? 'No snapshot loaded'
        : 'Updated ${fmtUtcToApp(data!.generatedAt)} · Manila';
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFFAC1E37), Color(0xFF751020)],
        ),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${def.label} workstation',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              IconButton(
                onPressed: onRefresh,
                tooltip: 'Refresh',
                color: Colors.white,
                icon: const Icon(HugeIcons.strokeRoundedRefresh, size: 20),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            def.welcome,
            style: const TextStyle(color: Colors.white70, fontSize: 13),
          ),
          const SizedBox(height: 10),
          Text(
            '${data?.day ?? ''} · Asia/Manila · $updated',
            style: const TextStyle(color: Colors.white70, fontSize: 11.5),
          ),
        ],
      ),
    );
  }
}

class _OwnScopeNote extends StatelessWidget {
  const _OwnScopeNote();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFF5F3FF),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFDDD6FE)),
      ),
      child: const Text(
        'Your sessions — session metrics, activity and recent records cover '
        'only sessions assigned to you. Queue and referral metrics use the '
        'scope described on each card.',
        style: TextStyle(fontSize: 12, color: Color(0xFF5B4BA6)),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF4E5),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFF0D9B5)),
      ),
      child: Row(
        children: [
          const Expanded(
            child: Text(
              'Refresh failed. The last successful snapshot is still shown.',
              style: TextStyle(fontSize: 12, color: Color(0xFF8A5A00)),
            ),
          ),
          TextButton(onPressed: onRetry, child: const Text('Try again')),
        ],
      ),
    );
  }
}

class _MetricsGrid extends StatelessWidget {
  const _MetricsGrid({required this.metrics});

  final List<WorkstationMetric> metrics;

  @override
  Widget build(BuildContext context) {
    if (metrics.isEmpty) {
      return const Text(
        'No metrics are available for your permissions.',
        style: TextStyle(color: Colors.black54, fontSize: 13),
      );
    }
    return GridView.count(
      crossAxisCount: 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 10,
      crossAxisSpacing: 10,
      childAspectRatio: 1.18,
      children: [for (final m in metrics) _MetricCard(metric: m)],
    );
  }
}

class _MetricCard extends StatelessWidget {
  const _MetricCard({required this.metric});

  final WorkstationMetric metric;

  String get _value {
    final v = metric.value;
    if (v == v.roundToDouble()) return v.toInt().toString();
    return v.toStringAsFixed(1);
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: Theme.of(context)
              .colorScheme
              .outlineVariant
              .withValues(alpha: 0.6),
        ),
        boxShadow: const [
          BoxShadow(color: Color(0x0A000000), blurRadius: 8, offset: Offset(0, 2)),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            metric.label,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: Colors.black54,
            ),
          ),
          const Spacer(),
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                _value,
                style: const TextStyle(
                  fontSize: 26,
                  fontWeight: FontWeight.w800,
                  color: Color(0xFF1C1917),
                ),
              ),
              if (metric.unit != null) ...[
                const SizedBox(width: 4),
                Text(
                  metric.unit!,
                  style: const TextStyle(fontSize: 12, color: Colors.black45),
                ),
              ],
            ],
          ),
          const SizedBox(height: 4),
          Text(
            metric.description,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 10.5, color: Colors.black45),
          ),
        ],
      ),
    );
  }
}

class _TrendPanel extends StatelessWidget {
  const _TrendPanel({required this.trend});

  final WorkstationTrend trend;

  @override
  Widget build(BuildContext context) {
    final maxValue = trend.points.fold<int>(
      1,
      (max, p) => p.value > max ? p.value : max,
    );
    return _Panel(
      title: trend.title,
      subtitle: trend.description,
      child: SizedBox(
        height: 150,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            for (final point in trend.points)
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 3),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
                      Text(
                        '${point.value}',
                        style: const TextStyle(
                          fontSize: 10,
                          color: Colors.black54,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Container(
                        height: 90 * (point.value / maxValue),
                        decoration: BoxDecoration(
                          color: const Color(0xFF800000),
                          borderRadius: BorderRadius.circular(4),
                        ),
                      ),
                      const SizedBox(height: 6),
                      Text(
                        point.day.length >= 10
                            ? point.day.substring(5)
                            : point.day,
                        style: const TextStyle(
                          fontSize: 9.5,
                          color: Colors.black45,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _BreakdownPanel extends StatelessWidget {
  const _BreakdownPanel({required this.breakdown});

  final WorkstationBreakdown breakdown;

  @override
  Widget build(BuildContext context) {
    final total = breakdown.items.fold<int>(0, (sum, i) => sum + i.value);
    return _Panel(
      title: breakdown.title,
      subtitle: breakdown.description,
      child: breakdown.items.isEmpty
          ? const Text(
              'No recorded states in scope.',
              style: TextStyle(color: Colors.black45, fontSize: 12),
            )
          : Column(
              children: [
                for (final item in breakdown.items)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                item.label,
                                style: const TextStyle(fontSize: 12.5),
                              ),
                            ),
                            Text(
                              '${item.value}',
                              style: const TextStyle(
                                fontSize: 12.5,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 4),
                        ClipRRect(
                          borderRadius: BorderRadius.circular(4),
                          child: LinearProgressIndicator(
                            value: total == 0 ? 0 : item.value / total,
                            minHeight: 6,
                            backgroundColor: const Color(0xFFF1F0EE),
                            valueColor: const AlwaysStoppedAnimation(
                              Color(0xFF800000),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
    );
  }
}

class _RecentPanel extends StatelessWidget {
  const _RecentPanel({required this.recent, required this.onTap});

  final WorkstationRecent recent;
  final ValueChanged<WorkstationRecentRow> onTap;

  @override
  Widget build(BuildContext context) {
    return _Panel(
      title: recent.title,
      subtitle: 'Up to six recent records in scope.',
      child: recent.rows.isEmpty
          ? const Text(
              'No recent records.',
              style: TextStyle(color: Colors.black45, fontSize: 12),
            )
          : Column(
              children: [
                for (final row in recent.rows)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                    onTap: () => onTap(row),
                    title: Text(
                      '${row.code} · ${row.title}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    subtitle: Text(
                      [
                        if (row.subtitle != null && row.subtitle!.isNotEmpty)
                          row.subtitle!,
                        if (row.owner != null && row.owner!.isNotEmpty)
                          row.owner!,
                        if (row.occurredAt != null)
                          fmtUtcShort(row.occurredAt),
                      ].join(' · '),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 11),
                    ),
                    trailing: StatusBadge(
                      label: titleCaseOption(row.status),
                      color: _statusColor(row.status),
                    ),
                  ),
              ],
            ),
    );
  }

  static Color _statusColor(String status) => switch (status) {
        'open' || 'waiting' || 'processing' || 'awaiting_output' =>
          const Color(0xFFB45309),
        'closed' || 'released' || 'completed' || 'idle' =>
          const Color(0xFF1B7A43),
        'called' => const Color(0xFF1E6FD9),
        'cancelled' || 'failed' => const Color(0xFFB3261E),
        _ => Colors.black45,
      };
}

class _QuickActions extends StatelessWidget {
  const _QuickActions({required this.def});

  final WorkstationDef def;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<AuthController>().session;
    final actions = <({String label, IconData icon, String permission, Widget screen})>[
      if (def.unit == 'clinic') ...[
        (
          label: 'Patient queue',
          icon: HugeIcons.strokeRoundedStethoscope,
          permission: 'clinic.encounters.read',
          screen: const ClinicScreen(),
        ),
        (
          label: 'Appointments',
          icon: HugeIcons.strokeRoundedCalendar01,
          permission: 'clinic.appointments.read',
          screen: const AppointmentsScreen(),
        ),
        (
          label: 'Inventory',
          icon: HugeIcons.strokeRoundedPackage02,
          permission: 'clinic.inventory.read',
          screen: const InventoryScreen(),
        ),
        (
          label: 'Patient records',
          icon: HugeIcons.strokeRoundedUserMultiple,
          permission: 'clinic.patients.read',
          screen: const PatientsScreen(),
        ),
      ] else if (def.unit == 'guidance') ...[
        (
          label: 'Counselling',
          icon: HugeIcons.strokeRoundedMessage01,
          permission: 'counselling.records.read',
          screen: const CounsellingScreen(),
        ),
        (
          label: 'Referrals',
          icon: HugeIcons.strokeRoundedShare01,
          permission: 'referrals.read',
          screen: const ReferralsScreen(),
        ),
      ] else ...[
        (
          label: 'Composting drums',
          icon: HugeIcons.strokeRoundedFactory01,
          permission: 'facilities.units.read',
          screen: const FacilitiesScreen(),
        ),
        (
          label: 'Devices',
          icon: HugeIcons.strokeRoundedCpu,
          permission: 'facilities.units.read',
          screen: const DevicesScreen(),
        ),
        (
          label: 'Waste categories',
          icon: HugeIcons.strokeRoundedRecycle01,
          permission: 'facilities.units.read',
          screen: const WasteCategoriesScreen(),
        ),
        (
          label: 'Reports',
          icon: HugeIcons.strokeRoundedChart01,
          permission: 'reports.read',
          screen: const ReportsScreen(),
        ),
      ],
    ].where((a) => session?.hasPermission(a.permission) ?? false).toList();

    if (actions.isEmpty) return const SizedBox.shrink();
    return _Panel(
      title: def.hub,
      subtitle: 'Quick actions',
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final action in actions)
            OutlinedButton.icon(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => action.screen),
              ),
              icon: Icon(action.icon, size: 17),
              label: Text(action.label),
            ),
        ],
      ),
    );
  }
}

class _Panel extends StatelessWidget {
  const _Panel({
    required this.title,
    required this.subtitle,
    required this.child,
  });

  final String title;
  final String subtitle;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: Theme.of(context)
              .colorScheme
              .outlineVariant
              .withValues(alpha: 0.6),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700),
          ),
          if (subtitle.isNotEmpty) ...[
            const SizedBox(height: 2),
            Text(
              subtitle,
              style: const TextStyle(fontSize: 11, color: Colors.black45),
            ),
          ],
          const SizedBox(height: 12),
          child,
        ],
      ),
    );
  }
}
