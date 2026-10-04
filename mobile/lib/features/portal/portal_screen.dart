import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
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
import 'portal_surveys_section.dart';

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
  // Position-history selector: which of the caller's MIS records the
  // clinic-visits list shows. Null = the newest record (the primary).
  int? _selectedRecordId;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _determineAndLoad();
    });
    // Live updates — mirrors the SPA's 30s portal `refetchInterval`.
    startPolling(const Duration(seconds: 30), _silentRefresh);
  }

  /// The MIS record whose clinic visits to show: the user's pick, else
  /// the person's newest record (the primary) when they hold several.
  int? _effectiveRecordId(UserProfile profile) {
    if (_isStudent || profile.records.length <= 1) return null;
    return _selectedRecordId ?? profile.primaryRecord?.id;
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
      final visits = await ApiService.I.clinicVisits(
        student: _isStudent,
        recordId: _effectiveRecordId(profile),
      );
      if (!mounted) return;
      setState(() {
        _profile = profile;
        _visits = visits;
      });
    } catch (e) {
      // Keep the current profile / visits.
      if (kDebugMode) debugPrint('PortalScreen.poll failed: $e');
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
      final visits = await ApiService.I.clinicVisits(
        student: _isStudent,
        recordId: _effectiveRecordId(profile),
      );
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
      () => ApiService.I
          .changePassword(currentPassword: current, newPassword: next),
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
          // Guidance office surveys & interviews — students only (the
          // /me/guidance/* feeds are student-scoped on the backend).
          if (_isStudent) ...[
            const SizedBox(height: 12),
            const PortalSurveysSection(),
          ],
          const SizedBox(height: 12),
          _ProfileCard(
            profile: profile,
            email: context.read<AuthController>().session?.email ?? '',
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
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // Position-history pills — only when the person holds
                // several MIS records (mirrors the web portal).
                if (!_isStudent && profile.records.length > 1) ...[
                  _RecordSelector(
                    records: profile.records,
                    selectedId: _effectiveRecordId(profile),
                    onSelect: (id) {
                      setState(() => _selectedRecordId = id);
                      _load();
                    },
                  ),
                  if (_effectiveRecordId(profile) != profile.id)
                    const Padding(
                      padding: EdgeInsets.only(bottom: 8),
                      child: Text(
                        'Showing the visits recorded under the selected MIS record.',
                        style: TextStyle(color: Colors.black54, fontSize: 11),
                      ),
                    ),
                ],
                if (_visits.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 12),
                    child: Text(
                      'No clinic visits on record.',
                      style: TextStyle(color: Colors.black54),
                    ),
                  )
                else
                  Column(
                    children: [
                      for (final visit in _visits) _VisitRow(visit: visit),
                    ],
                  ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          if (context.read<AuthController>().session?.hasLocalPassword ?? false)
            OutlinedButton.icon(
              onPressed: _changePassword,
              icon: const Icon(HugeIcons.strokeRoundedLock),
              label: const Text('Change password'),
              style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 14),
              ),
            )
          else
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 8),
              child: Text(
                'Your password is managed by the university — to reset it, '
                'email helpdesk@foundationu.com.',
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.black54, fontSize: 12),
              ),
            ),
          if (!_isStudent) ...[
            const SizedBox(height: 12),
            // Quick action — mirrors the web employee portal's "Refer a
            // student to counselling" button. Every employee can refer
            // (the former teaching-only service gate was removed);
            // `referrals.create` is what governs access.
            OutlinedButton.icon(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (_) => const ReferralsScreen(),
                ),
              ),
              icon: const Icon(HugeIcons.strokeRoundedShare01),
              label: const Text('Refer a student to counselling'),
              style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 14),
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

/// The ATM-style identity card at the top of the portal — mirrors
/// `frontend/src/components/PortalProfileCard.tsx`: maroon card face with a
/// white-faded crest watermark on the right edge (PortalCardArt), uppercase
/// caption, name, `ID:` line, and the portal's field rows anchored to the
/// card's bottom edge behind a 248px min-height floor.
class _ProfileCard extends StatelessWidget {
  const _ProfileCard({required this.profile, required this.email});

  final UserProfile profile;
  final String email;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final isStudent = profile.isStudent;
    // MIS issues one record per appointment — the card shows the person's
    // newest record (the primary), mirroring the web portal's card.
    final primary = profile.primaryRecord;

