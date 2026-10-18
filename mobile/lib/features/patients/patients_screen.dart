import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/counselling.dart';
import '../../core/models/profile.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../../core/utils/university_email.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';

/// Patient registry — students + employees with live search.
///
/// Mirrors `frontend/src/pages/PatientsPage.tsx`:
/// `GET /clinic/students` / `/clinic/employees` (keyset pagination) and
/// `GET /clinic/students/search` / `/clinic/employees/search` (plain list).
class PatientsScreen extends StatefulWidget {
  const PatientsScreen({super.key});

  @override
  State<PatientsScreen> createState() => _PatientsScreenState();
}

class _PatientsScreenState extends State<PatientsScreen> {
  final _searchController = TextEditingController();
  Timer? _searchDebounce;
  bool _employee = false;
  String _query = '';
  bool _showArchived = false;
  String _teaching = 'all'; // all | teaching | non_teaching (employees)
  bool _loading = true;
  String? _error;
  List<UserProfile> _items = [];
  String? _nextCursor;
  bool _loadingMore = false;
  bool _loadedOnce = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchDebounce?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      if (_query.length >= 2) {
        final results = _employee
            ? await ApiService.I.employeeSearch(
                _query,
                includeArchived: _showArchived,
              )
            : await ApiService.I.studentSearch(
                _query,
                includeArchived: _showArchived,
              );
        setState(() {
          _items = results;
          _nextCursor = null;
          _loadedOnce = true;
          _loading = false;
        });
      } else {
        final page = _employee
            ? await ApiService.I.employees(
                includeArchived: _showArchived,
                teaching: _teaching,
              )
            : await ApiService.I.students(includeArchived: _showArchived);
        setState(() {
          _items = page.items;
          _nextCursor = page.meta?.nextCursor;
          _loadedOnce = true;
          _loading = false;
        });
      }
    } catch (e) {
      setState(() {
        _error = mapDioError(e).message;
        _loading = false;
      });
    }
  }

  Future<void> _loadMore() async {
    if (_loadingMore || _nextCursor == null || _query.length >= 2) return;
    setState(() => _loadingMore = true);
    try {
      final page = _employee
          ? await ApiService.I.employees(
              cursor: _nextCursor,
              includeArchived: _showArchived,
              teaching: _teaching,
            )
          : await ApiService.I.students(
              cursor: _nextCursor,
              includeArchived: _showArchived,
            );
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // ignore
      if (kDebugMode) debugPrint('PatientsScreen.loadMore failed: $e');
    } finally {
      setState(() => _loadingMore = false);
    }
  }

  void _onSearchChanged(String value) {
    setState(() => _query = value.trim());
    // Debounce like the SPA (300 ms) so we don't hit the API per keystroke.
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 300), _load);
  }

  bool get _canWrite =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('clinic.patients.write') ??
      false;

  /// Guidance-side action (2026-09-27): clinic records and guidance
  /// records carry different actions — a viewer without registry write
  /// but with `referrals.create` (guidance staff) refers a student to
  /// the clinic. Mirrors the web's guidance-side Records.
  bool get _canRefer =>
      !_canWrite &&
      (context
              .read<AuthController>()
              .session
              ?.hasPermission('referrals.create') ??
          false);

  /// `counselling.records.read` — gates the per-person guidance record,
  /// mirroring the SPA's `canReadGuidance` gate. Clinic permissions alone
  /// never expose the guidance record (privacy split, 2026-10).
  bool get _canReadGuidance =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('counselling.records.read') ??
      false;

  /// `referrals.create` — the SPA's `canRefer`. Held by BOTH bridge-side
  /// groups (clinic staff and guidance staff), so it must NOT be folded
  /// into the guidance-record permission: clinic staff raise
  /// clinic → Guidance referrals without holding counselling read.
  bool get _canCreateReferral =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('referrals.create') ??
      false;

  List<CrudField> _studentFields([UserProfile? p]) => [
        CrudField.text('first_name', 'First name', initial: p?.firstName),
        CrudField.text('last_name', 'Last name', initial: p?.lastName),
        CrudField.text('middle_name', 'Middle name',
            required: false, initial: p?.middleName),
        if (p == null) const CrudField.text('student_number', 'Student number'),
        CrudField.text('course', 'Course', required: false, initial: p?.course),
        CrudField.number('year_level', 'Year level',
            required: false, initial: p?.yearLevel?.toString()),
        CrudField.text('section', 'Section',
            required: false, initial: p?.section),
        const CrudField.dropdown(
            'gender', 'Gender', ['male', 'female', 'other'],
            required: false),
        CrudField.text('blood_type', 'Blood type',
            required: false, initial: p?.bloodType),
        if (p == null)
          CrudField.text('account_email', 'Account email (optional)',
              required: false,
              keyboard: TextInputType.emailAddress,
              // Live prefill from the name fields — the web register
              // dialogs' university-address convention; editing the field
              // latches it so the user's value is never overwritten.
              derive: (values) => deriveUniversityEmail(
                  values['first_name'], values['last_name'])),
      ];

  List<CrudField> _employeeFields([UserProfile? p]) => [
        CrudField.text('first_name', 'First name', initial: p?.firstName),
        CrudField.text('last_name', 'Last name', initial: p?.lastName),
        CrudField.text('department', 'Department',
            required: false, initial: p?.department),
        CrudField.text('position', 'Position',
            required: false, initial: p?.position),
        CrudField.dropdown('employment_status', 'Employment status',
            ['active', 'inactive', 'on_leave'],
            required: false, initial: p?.employmentStatus ?? 'active'),
        CrudField.bool('is_teaching', 'Teaching faculty',
            boolInitial: p?.isTeaching == true),
        if (p == null)
          const CrudField.text('employee_number', 'Employee number'),
        if (p == null)
          CrudField.text('account_email', 'Account email (optional)',
              required: false,
              keyboard: TextInputType.emailAddress,
              // Same account-email prefill as the student dialog.
              derive: (values) => deriveUniversityEmail(
                  values['first_name'], values['last_name'])),
      ];

  Future<void> _create() async {
    final isEmployee = _employee;
    final payload = await showCrudForm(
      context,
      title: isEmployee ? 'Add employee' : 'Add student',
      fields: isEmployee ? _employeeFields() : _studentFields(),
      submitLabel: 'Add',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => isEmployee
          ? ApiService.I.createEmployee(payload)
          : ApiService.I.createStudent(payload),
      successMessage:
          isEmployee ? 'Employee registered.' : 'Student registered.',
    );
    if (ok) _load();
  }

  Future<void> _editPerson(UserProfile p) async {
    final payload = await showCrudForm(
      context,
      title: p.isStudent ? 'Edit student' : 'Edit employee',
      fields: p.isStudent ? _studentFields(p) : _employeeFields(p),
      submitLabel: 'Save',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => p.isStudent
          ? ApiService.I.updateStudent(p.id, payload)
          : ApiService.I.updateEmployee(p.id, payload),
      successMessage: 'Patient record updated.',
    );
    if (ok) _load();
  }

  Future<void> _archive(UserProfile p) async {    final confirmed = await showCrudConfirm(
      context,
      title: p.archived ? 'Restore patient?' : 'Archive patient?',
      message: p.archived
          ? 'Restore ${p.displayName}?'
          : 'Archive ${p.displayName}? The account stays, but they leave the registry view.',
      confirmLabel: p.archived ? 'Restore' : 'Archive',
      destructive: !p.archived,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => p.isStudent
          ? ApiService.I.setStudentArchived(p.id, !p.archived)
          : ApiService.I.setEmployeeArchived(p.id, !p.archived),
      successMessage: p.archived ? 'Patient restored.' : 'Patient archived.',
    );
    if (ok) _load();
  }

  /// Opens the guidance refer sheet — fixed direction (Counselling →
  /// Clinic), fixed patient, artifact + optional reason/notes. Posts
  /// through the same `POST /referrals` pipeline the Referrals module
  /// uses. Mirrors the web `ReferStudentDialog`.
  Future<void> _refer(UserProfile person) async {
    final payload = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => _ReferSheet(person: person),
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.createReferral(payload),
      successMessage: 'Referral created.',
    );
    if (ok) _load();
  }

  /// Opens the patient detail sheet — allergies + emergency contacts
  /// (add/edit/remove) for BOTH kinds, plus the profile sections.
  /// Mirrors the web View dialog (`StudentDetailDialog` /
  /// `EmployeeDetailDialog`).
  ///
  /// [openMedical] scrolls straight to the medical block — the
  /// "Manage medical record" action's destination, matching the SPA's
  /// separate menu entry that opens the same dialog.
  Future<void> _viewDetail(UserProfile p, {bool openMedical = false}) async {
    if (!mounted) return;
    final fresh = await showDialog<UserProfile>(
      context: context,
      barrierDismissible: false,
      builder: (dialogContext) => _DetailSheet(
        person: p,
        openMedical: openMedical,
      ),
    );
    if (fresh != null && mounted) {
      // Detail edits returned an updated row; refresh the list.
      _load();
    }
  }

  /// Opens the per-person guidance record sheet — counselling session
  /// history (date, counsellor, open/closed). Gated by
  /// `counselling.records.read`; mirrors the SPA's GuidanceRecordSection.
  /// Notes are NOT part of this payload.
  Future<void> _viewGuidanceRecord(UserProfile p) async {
    final number = p.registryNumber;
    if (number == null || number.isEmpty) return;
    if (!mounted) return;
    await showSynapseSheet<void>(
      context,
      builder: (_) => _GuidanceRecordSheet(
        personName: p.displayName,
        registryNumber: number,
      ),
    );
  }

  /// Clinic → Guidance referral — the SPA's `ReferToGuidanceDialog`.
  /// Fixed direction (Clinic → Counselling), artifact `intake_pass`, and
  /// a required reason + priority. Only reason / notes / priority travel
  /// to Guidance; the clinic medical record stays confidential.
  /// A 409 `referral.active_duplicate` shows the existing referral.
  Future<void> _referToGuidance(UserProfile p) async {
    final number = p.registryNumber;
    if (number == null || number.isEmpty) return;
    final payload = await showSynapseSheet<Map<String, dynamic>>(
      context,
      builder: (_) => _ReferToGuidanceSheet(person: p, number: number),
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.createReferral(payload),
      successMessage: 'Referral sent to Guidance.',
    );
    if (ok) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Records')),
      floatingActionButton: _canWrite
          ? FloatingActionButton.extended(
              backgroundColor: const Color(0xFF800000),
              foregroundColor: Colors.white,
              onPressed: _create,
              icon: const Icon(HugeIcons.strokeRoundedUserMultiple),
              label: Text(_employee ? 'Add employee' : 'Add student'),
            )
          : null,
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: TextField(
              controller: _searchController,
              onChanged: _onSearchChanged,
              decoration: InputDecoration(
                hintText: 'Search number or name…',
                prefixIcon: const Icon(HugeIcons.strokeRoundedSearch01),
                isDense: true,
                suffixIcon: _query.isEmpty
                    ? null
                    : IconButton(
                        icon: const Icon(HugeIcons.strokeRoundedCancel01),
                        onPressed: () {
                          _searchController.clear();
                          _onSearchChanged('');
                        },
                      ),
              ),
            ),
          ),
          SegmentedButton<bool>(
            segments: const [
              ButtonSegment(
                  value: false,
                  label: Text('Students'),
                  icon: Icon(HugeIcons.strokeRoundedBook02)),
              ButtonSegment(
                  value: true,
                  label: Text('Employees'),
                  icon: Icon(HugeIcons.strokeRoundedId)),
            ],
            selected: {_employee},
            onSelectionChanged: (s) {
              setState(() => _employee = s.first);
              _load();
            },
          ),
          // Employee teaching filter + archive scope — mirrors the web
          // All/Teaching/Non-teaching select + Active/Archived toggle.
          // The archived chip applies to both kinds; teaching only to employees.
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
            child: Row(
              children: [
                if (_employee) ...[
                  Expanded(
                    child: DropdownButtonHideUnderline(
                      child: DropdownButtonFormField<String>(
                        initialValue: _teaching,
                        isDense: true,
                        decoration: const InputDecoration(
                          isDense: true,
                          contentPadding:
                              EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                          border: OutlineInputBorder(),
                        ),
                        items: const [
                          DropdownMenuItem(
                              value: 'all', child: Text('All employees')),
                          DropdownMenuItem(
                              value: 'teaching',
                              child: Text('Teaching (faculty)')),
                          DropdownMenuItem(
                              value: 'non_teaching',
                              child: Text('Non-teaching')),
                        ],
                        onChanged: (v) {
                          if (v == null) return;
                          setState(() => _teaching = v);
                          _load();
                        },
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                ],
                // Archive scope: off = active only, on = archived only.
                FilterChip(
                  label: const Text('Archived'),
                  selected: _showArchived,
                  onSelected: (v) {
                    setState(() => _showArchived = v);
                    _load();
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 4),
          Expanded(
            child: RefreshIndicator(onRefresh: _load, child: _buildBody()),
          ),
        ],
      ),
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty(
        _query.isEmpty ? 'No patients in this view.' : 'No matches.',
      );
    }
    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _items.length + (_nextCursor != null ? 1 : 0),
      // Pre-build rows ahead of the viewport so fast flings don't hitch on
      // widget builds — keeps long lists smooth at the 120Hz vsync.
      scrollCacheExtent: const ScrollCacheExtent.pixels(600),
      // Patient tiles are `isThreeLine` ListTiles = fixed 88dp + 8dp gap.
      // A fixed itemExtent lets the viewport skip layout of off-screen rows
      // (ListView.separated lost itemExtent in 3.44).
      itemExtent: 96,
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
        return _PatientTile(
          person: _items[i],
          canWrite: _canWrite,
          canRefer: _canRefer,
          canReadGuidance: _canReadGuidance,
          canCreateReferral: _canCreateReferral,
          onView: () => _viewDetail(_items[i]),
          onEdit: () => _editPerson(_items[i]),
          onArchive: () => _archive(_items[i]),
          onRefer: () => _refer(_items[i]),
          onReferGuidance: () => _referToGuidance(_items[i]),
          onGuidanceRecord: () => _viewGuidanceRecord(_items[i]),
          onManageMedical: () => _viewDetail(_items[i], openMedical: true),
        );
      },
    );
  }
}

