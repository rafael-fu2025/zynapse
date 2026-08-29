import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:hugeicons/hugeicons.dart';
import 'package:provider/provider.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_envelope.dart';
import '../../core/models/appointment.dart';
import '../../core/models/profile.dart';
import '../../core/models/session.dart';
import '../../core/services/api_service.dart';
import '../../core/services/auth_controller.dart';
import '../../core/utils/dates.dart';
import '../common/auto_polling.dart';
import '../common/crud_form.dart';
import '../common/widgets.dart';
import 'appointment_qr_sheet.dart';

/// Appointments — list + search + status filter, plus student self-booking.
///
/// Mirrors `frontend/src/pages/AppointmentsPage.tsx` (list/search) and
/// `frontend/src/components/StudentBooking.tsx` (self-service booking via
/// `/me/student-providers` + `POST /me/student-appointments`).
class AppointmentsScreen extends StatefulWidget {
  const AppointmentsScreen({super.key});

  @override
  State<AppointmentsScreen> createState() => _AppointmentsScreenState();
}

class _AppointmentsScreenState extends State<AppointmentsScreen>
    with AutoPolling<AppointmentsScreen> {
  final _searchController = TextEditingController();
  String? _status;
  String _query = '';
  bool _loading = true;
  String? _error;
  List<Appointment> _items = [];
  String? _nextCursor;
  bool _loadingMore = false;
  bool _loadedOnce = false;
  bool _studentMode = false;
  bool _staffMode = false;
  Timer? _searchDebounce;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final session = context.read<AuthController>().session;
      setState(() {
        _studentMode = session?.isStudent ?? false;
        _staffMode =
            session?.hasPermission('clinic.appointments.write') ?? false;
      });
      _load();
      // Live updates — mirrors the SPA's 30s `refetchInterval`.
      startPolling(const Duration(seconds: 30), _silentRefresh);
    });
  }

  @override
  void dispose() {
    _searchDebounce?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  /// Polled live update: silently refetch the first page so new/updated
  /// appointments appear without a manual refresh. Skipped while a search is
  /// active or once the user has paginated deeper (pull-to-refresh there) so
  /// the list never collapses mid-scroll.
  Future<void> _silentRefresh() async {
    if (_query.isNotEmpty || _items.length > 25) return;
    try {
      if (_studentMode) {
        final items = await ApiService.I.myStudentAppointments();
        if (!mounted) return;
        setState(() => _items = items);
        return;
      }
      final page = await ApiService.I.appointments(
        status: _status,
        q: _query,
        limit: 25,
      );
      if (!mounted) return;
      setState(() {
        _items = page.items;
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // Keep the current list on transient errors.
      if (kDebugMode) debugPrint('AppointmentsScreen.poll failed: $e');
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = !_loadedOnce;
      _error = null;
    });
    try {
      if (_studentMode) {
        // Students see their own appointments (self-scoped endpoint).
        final items = await ApiService.I.myStudentAppointments();
        setState(() {
          _items = items;
          _nextCursor = null;
          _loadedOnce = true;
          _loading = false;
        });
      } else {
        final page = await ApiService.I.appointments(
          status: _status,
          q: _query,
          limit: 25,
        );
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
    if (_loadingMore || _nextCursor == null || _studentMode) return;
    setState(() => _loadingMore = true);
    try {
      final page = await ApiService.I.appointments(
        status: _status,
        q: _query,
        limit: 25,
        cursor: _nextCursor,
      );
      setState(() {
        _items = [..._items, ...page.items];
        _nextCursor = page.meta?.nextCursor;
      });
    } catch (e) {
      // keep current list; the user can pull to refresh.
      if (kDebugMode) debugPrint('AppointmentsScreen.loadMore failed: $e');
    } finally {
      setState(() => _loadingMore = false);
    }
  }

  void _setStatus(String? status) {
    setState(() => _status = status);
    _load();
  }

  void _onSearchChanged(String value) {
    setState(() => _query = value.trim());
    // Debounce like the SPA (300 ms) so we don't hit the API per keystroke.
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 300), _load);
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<AuthController>().session;
    final isStudent = session?.isStudent ?? false;
    final isStaff =
        session?.hasPermission('clinic.appointments.write') ?? false;

    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: isStudent
          ? FloatingActionButton.extended(
              onPressed: () => _openBookingDialog(context),
              icon: const Icon(HugeIcons.strokeRoundedAdd01),
              label: const Text('Book'),
            )
          : (isStaff
              ? FloatingActionButton.extended(
                  onPressed: () => _openStaffScheduleDialog(context),
                  icon: const Icon(HugeIcons.strokeRoundedCalendarAdd01),
                  label: const Text('Schedule'),
                )
              : null),
      body: Column(
        children: [
          if (!isStudent)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
              child: TextField(
                controller: _searchController,
                onChanged: _onSearchChanged,
                decoration: InputDecoration(
                  hintText: 'Search number, name, provider, date…',
                  prefixIcon: const Icon(HugeIcons.strokeRoundedSearch01),
                  isDense: true,
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
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
          if (!isStudent) ...[
            const SizedBox(height: 8),
            _statusFilterBar(),
          ],
          const SizedBox(height: 4),
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

  /// Student self-booking dialog — mirrors the SPA's `StudentBooking`
  /// component (provider + date + time + reason -> `POST /me/student-appointments`).
  Future<void> _openBookingDialog(BuildContext context) async {
    final session = context.read<AuthController>().session;
    if (session == null) return;

    await showSynapseSheet<void>(
      context,
      builder: (_) => _BookingDialog(session: session),
    );
    // Refresh the list after a successful booking (the sheet pops with
    // the result handled inside; reload regardless for simplicity).
    _load();
  }

  /// Staff scheduling — mirrors the SPA's `ScheduleDialog`
  /// (patient lookup + provider + date/time -> `POST /clinic/appointments`).
  Future<void> _openStaffScheduleDialog(BuildContext context) async {
    await showSynapseSheet<void>(
      context,
      builder: (_) => const _StaffScheduleDialog(),
    );
    _load();
  }

  /// Applies an appointment status transition and refreshes the list.
  Future<void> _transition(Appointment appointment, String status) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final updated = await ApiService.I.appointmentTransition(
        id: appointment.id,
        status: status,
      );
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            'Appointment #${appointment.id} → ${titleCaseOption(updated.status)}.',
          ),
        ),
      );
      _load();
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(mapDioError(e).message)));
    }
  }

  Widget _statusFilterBar() {
    const statuses = <(String?, String)>[
      (null, 'All'),
      ('scheduled', 'Scheduled'),
      ('checked_in', 'Checked in'),
      ('completed', 'Completed'),
      ('cancelled', 'Cancelled'),
      ('no_show', 'No show'),
    ];
    return SizedBox(
      height: 40,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        itemCount: statuses.length,
        separatorBuilder: (_, __) => const SizedBox(width: 8),
        itemBuilder: (context, i) {
          final (value, label) = statuses[i];
          final selected = _status == value;
          return FilterChip(
            label: Text(label),
            selected: selected,
            onSelected: (_) => _setStatus(value),
          );
        },
      ),
    );
  }

  Widget _buildBody() {
    if (_loading) return AsyncState.loading();
    if (_error != null) return AsyncState.error(_error!, onRetry: _load);
    if (_items.isEmpty) {
      return AsyncState.empty(
        _query.isEmpty ? 'No appointments in this view.' : 'No matches.',
      );
    }
    return ListView.separated(
      padding: const EdgeInsets.all(16),
      itemCount: _items.length + (_nextCursor != null ? 1 : 0),
      separatorBuilder: (_, __) => const SizedBox(height: 10),
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
        return _AppointmentCard(
          appointment: _items[i],
          staffMode: _staffMode,
          onTransition: (s) => _transition(_items[i], s),
        );
      },
    );
  }
}

