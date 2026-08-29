import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/dashboard.dart';
import '../../core/models/session.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../audit/audit_screen.dart';
import '../clinic/clinic_screen.dart';
import '../common/auto_polling.dart';
import '../common/widgets.dart';
import '../counselling/counselling_screen.dart';
import '../facilities/facilities_screen.dart';
import '../referrals/referrals_screen.dart';
import '../reports/reports_screen.dart';

/// Dashboard — live counters from `GET /dashboard/counters`.
///
/// Mirrors `frontend/src/pages/DashboardPage.tsx`: tiles are rendered only
/// for the module counters the caller's permissions allow the backend to
/// return.
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen>
    with AutoPolling<DashboardScreen> {
  bool _loading = true;
  String? _error;
  DashboardCounters? _data;
  // True once data has rendered at least once: pull-to-refresh then keeps
  // the current content visible and relies on the RefreshIndicator's own
  // spinner, instead of swapping in a second full-screen loading state.
  bool _loadedOnce = false;

  @override
  void initState() {
    super.initState();
    _load();
    // Live updates — mirrors the SPA's 60s `refetchInterval`.
    startPolling(const Duration(seconds: 60), _silentRefresh);
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      final c = await ApiService.I.dashboardCounters();
      if (!mounted) return;
      setState(() {
        _data = c;
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

  /// Polled live update: silently swap the counters so the dashboard stays
  /// fresh without a manual refresh. Transient errors keep the last values.
  Future<void> _silentRefresh() async {
    try {
      final c = await ApiService.I.dashboardCounters();
      if (!mounted) return;
      setState(() => _data = c);
    } catch (e) {
      // Keep the last known counters.
      if (kDebugMode) debugPrint('DashboardScreen.poll failed: $e');
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<AuthController>().session;
    final data = _data;
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
        children: [
          _GreetingHeader(session: session),
          const SizedBox(height: 16),
          if (_loading)
            SizedBox(height: 320, child: AsyncState.loading())
          else if (_error != null)
            SizedBox(
              height: 320,
              child: AsyncState.error(_error!, onRetry: _load),
            )
          else ...[
            _HeroCard(counters: data ?? DashboardCounters()),
            const SizedBox(height: 18),
            _QuickActions(session: session),
            const SizedBox(height: 18),
            if ((data?.facilities?.atRisk ?? 0) > 0) ...[
              _AlertsBanner(atRisk: data!.facilities!.atRisk),
              const SizedBox(height: 18),
            ],
            const _SectionHeader(title: 'Live overview'),
            const SizedBox(height: 10),
            _LiveOverviewGrid(counters: data ?? DashboardCounters()),
          ],
        ],
      ),
    );
  }
}

/// Time-of-day greeting ("Good morning", …) based on the device clock.
String _timeGreeting() {
  final h = DateTime.now().hour;
  if (h < 12) return 'Good morning';
  if (h < 18) return 'Good afternoon';
  return 'Good evening';
}

/// Human display name: `personName` ("First Last") when available, otherwise
/// derived from the email's local part ("admin@synapse.dev" → "Admin",
/// "nina.reyes@…" → "Nina Reyes"), falling back to "there". Avoids showing
/// a raw email/username as the name.
String _displayName(Session? session) {
  final name = session?.personName?.trim();
  if (name != null && name.isNotEmpty) return name;
  final local = (session?.email ?? '').split('@').first.trim();
  if (local.isNotEmpty) {
    final words = local
        .split(RegExp(r'[._\-]+'))
        .where((w) => w.isNotEmpty)
        .map((w) => w[0].toUpperCase() + w.substring(1))
        .join(' ');
    if (words.isNotEmpty) return words;
  }
  return 'there';
}

/// Header row: school-crest avatar + time greeting with first name + date.
class _GreetingHeader extends StatelessWidget {
  const _GreetingHeader({this.session});

  final Session? session;

  @override
  Widget build(BuildContext context) {
    final firstName = _displayName(session).split(' ').first;
    return Row(
      children: [
        Container(
          width: 46,
          height: 46,
          clipBehavior: Clip.antiAlias,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            border: Border.all(
              color: const Color(0xFF800000).withValues(alpha: 0.2),
            ),
          ),
          child: Image.asset(avatarAssetFor(session), fit: BoxFit.cover),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${_timeGreeting()}, $firstName',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                  color: Color(0xFF1C1917),
                ),
              ),
              const SizedBox(height: 2),
              Text(
                DateFormat('EEEE, MMM d').format(DateTime.now()),
                style: const TextStyle(color: Colors.black54, fontSize: 12),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader({required this.title});

  final String title;

  @override
  Widget build(BuildContext context) {
    return Text(
      title,
      style: Theme.of(context)
          .textTheme
          .titleMedium
          ?.copyWith(fontWeight: FontWeight.w700),
    );
  }
}

/// Hero summary card — a maroon gradient focal point with the total count of
/// open/actionable items across the caller's modules plus per-module chips.
class _HeroCard extends StatelessWidget {
  const _HeroCard({required this.counters});

  final DashboardCounters counters;

  @override
  Widget build(BuildContext context) {
    final clinic = counters.clinic;
    final counselling = counters.counselling;
    final facilities = counters.facilities;
    final referrals = counters.referrals;
    final openReferrals = (referrals?.submitted ?? 0) +
        (referrals?.acknowledged ?? 0) +
        (referrals?.underReview ?? 0);
    final total = (clinic?.openEncounters ?? 0) +
        (counselling?.openSessions ?? 0) +
        (facilities?.unitsProcessing ?? 0) +
        openReferrals;

    final chips = <Widget>[
      if (clinic != null)
        _HeroChip(
          color: const Color(0xFF9BE8C4),
          text: '${clinic.openEncounters} Open encounters',
        ),
      if (referrals != null)
        _HeroChip(color: const Color(0xFFFDE68A), text: '$openReferrals Referrals'),
      if (facilities != null)
        _HeroChip(
          color: const Color(0xFFFFC7A3),
          text: '${facilities.unitsProcessing} Drums processing',
        ),
      if (counselling != null)
        _HeroChip(
          color: const Color(0xFFC9B8FF),
          text: '${counselling.openSessions} Sessions',
        ),
    ];

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFF8A1226), Color(0xFF5C0000)],
        ),
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF800000).withValues(alpha: 0.22),
            blurRadius: 18,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'LIVE OVERVIEW',
            style: TextStyle(
              color: Colors.white70,
              fontSize: 11,
              fontWeight: FontWeight.w700,
              letterSpacing: 1.4,
            ),
          ),
          const SizedBox(height: 6),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                '$total',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 42,
                  fontWeight: FontWeight.w800,
                  height: 1,
                ),
              ),
              const SizedBox(width: 10),
              Padding(
                padding: const EdgeInsets.only(bottom: 5),
                child: Text(
                  total == 1 ? 'Open item' : 'Open items',
                  style: const TextStyle(color: Colors.white70, fontSize: 14),
                ),
              ),
            ],
          ),
          if (chips.isNotEmpty) ...[
            const SizedBox(height: 14),
            Wrap(spacing: 8, runSpacing: 8, children: chips),
          ],
        ],
      ),
    );
  }
}

