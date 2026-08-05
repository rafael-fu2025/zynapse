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
  BookOpen,
  CheckCircle2,
  Droplet,
  GraduationCap,
  Hash,
  IdCard,
  Mail,
  UserCircle2,
} from 'lucide-react';
import { QRCodeCanvas } from 'qrcode.react';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { CopyButton } from '@/components/CopyButton';
import { QueryErrorState } from '@/components/QueryErrorState';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { YourQueueCard } from '@/components/YourQueueCard';
import { StudentBookingSection } from '@/components/StudentBooking';
import { useMe } from '@/hooks/useAuth';
import { useNotifications } from '@/hooks/useNotifications';
import { useMyStudentClinicVisits, useMyStudentProfile } from '@/hooks/useStudentPortal';
import { notificationDetail, notificationLabel } from '@/utils/notifications';
import { fmtUtcToApp } from '@/utils/date';
import { statusLabel } from '@/utils/status';

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
  const notifications = useNotifications(5);
  const me = useMe();

  if (profile.error?.httpStatus === 404) {
    return <NotOnRegistry />;
  }

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <header>
        <h1 className="text-2xl font-semibold tracking-tight">My portal</h1>
        <p className="text-sm text-muted-foreground">
          Book clinic appointments, track your queue, and review your history.
        </p>
      </header>

      {/* Live queue status — stays above the tabs so an alert is never missed. */}
      <YourQueueCard kind="student" />

      {profile.isLoading && <ProfileSkeleton />}

      {profile.isError && profile.error?.httpStatus !== 404 && (
        <QueryErrorState message="Failed to load your profile." onRetry={() => void profile.refetch()} pending={profile.isFetching} />
      )}

      {profile.data !== undefined && (
        <>
          <Tabs defaultValue="overview">
            <TabsList>
              <TabsTrigger value="overview">Overview</TabsTrigger>
              <TabsTrigger value="appointments">Appointments</TabsTrigger>
              <TabsTrigger value="history">History</TabsTrigger>
              <TabsTrigger value="notifications">Notifications</TabsTrigger>
            </TabsList>

            <TabsContent value="overview" className="space-y-6 pt-4">
          {/* Phase 3.4: Unified identity card.
              Surfaces the cross-cutting person row (kind, persons_id,
              patient_identifier_id) so the user can verify the unified
              model is wiring their user <-> patient link correctly. */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <UserCircle2 className="size-4" aria-hidden /> Identity
              </CardTitle>
              <CardDescription>Your SYNAPSE identity across the user and patient registries.</CardDescription>
            </CardHeader>
            <CardContent>
              <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1.5 text-xs">
                <dt className="text-muted-foreground">Full name</dt>
                <dd className="font-medium">
                  {profile.data.first_name} {profile.data.middle_name !== null ? `${profile.data.middle_name} ` : ''}
                  {profile.data.last_name}
                </dd>
                <dt className="text-muted-foreground">Kind</dt>
                <dd>
                  <Badge variant="secondary">{(profile.data.kind ?? 'student')}</Badge>
                </dd>
                <dt className="text-muted-foreground">Student No.</dt>
                <dd className="flex items-center gap-1.5 font-mono">
                  <Hash className="size-3" aria-hidden /> {profile.data.student_number}
                </dd>
                <dt className="text-muted-foreground">Email</dt>
                <dd className="text-foreground">
                  {me.data?.email ?? <span className="text-muted-foreground">—</span>}
                </dd>
              </dl>
            </CardContent>
          </Card>

          {/* Profile + Kiosk identifier */}
          <div className="grid gap-4 lg:grid-cols-3">
            <Card className="lg:col-span-1">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <IdCard className="size-4" aria-hidden /> Profile
                </CardTitle>
                <CardDescription>Linked to your SYNAPSE account.</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3 text-sm">
                <p className="text-base font-semibold leading-tight">
                  {profile.data.first_name} {profile.data.middle_name !== null ? `${profile.data.middle_name} ` : ''}
                  {profile.data.last_name}
                </p>
                <dl className="grid grid-cols-[max-content_1fr] gap-x-3 gap-y-1.5 text-xs">
                  <dt className="text-muted-foreground">Student No.</dt>
                  <dd className="font-mono">{profile.data.student_number}</dd>
                  <dt className="text-muted-foreground">Course</dt>
                  <dd className="flex items-center gap-1.5">
                    <BookOpen className="size-3" aria-hidden /> {profile.data.course ?? '—'}
                  </dd>
                  <dt className="text-muted-foreground">Year &amp; Section</dt>
                  <dd>
                    {profile.data.year_level !== null
                      ? `${profile.data.year_level}-${profile.data.section ?? '—'}`
                      : '—'}
                  </dd>
                  <dt className="text-muted-foreground">Blood type</dt>
                  <dd className="flex items-center gap-1.5">
                    <Droplet className="size-3" aria-hidden /> {profile.data.blood_type ?? '—'}
                  </dd>
                  <dt className="text-muted-foreground">No-shows</dt>
                  <dd>
                    {profile.data.consecutive_no_shows === 0 ? (
                      <Badge variant="secondary">clean</Badge>
                    ) : (
                      <Badge variant="destructive">{profile.data.consecutive_no_shows}</Badge>
                    )}
                  </dd>
                </dl>
              </CardContent>
            </Card>

            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <IdCard className="size-4" aria-hidden /> Kiosk identifier
                </CardTitle>
                <CardDescription>
                  Show this at the clinic kiosk to self-admit without typing your student number.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex flex-col items-center gap-3 sm:flex-row">
                  <div className="shrink-0 rounded-xl border bg-white p-2">
                    <QRCodeCanvas value={profile.data.kiosk_identifier} size={128} includeMargin />
                  </div>
                  <div className="min-w-0 flex-1 space-y-2">
                    <div className="flex items-center gap-2">
                      <p className="min-w-0 flex-1 truncate rounded-md border bg-muted/50 px-3 py-2 font-mono text-sm">
                        {profile.data.kiosk_identifier}
                      </p>
                      <CopyButton value={profile.data.kiosk_identifier} label="Copy kiosk identifier" successMessage="Kiosk identifier copied." />
                    </div>
                    <p className="text-xs text-muted-foreground">
                      The kiosk accepts {profile.data.has_qr ? 'QR' : profile.data.has_rfid ? 'RFID' : 'manual entry'}; we send the{' '}
                      <span className="font-mono">{profile.data.kiosk_identifier.split(':')[0]}</span> variant first.
                    </p>
                    {profile.data.has_qr ? (
                      <p className="flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400">
                        <CheckCircle2 className="size-3.5 shrink-0" /> Staff-issued QR on file — scan this at the kiosk.
                      </p>
                    ) : (
                      <p className="text-xs text-muted-foreground">
                        No staff-issued QR on your account yet — this QR uses your <strong>student number</strong> and still
                        works at the kiosk. Ask clinic staff to assign a QR in <strong>Patients</strong> for a permanent card.
                      </p>
                    )}
                  </div>
                </div>
                <div className="flex flex-wrap gap-2 pt-2">
                  <Button asChild size="sm" variant="outline">
                    <Link to="/change-password">
                      Change password
                      <ArrowRight />
                    </Link>
                  </Button>
                </div>
              </CardContent>
            </Card>
          </div>
          </TabsContent>

          <TabsContent value="appointments" className="space-y-6 pt-4">
            <StudentBookingSection />
          </TabsContent>

          <TabsContent value="history" className="space-y-6 pt-4">
          {/* Clinic visits */}
          <Card>
            <CardHeader>
              <CardTitle>My clinic visits</CardTitle>
              <CardDescription>Your most recent encounters, newest first.</CardDescription>
            </CardHeader>
            <CardContent>
              {visits.isLoading && <Skeleton className="h-32" />}
              {visits.data !== undefined && visits.data.length === 0 && (
                <p className="py-6 text-center text-sm text-muted-foreground">
                  You have no clinic visits on record.
                </p>
              )}
              {visits.data !== undefined && visits.data.length > 0 && (
                <Table>
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
                    {visits.data.map((v) => (
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
                            <p className="font-mono text-[10px] text-muted-foreground">
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
            <Button asChild size="sm" variant="ghost">
              <Link to="/change-password">Change password</Link>
            </Button>
          </footer>
        </>
      )}
    </div>
  );
}
