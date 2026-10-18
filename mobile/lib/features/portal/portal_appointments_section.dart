import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import '../../core/api/api_client.dart';
import '../../core/models/appointment_portal.dart';
import '../../core/services/api_service.dart';
import '../common/slot_booking_sheet.dart';
import '../common/widgets.dart';

class PortalAppointmentsSection extends StatefulWidget {
  const PortalAppointmentsSection({super.key});
  @override
  State<PortalAppointmentsSection> createState() => _State();
}

class _State extends State<PortalAppointmentsSection> {
  List<PortalAppointment> _items = const [];
  bool _loading = true;
  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final r = await ApiService.I.portalAppointments();
      if (mounted)
        setState(() {
          _items = r;
          _loading = false;
        });
    } catch (e) {
      if (kDebugMode) debugPrint('PortalAppointmentsSection.load failed: $e');
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _book() async {
    final booked = await showSlotBookingSheet(context);
    if (!booked || !mounted) return;
    await _load();
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('Appointment booked.')),
    );
  }

  Future<void> _cancel(PortalAppointment a) async {
    final ok = await showDialog<bool>(
        context: context,
        builder: (c) => AlertDialog(
                title: const Text('Cancel appointment?'),
                content: const Text(
                    'Online cancellation closes one hour before the start time.'),
                actions: [
                  TextButton(
                      onPressed: () => Navigator.pop(c, false),
                      child: const Text('Keep')),
                  FilledButton(
                      onPressed: () => Navigator.pop(c, true),
                      child: const Text('Cancel appointment'))
                ]));
    if (ok == true) {
      try {
        await ApiService.I.cancelPortalAppointment(a);
        await _load();
      } catch (e) {
        if (mounted)
          ScaffoldMessenger.of(context)
              .showSnackBar(SnackBar(content: Text(mapDioError(e).message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) => SectionCard(
      title: 'Appointments',
      icon: Icons.calendar_month_outlined,
      child: Column(children: [
        Align(
            alignment: Alignment.centerLeft,
            child: FilledButton.icon(
                onPressed: _book,
                icon: const Icon(Icons.add),
                label: const Text('Book Clinic or Guidance'))),
        const SizedBox(height: 8),
        if (_loading)
          const LinearProgressIndicator()
        else if (_items.isEmpty)
          const Padding(
              padding: EdgeInsets.all(12), child: Text('No appointments yet.'))
        else
          ..._items.map((a) => ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(
                  '${a.department == 'clinic' ? 'Clinic' : 'Guidance'} · ${a.providerName ?? 'Provider'}'),
              subtitle:
                  Text('${a.startsAt} · ${a.status.replaceAll('_', ' ')}'),
              trailing: a.status == 'scheduled'
                  ? IconButton(
                      onPressed: () => _cancel(a),
                      tooltip: 'Cancel',
                      icon: const Icon(Icons.cancel_outlined))
                  : null))
      ]));
}
