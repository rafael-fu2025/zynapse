import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/profile.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';
import '../queue/your_queue_section.dart';
import '../referrals/referrals_screen.dart';
import 'portal_appointments_section.dart';

/// My Portal — the caller's identity profile + clinic-visit history.
///
/// Mirrors `frontend/src/pages/EmployeePortalPage.tsx` and
/// `StudentPortalPage.tsx`. Routes to the employee or student endpoint
/// based on the session (`/me/employee-profile` vs `/me/student-profile`,
/// `/me/clinic-visits` vs `/me/student-clinic-visits`).
class PortalScreen extends StatefulWidget {
  const PortalScreen({super.key});

  @override
  State<PortalScreen> createState() => _PortalScreenState();
}

class _PortalScreenState extends State<PortalScreen>
    with AutoPolling<PortalScreen> {
  bool _isStudent = false;
  bool _loading = true;
  bool _notOnRegistry = false;
  String? _error;
  UserProfile? _profile;
  List<ClinicVisit> _visits = [];
  bool _loadedOnce = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _determineAndLoad();
    });
    // Live updates — mirrors the SPA's 30s portal `refetchInterval`.
    startPolling(const Duration(seconds: 30), _silentRefresh);
  }

  /// Polled live update: silently refresh the profile + clinic-visit history
  /// so new visits appear without a manual refresh. Transient errors keep the
  /// current data on screen.
  Future<void> _silentRefresh() async {
    if (_notOnRegistry) return;
    try {
      final profile = _isStudent
          ? await ApiService.I.studentProfile()
          : await ApiService.I.employeeProfile();
      final visits = await ApiService.I.clinicVisits(student: _isStudent);
      if (!mounted) return;
      setState(() {
        _profile = profile;
        _visits = visits;
      });
    } catch (_) {
      // Keep the current profile / visits.
    }
  }

  void _determineAndLoad() {
    final session = context.read<AuthController>().session;
    _isStudent = session?.isStudent ?? false;
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
      _notOnRegistry = false;
    });
    try {
      final profile = _isStudent
          ? await ApiService.I.studentProfile()
          : await ApiService.I.employeeProfile();
      final visits = await ApiService.I.clinicVisits(student: _isStudent);
      setState(() {
        _profile = profile;
        _visits = visits;
        _loadedOnce = true;
        _loading = false;
      });
    } catch (e) {
      final api = mapDioError(e);
      // Not-on-registry (404 employee.not_registered / student.not_registered):
      // clinic & counselling staff (system accounts with no employee row) and
      // any unlinked account hit this. Mirror the web's friendly empty state
      // instead of a raw error so the portal still "loads".
      final notOnRegistry = api.statusCode == 404 &&
          (api.first?.code == 'employee.not_registered' ||
              api.first?.code == 'student.not_registered');
      setState(() {
        _notOnRegistry = notOnRegistry;
        _error = notOnRegistry ? null : api.message;
        _loading = false;
      });
    }
  }

  Future<void> _changePassword() async {
    final payload = await showCrudForm(
      context,
      title: 'Change password',
      fields: const [
        CrudField.text('current_password', 'Current password',
            hint: 'Min 8 characters'),
        CrudField.text('new_password', 'New password',
            hint: 'Min 12 characters'),
        CrudField.text('new_password_confirm', 'Confirm new password'),
      ],
      submitLabel: 'Update',
    );
    if (payload == null || !mounted) return;
    final current = payload['current_password'] as String? ?? '';
    final next = payload['new_password'] as String? ?? '';
    final confirm = payload['new_password_confirm'] as String? ?? '';
    if (next != confirm) {
      showCrudMessage(context, 'New passwords do not match.', error: true);
      return;
    }
    final ok = await runCrudAction(
      context,
      () => ApiService.I.changePassword(
          currentPassword: current, newPassword: next),
      successMessage: 'Password changed — other sessions signed out.',
    );
    if (ok && mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return AsyncState.loading();
    if (_notOnRegistry) return _NotOnRegistry(student: _isStudent);
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    final profile = _profile;
    if (profile == null) {
      return AsyncState.empty('No profile on file for this account.');
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // Live "Your queue" card — polls every 10s (mirrors the web
          // portal's YourQueueCard) so the caller can track their turn.
          YourQueueSection(student: _isStudent),
          const PortalAppointmentsSection(),
          const SizedBox(height: 12),
          _ProfileCard(
            profile: profile,
            avatarAsset:
                avatarAssetFor(context.read<AuthController>().session),
          ),
          const SizedBox(height: 12),
          if (_isStudent)
            _StudentFields(profile: profile)
          else
            _EmployeeFields(profile: profile),
          const SizedBox(height: 12),
          SectionCard(
            title: 'My clinic visits',
            icon: HugeIcons.strokeRoundedStethoscope,
            child: _visits.isEmpty
                ? const Padding(
                    padding: EdgeInsets.symmetric(vertical: 12),
                    child: Text(
                      'No clinic visits on record.',
                      style: TextStyle(color: Colors.black54),
                    ),
                  )
                : Column(
                    children: [
                      for (final visit in _visits) _VisitRow(visit: visit),
                    ],
                  ),
          ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: _changePassword,
            icon: const Icon(HugeIcons.strokeRoundedLock),
            label: const Text('Change password'),
            style: OutlinedButton.styleFrom(
              padding: const EdgeInsets.symmetric(vertical: 14),
            ),
          ),
          if (!_isStudent) ...[
            const SizedBox(height: 12),
            // Teaching-only quick action — mirrors the web employee
            // portal's "Refer a student to counselling" button. The
            // backend enforces `is_teaching=1` with a 403
            // (code: referral.teaching_required), so we hide/enable it
            // preemptively by the profile flag.
            _ReferralAction(
              teaching: profile.isTeaching == true,
              onRefer: () => Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (_) => const ReferralsScreen(),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Friendly empty state for accounts with no student/employee registry row
/// (404 `*.not_registered`) — e.g. clinic & counselling staff who are
/// system accounts. Mirrors the web's `NotOnRegistry` (EmployeePortalPage /
/// StudentPortalPage) so the portal "loads" instead of erroring.
class _NotOnRegistry extends StatelessWidget {
  const _NotOnRegistry({required this.student});

  final bool student;

  @override
  Widget build(BuildContext context) {
    final label = student ? 'student' : 'employee';
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(
              HugeIcons.strokeRoundedId,
              size: 56,
              color: Colors.black38,
            ),
            const SizedBox(height: 16),
            Text(
              'No $label record on file',
              style: const TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              'Your account is signed in, but we could not find a matching '
              '$label row in the patient registry. Reach out to HR so they '
              'can link your account.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.black54, fontSize: 13),
            ),
          ],
        ),
      ),
    );
  }
}

/// "Refer a student to counselling" quick action (teaching employees).
///
/// Mirrors `frontend/src/pages/EmployeePortalPage.tsx`: teaching faculty
/// get a working button that opens the Referrals screen (where "New
/// referral" creates a clinic→counselling referral); non-teaching staff
/// get a disabled button + explanatory caption.
class _ReferralAction extends StatelessWidget {
  const _ReferralAction({required this.teaching, required this.onRefer});

  final bool teaching;
  final VoidCallback onRefer;

  @override
  Widget build(BuildContext context) {
    if (teaching) {
      return OutlinedButton.icon(
        onPressed: onRefer,
        icon: const Icon(HugeIcons.strokeRoundedShare01),
        label: const Text('Refer a student to counselling'),
        style: OutlinedButton.styleFrom(
          padding: const EdgeInsets.symmetric(vertical: 14),
        ),
      );
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        OutlinedButton.icon(
          onPressed: null,
          icon: const Icon(HugeIcons.strokeRoundedShare01),
          label: const Text('Refer a student to counselling'),
          style: OutlinedButton.styleFrom(
            padding: const EdgeInsets.symmetric(vertical: 14),
          ),
        ),
        const SizedBox(height: 6),
        Text(
          'Only teaching employees (faculty) can refer students to '
          'counselling. Talk to your supervisor if you believe this is '
          'in error.',
          style: Theme.of(context)
              .textTheme
              .bodySmall
              ?.copyWith(color: Colors.black45),
        ),
      ],
    );
  }
}