/// Avatar placeholder initials — first + last name initials (e.g. "Juan
/// Dela Cruz" → "JD"). Falls back to a single initial or "?" when names are
/// missing.
String _avatarInitials(UserProfile p) {
  final first = p.firstName.trim();
  final last = p.lastName.trim();
  final f = first.isNotEmpty ? first[0].toUpperCase() : '';
  final l = last.isNotEmpty ? last[0].toUpperCase() : '';
  final initials = '$f$l';
  if (initials.isNotEmpty) return initials;
  final full = p.fullName.trim();
  return full.isNotEmpty ? full[0].toUpperCase() : '?';
}

class _PatientTile extends StatelessWidget {
  const _PatientTile({
    required this.person,
    required this.canWrite,
    required this.canRefer,
    this.canReadGuidance = false,
    this.canCreateReferral = false,
    this.onView,
    this.onEdit,
    this.onArchive,
    this.onRefer,
    this.onReferGuidance,
    this.onGuidanceRecord,
    this.onManageMedical,
  });

  final UserProfile person;
  final bool canWrite;

  /// Guidance-side refer action — students only, matching the web's
  /// guidance Records surface (`referrals.create`, no registry write).
  final bool canRefer;

  /// `counselling.records.read` — gates the per-person guidance record
  /// and the clinic→Guidance referral, exactly like the SPA. A clinic
  /// user does NOT get the guidance record from clinic permissions.
  final bool canReadGuidance;