class _AppointmentCard extends StatelessWidget {
  const _AppointmentCard({
    required this.appointment,
    this.staffMode = false,
    this.onTransition,
  });

  final Appointment appointment;
  final bool staffMode;
  final ValueChanged<String>? onTransition;

  /// Available status transitions for staff, by current status.
  List<(String, String, IconData)> get _actions => switch (appointment.status) {
        'scheduled' => [
            ('checked_in', 'Check in', HugeIcons.strokeRoundedLogin02),
            ('cancelled', 'Cancel', HugeIcons.strokeRoundedDelete02),
            ('no_show', 'No show', HugeIcons.strokeRoundedUserRemove01),
          ],
        'checked_in' => [
            ('completed', 'Complete', HugeIcons.strokeRoundedCheckmarkCircle01),
            ('cancelled', 'Cancel', HugeIcons.strokeRoundedDelete02),
            ('no_show', 'No show', HugeIcons.strokeRoundedUserRemove01),
          ],
        _ => const [],
      };

  @override
  Widget build(BuildContext context) {
    final color = appointmentStatusColor(appointment.status);
    final actions =
        staffMode ? _actions : const <(String, String, IconData)>[];
    return Card(
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(
          color: Theme.of(context).colorScheme.outlineVariant.withValues(alpha: 0.5),
        ),
      ),
      child: ListTile(
        title: Row(
          children: [
            Expanded(
              child: Text(
                appointment.patientLabel,
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
            StatusBadge(
              label: titleCaseOption(appointment.status),
              color: color,
            ),
          ],
        ),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                fmtUtcToApp(appointment.scheduledAt),
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
              const SizedBox(height: 2),
              Text('Provider: ${appointment.providerLabel}'),
              if (appointment.reason != null && appointment.reason!.isNotEmpty)
                Text('“${appointment.reason}”'),
            ],
          ),
        ),
        trailing: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Proof-of-booking QR — available to staff and to the student
            // owner (their own appointments). Opens the QR sheet, which can
            // also re-issue + verify the token.
            IconButton(
              tooltip: 'Show appointment QR',
              onPressed: () => showAppointmentQrSheet(
                context,
                appointment: appointment,
                initialToken: appointment.qrToken,
                // In this screen the viewer is either staff or the booking
                // owner — both may (re)issue the token on the backend.
                canIssue: true,
              ),
              icon: const Icon(HugeIcons.strokeRoundedQrCode01),
            ),
            if (actions.isNotEmpty)
              PopupMenuButton<String>(
                tooltip: 'Appointment actions',
                onSelected: onTransition,
                itemBuilder: (_) => [
                  for (final (value, label, icon) in actions)
                    PopupMenuItem(
                      value: value,
                      child: Row(
                        children: [
                          Icon(icon, size: 18),
                          const SizedBox(width: 8),
                          Text(label),
                        ],
                      ),
                    ),
                ],
                child: const Padding(
                  padding: EdgeInsets.all(4),
                  child: Icon(HugeIcons.strokeRoundedMore),
                ),
              ),
          ],
        ),
        isThreeLine: true,
      ),
    );
  }
}

