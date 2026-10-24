/**
 * AccountSecurityPage — self-service security surface (2026-10 audit
 * G-1/G-5): TOTP two-step sign-in lifecycle and the signed-in-sessions
 * inventory with selective sign-out.
 *
 * Everything here is self-scoped server-side; this page only renders
 * state. MIS-delegated accounts (no local password) see the helpdesk
 * pointer for MFA, mirroring ChangePasswordPage.
 */
import { Copy, Loader2, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useMe } from '@/hooks/useAuth';
import {
  useMfaConfirm,
  useMfaDisable,
  useMfaSetup,
  useMfaStatus,
  useRevokeSession,
  useSessions,
} from '@/hooks/useAccountSecurity';

export default function AccountSecurityPage() {
  const me = useMe();
  const mfaStatus = useMfaStatus();
  const sessions = useSessions();

  return (
    <main className="mx-auto max-w-3xl space-y-6 p-6">
      <PageHeader
        title="Account security"
        description="Two-step sign-in and the devices currently signed in to your account."
      />
      <MfaCard
        hasLocalPassword={me.data?.has_local_password !== false}
        status={mfaStatus.data}
        pending={mfaStatus.isPending}
      />
      <SessionsCard pending={sessions.isPending} />
    </main>
  );
}

function MfaCard({ hasLocalPassword, status, pending }: {
  hasLocalPassword: boolean;
  status: { enabled: boolean; staged: boolean; required: boolean } | undefined;
  pending: boolean;
}) {
  const setup = useMfaSetup();
  const confirm = useMfaConfirm();
  const disable = useMfaDisable();
  const [enrollment, setEnrollment] = useState<{ secret: string; otpauth_uri: string } | null>(null);
  const [confirmCode, setConfirmCode] = useState('');
  const [disableCode, setDisableCode] = useState('');
  const [disablePassword, setDisablePassword] = useState('');

  if (!hasLocalPassword) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Two-step sign-in</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2 text-sm text-muted-foreground">
          <p>Your account signs in with your university ID, so SYNAPSE does not manage a second factor for it.</p>
          <p>Credential changes for university-ID accounts go through <span className="font-medium text-foreground">helpdesk@foundationu.com</span>.</p>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <ShieldCheck aria-hidden className="size-5" />
          Two-step sign-in
          {status?.enabled === true && (
            <span className="rounded-full bg-emerald-600/15 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-400">Enabled</span>
          )}
          {status?.required === true && status.enabled !== true && (
            <span className="rounded-full bg-amber-600/15 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-400">Required — enroll now</span>
          )}
        </CardTitle>
        <CardDescription>
          Sign in with your password plus a 6-digit code from your phone's authenticator app
          (Google Authenticator, Microsoft Authenticator, Authy…). A stolen password alone will not be enough.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {pending && <p className="text-sm text-muted-foreground">Loading…</p>}

        {!pending && status?.enabled !== true && enrollment === null && (
          <div className="space-y-3">
            <p className="text-sm text-muted-foreground">
              {status?.staged === true
                ? 'An enrollment was started but never confirmed. Starting again replaces it.'
                : 'Not set up yet.'}
            </p>
            <Button
              onClick={() => setup.mutate(undefined, { onSuccess: (data) => { setEnrollment(data); setConfirmCode(''); } })}
              disabled={setup.isPending}
            >
              {setup.isPending && <Loader2 className="animate-spin" />}
              Set up two-step sign-in
            </Button>
          </div>
        )}

        {enrollment !== null && (
          <div className="space-y-4">
            <div className="space-y-2 rounded-lg border bg-muted/30 p-4">
              <p className="text-sm font-medium">1. Add SYNAPSE to your authenticator app</p>
              <p className="text-xs text-muted-foreground">
                Choose “enter a setup key” in the app and paste this key (spaces are ignored):
              </p>
              <div className="flex items-center gap-2">
                <code className="flex-1 break-all rounded bg-background px-2 py-1.5 text-sm">{enrollment.secret}</code>
                <CopyButton value={enrollment.secret} label="Copy key" />
              </div>
              <p className="pt-1 text-xs text-muted-foreground">
                Prefer a link? Copy this into an app that accepts otpauth URLs:
              </p>
              <div className="flex items-center gap-2">
                <code className="flex-1 truncate rounded bg-background px-2 py-1.5 text-xs">{enrollment.otpauth_uri}</code>
                <CopyButton value={enrollment.otpauth_uri} label="Copy link" />
              </div>
            </div>
            <form
              className="space-y-2"
              onSubmit={(e) => {
                e.preventDefault();
                if (confirmCode.length !== 6) return;
                confirm.mutate(
                  { code: confirmCode },
                  { onSuccess: () => setEnrollment(null) },
                );
              }}
            >
              <Label htmlFor="mfa-confirm-code">2. Enter the app's current 6-digit code</Label>
              <div className="flex gap-2">
                <Input
                  id="mfa-confirm-code"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  placeholder="000000"
                  value={confirmCode}
                  onChange={(e) => setConfirmCode(e.target.value.replace(/[^0-9]/g, ''))}
                  className="w-32 text-center tracking-[0.3em]"
                />
                <Button type="submit" disabled={confirm.isPending || confirmCode.length !== 6}>
                  {confirm.isPending && <Loader2 className="animate-spin" />}
                  Confirm
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  onClick={() => setEnrollment(null)}
                  disabled={confirm.isPending}
                >
                  Cancel
                </Button>
              </div>
            </form>
          </div>
        )}

        {!pending && status?.enabled === true && (
          <form
            className="space-y-3"
            onSubmit={(e) => {
              e.preventDefault();
              disable.mutate(
                { code: disableCode, current_password: disablePassword },
                {
                  onSuccess: () => {
                    setDisableCode('');
                    setDisablePassword('');
                  },
                },
              );
            }}
          >
            <p className="text-sm text-muted-foreground">
              To turn two-step sign-in off, confirm with both a current code and your password.
            </p>
            <div className="grid gap-3 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="mfa-disable-code">Authenticator code</Label>
                <Input
                  id="mfa-disable-code"
                  inputMode="numeric"
                  maxLength={6}
                  placeholder="000000"
                  value={disableCode}
                  onChange={(e) => setDisableCode(e.target.value.replace(/[^0-9]/g, ''))}
                  className="text-center tracking-[0.3em]"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="mfa-disable-password">Password</Label>
                <Input
                  id="mfa-disable-password"
                  type="password"
                  autoComplete="current-password"
                  value={disablePassword}
                  onChange={(e) => setDisablePassword(e.target.value)}
                />
              </div>
            </div>
            <Button type="submit" variant="destructive" disabled={disable.isPending || disableCode.length !== 6 || disablePassword === ''}>
              {disable.isPending && <Loader2 className="animate-spin" />}
              Disable two-step sign-in
            </Button>
          </form>
        )}
      </CardContent>
    </Card>
  );
}