  /// `referrals.create` — held by both bridge-side groups. The
  /// clinic → Guidance referral needs this, NOT the guidance read code.
  final bool canCreateReferral;

  final VoidCallback? onView;
  final VoidCallback? onEdit;
  final VoidCallback? onArchive;
  final VoidCallback? onRefer;
  final VoidCallback? onReferGuidance;
  final VoidCallback? onGuidanceRecord;
  final VoidCallback? onManageMedical;

  /// Opens the patient action sheet — a modern replacement for the default
  /// popup menu, mirroring the SPA's employee/student Actions menu
  /// (`frontend/src/pages/PatientsPage.tsx::employeeActions`):
  ///
  ///   View record · View guidance record* · Refer to clinic† ·
  ///   Manage medical record‡ · Refer to Guidance§ · Archive/Restore‡
  ///
  ///   *  `counselling.records.read`
  ///   †  guidance side (`referrals.create`, no registry write)
  ///   ‡  clinic side + `clinic.patients.write`, and never for
  ///      directory-only MIS rows
  ///   §  clinic side + `referrals.create`
  ///
  /// "Edit employee record" was removed from the SPA (2026-10) — the
  /// mobile sheet keeps only the actions the web still offers.
  void _openActions(BuildContext context) {
    final isEmployee = !person.isStudent;
    // Medical rows only exist for local users; synthetic MIS directory
    // rows have nothing to attach allergies/contacts to.
    final canManageMedical = canWrite && !person.isDirectoryRecord;
    showSynapseSheet<void>(
      context,
      builder: (sheetContext) => SafeArea(
        child: SingleChildScrollView(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                SheetHeader(
                  title: person.displayName,
                  subtitle: person.isStudent
                      ? (person.studentNumber ?? 'Student')
                      : (person.employeeNumber ?? 'Employee'),
                ),
                const SizedBox(height: 8),
                _ActionRow(
                  icon: HugeIcons.strokeRoundedView,
                  label: 'View record',
                  description: person.isStudent
                      ? 'Allergies, emergency contacts, profile'
                      : 'Profile, handles, emergency contact',
                  onTap: () {
                    Navigator.of(sheetContext).pop();
                    onView?.call();
                  },
                ),
                if (canWrite && !person.isDirectoryRecord) ...[
                  _ActionRow(
                    icon: HugeIcons.strokeRoundedPencilEdit01,
                    label: 'Edit record',
                    description: person.isStudent
                        ? 'Name, course, year, section, blood type'
                        : 'Name, department, position, employment status',
                    onTap: () {
                      Navigator.of(sheetContext).pop();
                      onEdit?.call();
                    },
                  ),
                ],
                if (canReadGuidance) ...[
                  _ActionRow(
                    icon: HugeIcons.strokeRoundedNote01,
                    label: 'View guidance record',
                    description: 'Counselling session history',
                    onTap: () {
                      Navigator.of(sheetContext).pop();
                      onGuidanceRecord?.call();
                    },
                  ),
                ],
                // Guidance's cross-action: raise a counselling → clinic
                // referral. The SPA offers this for BOTH kinds (2026-10),
                // not students only.
                if (canRefer) ...[
                  _ActionRow(
                    icon: HugeIcons.strokeRoundedShare01,
                    label: 'Refer to clinic',
                    description: isEmployee
                        ? 'Counselling → Clinic referral for this employee'
                        : 'Counselling → Clinic referral for this student',
                    onTap: () {
                      Navigator.of(sheetContext).pop();
                      onRefer?.call();
                    },
                  ),
                ],
                if (canManageMedical) ...[
                  _ActionRow(
                    icon: HugeIcons.strokeRoundedHealth,
                    label: 'Manage medical record',
                    description: 'Allergies and emergency contacts',
                    onTap: () {
                      Navigator.of(sheetContext).pop();
                      onManageMedical?.call();
                    },
                  ),
                ],
                // Clinic → Guidance referral. The SPA offers this on the
                // CLINIC side (`side === 'clinic' && canRefer`), so it is
                // gated on registry write + `referrals.create` — never the
                // guidance read code, which clinic staff do not hold.
                if (canWrite && canCreateReferral) ...[
                  _ActionRow(
                    icon: HugeIcons.strokeRoundedHeartCheck,
                    label: 'Refer to Guidance',
                    description: isEmployee
                        ? 'Clinic → Guidance referral for this employee'
                        : 'Clinic → Guidance referral for this student',
                    onTap: () {
                      Navigator.of(sheetContext).pop();
                      onReferGuidance?.call();
                    },
                  ),
                ],
                if (canWrite && !person.isDirectoryRecord) ...[
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 12),
                    child: Divider(height: 1),
                  ),
                  _ActionRow(
                    icon: person.archived
                        ? HugeIcons.strokeRoundedRefresh
                        : HugeIcons.strokeRoundedArchive01,
                    label: person.archived ? 'Restore' : 'Archive',
                    description: person.archived
                        ? 'Put the patient back on the registry'
                        : 'Remove from the registry view',
                    color: person.archived ? null : const Color(0xFFB3261E),
                    onTap: () {
                      Navigator.of(sheetContext).pop();
                      onArchive?.call();
                    },
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isStudent = person.isStudent;
    // Students get a blue accent, employees a teal accent — the avatar tint
    // + the type tag make the two registry kinds instantly distinguishable.
    final accent =
        isStudent ? const Color(0xFF1E6FD9) : const Color(0xFF0F766E);
    final accentBg =
        isStudent ? const Color(0xFFE8F0FE) : const Color(0xFFE6F4F1);

    // Line 1 — registry ID + academic/organisational context. The
    // department mirrors the web Records table's Department column
    // (MIS supplies it; hand-registered rows read "—"/absent).
    final line1 = isStudent
        ? [
            if (person.studentNumber?.isNotEmpty ?? false)
              person.studentNumber!
            else
              'No ID',
            if (person.course?.isNotEmpty ?? false) person.course!,
            if (person.department?.isNotEmpty ?? false) person.department!,
          ].join(' · ')
        : [
            if (person.employeeNumber?.isNotEmpty ?? false)
              person.employeeNumber!
            else
              'No ID',
            if (person.department?.isNotEmpty ?? false) person.department!,
          ].join(' · ');

    // Line 2 — secondary identity (always shown so rows stay uniform height).
    final line2 = isStudent
        ? [
            if (person.yearLevel != null) 'Year ${person.yearLevel}',
            if (person.section?.isNotEmpty ?? false) person.section!,
            if (person.bloodType?.isNotEmpty ?? false)
              'Type ${person.bloodType}',
            if (person.activeGuidanceReferral) 'Referred to Guidance',
          ].join(' · ')
        : [
            if (person.position?.isNotEmpty ?? false) person.position!,
            if (person.employmentStatus != null)
              titleCaseOption(person.employmentStatus!),
            if (person.activeGuidanceReferral) 'Referred to Guidance',
          ].join(' · ');

    // Type tag — mirrors the web's TeachingBadge (Teaching / Non-teaching /
    // Not classified). The student year-level tag was removed (2026-08-12)
    // per product request — students now carry no trailing tag.
    final String? tag;
    final Color tagColor;
    if (isStudent) {
      tag = null;
      tagColor = accent;
    } else if (person.isTeaching == true) {
      tag = 'Teaching';
      tagColor = const Color(0xFF0F766E);
    } else if (person.isTeaching == false) {
      tag = 'Non-teaching';
      tagColor = Colors.grey;
    } else {
      tag = null;
      tagColor = Colors.grey;
    }

    return Card(
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
      // isThreeLine keeps every row a fixed 88dp so the list's itemExtent
      // stays valid (the two subtitle lines are each ellipsized to one).
      child: ListTile(
        isThreeLine: true,
        leading: Container(
          width: 42,
          height: 42,
          decoration: BoxDecoration(color: accentBg, shape: BoxShape.circle),
          alignment: Alignment.center,
          child: Text(
            _avatarInitials(person),
            style: TextStyle(
              fontWeight: FontWeight.w700,
              color: accent,
              fontSize: 16,
            ),
          ),
        ),
        title: Text(
          person.displayName,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 3),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                line1,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 12.5, color: Colors.black54),
              ),
              const SizedBox(height: 2),
              Text(
                line2.isEmpty ? '—' : line2,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 11.5, color: Colors.black45),
              ),
            ],
          ),
        ),
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Patient identity QR — separate from appointment booking.
            if (person.hasQr)
              const StatusBadge(label: 'QR', color: Color(0xFF1E6FD9)),
            if (isStudent && person.consecutiveNoShows > 0)
              StatusBadge(
                label: '${person.consecutiveNoShows}× no-show',
                color: const Color(0xFFB45309),
              ),
            // MIS issues one record per appointment — flag employees who
            // hold several (the row itself is the newest record).
            if (!isStudent && person.records.length > 1)
              StatusBadge(
                label: '${person.records.length} records',
                color: const Color(0xFF1E6FD9),
              ),
            if (tag != null) StatusBadge(label: tag, color: tagColor),
            if (person.archived)
              const StatusBadge(label: 'Archived', color: Colors.black45),
            if (canWrite || (canRefer && person.isStudent)) ...[
              const SizedBox(width: 4),
              IconButton(
                icon: const Icon(HugeIcons.strokeRoundedMore),
                tooltip: 'Actions',
                onPressed: () => _openActions(context),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// Guidance refer sheet — fixed direction (Counselling → Clinic) and
/// fixed patient; the operator picks the artifact and may add a reason
/// or notes. Pops the same payload shape `_ReferralCreateSheet` produces
/// so `ApiService.I.createReferral` consumes it unchanged. Mirrors the
/// web `ReferStudentDialog`, extended to employees (2026-10) so the
/// SPA's guidance-side "Refer to clinic" action is reachable for both
/// kinds — not students only.
class _ReferSheet extends StatefulWidget {
  const _ReferSheet({required this.person});

  final UserProfile person;

  @override
  State<_ReferSheet> createState() => _ReferSheetState();
}

class _ReferSheetState extends State<_ReferSheet> {
  final _artifactController = TextEditingController(text: 'referral_letter');
  final _reasonController = TextEditingController();
  final _notesController = TextEditingController();
  bool _customArtifact = false;
  String? _submitError;

  @override
  void dispose() {
    _artifactController.dispose();
    _reasonController.dispose();
    _notesController.dispose();
    super.dispose();
  }

  void _submit() {
    final identifier = widget.person.registryNumber ?? '';
    final artifact = _artifactController.text.trim();
    if (identifier.isEmpty) {
      setState(() => _submitError = widget.person.isStudent
          ? 'This record has no student number and cannot be referred.'
          : 'This record has no employee number and cannot be referred.');
      return;
    }
    if (artifact.isEmpty) {
      setState(() => _submitError = 'Artifact type is required.');
      return;
    }
    final reason = _reasonController.text.trim();
    final notes = _notesController.text.trim();
    Navigator.of(context).pop(<String, dynamic>{
      'patient_school_id': identifier,
      'source_module': 'counselling',
      'target_module': 'clinic',
      'artifact_type': artifact,
      if (reason.isNotEmpty) 'reason_code': reason,
      if (notes.isNotEmpty) 'notes_plaintext': notes,
    });
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(
        20,
        12,
        20,
        20 + MediaQuery.of(context).viewInsets.bottom,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SheetHeader(
            title: 'Refer to clinic',
            subtitle:
                '${widget.person.displayName} · ${widget.person.registryNumber ?? (widget.person.isStudent ? 'Student' : 'Employee')}',
          ),
          const SizedBox(height: 4),
          const Text(
            'Direction: Counselling → Clinic (fixed)',
            style: TextStyle(color: Colors.black54, fontSize: 12.5),
          ),
          const SizedBox(height: 12),
          const Text(
            'Artifact type',
            style: TextStyle(
              fontSize: 12.5,
              fontWeight: FontWeight.w600,
              color: Colors.black87,
            ),
          ),
          const SizedBox(height: 6),
          if (_customArtifact) ...[
            TextField(
              controller: _artifactController,
              autofocus: true,
              decoration: const InputDecoration(
                hintText: 'e.g. clearance, school_letter',
                border: OutlineInputBorder(),
                isDense: true,
              ),
            ),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton(
                onPressed: () => setState(() {
                  _customArtifact = false;
                  _artifactController.text = 'referral_letter';
                }),
                child: const Text('Use preset'),
              ),
            ),
          ] else
            Wrap(
              spacing: 8,
              children: [
                ChoiceChip(
                  label: const Text('Referral letter'),
                  selected: _artifactController.text == 'referral_letter',
                  onSelected: (_) => setState(
                      () => _artifactController.text = 'referral_letter'),
                ),
                ChoiceChip(
                  label: const Text('Intake pass'),
                  selected: _artifactController.text == 'intake_pass',
                  onSelected: (_) =>
                      setState(() => _artifactController.text = 'intake_pass'),
                ),
                ChoiceChip(
                  label: const Text('Custom…'),
                  selected: false,
                  onSelected: (_) => setState(() => _customArtifact = true),
                ),
              ],
            ),
          const SizedBox(height: 10),
          TextField(
            controller: _reasonController,
            decoration: const InputDecoration(
              labelText: 'Reason code (optional)',
              border: OutlineInputBorder(),
              isDense: true,
            ),
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _notesController,
            maxLines: 3,
            decoration: const InputDecoration(
              labelText: 'Notes (optional)',
              border: OutlineInputBorder(),
            ),
          ),
          if (_submitError != null) ...[
            const SizedBox(height: 8),
            Text(
              _submitError!,
              style: TextStyle(
                color: Theme.of(context).colorScheme.error,
                fontSize: 13,
              ),
            ),
          ],
          const SizedBox(height: 12),
          FilledButton(
            onPressed: _submit,
            style: FilledButton.styleFrom(
              backgroundColor: const Color(0xFF800000),
              padding: const EdgeInsets.symmetric(vertical: 14),
            ),
            child: const Text('Create referral'),
          ),
        ],
      ),
    );
  }
}