/// Staff scheduling dialog — mirrors the SPA `ScheduleDialog`:
/// patient lookup + provider picker + date/time -> `POST /clinic/appointments`.
class _StaffScheduleDialog extends StatefulWidget {
  const _StaffScheduleDialog();

  @override
  State<_StaffScheduleDialog> createState() => _StaffScheduleDialogState();
}

class _StaffScheduleDialogState extends State<_StaffScheduleDialog> {
  final _patientController = TextEditingController();
  Timer? _debounce;
  List<PatientLookupResult> _suggestions = [];
  PatientLookupResult? _selected;
  String? _lookupError;
  bool _lookingUp = false;

  List<UserProfile>? _providers;
  String? _providersError;
  UserProfile? _selectedProvider;

  DateTime _date = DateTime.now().add(const Duration(days: 1));
  TimeOfDay _time = const TimeOfDay(hour: 9, minute: 0);
  final _reasonController = TextEditingController();

  bool _submitting = false;
  String? _submitError;

  @override
  void initState() {
    super.initState();
    _loadProviders();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _patientController.dispose();
    _reasonController.dispose();
    super.dispose();
  }

  Future<void> _loadProviders() async {
    try {
      final page = await ApiService.I.employees(limit: 100);
      if (!mounted) return;
      setState(() {
        _providers = page.items;
        if (page.items.isNotEmpty) _selectedProvider = page.items.first;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _providersError = mapDioError(e).message);
    }
  }

  void _onPatientChanged(String value) {
    _debounce?.cancel();
    final q = value.trim();
    if (q.length < 2) {
      setState(() {
        _suggestions = [];
        _selected = null;
      });
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 300), () async {
      setState(() => _lookingUp = true);
      try {
        final results = await ApiService.I.patientLookup(q);
        if (!mounted) return;
        setState(() {
          _suggestions = results;
          _lookupError = null;
          _lookingUp = false;
        });
      } catch (e) {
        if (!mounted) return;
        setState(() {
          _lookupError = mapDioError(e).message;
          _lookingUp = false;
        });
      }
    });
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime.now(),
      lastDate: DateTime.now().add(const Duration(days: 90)),
    );
    if (picked != null) setState(() => _date = picked);
  }

  Future<void> _pickTime() async {
    final picked = await showTimePicker(context: context, initialTime: _time);
    if (picked != null) setState(() => _time = picked);
  }

  Future<void> _submit() async {
    final patient = _selected;
    final provider = _selectedProvider;
    if (patient == null) {
      setState(() => _submitError = 'Select a patient from the suggestions.');
      return;
    }
    if (provider == null) {
      setState(() => _submitError = 'Select a provider.');
      return;
    }
    setState(() {
      _submitting = true;
      _submitError = null;
    });
    try {
      final local = DateTime(
        _date.year,
        _date.month,
        _date.day,
        _time.hour,
        _time.minute,
      );
      final appointment = await ApiService.I.scheduleAppointment(
        patientSchoolId: patient.schoolId,
        providerUserId: provider.id,
        scheduledAt: localToUtcSql(local),
        reason: _reasonController.text.trim(),
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Appointment #${appointment.id} scheduled for ${fmtUtcToApp(appointment.scheduledAt)}.',
          ),
        ),
      );
      // Show the proof-of-booking QR for the newly scheduled appointment on
      // top of this sheet; when it closes, close the scheduling dialog.
      await showAppointmentQrSheet(
        context,
        appointment: appointment,
        initialToken: appointment.qrToken,
        canIssue: true,
      );
      if (mounted) Navigator.of(context).pop();
    } on ApiException catch (e) {
      setState(() {
        _submitError = e.message;
        _submitting = false;
      });
    } catch (e) {
      setState(() {
        _submitError = mapDioError(e).message;
        _submitting = false;
      });
    }
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
            const SheetHeader(
              title: 'Schedule appointment',
              subtitle: 'Book a clinic appointment for a patient',
            ),
            const SizedBox(height: 16),
            TextField(
              controller: _patientController,
              onChanged: _onPatientChanged,
              decoration: InputDecoration(
                labelText: 'Patient (search by number or name)',
                border: const OutlineInputBorder(),
                isDense: true,
                suffixIcon: _lookingUp
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : null,
              ),
            ),
            const SizedBox(height: 8),
            if (_lookupError != null)
              Text(
                'Could not search: $_lookupError',
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 12,
                ),
              )
            else if (_suggestions.isNotEmpty)
              Container(
                constraints: const BoxConstraints(maxHeight: 150),
                decoration: BoxDecoration(
                  border: Border.all(
                    color: Theme.of(context).colorScheme.outlineVariant,
                  ),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    for (final s in _suggestions)
                      ListTile(
                        dense: true,
                        title: Text('${s.name} — ${s.schoolId}'),
                        subtitle: Text(titleCaseOption(s.kind)),
                        onTap: () {
                          setState(() {
                            _selected = s;
                            _patientController.text = '${s.name} — ${s.schoolId}';
                            _suggestions = [];
                          });
                        },
                      ),
                  ],
                ),
              )
            else if (_selected != null)
              Text(
                'Selected: ${_selected!.name} (#${_selected!.schoolId})',
                style: const TextStyle(
                  color: Color(0xFF1B7A43),
                  fontWeight: FontWeight.w600,
                  fontSize: 12,
                ),
              ),
            const SizedBox(height: 12),
            if (_providers == null && _providersError == null)
              const Padding(
                padding: EdgeInsets.all(8),
                child: Center(
                  child: SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                ),
              )
            else if (_providersError != null)
              Text(
                'Could not load providers: $_providersError',
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 13,
                ),
              )
            else
              InputDecorator(
                decoration: const InputDecoration(
                  labelText: 'Provider',
                  border: OutlineInputBorder(),
                ),
                child: DropdownButtonHideUnderline(
                  child: DropdownButton<UserProfile>(
                    value: _selectedProvider,
                    isExpanded: true,
                    isDense: true,
                    items: [
                      for (final p in _providers ?? <UserProfile>[])
                        DropdownMenuItem(value: p, child: Text(p.fullName)),
                    ],
                    onChanged: _submitting
                        ? null
                        : (v) => setState(() => _selectedProvider = v),
                  ),
                ),
              ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _submitting ? null : _pickDate,
                    icon: const Icon(HugeIcons.strokeRoundedCalendar01, size: 18),
                    label: Text(toDateInput(_date)),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _submitting ? null : _pickTime,
                    icon: const Icon(HugeIcons.strokeRoundedClock01, size: 18),
                    label: Text(_time.format(context)),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _reasonController,
              enabled: !_submitting,
              maxLength: 255,
              decoration: const InputDecoration(
                labelText: 'Reason (optional)',
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
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(
                  onPressed: _submitting
                      ? null
                      : () => Navigator.of(context).pop(),
                  child: const Text('Cancel'),
                ),
                const SizedBox(width: 8),
                FilledButton(
                  onPressed: _submitting ? null : _submit,
                  child: _submitting
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('Schedule'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Student self-booking dialog.
class _BookingDialog extends StatefulWidget {
  const _BookingDialog({required this.session});

  final Session session;

  @override
  State<_BookingDialog> createState() => _BookingDialogState();
}

class _BookingDialogState extends State<_BookingDialog> {
  List<ProviderRef>? _providers;
  String? _providersError;
  ProviderRef? _selectedProvider;
  DateTime _date = DateTime.now().add(const Duration(days: 1));
  TimeOfDay _time = const TimeOfDay(hour: 9, minute: 0);
  final _reasonController = TextEditingController();
  bool _submitting = false;
  String? _submitError;
  String? _success;

  @override
  void initState() {
    super.initState();
    _loadProviders();
  }

  @override
  void dispose() {
    _reasonController.dispose();
    super.dispose();
  }

  Future<void> _loadProviders() async {
    setState(() {
      _providersError = null;
    });
    try {
      final providers = await ApiService.I.studentProviders();
      setState(() {
        _providers = providers;
        if (providers.isNotEmpty) _selectedProvider = providers.first;
      });
    } catch (e) {
      setState(() => _providersError = mapDioError(e).message);
    }
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime.now(),
      lastDate: DateTime.now().add(const Duration(days: 90)),
    );
    if (picked != null) setState(() => _date = picked);
  }

  Future<void> _pickTime() async {
    final picked = await showTimePicker(context: context, initialTime: _time);
    if (picked != null) setState(() => _time = picked);
  }

  Future<void> _submit() async {
    if (_selectedProvider == null) {
      setState(() => _submitError = 'Choose a provider.');
      return;
    }
    setState(() {
      _submitting = true;
      _submitError = null;
      _success = null;
    });
    try {
      final local = DateTime(
        _date.year,
        _date.month,
        _date.day,
        _time.hour,
        _time.minute,
      );
      final appointment = await ApiService.I.bookStudentAppointment(
        providerUserId: _selectedProvider!.id,
        scheduledAt: localToUtcSql(local),
        reason: _reasonController.text.trim(),
      );
      setState(() {
        _success =
            'Appointment #${appointment.id} booked for ${fmtUtcToApp(appointment.scheduledAt)}.';
        _submitting = false;
      });
      // Pop shortly after showing success.
      await Future<void>.delayed(const Duration(seconds: 1));
      if (!mounted) return;
      // Surface the proof-of-booking QR right after booking so the student
      // sees it immediately; when the QR sheet closes, close the booking
      // dialog and refresh the list.
      await showAppointmentQrSheet(
        context,
        appointment: appointment,
        initialToken: appointment.qrToken,
        canIssue: true,
      );
      if (mounted) Navigator.of(context).pop();
    } on ApiException catch (e) {
      setState(() {
        _submitError = e.message;
        _submitting = false;
      });
    } catch (e) {
      setState(() {
        _submitError = mapDioError(e).message;
        _submitting = false;
      });
    }
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
            const SheetHeader(
              title: 'Book appointment',
              subtitle: 'Self-service booking',
            ),
            const SizedBox(height: 16),
            // Provider
            if (_providers == null && _providersError == null)
              const Padding(
                padding: EdgeInsets.all(8),
                child: Center(
                  child: SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                ),
              )
            else if (_providersError != null)
              Text(
                'Could not load providers: $_providersError',
                style: TextStyle(
                  color: Theme.of(context).colorScheme.error,
                  fontSize: 13,
                ),
              )
            else
              InputDecorator(
                decoration: const InputDecoration(
                  labelText: 'Provider',
                  border: OutlineInputBorder(),
                ),
                child: DropdownButtonHideUnderline(
                  child: DropdownButton<ProviderRef>(
                    value: _selectedProvider,
                    isExpanded: true,
                    isDense: true,
                    items: [
                      for (final p in _providers ?? <ProviderRef>[])
                        DropdownMenuItem(value: p, child: Text(p.name)),
                    ],
                    onChanged: _submitting
                        ? null
                        : (v) => setState(() => _selectedProvider = v),
                  ),
                ),
              ),
            const SizedBox(height: 12),
            // Date + time
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _submitting ? null : _pickDate,
                    icon: const Icon(HugeIcons.strokeRoundedCalendar01, size: 18),
                    label: Text(toDateInput(_date)),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _submitting ? null : _pickTime,
                    icon: const Icon(HugeIcons.strokeRoundedClock01, size: 18),
                    label: Text(_time.format(context)),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _reasonController,
              enabled: !_submitting,
              maxLength: 500,
              decoration: const InputDecoration(
                labelText: 'Reason (optional)',
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
            if (_success != null) ...[
              const SizedBox(height: 8),
              Text(
                _success!,
                style: const TextStyle(
                  color: Color(0xFF1B7A43),
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
            const SizedBox(height: 12),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(
                  onPressed: _submitting
                      ? null
                      : () => Navigator.of(context).pop(),
                  child: const Text('Cancel'),
                ),
                const SizedBox(width: 8),
                FilledButton(
                  onPressed: _submitting ? null : _submit,
                  child: _submitting
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('Book'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
