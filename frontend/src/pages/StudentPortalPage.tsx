/**
 * StudentPortalPage — Phase 13.
 *
 * Self-scope surface for any authenticated student on the patient
 * registry. Mirror of `EmployeePortalPage` but for the student
 * side. The page is read-only: full student self-service
 * (booking, QR check-in) is still deferred.
 */
import {
  ArrowRight,
  CalendarDays,
  CheckCircle2,
  ExternalLink,
  GraduationCap,
  KeyRound,
  Mail,
  Megaphone,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/PageHeader';
import { PortalProfileCard } from '@/components/PortalProfileCard';
import { TableStateBlock } from '@/components/TableStates';
import { QueryErrorState } from '@/components/QueryErrorState';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { YourQueueCard } from '@/components/YourQueueCard';
import { GuidancePortalTab } from '@/components/GuidancePortalTab';
import { useTabParam } from '@/hooks/useTabParam';
import { PortalAppointments } from '@/components/PortalAppointments';
import { useMe } from '@/hooks/useAuth';
import { useMyGuidanceAnnouncements } from '@/hooks/useGuidanceContent';
import { useNotifications } from '@/hooks/useNotifications';
import {
  useMyStudentAppointments,
  useMyStudentClinicVisits,
  useMyStudentProfile,
} from '@/hooks/useStudentPortal';
import { notificationDetail, notificationLabel } from '@/utils/notifications';
import { fmtUtcToApp, parseUtc } from '@/utils/date';
import { statusLabel } from '@/utils/status';
import type { StudentAppointment } from '@/schemas/studentPortal';

/** Portal sections — sidebar on wide screens, pills on mobile. */

const STATUS_VARIANT = {
  open: 'default',
  closed: 'secondary',
  referred: 'outline',
} as const;

function NotOnRegistry() {
  return (
    <div className="mx-auto max-w-xl py-12 text-center" role="status">
      <GraduationCap className="mx-auto mb-4 size-12 text-muted-foreground" aria-hidden />
      <h2 className="text-lg font-semibold">No student record on file</h2>
      <p className="mt-2 text-sm text-muted-foreground">
        Your account is signed in, but we could not find a matching student row in the patient
        registry. Reach out to the registrar so they can link your account.
      </p>
    </div>
  );
}

function ProfileSkeleton() {
  return (
    <div className="grid gap-4 lg:grid-cols-3">
      <Skeleton className="h-48 lg:col-span-1" />
      <Skeleton className="h-48 lg:col-span-2" />
    </div>
  );
}

/**
 * Announcements — the student's targeted Guidance announcements. The
 * portal overview is the only surface for these (the Guidance tab no
 * longer repeats the feed), so it renders every post in full, newest
 * first as the backend orders it.
 */
function GuidanceAnnouncementsCard() {
  const announcements = useMyGuidanceAnnouncements();

  return (
    <Card className="xl:col-span-2">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Megaphone className="size-4" aria-hidden /> Announcements
        </CardTitle>
        <CardDescription>Guidance office posts for you.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {announcements.isLoading && <Skeleton className="h-20" />}
        {announcements.isError && (
          <QueryErrorState
            message="Failed to load announcements."
            onRetry={() => void announcements.refetch()}
            pending={announcements.isFetching}
          />
        )}
        {announcements.data !== undefined && announcements.data.length === 0 && (
          <p className="py-3 text-sm text-muted-foreground">No announcements right now.</p>
        )}
        {announcements.data?.map((a) => (
          <article
            key={a.id}
            className={
              a.severity === 'urgent'
                ? 'rounded-lg border border-destructive/60 bg-destructive/5 p-3.5'
                : 'rounded-lg border bg-background p-3.5'
            }
          >
            <div className="flex flex-wrap items-center gap-2">
              {/* Red = urgent (2026-09-25 meeting); amber stays reserved
                  for the clearance "Required" flag. */}
              {a.severity === 'urgent' && <Badge variant="destructive">Urgent</Badge>}
              {a.is_required && <Badge variant="warning">Required</Badge>}
              <h3 className="text-sm font-medium text-foreground">{a.title}</h3>
            </div>
            <p className="mt-1.5 text-sm text-muted-foreground">{a.body}</p>
            <div className="mt-2.5 flex flex-wrap items-center gap-3">
              {a.publish_at !== null && (
                <p className="text-xs text-muted-foreground">
                  {fmtUtcToApp(a.publish_at, 'MMM d, yyyy')}
                </p>
              )}
              {a.action_url !== null && (
                <a
                  href={a.action_url}
                  target="_blank"
                  rel="noreferrer noopener"
                  className="inline-flex items-center gap-1 text-xs font-medium text-primary underline underline-offset-2"
                >
                  {a.action_label ?? 'Open link'} <ExternalLink className="size-3" aria-hidden />
                </a>
              )}
            </div>
          </article>
        ))}
      </CardContent>
    </Card>
  );
}

/**
 * Live (not yet resolved) appointment statuses — mirrors
 * LIVE_STATUSES in appointmentLiveState. The clinic domain has no
 * "pending": a portal booking starts at `scheduled` and becomes
 * `confirmed` when staff approve it.
 */
const UPCOMING_APPOINTMENT_STATUSES: ReadonlySet<string> = new Set(['scheduled', 'confirmed']);

/** Nearest future appointment that staff have not resolved yet. */
function nextUpcomingAppointment(rows: StudentAppointment[]): StudentAppointment | null {
  const now = Date.now();
  const upcoming = rows
    .filter(
      (a) =>
        UPCOMING_APPOINTMENT_STATUSES.has(a.status) && parseUtc(a.scheduled_at).getTime() >= now,
    )
    .sort((a, b) => parseUtc(a.scheduled_at).getTime() - parseUtc(b.scheduled_at).getTime());
  return upcoming[0] ?? null;
}

/**
 * UpcomingAppointmentCard — the student's next scheduled/confirmed
 * clinic appointment, one click from the appointments tab.
 */
function UpcomingAppointmentCard() {
  const appointments = useMyStudentAppointments();
  const next = appointments.data !== undefined ? nextUpcomingAppointment(appointments.data) : null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <CalendarDays className="size-4" aria-hidden /> Upcoming appointment
        </CardTitle>
        <CardDescription>Your next clinic slot.</CardDescription>
      </CardHeader>
      <CardContent>
        {appointments.isLoading && <Skeleton className="h-16" />}
        {appointments.isError && (
          <QueryErrorState
            message="Failed to load your appointments."
            onRetry={() => void appointments.refetch()}
            pending={appointments.isFetching}
          />
        )}
        {appointments.data !== undefined && next !== null && (
          <Link
            to="?tab=appointments"
            className="block rounded-lg border bg-background p-3.5 transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
          >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="text-sm font-semibold text-foreground">{fmtUtcToApp(next.scheduled_at)}</p>
              <Badge variant="secondary">{statusLabel(next.status)}</Badge>
            </div>
            {next.provider_name !== null && (
              <p className="mt-1 text-xs text-muted-foreground">With {next.provider_name}</p>
            )}
            <p className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary">
              View details <ArrowRight className="size-3" aria-hidden />
            </p>
          </Link>
        )}
        {appointments.data !== undefined && next === null && (
          <div className="space-y-3">
            <p className="text-sm text-muted-foreground">No upcoming appointment.</p>
            <Button asChild size="sm" variant="outline">
              <Link to="?tab=appointments">
                Book one <ArrowRight className="size-3.5" />
              </Link>
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

export default function StudentPortalPage() {
  const profile = useMyStudentProfile();
  const visits = useMyStudentClinicVisits();
  const visitRows = visits.data ?? [];
  const notifications = useNotifications(5);
  const me = useMe();
  // ?tab= so a booked-appointment or history view survives a refresh
  // and can be linked (matches the employee portal and other pages).
  const [tab, setTab] = useTabParam('overview');

  if (profile.error?.httpStatus === 404) {
    return <NotOnRegistry />;
  }

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <PageHeader
        title="My portal"
        description="Book clinic appointments, track your queue, and review your history."
      />

      {/* Live queue status — stays above the tabs so an alert is never missed. */}
      <YourQueueCard kind="student" />

      {profile.isLoading && <ProfileSkeleton />}

      {profile.isError && profile.error?.httpStatus !== 404 && (
        <QueryErrorState message="Failed to load your profile." onRetry={() => void profile.refetch()} pending={profile.isFetching} />
      )}

      {profile.data !== undefined && (
        <>
          <Tabs value={tab} onValueChange={setTab}>

            <TabsContent value="overview" className="space-y-6 pt-4">
              {/* Student profile fixed at its original 24rem (centered,
                  never stretched, while stacked below `xl`), upcoming
                  appointment beside it from `xl` up, announcements
                  running underneath both. */}
              <div className="grid gap-6 xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
                <PortalProfileCard
                  caption="Student Profile"
                  className="w-full max-w-96 justify-self-center"
                  name={`${profile.data.first_name} ${profile.data.middle_name !== null ? `${profile.data.middle_name} ` : ''}${profile.data.last_name}`}
                  idValue={profile.data.student_number}
                >
                  <dl className="mt-auto grid grid-cols-[max-content_1fr] gap-x-3 gap-y-1.5 text-xs">
                    <dt className="text-white/60 dark:text-muted-foreground">Email</dt>
                    <dd className="truncate text-white dark:text-foreground">
                      {me.data?.email ?? <span className="text-white/60 dark:text-muted-foreground">N/A</span>}
                    </dd>
                    <dt className="text-white/60 dark:text-muted-foreground">Course</dt>
                    <dd className="font-medium">{profile.data.course ?? <span className="text-white/60 dark:text-muted-foreground">N/A</span>}</dd>
                    <dt className="text-white/60 dark:text-muted-foreground">Year level</dt>
                    <dd>
                      {profile.data.year_level !== null ? (
                        profile.data.year_level
                      ) : (
                        <span className="text-white/60 dark:text-muted-foreground">N/A</span>
                      )}
                    </dd>
                    <dt className="text-white/60 dark:text-muted-foreground">Blood type</dt>
                    <dd>{profile.data.blood_type ?? <span className="text-white/60 dark:text-muted-foreground">N/A</span>}</dd>
                    <dt className="text-white/60 dark:text-muted-foreground">No-shows</dt>
                    <dd>
                      {profile.data.consecutive_no_shows === 0 ? (
                        <Badge variant="secondary">Clean</Badge>
                      ) : (
                        <Badge variant="destructive">{profile.data.consecutive_no_shows}</Badge>
                      )}
                    </dd>
                  </dl>
                </PortalProfileCard>

                {/* Upcoming-appointment shortcut beside the profile;
                    the announcements preview runs underneath both. */}
                <UpcomingAppointmentCard />

                <GuidanceAnnouncementsCard />

                {/* Password entry point — kept from the old Account access card. */}
                <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground xl:col-span-2">
                  <KeyRound className="size-3" aria-hidden />
                  {me.data?.has_local_password === true ? (
                    <>
                      Account access —{' '}
                      <Link className="underline underline-offset-2" to="/change-password">
                        Change password
                      </Link>
                    </>
                  ) : (
                    <span>
                      Your password is managed by the university — to reset it, email
                      helpdesk@foundationu.com.
                    </span>
                  )}
                </p>
              </div>
            </TabsContent>

          <TabsContent value="appointments" className="space-y-6 pt-4">
            <PortalAppointments />
          </TabsContent>

          <TabsContent value="guidance" className="space-y-6 pt-4">
            <GuidancePortalTab />
          </TabsContent>

          <TabsContent value="history" className="space-y-6 pt-4">
          {/* Clinic visits */}
          <Card>
            <CardHeader>
              <CardTitle>My clinic visits</CardTitle>
              <CardDescription>Your most recent encounters, newest first.</CardDescription>
            </CardHeader>
            <CardContent>
              <TableStateBlock
                isLoading={visits.isLoading}
                isError={visits.isError}
                isEmpty={visitRows.length === 0}
                onRetry={() => void visits.refetch()}
                pending={visits.isFetching}
                errorMessage="Failed to load your clinic visits."
                loadingLabel="Loading your clinic visits"
                skeletonRows={1}
                empty={{
                  title: 'You have no clinic visits on record.',
                  description: 'Visits appear here after you are seen at the clinic.',
                }}
              />
              {!visits.isLoading && !visits.isError && visitRows.length > 0 && (
                <Table ariaLabel="My clinic visits">
                  <TableHeader>
                    <TableRow>
                      <TableHead className="px-3">Date</TableHead>
                      <TableHead className="px-3">Chief complaint</TableHead>
                      <TableHead className="px-3">Triage</TableHead>
                      <TableHead className="px-3">Status</TableHead>
                      <TableHead className="px-3">Attending</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {visitRows.map((v) => (
                      <TableRow key={v.id}>
                        <TableCell className="px-3 text-xs text-muted-foreground">
                          {fmtUtcToApp(v.started_at)}
                        </TableCell>
                        <TableCell className="px-3 text-sm">{v.chief_complaint}</TableCell>
                        <TableCell className="px-3 text-xs">
                          {v.triage_priority !== null ? (
                            <span className="capitalize">{v.triage_priority}</span>
                          ) : (
                            <span className="text-muted-foreground">—</span>
                          )}
                        </TableCell>
                        <TableCell className="px-3">
                          <Badge variant={STATUS_VARIANT[v.status]}>{statusLabel(v.status)}</Badge>
                        </TableCell>
                        <TableCell className="px-3 text-xs">
                          {v.attending_username ?? <span className="text-muted-foreground">—</span>}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
          </TabsContent>

          <TabsContent value="notifications" className="space-y-6 pt-4">
          {/* Recent notifications */}
          <Card>
            <CardHeader>
              <CardTitle>Recent notifications</CardTitle>
              <CardDescription>
                5 most recent — see the bell or <Link className="underline underline-offset-2" to="/notifications">view all</Link>.
              </CardDescription>
            </CardHeader>
            <CardContent>
              {notifications.isLoading && <Skeleton className="h-24" />}
              {notifications.data !== undefined && notifications.data.length === 0 && (
                <p className="py-3 text-sm text-muted-foreground">No notifications yet.</p>
              )}
              {notifications.data !== undefined && notifications.data.length > 0 && (
                <ul className="space-y-2 text-xs">
                  {notifications.data.map((n) => (
                    <li
                      key={n.id}
                      className="flex items-start gap-2 rounded border bg-muted/30 px-3 py-2"
                    >
                      <CheckCircle2
                        className={
                          n.read_at !== null
                            ? 'mt-0.5 size-3.5 text-muted-foreground'
                            : 'mt-0.5 size-3.5 text-primary'
                        }
                        aria-hidden
                      />
                      <div className="flex-1">
                          <p className="font-medium">{notificationLabel(n.template_code, n.context)}</p>
                          {notificationDetail(n.template_code, n.context) !== null && (
                            <p className="tabular-nums text-[0.625rem] text-muted-foreground">
                              {notificationDetail(n.template_code, n.context)}
                            </p>
                          )}
                        <p className="text-muted-foreground">{fmtUtcToApp(n.created_at)}</p>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
          </TabsContent>
        </Tabs>

          <footer className="flex flex-wrap items-center justify-between gap-3 border-t pt-4 text-xs text-muted-foreground">
            <p className="flex items-center gap-1.5">
              <Mail className="size-3" aria-hidden />
              Need to update your contact details? See the registrar — the portal is read-only by design.
            </p>
          </footer>
        </>
      )}
    </div>
  );
}