/// Per-person guidance record — the counselling session history for one
/// student/employee. Mirrors the SPA's `GuidanceRecordSection`
/// (`frontend/src/components/clinic/GuidanceRecordSection.tsx`): date,
/// counsellor, open/closed, empty + error states. Notes are deliberately
/// NOT shown here — they stay behind their own session endpoints.
class _GuidanceRecordSheet extends StatefulWidget {
  const _GuidanceRecordSheet({
    required this.personName,
    required this.registryNumber,
  });

  final String personName;
  final String registryNumber;

  @override
  State<_GuidanceRecordSheet> createState() => _GuidanceRecordSheetState();
}

class _GuidanceRecordSheetState extends State<_GuidanceRecordSheet> {
  bool _loading = true;
  String? _error;
  List<CounsellingSession> _sessions = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final sessions =
          await ApiService.I.patientCounsellingSessions(widget.registryNumber);
      if (!mounted) return;
      setState(() {
        _sessions = sessions;
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

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SheetHeader(
              title: widget.personName,
              subtitle: 'Guidance record · ${widget.registryNumber}',
            ),
            const SizedBox(height: 8),
            Flexible(
              child: _loading
                  ? const SizedBox(
                      height: 140,
                      child: Center(child: CircularProgressIndicator()),
                    )
                  : _error != null
                      ? SizedBox(
                          height: 140,
                          child: AsyncState.error(_error!, onRetry: _load),
                        )
                      : _sessions.isEmpty
                          ? const Padding(
                              padding: EdgeInsets.symmetric(vertical: 18),
                              child: Text(
                                'No counselling sessions on record.',
                                style: TextStyle(
                                    color: Colors.black45, fontSize: 13),
                              ),
                            )
                          : ListView.separated(
                              shrinkWrap: true,
                              itemCount: _sessions.length,
                              separatorBuilder: (_, __) =>
                                  const Divider(height: 1),
                              itemBuilder: (context, i) {
                                final s = _sessions[i];
                                final open = s.isOpen;
                                return ListTile(
                                  contentPadding: EdgeInsets.zero,
                                  dense: true,
                                  leading: Icon(
                                    HugeIcons.strokeRoundedNote01,
                                    size: 20,
                                    color: open
                                        ? const Color(0xFF1B7A43)
                                        : Colors.black38,
                                  ),
                                  title: Text(
                                    fmtUtcShort(s.startedAt),
                                    style: const TextStyle(
                                        fontSize: 14,
                                        fontWeight: FontWeight.w600),
                                  ),
                                  subtitle: Text(
                                    s.counsellorName ?? 'Unassigned',
                                    style: const TextStyle(fontSize: 12),
                                  ),
                                  trailing: StatusBadge(
                                    label: open ? 'Open' : 'Closed',
                                    color: open
                                        ? const Color(0xFF1B7A43)
                                        : Colors.black45,
                                  ),
                                );
                              },
                            ),
            ),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: () => Navigator.of(context).pop(),
              icon: const Icon(HugeIcons.strokeRoundedCancel01, size: 18),
              label: const Text('Close'),
            ),
          ],
        ),
      ),
    );
  }
}

