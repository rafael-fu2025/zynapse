/**
 * CounsellingPage — university guidance & mental health sessions (Phase 15).
 *
 * Tab orchestrator:
 *   - Queue: independent FIFO queue for Guidance check-ins and handoffs.
 *   - Sessions: master-detail sessions list + active session workspace with
 *     AES-256-GCM encrypted notes and amendment tracking.
 *   - Scheduling: availability windows (list/calendar) + chronological appointments.
 *   - Analytics: deterministic no-show optimizer and slot metrics.
 *
 * Subcomponents, dialogs, and workspace live under `src/components/counselling/`.
 */
import { useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/PageHeader';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
      <main className="mx-auto max-w-7xl space-y-4 p-6">
        <Tabs value={tab} onValueChange={setTab} className="space-y-4">
          <PageHeader
            title="Counselling"
            description="Session notes are encrypted at rest (AES-256-GCM). Bookings must fall inside an availability window; repeated no-shows follow the three-strike policy."
            tabs={
              <TabsList>
                {canReadQueue && (
                  <TabsTrigger value="queue">
                    Queue{' '}
                    {active !== undefined && (
                      <Badge className="ml-1.5" variant="info">
                        {active.queue_number}
                      </Badge>
                    )}
                  </TabsTrigger>
                )}
                {canSeeFollowups && <TabsTrigger value="followups">Follow-ups</TabsTrigger>}
                <TabsTrigger value="sessions">Sessions &amp; Notes</TabsTrigger>
                <TabsTrigger value="scheduling">Scheduling</TabsTrigger>
                <TabsTrigger value="analytics">Analytics</TabsTrigger>
                {canManageSurveys && <TabsTrigger value="surveys">Surveys</TabsTrigger>}
                {canManageAnnouncements && <TabsTrigger value="announcements">Announcements</TabsTrigger>}
                {canManageServices && <TabsTrigger value="services">Services</TabsTrigger>}
              </TabsList>
            }
          />

          {canReadQueue && (
            <TabsContent value="queue" className="mt-4">
              <GuidanceQueueTab onOpenSession={selectSession} />
            </TabsContent>
          )}

          {canSeeFollowups && (
            <TabsContent value="followups" className="mt-4">
              <FollowupsTab />
            </TabsContent>
          )}

          <TabsContent value="sessions" className="mt-4">
            <SessionsTab selectedId={selectedId} onSelect={selectSession} />
          </TabsContent>

          <TabsContent value="scheduling" className="mt-4">
            <SchedulingTab />
          </TabsContent>

          <TabsContent value="analytics" className="mt-4">
            <AnalyticsTab />
          </TabsContent>

          {canManageSurveys && (
            <TabsContent value="surveys" className="mt-4">
              <SurveysTab />
            </TabsContent>
          )}

          {canManageAnnouncements && (
            <TabsContent value="announcements" className="mt-4">
              <AnnouncementsTab />
            </TabsContent>
          )}

          {canManageServices && (
            <TabsContent value="services" className="mt-4">
              <ServicesTab />
            </TabsContent>
          )}
        </Tabs>
      </main>
    </TooltipProvider>
  );
}