    final rows = <_CardField>[
      _CardField(
        'Email',
        value: email.isEmpty ? null : email,
        maxLines: 1,
      ),
      if (isStudent) ...[
        _CardField('Course', value: profile.course, emphasized: true),
        _CardField(
          'Year level',
          value: profile.yearLevel?.toString(),
        ),
        _CardField('Blood type', value: profile.bloodType),
        _CardField(
          'No-shows',
          value: null,
          valueWidget: profile.consecutiveNoShows == 0
              ? const _SolidBadge.light('Clean')
              : _SolidBadge.light('${profile.consecutiveNoShows}',
                  background: const Color(0xFFDC2626), foreground: Colors.white),
        ),
      ] else ...[
        _CardField('Department',
            value: primary?.department ?? profile.department,
            emphasized: true,
            maxLines: 1),
        _CardField('Position',
            value: primary?.position ?? profile.position, maxLines: 1),
        _CardField(
          'Status',
          value: titleCaseOption(profile.employmentStatus ?? 'active'),
        ),
        _CardField(
          'Type',
          value: null,
          valueWidget: profile.isTeaching == true
              ? const _SolidBadge.outline('Teaching')
              : const _SolidBadge.light('Non-teaching'),
        ),
      ],
    ];

    // Web name construction: `first [middle] last` with the FULL middle
    // name (the shared `fullName` getter keeps the mobile-only initial).
    final name = [
      profile.firstName,
      if (profile.middleName != null && profile.middleName!.isNotEmpty)
        profile.middleName!,
      profile.lastName,
    ].where((part) => part.isNotEmpty).join(' ');

    return ConstrainedBox(
      constraints: const BoxConstraints(minHeight: 248),
      child: IntrinsicHeight(
        child: Container(
          clipBehavior: Clip.hardEdge,
          decoration: BoxDecoration(
            color: scheme.primary,
            borderRadius: BorderRadius.circular(14),
            boxShadow: const [
              BoxShadow(
                color: Color(0x0F000000),
                offset: Offset(0, 1),
                blurRadius: 2,
              ),
              BoxShadow(
                color: Color(0x47000000),
                offset: Offset(0, 18),
                blurRadius: 40,
                spreadRadius: -12,
              ),
            ],
          ),
          child: Stack(
            children: [
              // PortalCardArt stand-in: the same levin7-white-fade mark the
              // web card layers over the maroon face, rasterized from
              // frontend/public/levin7-white-fade.svg. Capped at the web
              // card's absolute art height (~70% of its 15.5rem min-height)
              // since this card runs taller with the field rows.
              Positioned.fill(
                child: Align(
                  alignment: Alignment.centerRight,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxHeight: 176),
                    child: Image.asset(
                      'assets/levin7-white-fade.png',
                      fit: BoxFit.contain,
                    ),
                  ),
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Icon(
                          HugeIcons.strokeRoundedId,
                          size: 14,
                          color: Colors.white.withValues(alpha: 0.7),
                        ),
                        const SizedBox(width: 6),
                        Text(
                          (isStudent ? 'Student profile' : 'Employee profile')
                              .toUpperCase(),
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w500,
                            letterSpacing: 0.6,
                            color: Colors.white.withValues(alpha: 0.7),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    Text(
                      name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w600,
                        height: 1.25,
                        color: Colors.white,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      'ID: ${isStudent ? profile.studentNumber ?? '' : profile.employeeNumber ?? ''}',
                      style: TextStyle(
                        fontSize: 12,
                        color: Colors.white.withValues(alpha: 0.7),
                        fontFeatures: const [
                          FontFeature.tabularFigures(),
                        ],
                      ),
                    ),
                    const Spacer(),
                    _CardFieldTable(rows: rows),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// One label/value row of the identity card — the `<dt>/<dd>` pairs of the
/// web card's `<dl>` grid. A null [value] renders "N/A" dimmed unless
/// [valueWidget] supplies a badge instead.
class _CardField {
  const _CardField(
    this.label, {
    required this.value,
    this.emphasized = false,
    this.maxLines,
    this.valueWidget,
  });

  final String label;
  final String? value;
  final bool emphasized;
  final int? maxLines;
  final Widget? valueWidget;
}

/// Two-column table mirroring the web card's `grid-cols-[max-content_1fr]`
/// field grid: intrinsic-width label column, values filling the rest.
class _CardFieldTable extends StatelessWidget {
  const _CardFieldTable({required this.rows});

  final List<_CardField> rows;

  @override
  Widget build(BuildContext context) {
    return Table(
      columnWidths: const {
        0: IntrinsicColumnWidth(),
        1: FlexColumnWidth(),
      },
      children: [
        for (final row in rows)
          TableRow(
            children: [
              Padding(
                padding: const EdgeInsets.only(right: 12, bottom: 6),
                child: Text(
                  row.label,
                  style: TextStyle(
                    fontSize: 12,
                    color: Colors.white.withValues(alpha: 0.6),
                  ),
                ),
              ),
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                // Table cells force their width onto children — left-align
                // badges so the pill hugs its label instead of stretching.
                child: row.valueWidget != null
                    ? Align(
                        alignment: Alignment.centerLeft,
                        child: row.valueWidget,
                      )
                    : Text(
                        row.value ?? 'N/A',
                        maxLines: row.maxLines,
                        overflow: row.maxLines == null
                            ? null
                            : TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: row.emphasized
                              ? FontWeight.w500
                              : FontWeight.w400,
                          color: row.value == null
                              ? Colors.white.withValues(alpha: 0.6)
                              : Colors.white,
                        ),
                      ),
              ),
            ],
          ),
      ],
    );
  }
}

/// Solid pill badge matching shadcn's Badge (secondary/destructive/outline)
/// on the maroon card face, where the app's tinted [StatusBadge] would lose
/// contrast.
class _SolidBadge extends StatelessWidget {
  const _SolidBadge.light(
    this.label, {
    this.background = Colors.white,
    this.foreground = const Color(0xFF3F3F46),
  })  : bordered = false,
        borderColor = null;

  const _SolidBadge.outline(String label)
      : this._(
          label,
          background: Colors.transparent,
          foreground: Colors.white,
          bordered: true,
          borderColor: Colors.white54,
        );

  const _SolidBadge._(
    this.label, {
    required this.background,
    required this.foreground,
    required this.bordered,
    required this.borderColor,
  });

  final String label;
  final Color background;
  final Color foreground;
  final bool bordered;
  final Color? borderColor;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(999),
        border: bordered ? Border.all(color: Colors.white54) : null,
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 11,
          fontWeight: FontWeight.w600,
          color: foreground,
        ),
      ),
    );
  }
}

class _StudentFields extends StatelessWidget {
  const _StudentFields({required this.profile});