/// Clinic → Guidance referral — the SPA's `ReferToGuidanceDialog`.
/// Fixed direction (clinic → counselling), artifact `intake_pass`, and a
/// required reason + priority. Only reason / notes / priority travel to
/// Guidance; the clinic medical record stays confidential.
class _ReferToGuidanceSheet extends StatefulWidget {
  const _ReferToGuidanceSheet({required this.person, required this.number});

  final UserProfile person;
  final String number;

  @override
  State<_ReferToGuidanceSheet> createState() => _ReferToGuidanceSheetState();
}

class _ReferToGuidanceSheetState extends State<_ReferToGuidanceSheet> {
  /// Mirrors `GUIDANCE_REASON_OPTIONS` in
  /// `frontend/src/schemas/referrals.ts`.
  static const _reasons = <String, String>{
    'emotional_concern': 'Emotional concern',
    'behavioral_concern': 'Behavioral concern',
    'academic_stress': 'Academic stress',
    'family_concern': 'Family concern',
    'bullying': 'Bullying',
    'self_harm_risk': 'Self-harm risk',
    'other': 'Other',
  };

  /// Mirrors `REFERRAL_PRIORITIES`.
  static const _priorities = ['low', 'normal', 'high', 'urgent'];

  final _notesController = TextEditingController();
  String? _reason;
  String _priority = 'normal';
  String? _submitError;

