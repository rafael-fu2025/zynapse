import 'package:flutter/material.dart';
import 'package:hugeicons/hugeicons.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_envelope.dart';
import '../../core/models/appointment_portal.dart';
import '../../core/services/api_service.dart';
import '../../core/utils/dates.dart';
import 'widgets.dart';

/// Opens the pooled-slot booking sheet and returns true when an appointment
/// was created.
///
/// Both the student Appointments tab and the portal use this sheet. The
/// backend no longer accepts an arbitrary student-chosen time: it only books
/// a time that exists in the pooled staff-schedule slots, and provider
/// assignment is decided server-side. Mirrors the SPA's `StudentBooking` /
/// `PortalAppointments` slot picker.
Future<bool> showSlotBookingSheet(
  BuildContext context, {
  String initialDepartment = 'clinic',
}) async {
  final result = await showSynapseSheet<bool>(
    context,
    builder: (_) => _SlotBookingSheet(initialDepartment: initialDepartment),
  );
  return result ?? false;
}

class _SlotBookingSheet extends StatefulWidget {
  const _SlotBookingSheet({required this.initialDepartment});

  final String initialDepartment;

  @override
  State<_SlotBookingSheet> createState() => _SlotBookingSheetState();
}

class _SlotBookingSheetState extends State<_SlotBookingSheet> {
  late String _department = widget.initialDepartment;
  DateTime _date = DateTime.now().add(const Duration(days: 1));
  List<PortalAppointmentSlot> _slots = const [];
  PortalAppointmentSlot? _selected;
  bool _loadingSlots = false;
  bool _submitting = false;
  String? _error;
  final _reasonController = TextEditingController();

  @override
  void dispose() {
    _reasonController.dispose();
    super.dispose();
  }

  String get _dateInput => toDateInput(_date);

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime.now(),
      lastDate: DateTime.now().add(const Duration(days: 90)),
    );
    if (picked == null) return;
    setState(() {
      _date = picked;
      // A different day has a different slot pool — never keep a stale
      // selection that belongs to the previous date.
      _slots = const [];
      _selected = null;
    });
  }

  Future<void> _findSlots() async {
    setState(() {
      _loadingSlots = true;
      _error = null;
      _slots = const [];
      _selected = null;
    });
    try {
      final slots = await ApiService.I.portalAppointmentSlots(
        _department,
        _dateInput,
      );
      if (!mounted) return;
      setState(() {
        _slots = slots;
        _selected = slots.isEmpty ? null : slots.first;
        _loadingSlots = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loadingSlots = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _loadingSlots = false;
      });
    }
  }

  Future<void> _submit() async {
    final slot = _selected;
    if (slot == null) return;
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      await ApiService.I.bookPortalAppointment(
        slot,
        reason: _reasonController.text.trim(),
      );
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _submitting = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = mapDioError(e).message;
        _submitting = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final canBook = _selected != null && !_submitting;
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
              subtitle: 'Pick an open clinic or guidance slot',
            ),
            const SizedBox(height: 16),
            InputDecorator(
              decoration: const InputDecoration(
                labelText: 'Department',
                border: OutlineInputBorder(),
                isDense: true,
              ),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  value: _department,
                  isExpanded: true,
                  isDense: true,
                  items: const [
                    DropdownMenuItem(value: 'clinic', child: Text('Clinic')),
                    DropdownMenuItem(
                      value: 'counselling',
                      child: Text('Guidance'),
                    ),
                  ],
                  onChanged: _submitting
                      ? null
                      : (v) {
                          if (v == null) return;
                          setState(() {
                            _department = v;
                            _slots = const [];
                            _selected = null;
                          });
                        },
                ),
              ),
            ),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: _submitting ? null : _pickDate,
              icon: const Icon(HugeIcons.strokeRoundedCalendar01, size: 18),
              label: Text(_dateInput),
            ),
            const SizedBox(height: 12),
            FilledButton.icon(
              onPressed: _loadingSlots || _submitting ? null : _findSlots,
              icon: _loadingSlots
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(HugeIcons.strokeRoundedSearch01, size: 18),
              label: const Text('Find available slots'),
            ),
            if (!_loadingSlots && _slots.isNotEmpty) ...[
              const SizedBox(height: 12),
              InputDecorator(
                decoration: const InputDecoration(
                  labelText: 'Available slot',
                  border: OutlineInputBorder(),
                  isDense: true,
                ),
                child: DropdownButtonHideUnderline(
                  child: DropdownButton<PortalAppointmentSlot>(
                    value: _selected,
                    isExpanded: true,
                    isDense: true,
                    items: [
                      for (final slot in _slots)
                        DropdownMenuItem(
                          value: slot,
                          child: Text(
                            slot.remaining > 1
                                ? '${fmtClock(slot.startsAt.toLocal())} · ${slot.remaining} available'
                                : fmtClock(slot.startsAt.toLocal()),
                          ),
                        ),
                    ],
                    onChanged: _submitting
                        ? null
                        : (v) => setState(() => _selected = v),
                  ),
                ),
              ),
            ] else if (!_loadingSlots) ...[
              const SizedBox(height: 10),
              Text(
                _error == null
                    ? 'Choose a date, then find an open slot.'
                    : 'No available slots could be loaded for this date.',
                style: const TextStyle(color: Colors.black54, fontSize: 12),
              ),
            ],
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
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(
                _error!,
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
                  onPressed:
                      _submitting ? null : () => Navigator.of(context).pop(),
                  child: const Text('Cancel'),
                ),
                const SizedBox(width: 8),
                FilledButton(
                  onPressed: canBook ? _submit : null,
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
