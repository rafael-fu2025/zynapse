/**
 * EmployeePortalPage — Phase 11.
 *
 * Self-scope surface for any authenticated employee on the patient
 * registry. Pulls the caller's own employee row + clinic-visit
 * history + recent notifications into a single dashboard.
 *
 * Strictly READ-ONLY here. Mutations for the cross-module
 * surfaces (referrals, kiosk scan, password reset) are launched
 * through their own existing pages; this page is the dashboard.
 */
import {
  ArrowRight,
  Building2,
  CheckCircle2,
  IdCard,
  Mail,
  Phone,
  Stethoscope,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { CopyButton } from '@/components/CopyButton';
import { QueryErrorState } from '@/components/QueryErrorState';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useMyClinicVisits, useMyEmployeeProfile } from '@/hooks/useEmployeePortal';
import { useNotifications } from '@/hooks/useNotifications';
import { fmtUtcToApp } from '@/utils/date';

const STATUS_VARIANT = {
  Open: 'default',
  Closed: 'secondary',
  Referred: 'outline',
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
  const visits = useMyClinicVisits();
  const notifications = useNotifications(5);

  if (profile.error?.httpStatus === 404) {
    return <NotOnRegistry />;
  }

  return (
    <div className="space-y-6 p-4 sm:p-6">
      <header>
        <h1 className="text-2xl font-semibold tracking-tight">My portal</h1>
        <p className="text-sm text-muted-foreground">
          Your own clinic history, kiosk identifier, and recent notifications. All read-only.
        </p>
      </header>

      {profile.isLoading && <ProfileSkeleton />}

      {profile.isError && profile.error?.httpStatus !== 404 && (
        <QueryErrorState message="Failed to load your profile." onRetry={() => void profile.refetch()} pending={profile.isFetching} />
      )}

      {profile.data !== undefined && (
        <>
          {/* Profile + Kiosk QR */}
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
                  <dt className="text-muted-foreground">Employee No.</dt>
                  <dd className="font-mono">{profile.data.employee_number}</dd>
                  <dt className="text-muted-foreground">Department</dt>
                  <dd className="flex items-center gap-1.5">
                    <Building2 className="size-3" aria-hidden /> {profile.data.department ?? '—'}
                  </dd>
                  <dt className="text-muted-foreground">Position</dt>
                  <dd>{profile.data.position ?? '—'}</dd>
                  <dt className="text-muted-foreground">Status</dt>
                  <dd className="capitalize">{profile.data.employment_status.replace('_', ' ')}</dd>
                  <dt className="text-muted-foreground">Type</dt>
                  <dd>
                    {profile.data.is_teaching ? (
                      <Badge variant="default">teaching</Badge>
                    ) : (
                      <Badge variant="secondary">non-teaching</Badge>
                    )}
                  </dd>
                </dl>
              </CardContent>
            </Card>

            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Stethoscope className="size-4" aria-hidden /> Kiosk identifier
                </CardTitle>
                <CardDescription>
                  Show this at the clinic kiosk to self-admit without typing your employee number.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex items-center gap-2">
                  <p className="flex-1 rounded-md border bg-muted/50 px-3 py-2 font-mono text-sm">
                    {profile.data.kiosk_identifier}
                  </p>
                  <CopyButton value={profile.data.kiosk_identifier} label="Copy kiosk identifier" successMessage="Kiosk identifier copied." />
                </div>
                <p className="text-xs text-muted-foreground">
                  The kiosk accepts {profile.data.has_qr ? 'QR' : profile.data.has_rfid ? 'RFID' : 'manual entry'}; we send the{' '}
                  <span className="font-mono">{profile.data.kiosk_identifier.split(':')[0]}</span> variant first.
                </p>
                <div className="flex flex-wrap gap-2 pt-2">
                  <Button asChild size="sm" variant="outline">
                    <Link to="/change-password">
                      Change password
                      <ArrowRight />
                    </Link>
                  </Button>
                  {/* Teaching-only action: the backend enforces this
                      with a 403 (code: referral.teaching_required)
                      when source_module=clinic and the issuer's
                      employee row has is_teaching=0. We hide the
                      button preemptively so non-teaching staff never
                      see an action that will fail. */}
                  {profile.data.is_teaching ? (
                    <Button asChild size="sm" variant="outline">
                      <Link to="/referrals">Refer a student to counselling</Link>
                    </Button>
                  ) : (
                    <Button
                      size="sm"
                      variant="outline"
                      disabled
                      title="Only teaching employees (faculty) can refer students to counselling."
                      aria-label="Refer a student to counselling — disabled, teaching-only"
                    >
                      Refer a student to counselling
                    </Button>
                  )}
                </div>
                {!profile.data.is_teaching && (
                  <p className="pt-1 text-[11px] text-muted-foreground">
                    The “refer a student” action is only available to <strong>teaching</strong> employees
                    (faculty). Talk to your supervisor if you believe this is in error.
                  </p>
                )}
              </CardContent>
            </Card>
          </div>

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
                        <TableCell className="px-3 font-mono text-xs text-muted-foreground">
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
                          <Badge variant={STATUS_VARIANT[v.status]}>{v.status}</Badge>
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
                    <span className="font-mono">{profile.data.emergency_contact_phone}</span>
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
                          <p className="font-medium">{n.template_code}</p>
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
            <Button asChild size="sm" variant="ghost">
              <Link to="/change-password">Change password</Link>
            </Button>
          </footer>
        </>
      )}
    </div>
  );
}
