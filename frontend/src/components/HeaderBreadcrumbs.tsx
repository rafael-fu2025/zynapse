import { ChevronRight, Home } from 'lucide-react';
import { Link, useLocation } from 'react-router-dom';
import { resolvePageMeta } from '@/lib/pageMeta';
import { titleCase } from '@/lib/utils';

// Human-friendly labels for query tabs across modules
const TAB_LABELS: Record<string, string> = {
  queue: 'Queue',
  closed: 'Closed',
  staff: 'Staff Schedules',
  medicines: 'Medicines',
  supplies: 'Supplies',
  reorders: 'Reorders',
  insights: 'Insights',
  sessions: 'Sessions & Notes',
  scheduling: 'Scheduling',
  analytics: 'Analytics',
  students: 'Students',
  employees: 'Employees',
  overview: 'Overview',
  history: 'Visit History',
  booking: 'Book Appointment',
  active: 'Active Batches',
  compliance: 'Compliance',
  logs: 'Process Logs',
  clinic: 'Clinic',
  counselling: 'Counselling',
  facilities: 'Facilities',
  inventory: 'Inventory',
  referrals: 'Referrals',
};

export function HeaderBreadcrumbs() {
  const { pathname, search } = useLocation();
  if (pathname === '/') return null;

  const parts = pathname.split('/').filter(Boolean);
  const crumbs: Array<{ label: string; href: string }> = [];

  let acc = '';
  for (const part of parts) {
    acc += `/${part}`;
    const meta = resolvePageMeta(acc);
    let label = meta.title !== '' ? meta.title : (part.charAt(0).toUpperCase() + part.slice(1).replaceAll('-', ' '));

    // Refine param parts (e.g. numeric ID in `/facilities/drums/2`)
    if (/^\d+$/.test(part)) {
      label = `#${part}`;
    }

    crumbs.push({ label, href: acc });
  }

  // Extract active ?tab= or ?session= to show deep context in breadcrumbs
  const searchParams = new URLSearchParams(search);
  const activeTab = searchParams.get('tab');
  const activeSession = searchParams.get('session');

  if (activeTab !== null && activeTab !== '') {
    const tabName = TAB_LABELS[activeTab.toLowerCase()] ?? titleCase(activeTab.replaceAll('-', ' '));
    crumbs.push({
      label: tabName,
      href: `${pathname}?tab=${encodeURIComponent(activeTab)}`,
    });
  }

  if (activeSession !== null && /^\d+$/.test(activeSession)) {
    crumbs.push({
      label: `Session #${activeSession}`,
      href: `${pathname}?session=${activeSession}`,
    });
  }

  return (
    <nav aria-label="Breadcrumbs" className="hidden items-center gap-1.5 text-xs text-muted-foreground md:flex">
      <Link
        to="/"
        className="flex items-center gap-1 transition-colors hover:text-foreground"
        title="Dashboard"
      >
        <Home className="size-3.5" />
        <span className="sr-only">Dashboard</span>
      </Link>
      {crumbs.map((crumb, idx) => {
        const isLast = idx === crumbs.length - 1;
        return (
          <span key={crumb.href} className="flex items-center gap-1.5">
            <ChevronRight className="size-3 text-muted-foreground/60" />
            {isLast ? (
              <span className="font-semibold text-foreground">{crumb.label}</span>
            ) : (
              <Link to={crumb.href} className="transition-colors hover:text-foreground">
                {crumb.label}
              </Link>
            )}
          </span>
        );
      })}
    </nav>
  );
}