function SessionsCard({ pending }: { pending: boolean }) {
  const sessions = useSessions();
  const revoke = useRevokeSession();

  return (
    <Card>
      <CardHeader>
        <CardTitle>Signed-in sessions</CardTitle>
        <CardDescription>
          Each row is a sign-in that can refresh itself. Signing a session out blocks it from
          continuing; your current session can only be ended by signing out.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {pending && <p className="text-sm text-muted-foreground">Loading…</p>}
        {!pending && (sessions.data ?? []).length === 0 && (
          <p className="text-sm text-muted-foreground">No active sessions found.</p>
        )}
        {!pending && (sessions.data ?? []).length > 0 && (
          <table className="w-full text-sm" aria-label="Signed-in sessions">
            <thead>
              <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                <th scope="col" className="py-2 pr-3 font-medium">Session</th>
                <th scope="col" className="py-2 pr-3 font-medium">Started</th>
                <th scope="col" className="py-2 pr-3 font-medium">Last used</th>
                <th scope="col" className="py-2 pr-3 font-medium">Network</th>
                <th scope="col" className="py-2 font-medium sr-only">Actions</th>
              </tr>
            </thead>
            <tbody>
              {(sessions.data ?? []).map((s) => (
                <tr key={s.family_id} className="border-b last:border-0">
                  <td className="py-2.5 pr-3">
                    <span className="font-medium">{s.is_current ? 'This session' : 'Another session'}</span>
                    <span className="block text-xs text-muted-foreground">
                      {s.rotations} refresh{s.rotations === 1 ? '' : 'es'}
                      {s.user_agent_hash !== null && s.user_agent_hash !== '' ? ` · device ${s.user_agent_hash.slice(0, 8)}` : ''}
                    </span>
                  </td>
                  <td className="py-2.5 pr-3 text-muted-foreground">{fmt(s.started_at)}</td>
                  <td className="py-2.5 pr-3 text-muted-foreground">{s.last_used_at !== null ? fmt(s.last_used_at) : '—'}</td>
                  <td className="py-2.5 pr-3 text-muted-foreground">{s.ip_subnet ?? '—'}</td>
                  <td className="py-2.5 text-right">
                    {!s.is_current && (
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={revoke.isPending}
                        onClick={() => revoke.mutate({ family_id: s.family_id })}
                      >
                        {revoke.isPending && <Loader2 className="animate-spin" />}
                        Sign out
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </CardContent>
    </Card>
  );
}

function CopyButton({ value, label }: { value: string; label: string }) {
  return (
    <Button
      type="button"
      size="sm"
      variant="outline"
      onClick={() => {
        void navigator.clipboard.writeText(value).then(() => toast.success(`${label} copied.`));
      }}
    >
      <Copy aria-hidden className="size-4" />
      <span className="sr-only">{label}</span>
    </Button>
  );
}

function fmt(iso: string): string {
  const d = new Date(iso.includes('T') ? iso : iso.replace(' ', 'T') + 'Z');
  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}
