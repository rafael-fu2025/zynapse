import 'models/session.dart';

/// One operational workstation — mirrors `frontend/src/lib/workstations.ts`.
class WorkstationDef {
  const WorkstationDef({
    required this.unit,
    required this.label,
    required this.permission,
    required this.welcome,
    required this.activityUnit,
    required this.hub,
  });

  final String unit;
  final String label;
  final String permission;
  final String welcome;
  final String activityUnit;
  final String hub;
}

const workstations = <WorkstationDef>[
  WorkstationDef(
    unit: 'clinic',
    label: 'Clinic',
    permission: 'clinic.encounters.read',
    welcome:
        'Your clinic workstation for patient intake, consultations and daily care.',
    activityUnit: 'encounters',
    hub: 'Clinical operations',
  ),
  WorkstationDef(
    unit: 'guidance',
    label: 'Guidance',
    permission: 'counselling.records.read',
    welcome:
        'Your guidance workstation for counselling sessions, appointments and student support.',
    activityUnit: 'sessions',
    hub: 'Guidance operations',
  ),
  WorkstationDef(
    unit: 'facilities',
    label: 'Facilities',
    permission: 'facilities.units.read',
    welcome:
        'Your facilities workstation for composting drums, batch activity and bound devices.',
    activityUnit: 'batches',
    hub: 'Facilities operations',
  ),
];

WorkstationDef workstationFor(String unit) =>
    workstations.firstWhere((w) => w.unit == unit);

/// Workstations the caller can open, in the same order as the SPA sidebar.
List<WorkstationDef> permittedWorkstations(Session? session) {
  if (session == null) return const [];
  return workstations
      .where((w) => session.hasPermission(w.permission))
      .toList(growable: false);
}
