/**
 * EmployeePortalPage — Phase 11.
 *
 * Self-scope surface for any authenticated employee on the patient
 * registry. Pulls the caller's own employee row + clinic-visit
 * history + recent notifications into a single dashboard.
 *
 * Strictly READ-ONLY here. Mutations for the cross-module
 * surfaces (referrals, password reset) are launched
 * through their own existing pages; this page is the dashboard.
 */
import {
  ArrowRight,
  CheckCircle2,
  History,
  IdCard,
  Mail,
  Phone,
  Stethoscope,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/PageHeader';
import { PortalProfileCard } from '@/components/PortalProfileCard';
import { TableStateBlock } from '@/components/TableStates';
import { QueryErrorState } from '@/components/QueryErrorState';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { YourQueueCard } from '@/components/YourQueueCard';
import { PortalAppointments } from '@/components/PortalAppointments';
import { useMe } from '@/hooks/useAuth';
import { useMyClinicVisits, useMyEmployeeProfile } from '@/hooks/useEmployeePortal';
import { useNotifications } from '@/hooks/useNotifications';
import { primaryRecordOf } from '@/utils/employeeRecords';
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
      <IdCard className="mx-auto mb-4 size-12 text-muted-foreground" aria-hidden />
      <h2 className="text-lg font-semibold">No employee record on file</h2>
      <p className="mt-2 text-sm text-muted-foreground">
        Your account is signed in, but we could not find a matching employee row in the patient
        registry. Reach out to HR so they can link your account.
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

export default function EmployeePortalPage() {
  const profile = useMyEmployeeProfile();
  // MIS issues one record per appointment, so the caller may hold several
  // records. The card + visit history show the primary (newest) record's
  // position; the history selector picks among the person's records.
  const records = profile.data?.records ?? [];
  const primary = primaryRecordOf(records);
  const hasGroup = records.length > 1;
  const [selectedRecordId, setSelectedRecordId] = useState<number | null>(null);
  const activeRecordId = selectedRecordId ?? (hasGroup ? primary?.id ?? null : null);
  const visits = useMyClinicVisits(50, activeRecordId);
  const visitRows = visits.data ?? [];
  const notifications = useNotifications(5);
  const me = useMe();

  if (profile.error?.httpStatus === 404) {
    return <NotOnRegistry />;
  }

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <PageHeader
        title="My portal"
        description="Your own clinic history, referral tools, and recent notifications. All read-only."
      />

      {/* Live queue status — only appears while you're actually queued. */}
      <YourQueueCard kind="employee" />
      <PortalAppointments />

      {profile.isLoading && <ProfileSkeleton />}

      {profile.isError && profile.error?.httpStatus !== 404 && (
        <QueryErrorState message="Failed to load your profile." onRetry={() => void profile.refetch()} pending={profile.isFetching} />
      )}

      {profile.data !== undefined && (
        <>
          {/* Profile & Clinic Digital Pass */}
          <div className="grid max-w-96 gap-6 xl:max-w-none xl:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
            <PortalProfileCard
              caption="Employee Profile"
              name={`${profile.data.first_name} ${profile.data.middle_name !== null ? `${profile.data.middle_name} ` : ''}${profile.data.last_name}`}
              idValue={profile.data.employee_number}
            >
              <dl className="mt-auto grid grid-cols-[max-content_1fr] gap-x-3 gap-y-1.5 text-xs">
                <dt className="text-white/60 dark:text-muted-foreground">Email</dt>
                <dd className="truncate text-white dark:text-foreground">
                  {me.data?.email ?? <span className="text-white/60 dark:text-muted-foreground">N/A</span>}
                </dd>
                <dt className="text-white/60 dark:text-muted-foreground">Department</dt>
                <dd className="truncate font-medium">{(primary?.department ?? profile.data.department) ?? <span className="text-white/60 dark:text-muted-foreground">N/A</span>}</dd>
                <dt className="text-white/60 dark:text-muted-foreground">Position</dt>
                <dd className="truncate">{(primary?.position ?? profile.data.position) ?? <span className="text-white/60 dark:text-muted-foreground">N/A</span>}</dd>
                <dt className="text-white/60 dark:text-muted-foreground">Status</dt>
                <dd className="capitalize">{(profile.data.employment_status ?? 'active').replace('_', ' ')}</dd>
                <dt className="text-white/60 dark:text-muted-foreground">Type</dt>
                <dd>
                  {profile.data.is_teaching ? (
                    <Badge variant="outline">Teaching</Badge>
                  ) : (
                    <Badge variant="secondary">Non-teaching</Badge>
                  )}
                </dd>
              </dl>
            </PortalProfileCard>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Stethoscope className="size-4" aria-hidden /> Clinic access
                </CardTitle>
                <CardDescription>
                  Book clinic appointments online and check in at the desk — no pass or scanner needed.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
                  <div className="min-w-0 flex-1 space-y-2.5 text-center sm:text-left">
                    <p className="text-sm font-medium text-foreground">
                      Book, then check in at the desk
                    </p>
                    <p className="text-xs text-muted-foreground leading-relaxed">
                      Your appointment check-in is handled by clinic staff at the reception desk.
                    </p>
                    {profile.data.has_qr && (
                      <p className="flex items-center justify-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400 sm:justify-start">
                        <CheckCircle2 className="size-3.5 shrink-0" /> Verified clinic QR pass active
                      </p>
                    )}
                    <div className="flex flex-wrap items-center justify-center gap-2 pt-1 sm:justify-start">
                      {me.data?.has_local_password === true && (
                        <Button asChild size="sm" variant="outline">
                          <Link to="/change-password">
                            Change password
                            <ArrowRight className="size-3.5" />
                          </Link>
                        </Button>
                      )}
                      <Button asChild size="sm" variant="outline">
                        <Link to="/referrals">Refer a student to counselling</Link>
                      </Button>
                    </div>
                    {me.data?.has_local_password !== true && (
                      <p className="text-xs text-muted-foreground leading-relaxed">
                        Your password is managed by the university — to reset it, email
                        helpdesk@foundationu.com.
                      </p>
                    )}
                  </div>
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Clinic visits */}
          <Card>
            <CardHeader>
              <CardTitle>My clinic visits</CardTitle>
              <CardDescription>
                {hasGroup
                  ? 'Your most recent encounters, newest first — pick a record to see its history.'
                  : 'Your most recent encounters, newest first.'}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              {hasGroup && (
                <div
                  role="group"
                  aria-label="Position history — MIS records for this person"
                  className="flex flex-wrap gap-2"
                >
                  {records.map((r) => {
                    const active = r.id === activeRecordId;
                    return (
                      <button
                        key={r.id}
                        type="button"
                        aria-pressed={active}
                        onClick={() => setSelectedRecordId(r.id)}
                        className={`flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs transition-colors ${
                          active
                            ? 'border-primary bg-primary/10 text-foreground'
                            : 'border-border text-muted-foreground hover:bg-muted/50'
                        }`}
                      >
                        <History className="size-3.5 shrink-0" aria-hidden />
                        <span className="tabular-nums font-medium">{r.position_year ?? r.employee_number}</span>
                        <span className="max-w-48 truncate">{r.position ?? 'No position'}</span>
                        {r.is_primary && <Badge variant="outline">current</Badge>}
                        <span className="tabular-nums text-[0.625rem]">
                          {r.visit_count} visit{r.visit_count === 1 ? '' : 's'}
                        </span>
                      </button>
                    );
                  })}
                </div>
              )}
              {hasGroup && activeRecordId !== null && activeRecordId !== profile.data.id && (
                <p className="text-xs text-muted-foreground">
                  Showing visits recorded under MIS record{' '}
                  <span className="tabular-nums">
                    {records.find((r) => r.id === activeRecordId)?.employee_number}
                  </span>
                  .
                </p>
              )}
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

          {/* Emergency contacts + Notifications */}
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>Emergency contact</CardTitle>
                <CardDescription>From the patient registry.</CardDescription>
              </CardHeader>
              <CardContent className="space-y-1.5 text-sm">
                {profile.data.emergency_contact_name !== null ? (
                  <p className="font-medium">{profile.data.emergency_contact_name}</p>
                ) : (
                  <p className="text-muted-foreground">—</p>
                )}
                {profile.data.emergency_contact_phone !== null && (
                  <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Phone className="size-3" aria-hidden />
                    <span className="tabular-nums">{profile.data.emergency_contact_phone}</span>
                  </p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Recent notifications</CardTitle>
                <CardDescription>5 most recent — see the bell for the full list.</CardDescription>
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
          </div>

          {/* Contact + account footer */}
          <footer className="flex flex-wrap items-center justify-between gap-3 border-t pt-4 text-xs text-muted-foreground">
            <p className="flex items-center gap-1.5">
              <Mail className="size-3" aria-hidden />
              Need to update your contact details? See the HR team — the portal is read-only by design.
            </p>
            {me.data?.has_local_password === true ? (
              <Button asChild size="sm" variant="ghost">
                <Link to="/change-password">Change password</Link>
              </Button>
            ) : (
              <p className="flex items-center gap-1.5">
                <Mail className="size-3" aria-hidden />
                Password resets: helpdesk@foundationu.com
              </p>
            )}
          </footer>
        </>
      )}
    </div>
  );
}
