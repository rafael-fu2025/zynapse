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
  GuidanceQueueTab,
  SessionsTab,
  SchedulingTab,
  AnalyticsTab,
} from '@/components/counselling/tabs';
import { useGuidanceQueueToday } from '@/hooks/useQueue';
import { hasPermission, useAuthStore } from '@/store/auth';

export default function CounsellingPage() {
  const [params, setParams] = useSearchParams();
  const canReadQueue = useAuthStore((state) => hasPermission(state, 'counselling.queue.read'));
  const allowedTabs = canReadQueue
    ? ['queue', 'sessions', 'scheduling', 'analytics']
    : ['sessions', 'scheduling', 'analytics'];

  const requestedTab = params.get('tab') ?? 'sessions';
  const tab = allowedTabs.includes(requestedTab) ? requestedTab : 'sessions';
  const rawSessionId = params.get('session');
  const parsedSessionId = rawSessionId !== null && /^\d+$/.test(rawSessionId) ? Number(rawSessionId) : null;
  const selectedId = parsedSessionId !== null && parsedSessionId > 0 ? parsedSessionId : null;

  const queue = useGuidanceQueueToday(canReadQueue);
  const active = queue.data?.find((entry) => entry.status === 'called' || entry.status === 'in_session');

  useEffect(() => {
    if (
      requestedTab !== tab ||
      (rawSessionId !== null && selectedId === null) ||
      (tab !== 'sessions' && rawSessionId !== null)
    ) {
      const next = new URLSearchParams(params);
      if (tab === 'sessions') next.delete('tab');
      else next.set('tab', tab);
      if (tab !== 'sessions' || selectedId === null) next.delete('session');
      setParams(next, { replace: true });
    }
  }, [params, rawSessionId, requestedTab, selectedId, setParams, tab]);

  function setTab(nextTab: string) {
    const next = new URLSearchParams(params);
    if (nextTab === 'sessions') next.delete('tab');
    else next.set('tab', nextTab);
    if (nextTab !== 'sessions') next.delete('session');
    setParams(next, { replace: true });
  }

  function selectSession(id: number | null) {
    const next = new URLSearchParams(params);
    next.delete('tab');
    if (id === null) next.delete('session');
    else next.set('session', String(id));
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
                <TabsTrigger value="sessions">Sessions &amp; Notes</TabsTrigger>
                <TabsTrigger value="scheduling">Scheduling</TabsTrigger>
                <TabsTrigger value="analytics">Analytics</TabsTrigger>
              </TabsList>
            }
          />

          {canReadQueue && (
            <TabsContent value="queue" className="mt-4">
              <GuidanceQueueTab onOpenSession={selectSession} />
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
        </Tabs>
      </main>
    </TooltipProvider>
  );
}