  @override
  void dispose() {
    _notesController.dispose();
    super.dispose();
  }

  void _submit() {
    if (_reason == null || _reason!.isEmpty) {
      setState(() => _submitError = 'Pick a reason.');
      return;
    }
    final notes = _notesController.text.trim();
    Navigator.of(context).pop(<String, dynamic>{
      'patient_school_id': widget.number,
      'source_module': 'clinic',
      'target_module': 'counselling',
      'artifact_type': 'intake_pass',
      'reason_code': _reason,
      'priority': _priority,
      if (notes.isNotEmpty) 'notes_plaintext': notes,
    });
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 14, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SheetHeader(
              title: 'Refer to Guidance',
              subtitle: '${widget.person.displayName} · ${widget.number}',
            ),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFF5F5F4),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFFE7E5E4)),
              ),
              child: const Text(
                'Only the reason, notes, and priority are shared with the '
                'Guidance Center. The clinic medical record stays '
                'confidential to Clinic staff.',
                style: TextStyle(fontSize: 12, color: Colors.black54),
              ),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: _reason,
              decoration: const InputDecoration(
                labelText: 'Reason for referral',
                isDense: true,
              ),
              items: [
                for (final e in _reasons.entries)
                  DropdownMenuItem(value: e.key, child: Text(e.value)),
              ],
              onChanged: (v) => setState(() {
                _reason = v;
                _submitError = null;
              }),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<String>(
              initialValue: _priority,
              decoration: const InputDecoration(
                labelText: 'Priority',
                isDense: true,
              ),
              items: [
                for (final p in _priorities)
                  DropdownMenuItem(
                    value: p,
                    child: Text(p[0].toUpperCase() + p.substring(1)),
                  ),
              ],
              onChanged: (v) => setState(() => _priority = v ?? 'normal'),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _notesController,
              maxLength: 2000,
              maxLines: 3,
              decoration: const InputDecoration(
                labelText: 'Notes (optional)',
                hintText: 'Brief context for the Guidance counselor…',
                isDense: true,
                border: OutlineInputBorder(),
              ),
            ),
            if (_submitError != null) ...[
              const SizedBox(height: 4),
              Text(
                _submitError!,
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 13,
                ),
              ),
            ],
            const SizedBox(height: 8),
            FilledButton(
              onPressed: _submit,
              style: FilledButton.styleFrom(
                backgroundColor: const Color(0xFF800000),
                padding: const EdgeInsets.symmetric(vertical: 14),
              ),
              child: const Text('Refer to Guidance'),
            ),
          ],
        ),
      ),
    );
  }
}

/// A tappable row in the patient action sheet — tinted icon + label + a
/// muted description, with an optional destructive (red) treatment.
class _ActionRow extends StatelessWidget {
  const _ActionRow({
    required this.icon,
    required this.label,
    this.description,
    this.color,
    this.onTap,
  });

  final IconData icon;
  final String label;
  final String? description;
  final Color? color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final c = color ?? const Color(0xFF1C1917);
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        child: Row(
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: c.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, color: c, size: 20),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    label,
                    style: TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w600,
                      color: c,
                    ),
                  ),
                  if (description != null) ...[
                    const SizedBox(height: 2),
                    Text(
                      description!,
                      style: const TextStyle(
                        fontSize: 12.5,
                        color: Colors.black54,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const Icon(
              HugeIcons.strokeRoundedArrowRight01,
              size: 18,
              color: Colors.black26,
            ),
          ],
        ),
      ),
    );
  }
}

/// Full patient detail — mirrors the web View dialogs. BOTH kinds get the
/// person-level medical record (allergies + emergency contacts, with
/// add/edit/remove) plus their profile fields; the SPA made the medical
/// record shared in 2026-10 and the employee detail endpoint now returns
/// it. Pops with the latest row so the caller can refresh the list.
class _DetailSheet extends StatefulWidget {
  const _DetailSheet({required this.person, this.openMedical = false});