class _ProfileCard extends StatelessWidget {
  const _ProfileCard({required this.profile, required this.avatarAsset});

  final UserProfile profile;
  final String avatarAsset;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return SectionCard(
      title: profile.isStudent ? 'Student profile' : 'Employee profile',
      icon: HugeIcons.strokeRoundedId,
      child: Row(
        children: [
          CircleAvatar(
            radius: 28,
            backgroundColor: Colors.white,
            // Maroon ring around the crest, matching the header identity
            // pill so the logo reads clearly against the white card.
            child: Container(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                border: Border.all(
                  color: scheme.primary.withValues(alpha: 0.9),
                  width: 1.5,
                ),
              ),
              child: ClipOval(
                child: Image.asset(
                  avatarAsset,
                  width: 56,
                  height: 56,
                  fit: BoxFit.cover,
                ),
              ),
            ),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  profile.fullName,
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  profile.isStudent
                      ? 'Student #${profile.studentNumber ?? '—'}'
                      : 'Employee #${profile.employeeNumber ?? '—'}',
                  style: const TextStyle(color: Colors.black54),
                ),
                if (profile.kioskIdentifier != null) ...[
                  const SizedBox(height: 6),
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: scheme.primary.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(HugeIcons.strokeRoundedQrCode01,
                            size: 14, color: scheme.primary),
                        const SizedBox(width: 6),
                        Text(
                          profile.kioskIdentifier!,
                          style: TextStyle(
                            fontFamily: 'monospace',
                            fontSize: 12,
                            color: scheme.primary,
                          ),
                        ),
                        const SizedBox(width: 2),
                        InkWell(
                          onTap: () {
                            Clipboard.setData(
                              ClipboardData(text: profile.kioskIdentifier!),
                            );
                            ScaffoldMessenger.of(context)
                              ..hideCurrentSnackBar()
                              ..showSnackBar(
                                const SnackBar(
                                  content: Text('Kiosk identifier copied'),
                                  duration: Duration(seconds: 1),
                                ),
                              );
                          },
                          borderRadius: BorderRadius.circular(4),
                          child: const Padding(
                            padding: EdgeInsets.all(2),
                            child: Icon(
                              HugeIcons.strokeRoundedCopy01,
                              size: 14,
                              color: Color(0xFF800000),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _StudentFields extends StatelessWidget {
  const _StudentFields({required this.profile});

  final UserProfile profile;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: 'Details',
      icon: HugeIcons.strokeRoundedBook02,
      child: _InfoList(
        rows: [
          ('Course', profile.course),
          ('Year level', profile.yearLevel?.toString()),
          ('Section', profile.section),
          ('Blood type', profile.bloodType),
          ('Gender', profile.gender),
          ('Birth date', profile.dateOfBirth),
          ('No-shows', '${profile.consecutiveNoShows}'),
        ],
      ),
    );
  }
}

class _EmployeeFields extends StatelessWidget {
  const _EmployeeFields({required this.profile});

  final UserProfile profile;

  @override
  Widget build(BuildContext context) {
    return SectionCard(
      title: 'Details',
      icon: HugeIcons.strokeRoundedBuilding01,
      child: _InfoList(
        rows: [
          ('Department', profile.department),
          ('Position', profile.position),
          ('Employment status', profile.employmentStatus),
          ('Date hired', profile.dateHired),
          ('Teaching', profile.isTeaching == null
              ? null
              : (profile.isTeaching! ? 'Teaching' : 'Non-teaching')),
          ('Emergency contact', profile.emergencyContactName),
          ('Contact phone', profile.emergencyContactPhone),
        ],
      ),
    );
  }
}

class _InfoList extends StatelessWidget {
  const _InfoList({required this.rows});

  final List<(String, String?)> rows;

  @override
  Widget build(BuildContext context) {
    final present =
        rows.where((r) => r.$2 != null && r.$2!.isNotEmpty).toList();
    if (present.isEmpty) {
      return const Text(
        'No details on record.',
        style: TextStyle(color: Colors.black54),
      );
    }
    return Column(
      children: [
        for (final (i, row) in present.indexed) ...[
          if (i > 0) const Divider(height: 1),
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SizedBox(
                  width: 130,
                  child: Text(
                    row.$1,
                    style: const TextStyle(color: Colors.black54),
                  ),
                ),
                Expanded(
                  child: Text(
                    row.$2!,
                    style: const TextStyle(fontWeight: FontWeight.w600),
                  ),
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }
}

class _VisitRow extends StatelessWidget {
  const _VisitRow({required this.visit});

  final ClinicVisit visit;

  @override
  Widget build(BuildContext context) {
    final color = switch (visit.status) {
      'closed' => const Color(0xFF1B7A43),
      'referred' => const Color(0xFF0F766E),
      _ => const Color(0xFF1E6FD9),
    };
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  visit.chiefComplaint.isEmpty
                      ? 'Visit #${visit.id}'
                      : visit.chiefComplaint,
                  style: const TextStyle(fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 2),
                Text(
                  fmtUtcToApp(visit.startedAt),
                  style: const TextStyle(color: Colors.black54, fontSize: 12),
                ),
                if (visit.triagePriority != null &&
                    visit.triagePriority!.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Wrap(
                    spacing: 6,
                    runSpacing: 4,
                    children: [
                      StatusBadge(
                        label: 'Triage ${titleCaseOption(visit.triagePriority!)}',
                        color: const Color(0xFFB45309),
                      ),
                      if (visit.attendingUsername != null &&
                          visit.attendingUsername!.isNotEmpty)
                        Text(
                          'By ${visit.attendingUsername}',
                          style: const TextStyle(
                              color: Colors.black54, fontSize: 12),
                        ),
                    ],
                  ),
                ],
              ],
            ),
          ),
          StatusBadge(label: titleCaseOption(visit.status), color: color),
        ],
      ),
    );
  }
}
