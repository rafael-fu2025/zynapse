/**
 * CounsellingPage — university guidance & mental health sessions (Phase 15).
 *
 * Section orchestrator (2026-09-15: secondary sidebar on wide screens).
 *
 * Section order was reworked on 2026-09-23 so the two daily-working surfaces
 * lead: Queue (the day board — upcoming / today / archived) and Appointments
 * (the booking book, promoted out of Scheduling, which had buried it behind
 * a second sub-tab). What remains is the working set:
 *   - Queue: the day board. Upcoming, today's live sessions (appointments
 *     plus kiosk check-ins), and archived/completed.
 *   - Appointments: the booking book — book and confirm, with unapproved
 *     bookings grouped under a Needs action divider (2026-09-25). Rows
 *     whose session has started deep-link to it via `?session=`.
 *   - Follow-ups: WHO-5 aftercare loop, with the follow-up appointment list.
 *   - Scheduling: availability windows (list/calendar).
 *
 * **The four content surfaces moved to the sidebar** (2026-09-24). Surveys,
 * Announcements, Analytics and Services used to be sections here; they are now
 * their own routes (`/counselling/<surface>`) with their own rows under
 * Guidance Center, so this page holds only the surfaces the desk works in
 * daily. Two consequences worth knowing: the strip no longer groups (a single
 * heading over every remaining section would say nothing), and the four old
 * `?tab=` values now redirect to their new routes for the sake of stale
 * bookmarks. No surface was rewritten to move it — the components are the same
 * ones, rendered by a page instead of a tab.
 *
 * **Sessions & Notes is gone** (2026-09-23, later the same day). It was a
 * master-detail list of every session in the tenant, which duplicated the
 * booking the session belonged to and made the desk hunt for the patient twice.
 * Its content now lives inside each patient's booking row on the **Queue**
 * board: click an on-going session in Today's Sessions and it expands in place,
 * with Complete Session at the end of that flow.
 *
 * **Sessions are Queue-only** (2026-09-23, third revision). The Appointments
 * table lost its Open Session button and its expandable row, so the Queue is
 * the single place a session is read. That has one consequence worth stating:
 * a user without `counselling.queue.read` cannot reach sessions at all, even
 * though they can still read the booking. `?session=N` follows the move — it
 * resolves to the Queue, which force-opens the matching row (a link is an
 * explicit request for that session, so it is not held to the on-going-only
 * rule that governs a row click). When no row matches, the Queue renders the
 * session in a panel above the board rather than dead-ending the link.
 *
 * Every remaining section carries a red notification dot when it has pending
 * work — a counted dot where a backlog can be tallied, a bare dot where the
 * signal is not a number.
 *
 * Subcomponents, dialogs, and workspace live under `src/components/counselling/`.
 */
import { BellRing, CalendarCheck, CalendarDays, ListOrdered } from 'lucide-react';
import { useEffect } from 'react';
import { Navigate, useSearchParams } from 'react-router-dom';
import { NotificationDot } from '@/components/NotificationDot';
import { PageHeader } from '@/components/PageHeader';
import { TabSections, type TabSection } from '@/components/TabSections';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { TooltipProvider } from '@/components/ui/tooltip';
import {
  AppointmentsTab,
  FollowupsTab,
  GuidanceQueueTab,
  SchedulingTab,
} from '@/components/counselling/tabs';
import { useGuidanceFollowups } from '@/hooks/useGuidanceFollowups';
import { useGuidanceQueueToday } from '@/hooks/useQueue';
import { useAppointments, useAvailability } from '@/hooks/useSchedule';
import { hasPermission, useAuthStore } from '@/store/auth';

/** Queue entry states that count as work still in front of the desk. */
const QUEUE_OPEN_STATUSES = ['waiting', 'called', 'in_session'];

/**
 * `?tab=` values that used to select one of the four content surfaces which now
 * have their own routes and sidebar rows (2026-09-24). Nothing links to these
 * any more; the map is for bookmarks and for links pasted before the move.
 * Without it an old link would silently land on the default section, because an
 * unrecognised tab value falls back rather than erroring.
 */