  final UserProfile profile;

  @override
  Widget build(BuildContext context) {
    // Web parity: the portal shows nothing when the mobile-only extras
    // (section / gender / birth date) are all absent — no empty card.
    final hasRows = [
      profile.section,
      profile.gender,
      profile.dateOfBirth,
    ].any((v) => v != null && v.isNotEmpty);
    if (!hasRows) return const SizedBox.shrink();
    return SectionCard(
      title: 'Details',
      icon: HugeIcons.strokeRoundedBook02,
      child: _InfoList(
        rows: [
          // Course / year level / blood type / no-shows now live inside the
          // identity card (mirroring the web portal card); these are the
          // mobile-only extras.
          ('Section', profile.section),
          ('Gender', profile.gender),
          ('Birth date', profile.dateOfBirth),
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
    // Web parity: hide the card when the mobile-only extras (date hired /
    // emergency contact) are all absent — no empty card.
    final hasRows = [
      profile.dateHired,
      profile.emergencyContactName,
      profile.emergencyContactPhone,
    ].any((v) => v != null && v.isNotEmpty);
    if (!hasRows) return const SizedBox.shrink();
    return SectionCard(
      title: 'Details',
      icon: HugeIcons.strokeRoundedBuilding01,
      child: _InfoList(
        rows: [
          // Department / position / status / teaching type now live inside
          // the identity card (mirroring the web portal card); these are
          // the mobile-only extras.
          ('Date hired', profile.dateHired),
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

/// Position-history pills — one per MIS record of the caller, shown only
/// when a person holds several records. Selecting one reloads the clinic
/// visits for that record. Mirrors the web portal's record selector.
class _RecordSelector extends StatelessWidget {
  const _RecordSelector({
    required this.records,
    required this.selectedId,
    required this.onSelect,
  });

  final List<EmployeeRecord> records;
  final int? selectedId;
  final ValueChanged<int> onSelect;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final r in records)
            ChoiceChip(
              selected: r.id == selectedId,
              onSelected: (_) => onSelect(r.id),
              label: Text(
                '${r.positionYear ?? r.employeeNumber}'
                ' · ${r.position ?? 'No position'}'
                '${r.isPrimary ? ' (current)' : ''}'
                ' · ${r.visitCount} visit${r.visitCount == 1 ? '' : 's'}',
                style: const TextStyle(fontSize: 11),
              ),
            ),
        ],
      ),
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
                        label:
                            'Triage ${titleCaseOption(visit.triagePriority!)}',
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
