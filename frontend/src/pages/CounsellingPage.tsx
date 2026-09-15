/**
 * CounsellingPage — university guidance & mental health sessions (Phase 15).
 *
 * Section orchestrator (2026-09-15: secondary sidebar on wide screens):
 *   - Queue: independent FIFO queue for Guidance check-ins and handoffs.
 *   - Follow-ups: WHO-5 aftercare loop.
 *   - Sessions: master-detail sessions list + active session workspace with
 *     AES-256-GCM encrypted notes and amendment tracking.
 *   - Scheduling: availability windows (list/calendar) + chronological appointments.
 *   - Analytics: deterministic no-show optimizer and slot metrics.
 *   - Surveys / Announcements / Services: guidance content management.
 *
 * Subcomponents, dialogs, and workspace live under `src/components/counselling/`.
 */
import {
  BarChart3,
  BellRing,
  CalendarDays,
  ClipboardList,
  HeartHandshake,
  ListOrdered,
  Megaphone,
  NotebookPen,
} from 'lucide-react';
import { useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { CountBadge } from '@/components/CountBadge';
import { PageHeader } from '@/components/PageHeader';
import { TabSections, type TabSection } from '@/components/TabSections';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { TooltipProvider } from '@/components/ui/tooltip';
import {
  AnnouncementsTab,
  FollowupsTab,
  GuidanceQueueTab,
  SessionsTab,
  SchedulingTab,
  AnalyticsTab,
  ServicesTab,
  SurveysTab,
} from '@/components/counselling/tabs';
import { useDashboardCounters } from '@/hooks/useDashboard';
import { useGuidanceFollowups } from '@/hooks/useGuidanceFollowups';
import { useGuidanceQueueToday } from '@/hooks/useQueue';
import { hasPermission, useAuthStore } from '@/store/auth';

export default function CounsellingPage() {
  const [params, setParams] = useSearchParams();
  const canReadQueue = useAuthStore((state) => hasPermission(state, 'counselling.queue.read'));
  const canManageAnnouncements = useAuthStore((state) => hasPermission(state, 'counselling.announcements.manage'));
  const canManageServices = useAuthStore((state) => hasPermission(state, 'counselling.services.manage'));
  const canManageSurveys = useAuthStore((state) => hasPermission(state, 'counselling.surveys.manage'));
  const canSeeFollowups = useAuthStore((state) => hasPermission(state, 'counselling.responses.read_any'));

  const allowedTabs = [
    ...(canReadQueue ? ['queue'] : []),
    ...(canSeeFollowups ? ['followups'] : []),
    'sessions',
    'scheduling',
    'analytics',
    ...(canManageSurveys ? ['surveys'] : []),
    ...(canManageAnnouncements ? ['announcements'] : []),
    ...(canManageServices ? ['services'] : []),
  ];

  // The first tab is the daily landing surface — queue for queue-capable
  // staff, sessions for everyone else. A valid ?session=N still implies the
  // sessions view so existing deep links and breadcrumbs keep working.
  const defaultTab = allowedTabs[0] ?? 'sessions';
  const rawSessionId = params.get('session');
  const parsedSessionId = rawSessionId !== null && /^\d+$/.test(rawSessionId) ? Number(rawSessionId) : null;
  const selectedId = parsedSessionId !== null && parsedSessionId > 0 ? parsedSessionId : null;

  const requestedTab = params.get('tab') ?? (selectedId !== null ? 'sessions' : defaultTab);
  const tab = allowedTabs.includes(requestedTab) ? requestedTab : defaultTab;

  const queue = useGuidanceQueueToday(canReadQueue);
  const active = queue.data?.find((entry) => entry.status === 'called' || entry.status === 'in_session');
  const waiting = queue.data?.filter((entry) => entry.status === 'waiting').length ?? 0;
  // Lifted unfiltered caseload for the tab badge — mirrors the sidebar
  // counter's tenant-wide view, independent of the tab's status/mine
  // filters (same query key, so the tab itself dedupes into this cache).
  const followups = useGuidanceFollowups('all', false, canSeeFollowups);
  const openFollowups =
    followups.data?.filter((r) => r.status === 'new' || r.status === 'in_review').length ?? 0;
  const counters = useDashboardCounters();
  const openSessions = counters.data?.counselling?.open_sessions ?? 0;

  // Section nav — permission-filtered, order matches the sidebar. Count
  // badges mirror the sidebar counters so the module number is traceable
  // to the tab it belongs to.
  const tabs: readonly TabSection[] = [
    ...(canReadQueue ? [{
      value: 'queue',
      label: 'Queue',
      icon: ListOrdered,
      // Live "now serving" number while someone is called or in
      // session; otherwise the waiting backlog count.
      badge: active?.queue_number !== undefined ? (
        <Badge variant="info" className="ml-1.5 h-4 shrink-0 px-1.5 py-0 font-mono text-[10px] lg:ml-0">
          {active.queue_number}
        </Badge>
      ) : <CountBadge count={waiting} />,
    }] : []),
    ...(canSeeFollowups ? [{
      value: 'followups',
      label: 'Follow-ups',
      icon: BellRing,
      badge: <CountBadge count={openFollowups} />,
    }] : []),
    { value: 'sessions', label: 'Sessions & Notes', icon: NotebookPen, badge: <CountBadge count={openSessions} /> },
    { value: 'scheduling', label: 'Scheduling', icon: CalendarDays },
    { value: 'analytics', label: 'Analytics', icon: BarChart3 },
    ...(canManageSurveys ? [{ value: 'surveys', label: 'Surveys', icon: ClipboardList }] : []),
    ...(canManageAnnouncements ? [{ value: 'announcements', label: 'Announcements', icon: Megaphone }] : []),
    ...(canManageServices ? [{ value: 'services', label: 'Services', icon: HeartHandshake }] : []),
  ];

  useEffect(() => {
    if (
      requestedTab !== tab ||
      (tab === defaultTab && params.get('tab') !== null) ||
      (rawSessionId !== null && selectedId === null) ||
      (tab !== 'sessions' && rawSessionId !== null)
    ) {
      const next = new URLSearchParams(params);
      if (tab === defaultTab) next.delete('tab');
      else next.set('tab', tab);
      if (tab !== 'sessions' || selectedId === null) next.delete('session');
      setParams(next, { replace: true });
    }
  }, [params, defaultTab, rawSessionId, requestedTab, selectedId, setParams, tab]);

  function setTab(nextTab: string) {
    const next = new URLSearchParams(params);
    if (nextTab === defaultTab) next.delete('tab');
    else next.set('tab', nextTab);
    if (nextTab !== 'sessions') next.delete('session');
    setParams(next, { replace: true });
  }

  function selectSession(id: number | null) {
    const next = new URLSearchParams(params);
    if (id === null) {
      // Closing the workspace stays on Sessions & Notes rather than
      // bouncing back to the queue default.
      next.set('tab', 'sessions');
      next.delete('session');
    } else {
      // ?session=N without a tab param resolves to the sessions view —
      // deep links and HeaderBreadcrumbs rely on that contract.
      next.delete('tab');
      next.set('session', String(id));
    }
    setParams(next, { replace: false });
  }

  return (
    <TooltipProvider delayDuration={150} skipDelayDuration={300}>
      <main className="space-y-4 p-6">
        <Tabs value={tab} onValueChange={setTab} className="space-y-4">
          <PageHeader
            title="Counselling"
            description="Session notes are encrypted at rest (AES-256-GCM). Bookings must fall inside an availability window; repeated no-shows follow the three-strike policy."
          />

          <TabSections tabs={tabs} ariaLabel="Counselling sections">
            {canReadQueue && (
              <TabsContent value="queue">
                <GuidanceQueueTab onOpenSession={selectSession} />
              </TabsContent>
            )}

            {canSeeFollowups && (
              <TabsContent value="followups">
                <FollowupsTab />
              </TabsContent>
            )}

            <TabsContent value="sessions">
              <SessionsTab selectedId={selectedId} onSelect={selectSession} />
            </TabsContent>

            <TabsContent value="scheduling">
              <SchedulingTab />
            </TabsContent>

            <TabsContent value="analytics">
              <AnalyticsTab />
            </TabsContent>

            {canManageSurveys && (
              <TabsContent value="surveys">
                <SurveysTab />
              </TabsContent>
            )}

            {canManageAnnouncements && (
              <TabsContent value="announcements">
                <AnnouncementsTab />
              </TabsContent>
            )}

            {canManageServices && (
              <TabsContent value="services">
                <ServicesTab />
              </TabsContent>
            )}
          </TabSections>
        </Tabs>
      </main>
    </TooltipProvider>
  );
}
