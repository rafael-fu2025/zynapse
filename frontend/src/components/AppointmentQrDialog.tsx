/**
 * AppointmentQrDialog — proof-of-booking QR for a clinic appointment.
 *
 * Mirrors the mobile `AppointmentQrSheet` (mobile/lib/features/appointments/
 * appointment_qr_sheet.dart) so the web and mobile surfaces stay in sync
 * against the same backend:
 *
 *   * renders the current token via `qrcode.react` (the backend stores
 *     only the HMAC hash — the plaintext is returned at booking/issue time),
 *   * keeps the token IN MEMORY for the session only — it is a bearer
 *     secret per appointment and shared front-desk machines must not
 *     retain it after the tab closes (2026-09 audit; legacy persisted
 *     keys are swept on mount),
 *   * mints a token ONLY on an explicit "Issue QR" / "Re-issue" tap
 *     (never auto-issued on open), and re-issue confirms first because
 *     it silently invalidates the copy the patient already holds, and
 *   * runs the PUBLIC minimum-disclosure verify (`POST /appointments/verify`)
 *     that reveals only validity + status + scheduled time.
 *
 * NOTE: mutations are awaited via `mutateAsync().then()` (never per-call
 * `mutate(vars, { onSuccess })` callbacks) — per-call callbacks don't
 * propagate under React StrictMode's double-invoked effects, leaving the
 * component stuck on the in-flight state even after the request settles.
 * The ScheduleDialog uses the same awaited pattern for the same reason.
 */