const MOVED_TABS: Readonly<Record<string, string>> = {
  surveys: '/counselling/surveys',
  announcements: '/counselling/announcements',
  analytics: '/counselling/analytics',
  services: '/counselling/services',
};

export default function CounsellingPage() {
  const [params, setParams] = useSearchParams();
  const canReadQueue = useAuthStore((state) => hasPermission(state, 'counselling.queue.read'));
  const canReadSchedule = useAuthStore((state) => hasPermission(state, 'counselling.schedule.read'));
  const canMutateSchedule = useAuthStore(
    (state) =>
      hasPermission(state, 'counselling.schedule.manage') ||
      hasPermission(state, 'counselling.schedule.team_manage'),
  );
  const canSeeFollowups = useAuthStore((state) => hasPermission(state, 'counselling.responses.read_any'));

  const allowedTabs = [
    ...(canReadQueue ? ['queue'] : []),
    'appointments',
    ...(canSeeFollowups ? ['followups'] : []),
    'scheduling',
  ];

  // The landing surface is stated explicitly rather than taken from nav
  // position: the order leads with Queue → Appointments, and the contract is
  // "queue for queue-capable staff, the booking book for everyone else". With
  // Sessions & Notes gone, a supervisor without queue rights lands on
  // Appointments — the tab that now hosts sessions and notes.
  const defaultTab = canReadQueue ? 'queue' : 'appointments';

  const rawSessionId = params.get('session');
  const parsedSessionId = rawSessionId !== null && /^\d+$/.test(rawSessionId) ? Number(rawSessionId) : null;
  const selectedId = parsedSessionId !== null && parsedSessionId > 0 ? parsedSessionId : null;

  // Legacy deep links from when Appointments was the second sub-tab of
  // Scheduling: `?subtab=appointments` now means the Appointments section.
  const rawSubTab = params.get('subtab');
  const legacyTab =
    rawSubTab === 'appointments' ? 'appointments' : rawSubTab === 'availability' ? 'scheduling' : null;

  // `?session=N` carries no tab of its own — it resolves to the section that
  // renders sessions. Since 2026-09-23 (second revision) that is the **Queue**:
  // Sessions & Notes open from a patient's on-going row on the day board, and
  // the Appointments book deliberately offers no way in. Keeping the mapping
  // here rather than in the notification builder means every existing
  // `?session=` link keeps working without being rewritten.
  const requestedTab = params.get('tab') ?? (selectedId !== null ? 'queue' : (legacyTab ?? defaultTab));
  const tab = allowedTabs.includes(requestedTab) ? requestedTab : defaultTab;

  // An old `?tab=surveys`-style link redirects to the surface's own route
  // rather than silently landing on the default section.
  const movedTo = MOVED_TABS[requestedTab] ?? null;

  const queue = useGuidanceQueueToday(canReadQueue);
  const openQueue = queue.data?.filter((entry) => QUEUE_OPEN_STATUSES.includes(entry.status)).length ?? 0;
  // Lifted unfiltered caseload for the tab dot — mirrors the sidebar
  // counter's tenant-wide view, independent of the tab's status/mine
  // filters (same query key, so the tab itself dedupes into this cache).
  const followups = useGuidanceFollowups('all', false, canSeeFollowups);
  const openFollowups =
    followups.data?.filter((r) => r.status === 'new' || r.status === 'in_review').length ?? 0;
  // Same query key the Queue board's Today bucket reads, so an open board
  // shares this request rather than issuing a second one.
  const todayAppointments = useAppointments({ scope: 'today', enabled: canReadSchedule });
  const todayCount = todayAppointments.data?.data.length ?? 0;
  const availability = useAvailability({ enabled: canReadSchedule && canMutateSchedule });
  // A desk that cannot accept bookings is the one thing Scheduling can be
  // "notified" about; it has no numeric backlog of its own.
  const needsAvailability = canMutateSchedule && availability.data !== undefined && availability.data.length === 0;

  // Section nav — permission-filtered, order matches the sidebar. Indicators
  // mirror the sidebar counters so the module number is traceable to the tab it
  // belongs to; each is a red dot (see NotificationDot).
  //
  // Flat since 2026-09-24: this strip used to hold three named clusters, and the
  // four content surfaces that made up two of them now live in the sidebar. One
  // group over every remaining section would say nothing, so no `group` is
  // passed and `TabSections` renders the plain strip the other pages use.
  const tabs: readonly TabSection[] = [
    ...(canReadQueue ? [{
      value: 'queue',
      label: 'Queue',
      icon: ListOrdered,
      notify: <NotificationDot count={openQueue} label={`${openQueue} Guidance patients in play today`} />,
    }] : []),
    {
      value: 'appointments',
      label: 'Appointments',
      icon: CalendarCheck,
      notify: (
        <NotificationDot count={todayCount} label={`${todayCount} Guidance appointments today`} />
      ),
    },
    ...(canSeeFollowups ? [{
      value: 'followups',
      label: 'Follow-ups',
      icon: BellRing,
      notify: <NotificationDot count={openFollowups} label={`${openFollowups} open follow-ups`} />,
    }] : []),
    {
      value: 'scheduling',
      label: 'Scheduling',
      icon: CalendarDays,
      ...(needsAvailability
        ? { notify: <NotificationDot label="No availability windows configured" /> }
        : {}),
    },
  ];

  useEffect(() => {
    // A redirect is in flight for a moved tab: rewriting the URL here would
    // race the navigation and strip the tab before the redirect reads it.
    if (movedTo !== null) return;
    if (
      requestedTab !== tab ||
      (tab === defaultTab && params.get('tab') !== null) ||
      (rawSessionId !== null && selectedId === null) ||
      (tab !== 'queue' && rawSessionId !== null) ||
      rawSubTab !== null
    ) {
      const next = new URLSearchParams(params);
      if (tab === defaultTab) next.delete('tab');
      else next.set('tab', tab);
      if (tab !== 'queue' || selectedId === null) next.delete('session');
      // `subtab` belonged to the old Scheduling sub-tab pair.
      next.delete('subtab');
      setParams(next, { replace: true });
    }
  }, [params, defaultTab, movedTo, rawSessionId, rawSubTab, requestedTab, selectedId, setParams, tab]);

  function setTab(nextTab: string) {
    const next = new URLSearchParams(params);
    if (nextTab === defaultTab) next.delete('tab');
    else next.set('tab', nextTab);
    // Only the Queue hosts sessions now, so leaving it must drop the session
    // rather than strand a panel the destination cannot render.
    if (nextTab !== 'queue') next.delete('session');
    next.delete('subtab');
    setParams(next, { replace: true });
  }

  function selectSession(id: number | null) {
    const next = new URLSearchParams(params);
    if (id === null) {
      // Closing the panel keeps the user on the board that hosts it. Dropping
      // the session alone would resolve the bare URL back to the default and
      // bounce them off the page they were working on.
      next.delete('session');
      if (defaultTab === 'queue') next.delete('tab');
      else next.set('tab', 'queue');
    } else {
      // `?session=N` without a tab param resolves to the Queue — deep links
      // and HeaderBreadcrumbs rely on that contract.
      next.delete('tab');
      next.set('session', String(id));
    }
    setParams(next, { replace: false });
  }

  // The four relocated surfaces are their own routes now, so hand the URL over
  // before rendering anything — `tab` would otherwise fall back to the default
  // and the link would land on the wrong section.
  if (movedTo !== null) return <Navigate to={movedTo} replace />;

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
                <GuidanceQueueTab
                  selectedSessionId={selectedId}
                  onCloseSession={() => selectSession(null)}
                  onViewAllAppointments={() => setTab('appointments')}
                />
              </TabsContent>
            )}

            <TabsContent value="appointments">
              <AppointmentsTab />
            </TabsContent>

            {canSeeFollowups && (
              <TabsContent value="followups">
                <FollowupsTab />
              </TabsContent>
            )}

            <TabsContent value="scheduling">
              <SchedulingTab />
            </TabsContent>
          </TabSections>
        </Tabs>
      </main>
    </TooltipProvider>
  );
}