class _HeroChip extends StatelessWidget {
  const _HeroChip({required this.color, required this.text});

  final Color color;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 7,
            height: 7,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
          const SizedBox(width: 6),
          Text(text, style: const TextStyle(color: Colors.white, fontSize: 12)),
        ],
      ),
    );
  }
}

/// Horizontal row of permission-gated shortcuts to the most-used modules
/// (avoids duplicating the always-visible bottom tabs).
class _QuickActions extends StatelessWidget {
  const _QuickActions({this.session});

  final Session? session;

  @override
  Widget build(BuildContext context) {
    final s = session;
    final actions = <(IconData, Color, String, Widget)>[
      if (s?.hasPermission('clinic.encounters.read') ?? false)
        (
          HugeIcons.strokeRoundedStethoscope,
          const Color(0xFF0F766E),
          'Encounters',
          const ClinicScreen(),
        ),
      if (s?.hasPermission('referrals.read') ?? false)
        (
          HugeIcons.strokeRoundedShare01,
          const Color(0xFF1E6FD9),
          'Referrals',
          const ReferralsScreen(),
        ),
      if (s?.hasPermission('facilities.units.read') ?? false)
        (
          HugeIcons.strokeRoundedFactory01,
          const Color(0xFFB45309),
          'Facilities',
          const FacilitiesScreen(),
        ),
      if (s?.hasPermission('counselling.records.read') ?? false)
        (
          HugeIcons.strokeRoundedMessage01,
          const Color(0xFF5B4BA6),
          'Counselling',
          const CounsellingScreen(),
        ),
      if (s?.hasPermission('reports.read') ?? false)
        (
          HugeIcons.strokeRoundedChart01,
          const Color(0xFF6D4C41),
          'Reports',
          const ReportsScreen(),
        ),
    ];
    if (actions.isEmpty) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionHeader(title: 'Quick actions'),
        const SizedBox(height: 10),
        SizedBox(
          height: 92,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            itemCount: actions.length,
            separatorBuilder: (_, __) => const SizedBox(width: 16),
            itemBuilder: (context, i) => _QuickActionTile(
              icon: actions[i].$1,
              color: actions[i].$2,
              label: actions[i].$3,
              onTap: () => Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => actions[i].$4),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _QuickActionTile extends StatelessWidget {
  const _QuickActionTile({
    required this.icon,
    required this.color,
    required this.label,
    required this.onTap,
  });

  final IconData icon;
  final Color color;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: SizedBox(
        width: 64,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(
                color: color.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(16),
              ),
              child: Icon(icon, color: color, size: 24),
            ),
            const SizedBox(height: 6),
            Text(
              label,
              style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }
}

/// Red-tinted banner surfaced when any BMG drum is at risk.
class _AlertsBanner extends StatelessWidget {
  const _AlertsBanner({required this.atRisk});

  final int atRisk;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFB3261E).withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: const Color(0xFFB3261E).withValues(alpha: 0.3),
        ),
      ),
      child: Row(
        children: [
          const Icon(
            HugeIcons.strokeRoundedAlert01,
            color: Color(0xFFB3261E),
            size: 20,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              '$atRisk drum${atRisk == 1 ? '' : 's'} at risk — check Facilities',
              style: const TextStyle(
                color: Color(0xFFB3261E),
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Live counter tiles as modern stat cards (tinted icon chip + big value).
class _LiveOverviewGrid extends StatelessWidget {
  const _LiveOverviewGrid({required this.counters});

  final DashboardCounters counters;

  @override
  Widget build(BuildContext context) {
    final specs = <_StatSpec>[
      if (counters.clinic != null) ...[
        _StatSpec(
          'Open encounters',
          '${counters.clinic!.openEncounters}',
          HugeIcons.strokeRoundedStethoscope,
          const Color(0xFF0F766E),
          const ClinicScreen(),
        ),
        _StatSpec(
          'Closed encounters',
          '${counters.clinic!.closedEncounters}',
          HugeIcons.strokeRoundedCheckmarkCircle01,
          const Color(0xFF1B7A43),
          const ClinicScreen(),
        ),
      ],
      if (counters.counselling != null) ...[
        _StatSpec(
          'Open counselling',
          '${counters.counselling!.openSessions}',
          HugeIcons.strokeRoundedMessageMultiple01,
          const Color(0xFF5B4BA6),
          const CounsellingScreen(),
        ),
        _StatSpec(
          'Closed counselling',
          '${counters.counselling!.closedSessions}',
          HugeIcons.strokeRoundedMessage01,
          const Color(0xFF1E6FD9),
          const CounsellingScreen(),
        ),
      ],
      if (counters.facilities != null) ...[
        _StatSpec(
          'Drums processing',
          '${counters.facilities!.unitsProcessing}',
          HugeIcons.strokeRoundedFactory01,
          const Color(0xFFB45309),
          const FacilitiesScreen(),
        ),
        _StatSpec(
          'Drums idle',
          '${counters.facilities!.unitsIdle}',
          HugeIcons.strokeRoundedPowerSocket01,
          const Color(0xFF1B7A43),
          const FacilitiesScreen(),
        ),
      ],
      if (counters.referrals != null) ...[
        _StatSpec(
          'Referrals open',
          '${counters.referrals!.submitted + counters.referrals!.acknowledged}',
          HugeIcons.strokeRoundedShare01,
          const Color(0xFF0F766E),
          const ReferralsScreen(),
        ),
        _StatSpec(
          'Referrals closed',
          '${counters.referrals!.closed}',
          HugeIcons.strokeRoundedCheckmarkCircle01,
          const Color(0xFF1B7A43),
          const ReferralsScreen(),
        ),
      ],
      if (counters.audit != null)
        _StatSpec(
          'Audit events (24h)',
          '${counters.audit!.eventsLast24h}',
          HugeIcons.strokeRoundedAudit01,
          const Color(0xFF37474F),
          const AuditScreen(),
        ),
    ];

    if (specs.isEmpty) {
      return AsyncState.empty('No counters available for this account.');
    }

    return GridView.count(
      crossAxisCount: 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 12,
      crossAxisSpacing: 12,
      childAspectRatio: 1.55,
      children: [for (final t in specs) _StatCard(spec: t)],
    );
  }
}

class _StatSpec {
  const _StatSpec(this.label, this.value, this.icon, this.color, this.screen);

  final String label;
  final String value;
  final IconData icon;
  final Color color;
  final Widget screen;
}

class _StatCard extends StatelessWidget {
  const _StatCard({required this.spec});

  final _StatSpec spec;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        onTap: () => Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => spec.screen),
        ),
        borderRadius: BorderRadius.circular(16),
        child: Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: const Color(0xFFE2DDDB)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  color: spec.color.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Icon(spec.icon, color: spec.color, size: 18),
              ),
              const Spacer(),
              Text(
                spec.value,
                style: const TextStyle(
                  fontSize: 26,
                  fontWeight: FontWeight.w800,
                  height: 1,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                spec.label,
                style: const TextStyle(fontSize: 12, color: Colors.black54),
              ),
            ],
          ),
        ),
      ),
    );
  }
}