import { Check, Loader2, QrCode, RefreshCw, ShieldCheck, TriangleAlert, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { QRCodeCanvas } from 'qrcode.react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { CopyButton } from '@/components/CopyButton';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { ApiEnvelopeError } from '@/api/envelope';
import {
  useIssueAppointmentQr,
  useVerifyAppointmentQr,
} from '@/hooks/useAppointments';
import type { AppointmentQrVerify } from '@/schemas/appointments';
import { fmtUtcToApp } from '@/utils/date';
import { statusLabel } from '@/utils/status';
import { titleCase } from '@/lib/utils';

interface AppointmentQrDialogProps {
  /** Appointment id — used to (re)issue the token. */
  appointmentId: number;
  /** Human label for the booking (patient name / "You" in self-service). */
  patientLabel: string;
  /** UTC `YYYY-MM-DD HH:mm:ss` — rendered in Asia/Manila. */
  scheduledAt: string;
  status: string;
  /** Token already attached at booking time (no extra network call). */
  initialToken?: string | null;
  /** Staff (`clinic.appointments.write`) or the booking's owner. */
  canIssue?: boolean;
  onClose: () => void;
}

/** Legacy localStorage prefix from the pre-2026-09 persisted-token era. */
const LEGACY_QR_KEY_PREFIX = 'synapse_appointment_qr_';

/** A token still proves a booking only while the visit hasn't resolved. */
function bookingStillStands(status: string | null): boolean {
  return status === 'scheduled' || status === 'checked_in';
}

export function AppointmentQrDialog({
  appointmentId,
  patientLabel,
  scheduledAt,
  status,
  initialToken = null,
  canIssue = true,
  onClose,
}: AppointmentQrDialogProps) {
  const issue = useIssueAppointmentQr();
  const verify = useVerifyAppointmentQr();
  // Session-scoped token: booking-time token first; nothing is read
  // from or written to localStorage any more. One sweep on mount
  // clears any token an earlier version persisted to disk.
  const [token, setToken] = useState<string | null>(initialToken);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<AppointmentQrVerify | null>(null);
  const [confirmReissue, setConfirmReissue] = useState(false);
  // Local busy flags: under React StrictMode the mutation observer's
  // `isPending`/`isSuccess` don't always propagate to the mounted
  // instance (the same reason we await mutateAsync instead of using
  // per-call callbacks), so we drive the disabled states ourselves.
  const [issuing, setIssuing] = useState(false);
  const [verifying, setVerifying] = useState(false);

  useEffect(() => {
    try {
      const stale: string[] = [];
      for (let i = 0; i < window.localStorage.length; i += 1) {
        const key = window.localStorage.key(i);
        if (key !== null && key.startsWith(LEGACY_QR_KEY_PREFIX)) stale.push(key);
      }
      stale.forEach((key) => window.localStorage.removeItem(key));
    } catch {
      // Storage unavailable — nothing to clean.
    }
  }, []);

  /** Issue (or re-issue) the token and adopt it for QR. */
  async function issueToken() {
    setConfirmReissue(false);
    setIssuing(true);
    setError(null);
    setResult(null);
    try {
      const res = await issue.mutateAsync(appointmentId);
      if (res?.qr_token != null) {
        setToken(res.qr_token);
      }
    } catch (err) {
      setError(err instanceof ApiEnvelopeError ? (err.errors[0]?.message ?? 'Failed to issue QR.') : 'Failed to issue QR.');
    } finally {
      setIssuing(false);
    }
  }

  async function runVerify() {
    if (token === null) return;
    setVerifying(true);
    setError(null);
    setResult(null);
    try {
      const r = await verify.mutateAsync(token);
      setResult(r);
    } catch (err) {
      setError(err instanceof ApiEnvelopeError ? (err.errors[0]?.message ?? 'Verify failed.') : 'Verify failed.');
    } finally {
      setVerifying(false);
    }
  }

  // The token existing does not mean the booking stands: a cancelled,
  // completed or no-show appointment verifies "genuine but expired" so
  // staff don't honour a QR from a visit that already resolved.
  const tokenGenuine = result?.valid === true;
  const bookingStands = tokenGenuine && bookingStillStands(result?.status ?? null);
  const verdict = !tokenGenuine
    ? { tone: 'invalid', icon: <X className="size-4" aria-hidden />, label: 'Not found / invalid' }
    : bookingStands
      ? { tone: 'valid', icon: <Check className="size-4" aria-hidden />, label: 'Valid appointment' }
      : { tone: 'expired', icon: <TriangleAlert className="size-4" aria-hidden />, label: 'Token genuine — booking no longer active' };
  const resultColor = verdict.tone === 'valid'
    ? 'border-emerald-600/30 bg-emerald-600/5 text-emerald-700'
    : verdict.tone === 'expired'
      ? 'border-amber-600/40 bg-amber-600/5 text-amber-700 dark:text-amber-400'
      : 'border-destructive/30 bg-destructive/5 text-destructive';

  return (
    <DialogContent className="sm:max-w-sm">
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <QrCode className="size-4" /> Appointment QR
        </DialogTitle>
      </DialogHeader>

      <div className="space-y-3 text-center">
        <div>
          <p className="text-sm font-medium text-foreground">{patientLabel}</p>
          <div className="text-xs text-muted-foreground">
            {fmtUtcToApp(scheduledAt)}
            {' · '}
            <Badge variant="outline" className="text-[10px]">{statusLabel(status)}</Badge>
          </div>
        </div>

        <div className="flex flex-col items-center gap-2">
          {token !== null ? (
            <QRCodeCanvas
              value={token}
              size={200}
              marginSize={2}
              className="rounded-lg bg-white p-2 ring-1 ring-black/10"
            />
          ) : (
            <div className="flex h-52 w-52 flex-col items-center justify-center gap-2 rounded-lg bg-muted/40">
              <QrCode className="size-10 text-muted-foreground/60" aria-hidden />
              <p className="text-sm text-muted-foreground">No QR issued yet.</p>
              {canIssue && (
                <p className="px-4 text-xs text-muted-foreground/70">
                  Tap “Issue QR” to mint the proof-of-booking code.
                </p>
              )}
            </div>
          )}
          {token !== null && (
            <div className="flex items-center gap-2">
              {/* Mask the token — the QR already carries it, and the
                  copy button hands out the full value when needed. */}
              <p className="font-mono text-[10px] text-muted-foreground" title={token}>
                {token.slice(0, 4)}…{token.slice(-4)}
              </p>
              <CopyButton value={token} label="Copy QR token" successMessage="QR token copied." />
            </div>
          )}
        </div>

        {error !== null && (
          <p role="alert" className="text-xs text-destructive">{error}</p>
        )}

        {result !== null && (
          <div className={`rounded-md border px-3 py-2.5 text-sm ${resultColor}`}>
            <p className="flex items-center justify-center gap-1.5 font-medium">
              {verdict.icon}
              {verdict.label}
            </p>
            {result.status !== null && (
              <p className="mt-0.5 text-xs">
                Status: {titleCase(result.status)}
                {result.scheduled_at !== null ? ` · ${fmtUtcToApp(result.scheduled_at)}` : ''}
              </p>
            )}
          </div>
        )}
      </div>

      <DialogFooter>
        <div className="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          {canIssue && token === null && (
            <Button disabled={issuing} onClick={() => void issueToken()}>
              {issuing && <Loader2 className="animate-spin" />}
              <QrCode /> Issue QR
            </Button>
          )}
          {canIssue && token !== null && (
            <Button variant="outline" disabled={issuing} onClick={() => setConfirmReissue(true)}>
              {issuing && <Loader2 className="animate-spin" />}
              <RefreshCw /> Re-issue
            </Button>
          )}
          <Button disabled={token === null || verifying} onClick={() => void runVerify()}>
            {verifying && <Loader2 className="animate-spin" />}
            <ShieldCheck /> Verify
          </Button>
          <Button variant="ghost" onClick={onClose}>Close</Button>
        </div>
      </DialogFooter>

      <ConfirmDialog
        open={confirmReissue}
        title="Re-issue QR?"
        description="Re-issuing rotates the token — the QR the patient already holds stops working immediately."
        confirmLabel="Re-issue"
        pending={issuing}
        onConfirm={() => void issueToken()}
        onCancel={() => setConfirmReissue(false)}
      />
    </DialogContent>
  );
}
