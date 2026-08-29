/**
 * DashboardPage — Phase 4 widgets.
 *
 * - Permission-aware quick links to each module (shadcn Cards).
 * - Live counters from the new /dashboard/counters endpoint.
 * - Sign-out.
 */
import { subMonths } from 'date-fns';
import { formatInTimeZone } from 'date-fns-tz';
import {
  Boxes,
  CalendarClock,
  ContactRound,
  Factory,
  HeartPulse,
  MessagesSquare,
  ScrollText,
  Share2,
  Users,
  type LucideIcon,
} from 'lucide-react';
import { useMemo } from 'react';
import { Link } from 'react-router-dom';

import {
  Card,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { ClinicAnalyticsView } from '@/components/reports/ClinicAnalyticsView';
import { useMe } from '@/hooks/useAuth';
import { useDashboardCounters } from '@/hooks/useDashboard';
import { useClinicReport } from '@/hooks/useReports';
import { hasPermission, useAuthStore } from '@/store/auth';

interface Module {
  /** Permission code gating the module — surfaced via title, not copy. */
  code: string;
  label: string;
  /** Plain-language line for the card (what you'll find inside). */
  blurb: string;
  href: string;
  icon: LucideIcon;
  summary: (c: ReturnType<typeof useDashboardCounters>['data']) => string;
}

const MODULES: ReadonlyArray<Module> = [
  {
    code: 'clinic.encounters.read',
    label: 'Clinic',
    blurb: 'Encounters, vitals and treatments',
    href: '/clinic',
    icon: HeartPulse,
    summary: (c) => {
      const o = c?.clinic?.open_encounters ?? 0;
      const k = c?.clinic?.closed_encounters ?? 0;
      return `${o} open · ${k} closed`;
    },
  },
  {
    code: 'counselling.records.read',
    label: 'Counselling',
    blurb: 'Guidance sessions and encrypted notes',
    href: '/counselling',
    icon: MessagesSquare,
    summary: (c) => {
      const o = c?.counselling?.open_sessions ?? 0;
      const k = c?.counselling?.closed_sessions ?? 0;
      return `${o} open · ${k} closed`;
    },
  },
  {
    code: 'facilities.units.read',
    label: 'Facilities',
    blurb: 'Composting drums and batch tracking',
    href: '/facilities',
    icon: Factory,
    summary: (c) => {
      const f = c?.facilities;
      const risk = f?.at_risk ?? 0;
      return `${f?.units_idle ?? 0} idle · ${f?.units_processing ?? 0} processing · ${f?.units_awaiting ?? 0} awaiting${risk > 0 ? ` · ⚠ ${risk} at risk` : ''}`;
    },
  },
  {
    code: 'clinic.patients.read',
    label: 'Patients',
    blurb: 'Student and employee registry',
    href: '/patients',
    icon: ContactRound,
    summary: () => 'Students · employees · allergies',
  },
  {
    code: 'clinic.inventory.read',
    label: 'Inventory',
    blurb: 'Supplies, medicines and stock levels',
    href: '/inventory',
    icon: Boxes,
    summary: () => 'Clinic supplies · stock transactions',
  },
  {
    code: 'clinic.appointments.read',
    label: 'Appointments',
    blurb: 'Scheduling, check-in and visit history',
    href: '/appointments',
    icon: CalendarClock,
    summary: () => 'Scheduling · check-in · lifecycle',
  },
  {
    code: 'referrals.read',
    label: 'Referrals',
    blurb: 'Clinic ↔ counselling handoffs',
    href: '/referrals',
    icon: Share2,
    summary: (c) => {
      const r = c?.referrals;
      return `${r?.submitted ?? 0} submitted · ${r?.under_review ?? 0} in review`;
    },
  },
  {
    code: 'audit.read',
    label: 'Audit',
    blurb: 'Tamper-evident activity record',
    href: '/audit',
    icon: ScrollText,
    summary: (c) => `${c?.audit?.events_last_24h ?? 0} events in 24h`,
  },
  {
    code: 'rbac.manage',
    label: 'Users',
    blurb: 'Accounts, roles and password resets',
    href: '/admin/users',
    icon: Users,
    summary: () => 'Accounts · groups · password resets',
  },
];

/**
 * Clinic-role dashboard body: the analytics data visualization replaces
 * the Modules grid so clinic staff see it immediately on login. Uses a
 * trailing ~5-month window so the monthly bar chart is meaningful
 * (matches the clinic-statistics poster's five-month summary).
 */
function ClinicRoleDashboard() {
  const range = useMemo(() => {
    const now = new Date();
    return {
      start: formatInTimeZone(subMonths(now, 5), 'Asia/Manila', 'yyyy-MM-dd'),
      end: formatInTimeZone(now, 'Asia/Manila', 'yyyy-MM-dd'),
    };
  }, []);
  const clinic = useClinicReport(range.start, range.end);

  return (
    <ClinicAnalyticsView
      report={clinic.data}
      isLoading={clinic.isLoading}
      isError={clinic.isError}
      isFetching={clinic.isFetching}
      onRetry={() => void clinic.refetch()}
    />
  );
}

export default function DashboardPage() {
  const { data, isLoading } = useMe();
  const counters = useDashboardCounters();
  const perms = useAuthStore((s) => s.permissions);

  if (isLoading) {
    return <p className="p-6 text-sm text-muted-foreground">Loading session…</p>;
  }

  // Clinic role (has clinic.encounters.read but NOT the admin wildcard)
  // gets the analytics dashboard directly — the Modules grid is hidden.
  const isClinicRole = hasPermission({ permissions: perms } as never, 'clinic.encounters.read')
    && !hasPermission({ permissions: perms } as never, '*');

  if (isClinicRole) {
    return (
      <main className="mx-auto max-w-7xl space-y-6 p-4 md:p-6">
        <header>
          <h1 className="text-2xl font-semibold text-foreground">Dashboard</h1>
          <p className="text-sm text-muted-foreground">
            Signed in as {data?.email ?? 'unknown'} — Asia/Manila
          </p>
        </header>
        <ClinicRoleDashboard />
      </main>
    );
  }

  const visible = MODULES.filter((m) => hasPermission({ permissions: perms } as never, m.code));

  return (
    <main className="mx-auto max-w-6xl space-y-6 p-4 md:p-6">
      <header>
        <h1 className="text-2xl font-semibold text-foreground">Dashboard</h1>
        <p className="text-sm text-muted-foreground">
          Signed in as {data?.email ?? 'unknown'} — Asia/Manila
        </p>
      </header>

      {/* Identity coverage removed (2026-08-05): it tracked the legacy
          manual user↔patient linking rollout, which is gone after the
          identity-consolidation work. The Modules grid below is now the
          dashboard's only body. */}

      <section aria-labelledby="modules-title">
        <h2 id="modules-title" className="text-lg font-semibold text-foreground">
          Modules
        </h2>
        <ul className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {visible.map((m) => (
            <li key={m.code}>
              {/* Client-side navigation — an <a href> full reload would
                  drop the in-memory access token (never in localStorage). */}
              <Link to={m.href} className="group block touch-manipulation rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <Card className="relative overflow-hidden transition-colors hover:border-primary/50 group-active:border-primary/50">
                  {/* Upper-right sheen — theme-aware (black/white), hover-only. */}
                  <span
                    aria-hidden
                    className="pointer-events-none absolute inset-0 bg-radial-[circle_at_top_right] from-foreground/10 via-transparent via-40% to-transparent opacity-0 transition-opacity duration-200 group-hover:opacity-100"
                  />
                  <CardHeader className="p-4">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0 space-y-1">
                        <CardTitle className="text-sm">{m.label}</CardTitle>
                        <CardDescription className="text-xs">{m.summary(counters.data)}</CardDescription>
                        <p className="pt-1 text-xs text-muted-foreground" title={m.code}>
                          {m.blurb}
                        </p>
                      </div>
                      <m.icon
                        className="size-5 shrink-0 text-foreground transition-transform duration-200 group-hover:-rotate-12"
                        aria-hidden
                      />
                    </div>
                  </CardHeader>
                </Card>
              </Link>
            </li>
          ))}
        </ul>
        {counters.isError && (
          <p role="alert" className="mt-2 text-xs text-destructive">
            Counters unavailable. Page functionality is unaffected.
          </p>
        )}
      </section>
    </main>
  );
}
