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
  Bell,
  CalendarDays,
  CheckCircle2,
  GraduationCap,
  HeartHandshake,
  History,
  IdCard,
  LayoutDashboard,
  Mail,
} from 'lucide-react';
import { QRCodeCanvas } from 'qrcode.react';
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
import { TabSections, type TabSection } from '@/components/TabSections';
import { YourQueueCard } from '@/components/YourQueueCard';
import { GuidancePortalTab } from '@/components/GuidancePortalTab';
import { useTabParam } from '@/hooks/useTabParam';
import { PortalAppointments } from '@/components/PortalAppointments';
import { useMe } from '@/hooks/useAuth';
import { useNotifications } from '@/hooks/useNotifications';
import { useMyStudentClinicVisits, useMyStudentProfile } from '@/hooks/useStudentPortal';
import { notificationDetail, notificationLabel } from '@/utils/notifications';
import { fmtUtcToApp } from '@/utils/date';
import { statusLabel } from '@/utils/status';

/** Portal sections — sidebar on wide screens, pills on mobile. */
const PORTAL_TABS: readonly TabSection[] = [
  { value: 'overview', label: 'Overview', icon: LayoutDashboard },
  { value: 'appointments', label: 'Appointments', icon: CalendarDays },
  { value: 'history', label: 'History', icon: History },
  { value: 'guidance', label: 'Guidance', icon: HeartHandshake },
  { value: 'notifications', label: 'Notifications', icon: Bell },
];

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
            <TabSections tabs={PORTAL_TABS} ariaLabel="Student portal sections">

            <TabsContent value="overview" className="space-y-6 pt-4">
              {/* Profile & Clinic Digital Pass */}
              <div className="grid max-w-96 gap-6 xl:max-w-none xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
                <PortalProfileCard
                  caption="Student Profile"
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

                <Card>
                  <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                      <IdCard className="size-4" aria-hidden /> Clinic Check-in Pass
                    </CardTitle>
                    <CardDescription>
                      Present this QR pass at the clinic kiosk scanner to self-admit without typing.
                    </CardDescription>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                      <div className="shrink-0 rounded-xl border bg-white p-3 shadow-sm">
                        <QRCodeCanvas value={profile.data.kiosk_identifier} size={136} includeMargin />
                      </div>
                      <div className="min-w-0 flex-1 space-y-2.5 text-center sm:text-left">
                        <p className="text-sm font-medium text-foreground">
                          Quick Admission QR
                        </p>
                        <p className="text-xs text-muted-foreground leading-relaxed">
                          Hold your screen in front of the kiosk scanner at the clinic reception to automatically queue or check in for your appointment.
                        </p>
                        {profile.data.has_qr ? (
                          <p className="flex items-center justify-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400 sm:justify-start">
                            <CheckCircle2 className="size-3.5 shrink-0" /> Verified clinic QR pass active
                          </p>
                        ) : (
                          <p className="text-xs text-muted-foreground">
                            Standard student pass linked to your account.
                          </p>
                        )}
                        <div className="pt-1">
                          {me.data?.has_local_password === true ? (
                            <Button asChild size="sm" variant="outline">
                              <Link to="/change-password">
                                Change password
                                <ArrowRight className="size-3.5" />
                              </Link>
                            </Button>
                          ) : (
                            <p className="text-xs text-muted-foreground leading-relaxed">
                              Your password is managed by the university — to reset it, email
                              helpdesk@foundationu.com.
                            </p>
                          )}
                        </div>
                      </div>
                    </div>
                  </CardContent>
                </Card>
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
          </TabSections>
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
