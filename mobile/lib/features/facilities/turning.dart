import 'package:flutter/material.dart';

/// Turn-session readout shared by the unit tile and the drum detail sheet —
/// mirrors `describeTurning` in `frontend/src/lib/bmgFormat.ts` so both
/// apps phrase (and warn about) stale turning identically. `daysSince`
/// comes from the batch payload (`days_since_last_turning`, null = never
/// turned) and `dueDays` is the server's turning cadence
/// (`turning_due_days`, the same threshold the TURNING_DUE alert uses).
({String label, bool stale}) describeTurning(int? daysSince, int? dueDays) {
  if (daysSince == null) {
    return (label: 'Never turned', stale: true);
  }
  if (daysSince == 0) {
    return (label: 'Turned today', stale: false);
  }
  return (
    label:
        'Turned $daysSince day${daysSince == 1 ? '' : 's'} ago',
    stale: dueDays != null && daysSince > dueDays,
  );
}

/// The warning tone used for stale turning, matching the app's other
/// amber warning usages (queue/ETA text).
Color turningStaleColor() => const Color(0xFF8A5A00);
