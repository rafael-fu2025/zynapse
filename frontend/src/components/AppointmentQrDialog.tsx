/**
 * AppointmentQrDialog — proof-of-booking QR for a clinic appointment.
 *
 * Mirrors the mobile `AppointmentQrSheet` (mobile/lib/features/appointments/
 * appointment_qr_sheet.dart) so the web and mobile surfaces stay in sync
 * against the same backend:
 *
 *   * renders the current token via `qrcode.react` (the backend stores
 *     only the HMAC hash — the plaintext is returned at booking/issue time),
 *   * restores a previously-issued token from localStorage so reopening
 *     shows the SAME QR (no silent rotation),
 *   * mints a token ONLY on an explicit "Issue QR" / "Re-issue" tap
 *     (never auto-issued on open), and
 *   * runs the PUBLIC minimum-disclosure verify (`POST /appointments/verify`)
 *     that reveals only validity + status + scheduled time.
 *
 * NOTE: mutations are awaited via `mutateAsync().then()` (never per-call
 * `mutate(vars, { onSuccess })` callbacks) — per-call callbacks don't
 * propagate under React StrictMode's double-invoked effects, leaving the
 * component stuck on the in-flight state even after the request settles.
 * The ScheduleDialog uses the same awaited pattern for the same reason.
 */
import { Check, Loader2, QrCode, RefreshCw, ShieldCheck, X } from 'lucide-react';
import { useState } from 'react';
import { QRCodeCanvas } from 'qrcode.react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

/** localStorage key for a persisted proof-of-booking QR token. */
function qrStorageKey(appointmentId: number): string {
  return `synapse_appointment_qr_${appointmentId}`;
}

function readStoredToken(appointmentId: number): string | null {
  try {
    const v = window.localStorage.getItem(qrStorageKey(appointmentId));
    return v !== null && v !== '' ? v : null;
  } catch {
    return null;
  }
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
  // Restore a previously-issued token (booking-time token first; a
  // persisted token wins since it reflects the most recent explicit
  // issue). The token is only minted/rotated on an EXPLICIT "Issue QR" /
  // "Re-issue" tap — never auto-issued on open.
  const [token, setToken] = useState<string | null>(
    initialToken ?? readStoredToken(appointmentId),
  );
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<AppointmentQrVerify | null>(null);
  // Local busy flags: under React StrictMode the mutation observer's
  // `isPending`/`isSuccess` don't always propagate to the mounted
  // instance (the same reason we await mutateAsync instead of using
  // per-call callbacks), so we drive the disabled states ourselves.
  const [issuing, setIssuing] = useState(false);
  const [verifying, setVerifying] = useState(false);

  /** Issue (or re-issue) the token, persist it, and adopt it for QR. */
  async function issueToken() {
    setIssuing(true);
    setError(null);
    setResult(null);
    try {
      const res = await issue.mutateAsync(appointmentId);
      if (res?.qr_token != null) {
        setToken(res.qr_token);
        try {
          window.localStorage.setItem(qrStorageKey(appointmentId), res.qr_token);
        } catch {
          // Non-fatal: the in-memory token still works for this session.
        }
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

  const ok = result?.valid === true;
  const resultColor = ok
    ? 'border-emerald-600/30 bg-emerald-600/5 text-emerald-700'
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
              includeMargin
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
              <p className="max-w-60 truncate font-mono text-[10px] text-muted-foreground">{token}</p>
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
              {ok ? <Check className="size-4" aria-hidden /> : <X className="size-4" aria-hidden />}
              {ok ? 'Valid appointment' : 'Not found / invalid'}
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
            <Button variant="outline" disabled={issuing} onClick={() => void issueToken()}>
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
    </DialogContent>
  );
}
