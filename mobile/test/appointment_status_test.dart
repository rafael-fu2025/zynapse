import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:synapse_mobile/features/common/widgets.dart';

/// Locks the appointment status → badge color map against the web's
/// STATUS_VARIANT (`frontend/src/pages/AppointmentsPage.tsx`): confirmed is
/// the `secondary` muted tone, cancelled/no_show share the destructive red.
void main() {
  test('appointmentStatusColor mirrors the web STATUS_VARIANT', () {
    expect(appointmentStatusColor('scheduled'), const Color(0xFF1E6FD9));
    expect(appointmentStatusColor('confirmed'), Colors.grey);
    expect(appointmentStatusColor('checked_in'), const Color(0xFF8A5A00));
    expect(appointmentStatusColor('completed'), const Color(0xFF1B7A43));
    expect(
      appointmentStatusColor('cancelled'),
      appointmentStatusColor('no_show'),
    );
    expect(appointmentStatusColor('cancelled'), const Color(0xFFB3261E));
    // Unknown statuses keep the muted fallback.
    expect(appointmentStatusColor('unknown'), Colors.grey);
  });
}
