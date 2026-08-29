import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/models/profile.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
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
            ? await ApiService.I.employeeSearch(_query)
            : await ApiService.I.studentSearch(_query);
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
    } catch (_) {
      // ignore
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

  List<CrudField> _studentFields([UserProfile? p]) => [
        CrudField.text('first_name', 'First name', initial: p?.firstName),
        CrudField.text('last_name', 'Last name', initial: p?.lastName),
        CrudField.text('middle_name', 'Middle name',
            required: false, initial: p?.middleName),
        if (p == null)
          const CrudField.text('student_number', 'Student number'),
        CrudField.text('course', 'Course', required: false, initial: p?.course),
        CrudField.number('year_level', 'Year level',
            required: false, initial: p?.yearLevel?.toString()),
        CrudField.text('section', 'Section',
            required: false, initial: p?.section),
        const CrudField.dropdown('gender', 'Gender',
            ['male', 'female', 'other'], required: false),
        CrudField.text('blood_type', 'Blood type',
            required: false, initial: p?.bloodType),
        if (p == null)
          const CrudField.text('account_email', 'Account email (optional)',
              required: false, keyboard: TextInputType.emailAddress),
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
          const CrudField.text('account_email', 'Account email (optional)',
              required: false, keyboard: TextInputType.emailAddress),
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
      successMessage: isEmployee ? 'Employee registered.' : 'Student registered.',
    );
    if (ok) _load();
  }

  Future<void> _edit(UserProfile p) async {
    final isEmployee = p.isStudent == false;
    final payload = await showCrudForm(
      context,
      title: 'Edit ${p.fullName}',
      fields: isEmployee ? _employeeFields(p) : _studentFields(p),
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => isEmployee
          ? ApiService.I.updateEmployee(p.id, payload)
          : ApiService.I.updateStudent(p.id, payload),
      successMessage: '${p.fullName} updated.',
    );
    if (ok) _load();
  }

  Future<void> _archive(UserProfile p) async {
    final confirmed = await showCrudConfirm(
      context,
      title: p.archived ? 'Restore patient?' : 'Archive patient?',
      message: p.archived
          ? 'Restore ${p.fullName}?'
          : 'Archive ${p.fullName}? The account stays, but they leave the registry view.',
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

  /// Opens the patient detail sheet — allergies + emergency contacts for
  /// students (with add/edit/remove), and the full profile for employees.
  /// Mirrors the web View dialog (`StudentDetailDialog` /
  /// `EmployeeDetailDialog`).
  Future<void> _viewDetail(UserProfile p) async {
    if (!mounted) return;
    final fresh = await showDialog<UserProfile>(
      context: context,
      barrierDismissible: false,
      builder: (dialogContext) => _DetailSheet(person: p),
    );
    if (fresh != null && mounted) {
      // Detail edits returned an updated row; refresh the list.
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Patients')),
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
              ButtonSegment(value: false, label: Text('Students'), icon: Icon(HugeIcons.strokeRoundedBook02)),
              ButtonSegment(value: true, label: Text('Employees'), icon: Icon(HugeIcons.strokeRoundedId)),
            ],
            selected: {_employee},
            onSelectionChanged: (s) {
              setState(() => _employee = s.first);
              _load();
            },
          ),
          // Employee teaching filter + archived toggle — mirrors the web
          // All/Teaching/Non-teaching select + "Show archived" button.
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
                          contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                          border: OutlineInputBorder(),
                        ),
                        items: const [
                          DropdownMenuItem(value: 'all', child: Text('All employees')),
                          DropdownMenuItem(value: 'teaching', child: Text('Teaching (faculty)')),
                          DropdownMenuItem(value: 'non_teaching', child: Text('Non-teaching')),
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
                // Archived toggle — matches the web "Show archived" button.
                FilterChip(
                  label: Text(_showArchived ? 'Archived' : 'Show archived'),
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
          onView: () => _viewDetail(_items[i]),
          onEdit: () => _edit(_items[i]),
          onArchive: () => _archive(_items[i]),
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
    this.onView,
    this.onEdit,
    this.onArchive,
  });

  final UserProfile person;
  final bool canWrite;
  final VoidCallback? onView;
  final VoidCallback? onEdit;
  final VoidCallback? onArchive;

  /// Opens the patient action sheet — a modern replacement for the default
  /// popup menu: Edit + Archive/Restore as tinted icon rows, with the
  /// patient's name + ID in the header.
  void _openActions(BuildContext context) {
    showSynapseSheet<void>(
      context,
      builder: (sheetContext) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              SheetHeader(
                title: person.fullName,
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
              const Padding(
                padding: EdgeInsets.symmetric(horizontal: 12),
                child: Divider(height: 1),
              ),
              _ActionRow(
                icon: HugeIcons.strokeRoundedPencilEdit01,
                label: 'Edit',
                description: 'Update profile details',
                onTap: () {
                  Navigator.of(sheetContext).pop();
                  onEdit?.call();
                },
              ),
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
    final accent = isStudent ? const Color(0xFF1E6FD9) : const Color(0xFF0F766E);
    final accentBg =
        isStudent ? const Color(0xFFE8F0FE) : const Color(0xFFE6F4F1);

    // Line 1 — registry ID + academic/organisational context.
    final line1 = isStudent
        ? [
            if (person.studentNumber?.isNotEmpty ?? false)
              person.studentNumber!
            else
              'No ID',
            if (person.course?.isNotEmpty ?? false) person.course!,
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
          ].join(' · ')
        : [
            if (person.position?.isNotEmpty ?? false) person.position!,
            if (person.employmentStatus != null)
              titleCaseOption(person.employmentStatus!),
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
          color: Theme.of(context).colorScheme.outlineVariant.withValues(alpha: 0.5),
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
          person.fullName,
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
            // Handles (QR / RFID) — mirrors the web EmployeeDetailDialog
            // "Handles" badges; shown for both kinds when present.
            if (person.hasQr)
              const StatusBadge(label: 'QR', color: Color(0xFF1E6FD9)),
            if (person.hasRfid)
              const StatusBadge(label: 'RFID', color: Color(0xFF1E6FD9)),
            if (isStudent && person.consecutiveNoShows > 0)
              StatusBadge(
                label: '${person.consecutiveNoShows}× no-show',
                color: const Color(0xFFB45309),
              ),
            if (tag != null) StatusBadge(label: tag, color: tagColor),
            if (person.archived)
              const StatusBadge(label: 'Archived', color: Colors.black45),
            if (canWrite) ...[
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

/// Full patient detail — mirrors the web View dialogs. Students get
/// allergies + emergency contacts (with add/edit/remove); employees get
/// their profile + handles + emergency contact. Pops with the latest row
/// so the caller can refresh the list after any edit.
class _DetailSheet extends StatefulWidget {
  const _DetailSheet({required this.person});

  final UserProfile person;

  @override
  State<_DetailSheet> createState() => _DetailSheetState();
}

class _DetailSheetState extends State<_DetailSheet> {
  bool _loading = true;
  String? _error;
  UserProfile? _detail;

  bool get _canWrite =>
      context
          .read<AuthController>()
          .session
          ?.hasPermission('clinic.patients.write') ??
      false;

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
      final detail = widget.person.isStudent
          ? await ApiService.I.studentDetail(widget.person.id)
          : await ApiService.I.employeeDetail(widget.person.id);
      if (!mounted) return;
      setState(() {
        _detail = detail;
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
      () => ApiService.I.addStudentAllergy(widget.person.id, payload),
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
      () => ApiService.I.updateStudentAllergy(widget.person.id, a.id, payload),
      successMessage: 'Allergy updated.',
    );
    if (ok && mounted) _load();
  }

  Future<void> _removeAllergy(PatientAllergy a) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Remove allergy?',
      message: 'Remove "${a.allergen}" from this student\'s record?',
      confirmLabel: 'Remove',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.deleteStudentAllergy(widget.person.id, a.id),
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
      () => ApiService.I.addStudentContact(widget.person.id, payload),
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
        CrudField.bool('is_primary', 'Primary contact', boolInitial: c.isPrimary),
      ],
      submitLabel: 'Save',
    );
    if (payload == null || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.updateStudentContact(widget.person.id, c.id, payload),
      successMessage: 'Emergency contact updated.',
    );
    if (ok && mounted) _load();
  }

  Future<void> _removeContact(PatientContact c) async {
    final confirmed = await showCrudConfirm(
      context,
      title: 'Remove contact?',
      message: 'Remove "${c.contactName}" from this student\'s record?',
      confirmLabel: 'Remove',
      destructive: true,
    );
    if (!confirmed || !mounted) return;
    final ok = await runCrudAction(
      context,
      () => ApiService.I.deleteStudentContact(widget.person.id, c.id),
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
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              if (d.isStudent) ...[
                                _buildAllergies(d),
                                const SizedBox(height: 16),
                                _buildContacts(d),
                              ] else
                                _buildEmployeeProfile(d),
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
            if (_canWrite)
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
                  style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600)),
              subtitle: a.reaction != null && a.reaction!.isNotEmpty
                  ? Text(a.reaction!,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 12))
                  : null,
              trailing: _canWrite
                  ? Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        IconButton(
                          tooltip: 'Edit',
                          icon: const Icon(HugeIcons.strokeRoundedPencilEdit01, size: 18),
                          onPressed: () => _editAllergy(a),
                        ),
                        IconButton(
                          tooltip: 'Remove',
                          color: const Color(0xFFB3261E),
                          icon: const Icon(HugeIcons.strokeRoundedDelete02, size: 18),
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
            if (_canWrite)
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
                child: Icon(c.isPrimary
                    ? HugeIcons.strokeRoundedStar
                    : HugeIcons.strokeRoundedContact01,
                    size: 18,
                    color: const Color(0xFF1E6FD9)),
              ),
              title: Text(
                c.contactName + (c.isPrimary ? '  ·  primary' : ''),
                style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
              ),
              subtitle: Text(
                '${c.relationship} · ${c.phone}',
                style: const TextStyle(fontSize: 12),
              ),
              trailing: _canWrite
                  ? Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        IconButton(
                          tooltip: 'Edit',
                          icon: const Icon(HugeIcons.strokeRoundedPencilEdit01, size: 18),
                          onPressed: () => _editContact(c),
                        ),
                        IconButton(
                          tooltip: 'Remove',
                          color: const Color(0xFFB3261E),
                          icon: const Icon(HugeIcons.strokeRoundedDelete02, size: 18),
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
                    style: const TextStyle(
                        color: Colors.black45, fontSize: 13)),
              ),
              Expanded(
                child: Text(value ?? '—',
                    style: const TextStyle(fontSize: 13)),
              ),
            ],
          ),
        );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        row('Department', d.department),
        row('Position', d.position),
        row('Status', d.employmentStatus != null
            ? titleCaseOption(d.employmentStatus!)
            : null),
        row('Hired', d.dateHired),
        row('Emergency contact',
            (d.emergencyContactName != null && d.emergencyContactName!.isNotEmpty)
                ? '${d.emergencyContactName}'
                    '${d.emergencyContactPhone != null && d.emergencyContactPhone!.isNotEmpty ? ' · ${d.emergencyContactPhone}' : ''}'
                : null),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 6,
          children: [
            StatusBadge(label: d.hasQr ? 'QR' : 'no QR',
                color: d.hasQr ? const Color(0xFF1E6FD9) : Colors.black38),
            StatusBadge(label: d.hasRfid ? 'RFID' : 'no RFID',
                color: d.hasRfid ? const Color(0xFF1E6FD9) : Colors.black38),
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
