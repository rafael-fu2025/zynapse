/**
 * CounsellingPage — university guidance & mental health sessions (Phase 15).
 *
 * Section orchestrator (2026-09-15: secondary sidebar on wide screens).
 *
 * Section order was reworked on 2026-09-23 so the two daily-working surfaces
 * lead: Queue (the day board — upcoming / today / archived) and Appointments
 * (the booking book, promoted out of Scheduling, which had buried it behind
 * a second sub-tab). Configuration and content follow:
 *   - Queue: the day board. Upcoming, today's live sessions (appointments
 *     plus kiosk check-ins), and archived/completed.
 *   - Appointments: the booking book — book and confirm. It deliberately
 *     offers **no** route into a session.
 *   - Follow-ups: WHO-5 aftercare loop, with the follow-up appointment list.
 *   - Scheduling: availability windows (list/calendar).
 *   - Surveys / Announcements: guidance content management.
 *   - Analytics / Services: reporting and the CMO catalogue.
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
 * **Sections are grouped** (2026-09-23, third revision) into Sessions &
 * Bookings, Communication, and Insights & Services — see the tab array below
 * for why that reordered Analytics.
 *
 * Every section carries a red notification dot when it has pending work — a
 * counted dot where a backlog can be tallied, a bare dot where the signal is
 * not a number. Sections with no pending-work concept (Analytics, and the
 * three content tabs) show none.
 *
 * Subcomponents, dialogs, and workspace live under `src/components/counselling/`.
 */
import {
  BarChart3,
  BellRing,
  CalendarCheck,
  CalendarDays,
  ClipboardList,
  HeartHandshake,
  ListOrdered,
  Megaphone,
} from 'lucide-react';
import { useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { NotificationDot } from '@/components/NotificationDot';
import { PageHeader } from '@/components/PageHeader';
import { TabSections, type TabSection } from '@/components/TabSections';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { TooltipProvider } from '@/components/ui/tooltip';
import {
  AnnouncementsTab,
  AppointmentsTab,
  FollowupsTab,
  GuidanceQueueTab,
  SchedulingTab,
  AnalyticsTab,
  ServicesTab,
  SurveysTab,
} from '@/components/counselling/tabs';
import { useGuidanceFollowups } from '@/hooks/useGuidanceFollowups';
import { useGuidanceQueueToday } from '@/hooks/useQueue';
import { useAppointments, useAvailability } from '@/hooks/useSchedule';
import { hasPermission, useAuthStore } from '@/store/auth';

/** Queue entry states that count as work still in front of the desk. */
const QUEUE_OPEN_STATUSES = ['waiting', 'called', 'in_session'];

/**
 * Nav group labels. Held as constants so the ordering contract and the
 * labels cannot drift apart across the tab array below.
 */
const GROUP_BOOKINGS = 'Sessions & Bookings';
const GROUP_COMMUNICATION = 'Communication';
const GROUP_INSIGHTS = 'Insights & Services';

export default function CounsellingPage() {
  const [params, setParams] = useSearchParams();
  const canReadQueue = useAuthStore((state) => hasPermission(state, 'counselling.queue.read'));
  const canReadSchedule = useAuthStore((state) => hasPermission(state, 'counselling.schedule.read'));
  const canMutateSchedule = useAuthStore(
    (state) =>
      hasPermission(state, 'counselling.schedule.manage') ||
      hasPermission(state, 'counselling.schedule.team_manage'),
  );
  const canManageAnnouncements = useAuthStore((state) => hasPermission(state, 'counselling.announcements.manage'));
  const canManageServices = useAuthStore((state) => hasPermission(state, 'counselling.services.manage'));
  const canManageSurveys = useAuthStore((state) => hasPermission(state, 'counselling.surveys.manage'));
  const canSeeFollowups = useAuthStore((state) => hasPermission(state, 'counselling.responses.read_any'));

  // Membership and order both follow the grouped nav above.
  const allowedTabs = [
    ...(canReadQueue ? ['queue'] : []),
    'appointments',
    ...(canSeeFollowups ? ['followups'] : []),
    'scheduling',
    ...(canManageSurveys ? ['surveys'] : []),
    ...(canManageAnnouncements ? ['announcements'] : []),
    'analytics',
    ...(canManageServices ? ['services'] : []),
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

  // Section nav — permission-filtered, grouped, order matches the sidebar.
  // Indicators mirror the sidebar counters so the module number is traceable
  // to the tab it belongs to; each is a red dot (see NotificationDot).
  //
  // **Three groups (2026-09-23).** Eight flat sections became three named
  // clusters, which *required a reorder*: grouping is by consecutive run, so
  // Analytics had to move down beside Services. The resulting order is the
  // one the grouping implies — the four booking surfaces, then the two
  // outward-facing content surfaces, then reporting and the catalogue.
  // `defaultTab` is unaffected: both candidate landings sit in group 1.
  const tabs: readonly TabSection[] = [
    ...(canReadQueue ? [{
      value: 'queue',
      label: 'Queue',
      icon: ListOrdered,
      group: GROUP_BOOKINGS,
      notify: <NotificationDot count={openQueue} label={`${openQueue} Guidance patients in play today`} />,
    }] : []),
    {
      value: 'appointments',
      label: 'Appointments',
      icon: CalendarCheck,
      group: GROUP_BOOKINGS,
      notify: (
        <NotificationDot count={todayCount} label={`${todayCount} Guidance appointments today`} />
      ),
    },
    ...(canSeeFollowups ? [{
      value: 'followups',
      label: 'Follow-ups',
      icon: BellRing,
      group: GROUP_BOOKINGS,
      notify: <NotificationDot count={openFollowups} label={`${openFollowups} open follow-ups`} />,
    }] : []),
    {
      value: 'scheduling',
      label: 'Scheduling',
      icon: CalendarDays,
      group: GROUP_BOOKINGS,
      ...(needsAvailability
        ? { notify: <NotificationDot label="No availability windows configured" /> }
        : {}),
    },
    ...(canManageSurveys ? [{
      value: 'surveys',
      label: 'Surveys',
      icon: ClipboardList,
      group: GROUP_COMMUNICATION,
    }] : []),
    ...(canManageAnnouncements ? [{
      value: 'announcements',
      label: 'Announcements',
      icon: Megaphone,
      group: GROUP_COMMUNICATION,
    }] : []),
    { value: 'analytics', label: 'Analytics', icon: BarChart3, group: GROUP_INSIGHTS },
    ...(canManageServices ? [{
      value: 'services',
      label: 'Services',
      icon: HeartHandshake,
      group: GROUP_INSIGHTS,
    }] : []),
  ];

  useEffect(() => {
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
  }, [params, defaultTab, rawSessionId, rawSubTab, requestedTab, selectedId, setParams, tab]);

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