  final UserProfile person;

  /// True when opened from "Manage medical record" — scrolls the medical
  /// block into view once loaded.
  final bool openMedical;

  @override
  State<_DetailSheet> createState() => _DetailSheetState();
}

class _DetailSheetState extends State<_DetailSheet> {
  bool _loading = true;
  String? _error;
  UserProfile? _detail;
  final _scrollController = ScrollController();
  final _medicalKey = GlobalKey();
  bool _scrolledToMedical = false;

  bool get _canWrite =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('clinic.patients.write') ??
      false;

  /// Medical writes are clinic-side and need a local user row; synthetic
  /// MIS directory rows carry no id to attach rows to.
  bool get _canManageMedical => _canWrite && !widget.person.isDirectoryRecord;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final detail = widget.person.isStudent
          ? await ApiService.I.studentDetail(widget.person.id)
          : await ApiService.I.employeeDetail(widget.person.id);
      if (!mounted) return;
      setState(() {
        _detail = detail;
        _loading = false;
      });
      _maybeScrollToMedical();
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _loading = false;
      });
    }
  }

  /// "Manage medical record" lands on the medical block rather than the
  /// top of the profile.
  void _maybeScrollToMedical() {
    if (!widget.openMedical || _scrolledToMedical) return;
    _scrolledToMedical = true;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final ctx = _medicalKey.currentContext;
      if (ctx == null || !mounted) return;
      Scrollable.ensureVisible(
        ctx,
        duration: const Duration(milliseconds: 250),
        alignment: 0.05,
      );
    });
  }

  void _close([UserProfile? updated]) {
    Navigator.of(context).pop(updated ?? _detail);
  }

  Future<void> _addAllergy() async {
    final payload = await showCrudForm(
      context,
      title: 'Add allergy',
      fields: const [
        CrudField.text('allergen', 'Allergen'),
        CrudField.dropdown(
          'severity',
          'Severity',
          ['mild', 'moderate', 'severe'],
          initial: 'mild',
        ),
        CrudField.text('reaction', 'Reaction', required: false),
      ],
      submitLabel: 'Add',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => widget.person.isStudent
          ? ApiService.I.addStudentAllergy(widget.person.id, payload)
          : ApiService.I.addEmployeeAllergy(widget.person.id, payload),
      successMessage: 'Allergy recorded.',
    );
    if (ok && mounted) _load();
  }

  Future<void> _editAllergy(PatientAllergy a) async {
    final payload = await showCrudForm(
      context,
      title: 'Edit allergy',
      fields: [
        CrudField.text('allergen', 'Allergen', initial: a.allergen),
        CrudField.dropdown(
          'severity',
          'Severity',
          ['mild', 'moderate', 'severe'],
          initial: a.severity,
        ),
        CrudField.text('reaction', 'Reaction',
            required: false, initial: a.reaction),
      ],
      submitLabel: 'Save',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => widget.person.isStudent
          ? ApiService.I.updateStudentAllergy(widget.person.id, a.id, payload)
          : ApiService.I.updateEmployeeAllergy(
              widget.person.id, a.id, payload),
      successMessage: 'Allergy updated.',
    );
    if (ok && mounted) _load();
  }

  Future<void> _removeAllergy(PatientAllergy a) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Remove allergy?',
      message:
          'Remove "${a.allergen}" from this ${widget.person.isStudent ? 'student' : 'employee'}\'s record?',
      confirmLabel: 'Remove',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => widget.person.isStudent
          ? ApiService.I.deleteStudentAllergy(widget.person.id, a.id)
          : ApiService.I.deleteEmployeeAllergy(widget.person.id, a.id),
      successMessage: 'Allergy removed.',
    );
    if (ok && mounted) _load();
  }

  Future<void> _addContact() async {
    final payload = await showCrudForm(
      context,
      title: 'Add emergency contact',
      fields: const [
        CrudField.text('contact_name', 'Name'),
        CrudField.text('relationship', 'Relationship'),
        CrudField.text('phone', 'Phone', keyboard: TextInputType.phone),
        CrudField.bool('is_primary', 'Primary contact'),
      ],
      submitLabel: 'Add',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => widget.person.isStudent
          ? ApiService.I.addStudentContact(widget.person.id, payload)
          : ApiService.I.addEmployeeContact(widget.person.id, payload),
      successMessage: 'Emergency contact added.',
    );
    if (ok && mounted) _load();
  }

  Future<void> _editContact(PatientContact c) async {
    final payload = await showCrudForm(
      context,
      title: 'Edit emergency contact',
      fields: [
        CrudField.text('contact_name', 'Name', initial: c.contactName),
        CrudField.text('relationship', 'Relationship', initial: c.relationship),
        CrudField.text('phone', 'Phone',
            initial: c.phone, keyboard: TextInputType.phone),
        CrudField.bool('is_primary', 'Primary contact',
            boolInitial: c.isPrimary),
      ],
      submitLabel: 'Save',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => widget.person.isStudent
          ? ApiService.I.updateStudentContact(widget.person.id, c.id, payload)
          : ApiService.I.updateEmployeeContact(
              widget.person.id, c.id, payload),
      successMessage: 'Emergency contact updated.',
    );
    if (ok && mounted) _load();
  }

  Future<void> _removeContact(PatientContact c) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Remove contact?',
      message:
          'Remove "${c.contactName}" from this ${widget.person.isStudent ? 'student' : 'employee'}\'s record?',
      confirmLabel: 'Remove',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => widget.person.isStudent
          ? ApiService.I.deleteStudentContact(widget.person.id, c.id)
          : ApiService.I.deleteEmployeeContact(widget.person.id, c.id),
      successMessage: 'Emergency contact removed.',
    );
    if (ok && mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    final d = _detail ?? widget.person;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SheetHeader(
              title: d.fullName,
              subtitle: d.isStudent
                  ? '${d.studentNumber ?? 'Student'} · ${d.course ?? '—'} · Y${d.yearLevel ?? '?'}'
                  : '${d.employeeNumber ?? 'Employee'} · ${d.department ?? '—'}',
            ),
            if (d.activeGuidanceReferral) ...[
              const SizedBox(height: 8),
              const Align(
                alignment: Alignment.centerLeft,
                child: StatusBadge(
                  label: 'Referred to Guidance',
                  color: Color(0xFF5B4BA6),
                ),
              ),
            ],
            const SizedBox(height: 8),
            Flexible(
              child: _loading
                  ? const SizedBox(
                      height: 160,
                      child: Center(child: CircularProgressIndicator()),
                    )
                  : _error != null
                      ? SizedBox(
                          height: 160,
                          child: AsyncState.error(_error!, onRetry: _load),
                        )
                      : SingleChildScrollView(
                          controller: _scrollController,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              // Both kinds carry the person-level medical
                              // record; employees also get their profile.
                              if (!d.isStudent) ...[
                                _buildEmployeeProfile(d),
                                const SizedBox(height: 16),
                              ],
                              Column(
                                key: _medicalKey,
                                crossAxisAlignment: CrossAxisAlignment.stretch,
                                children: [
                                  _buildAllergies(d),
                                  const SizedBox(height: 16),
                                  _buildContacts(d),
                                ],
                              ),
                            ],
                          ),
                        ),
            ),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: () => _close(),
              icon: const Icon(HugeIcons.strokeRoundedCancel01, size: 18),
              label: const Text('Close'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildAllergies(UserProfile d) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const Expanded(
              child: Text(
                'Allergies',
                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
              ),
            ),
            if (_canManageMedical)
              IconButton(
                tooltip: 'Add allergy',
                icon: const Icon(HugeIcons.strokeRoundedAdd01, size: 20),
                onPressed: _addAllergy,
              ),
          ],
        ),
        if (d.allergies.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 6),
            child: Text('None recorded.',
                style: TextStyle(color: Colors.black45, fontSize: 13)),
          )
        else
          for (final a in d.allergies)
            ListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              leading: _SeverityDot(severity: a.severity),
              title: Text(a.allergen,
                  style: const TextStyle(
                      fontSize: 14, fontWeight: FontWeight.w600)),
              subtitle: a.reaction != null && a.reaction!.isNotEmpty
                  ? Text(a.reaction!,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 12))
                  : null,
              trailing: _canManageMedical
                  ? Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        IconButton(
                          tooltip: 'Edit',
                          icon: const Icon(HugeIcons.strokeRoundedPencilEdit01,
                              size: 18),
                          onPressed: () => _editAllergy(a),
                        ),
                        IconButton(
                          tooltip: 'Remove',
                          color: const Color(0xFFB3261E),
                          icon: const Icon(HugeIcons.strokeRoundedDelete02,
                              size: 18),
                          onPressed: () => _removeAllergy(a),
                        ),
                      ],
                    )
                  : null,
            ),
      ],
    );
  }

  Widget _buildContacts(UserProfile d) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const Expanded(
              child: Text(
                'Emergency contacts',
                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
              ),
            ),
            if (_canManageMedical)
              IconButton(
                tooltip: 'Add contact',
                icon: const Icon(HugeIcons.strokeRoundedAdd01, size: 20),
                onPressed: _addContact,
              ),
          ],
        ),
        if (d.contacts.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 6),
            child: Text('None recorded.',
                style: TextStyle(color: Colors.black45, fontSize: 13)),
          )
        else
          for (final c in d.contacts)
            ListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              leading: CircleAvatar(
                radius: 18,
                backgroundColor: const Color(0xFFE8F0FE),
                child: Icon(
                    c.isPrimary
                        ? HugeIcons.strokeRoundedStar
                        : HugeIcons.strokeRoundedContact01,
                    size: 18,
                    color: const Color(0xFF1E6FD9)),
              ),
              title: Text(
                c.contactName + (c.isPrimary ? '  ·  primary' : ''),
                style:
                    const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
              ),
              subtitle: Text(
                '${c.relationship} · ${c.phone}',
                style: const TextStyle(fontSize: 12),
              ),
              trailing: _canManageMedical
                  ? Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        IconButton(
                          tooltip: 'Edit',
                          icon: const Icon(HugeIcons.strokeRoundedPencilEdit01,
                              size: 18),
                          onPressed: () => _editContact(c),
                        ),
                        IconButton(
                          tooltip: 'Remove',
                          color: const Color(0xFFB3261E),
                          icon: const Icon(HugeIcons.strokeRoundedDelete02,
                              size: 18),
                          onPressed: () => _removeContact(c),
                        ),
                      ],
                    )
                  : null,
            ),
      ],
    );
  }

  Widget _buildEmployeeProfile(UserProfile d) {
    Widget row(String label, String? value) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SizedBox(
                width: 120,
                child: Text(label,
                    style:
                        const TextStyle(color: Colors.black45, fontSize: 13)),
              ),
              Expanded(
                child: Text(value ?? '—', style: const TextStyle(fontSize: 13)),
              ),
            ],
          ),
        );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        row('Department', d.department),
        row('Position', d.position),
        row(
            'Status',
            d.employmentStatus != null
                ? titleCaseOption(d.employmentStatus!)
                : null),
        row('Hired', d.dateHired),
        row(
            'Emergency contact',
            (d.emergencyContactName != null &&
                    d.emergencyContactName!.isNotEmpty)
                ? '${d.emergencyContactName}'
                    '${d.emergencyContactPhone != null && d.emergencyContactPhone!.isNotEmpty ? ' · ${d.emergencyContactPhone}' : ''}'
                : null),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 6,
          children: [
            StatusBadge(
                label: d.hasQr ? 'QR' : 'no QR',
                color: d.hasQr ? const Color(0xFF1E6FD9) : Colors.black38),
            if (d.isTeaching == true)
              const StatusBadge(label: 'Teaching', color: Color(0xFF0F766E))
            else if (d.isTeaching == false)
              const StatusBadge(label: 'Non-teaching', color: Colors.black54),
            if (d.archived)
              const StatusBadge(label: 'Archived', color: Colors.black45),
          ],
        ),
      ],
    );
  }
}

/// Colored severity dot for an allergy row.
class _SeverityDot extends StatelessWidget {
  const _SeverityDot({required this.severity});

  final String severity;

  @override
  Widget build(BuildContext context) {
    final color = switch (severity) {
      'severe' => const Color(0xFFB3261E),
      'moderate' => const Color(0xFFB45309),
      _ => const Color(0xFF1B7A43),
    };
    return CircleAvatar(
      radius: 8,
      backgroundColor: color.withValues(alpha: 0.15),
      child: Icon(HugeIcons.strokeRoundedAlert02, size: 11, color: color),
    );
  }
}
