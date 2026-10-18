import type { WorkstationUnit } from '@/schemas/workstationDashboard';

interface WorkstationAction {
  label: string;
  href: string;
  permission: string;
}

interface WorkstationDefinition {
  unit: WorkstationUnit;
  label: string;
  path: string;
  permission: string;
  welcome: string;
  activityUnit: string;
  hub: string;
  emptyRecent: string;
  actions: readonly WorkstationAction[];
}

/** Shared by routing, navigation, prefetch and the root workstation selector. */
export const WORKSTATIONS: readonly WorkstationDefinition[] = [
  {
    unit: 'clinic', label: 'Clinic', path: '/clinic/dashboard',
    permission: 'clinic.encounters.read',
    welcome: 'Your clinic workstation for patient intake, consultations and daily care.',
    activityUnit: 'encounters', hub: 'Clinical operations',
    emptyRecent: 'No encounters recorded yet.',
    actions: [
      { label: 'Patient queue', href: '/clinic?tab=queue', permission: 'clinic.queue.read' },
      { label: 'Appointments', href: '/appointments', permission: 'clinic.appointments.read' },
      { label: 'Inventory', href: '/inventory', permission: 'clinic.inventory.read' },
      { label: 'Patient records', href: '/patients', permission: 'clinic.patients.read' },
    ],
  },
  {
    unit: 'guidance', label: 'Guidance', path: '/counselling/dashboard',
    permission: 'counselling.records.read',
    welcome: 'Your guidance workstation for counselling sessions, appointments and student support.',
    activityUnit: 'sessions', hub: 'Guidance operations',
    emptyRecent: 'No sessions recorded in this scope yet.',
    actions: [
      { label: 'Guidance queue', href: '/counselling?tab=queue', permission: 'counselling.queue.read' },
      { label: 'Appointments', href: '/counselling?tab=appointments', permission: 'counselling.records.read' },
      { label: 'Staff schedules', href: '/counselling?tab=scheduling', permission: 'counselling.records.read' },
      { label: 'Referrals', href: '/referrals', permission: 'referrals.read' },
      { label: 'Services', href: '/counselling/services', permission: 'counselling.services.manage' },
      { label: 'Surveys', href: '/counselling/surveys', permission: 'counselling.surveys.manage' },
      { label: 'Announcements', href: '/counselling/announcements', permission: 'counselling.announcements.manage' },
    ],
  },
  {
    unit: 'facilities', label: 'Facilities', path: '/facilities/dashboard',
    permission: 'facilities.units.read',
    welcome: 'Your facilities workstation for composting drums, batch activity and bound devices.',
    activityUnit: 'batches', hub: 'Facilities operations',
    emptyRecent: 'No drums recorded yet.',
    actions: [
      { label: 'Composting drums', href: '/facilities', permission: 'facilities.units.read' },
      { label: 'Devices', href: '/facilities/devices', permission: 'facilities.units.read' },
      { label: 'Waste categories', href: '/facilities/waste-categories', permission: 'facilities.units.read' },
      { label: 'Reports', href: '/reports', permission: 'reports.read' },
    ],
  },
];

export function workstationFor(unit: WorkstationUnit): WorkstationDefinition {
  return WORKSTATIONS.find((item) => item.unit === unit)!;
}

export const DASHBOARD_PERMISSIONS = [
  'employee.portal.read', ...WORKSTATIONS.map((item) => item.permission),
];
