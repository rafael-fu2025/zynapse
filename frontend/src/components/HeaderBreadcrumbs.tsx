import { ChevronRight, Home } from 'lucide-react';
import { Link, useLocation } from 'react-router-dom';
import { resolvePageMeta } from '@/lib/pageMeta';
import { titleCase } from '@/lib/utils';

// Human-friendly labels for query tabs across modules
const TAB_LABELS: Record<string, string> = {
  queue: 'Queue',
  closed: 'Closed',
  staff: 'Staff schedules',
  medicines: 'Medicines',
  supplies: 'Supplies',
  reorders: 'Purchases',
  insights: 'Insights',
  scheduling: 'Staff schedules',
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

  const searchParams = new URLSearchParams(search);
  const isRecordsPage = pathname.replace(/\/+$/, '') === '/patients';
  // Clinic and Guidance Records share this route. The registry type is
  // the destination; linking the Records parent only reopens Students.
  const parts = isRecordsPage ? [] : pathname.split('/').filter(Boolean);
  const crumbs: Array<{ label: string; href: string; linkable: boolean }> = [];

  let acc = '';
  for (const part of parts) {
    acc += `/${part}`;
    const meta = resolvePageMeta(acc);
    let label = meta.title !== '' ? meta.title : (part.charAt(0).toUpperCase() + part.slice(1).replaceAll('-', ' '));

    // Refine param parts (e.g. numeric ID in `/facilities/drums/2`)
    if (/^\d+$/.test(part)) {
      label = `#${part}`;
    }

    crumbs.push({ label, href: acc, linkable: meta.linkable !== false });
  }

  // Extract active ?tab= or ?session= to show deep context in breadcrumbs
  // Records defaults to Students even when the canonical URL omits ?tab=.
  const activeTab = searchParams.get('tab') ?? (isRecordsPage ? 'students' : null);
  const activeSession = searchParams.get('session');

  if (activeTab !== null && activeTab !== '') {
    const tabName = TAB_LABELS[activeTab.toLowerCase()] ?? titleCase(activeTab.replaceAll('-', ' '));
    crumbs.push({
      label: tabName,
      href: `${pathname}?tab=${encodeURIComponent(activeTab)}`,
      linkable: true,
    });
  }

  if (activeSession !== null && /^\d+$/.test(activeSession)) {
    crumbs.push({
      label: `Session #${activeSession}`,
      href: `${pathname}?session=${activeSession}`,
      linkable: true,
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
            ) : !crumb.linkable ? (
              <span>{crumb.label}</span>
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
