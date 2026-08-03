/**
 * KioskCheckin — shared check-in kiosk core (kiosk gap analysis, July 2026).
 *
 * Used by BOTH kiosk surfaces:
 *   - KioskPage        — staff view inside the shell (trail + stats)
 *   - KioskStationPage — fullscreen locked-down station (attract screen)
 *
 * Concentrates the behaviors the gap analysis called out so the two
 * surfaces cannot drift:
 *   #4  offline buffer auto-replays on the browser `online` event
 *   #5  result card auto-clears after a countdown (PII off the shared
 *       screen); the allergy alert is a masked flag server-side
 *   #7  station id persists in localStorage
 *   #9  audio + full-screen color flash on scan feedback
 *   #10 ready-to-scan indicator driven by real input focus
 */
import { Html5Qrcode } from 'html5-qrcode';
import { AlertTriangle, Camera, Loader2, XCircle } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { playChime, type ChimeKind } from '@/lib/chime';
import { useScan } from '@/hooks/useCheckin';
import type { ApiEnvelopeError } from '@/api/envelope';
import { scanResultSchema, type BufferedScan, type CheckinOutcome, type ScanMethod, type ScanResult } from '@/schemas/checkin';

const BUFFER_KEY = 'kiosk_offline_scans';
const STATION_KEY = 'kiosk_station_id';

/**
 * Trim the kiosk identifier before it is sent to the backend.
 *
 * The backend `CheckinService::scan` resolves by EXACT equality against
 * `students_students.student_number` / `patients_employees.employee_number`
 * (and the qr/rfid columns for those methods). The value is sent verbatim
 * so users can type their ID exactly as printed on the card (including
 * dashes). QR / RFID payloads are opaque tokens and pass through as-is.
 *
 * Dashless fallback (`dashlessFallback`) is applied separately as a
 * one-shot retry on 404 — see `submitIdentifier`.
 */
export function normalizeKioskIdentifier(raw: string, _method: ScanMethod): string {
  return raw.trim();
}

/**
 * If the typed identifier looks like a dashless student or employee ID,
 * return the dash-restored variant the backend is most likely to match.
 * Returns null for values that already contain dashes or that don't fit
 * the conventional formats (so we don't retry garbage and double the
 * latency for true unknowns).
 *
 * Recognised shapes:
 *   - Student : `YYYY#####`  (e.g. `202412301` → `2024-12301`)
 *   - Employee: `EMPYYYY###` (e.g. `EMP2024001` → `EMP-2024-001`)
 */
export function dashlessFallback(value: string): string | null {
  if (value.includes('-')) return null;
  if (/^20\d{2}\d{5}$/.test(value)) return `${value.slice(0, 4)}-${value.slice(4)}`;
  if (/^EMP\d{4}\d{3}$/i.test(value)) return `${value.slice(0, 3)}-${value.slice(3, 7)}-${value.slice(7)}`;
  return null;
}

/** A scan outcome carries a queue assignment we can show in the modal. */
export function hasQueueAssignment(result: ScanResult | null): boolean {
  if (result === null) return false;
  if (result.outcome !== 'clinic_queued' && result.outcome !== 'clinic_appointment_confirmed') return false;
  if (result.queue === null) return false;
  return Number.isInteger(result.queue.position) && result.queue.position > 0;
}

/** Format a queue position as `C-001` (zero-padded to 3 digits). */
export function formatQueueNumber(position: number): string {
  if (!Number.isInteger(position) || position <= 0) return 'C-???';
  return `C-${String(position).padStart(3, '0')}`;
}

export const OUTCOME_VARIANT: Record<CheckinOutcome, 'success' | 'info' | 'warning' | 'secondary'> = {
  counselling_confirmed: 'success',
  clinic_appointment_confirmed: 'success',
  clinic_queued: 'info',
  counselling_already: 'secondary',
  duplicate: 'warning',
};

export const OUTCOME_LABEL: Record<CheckinOutcome, string> = {
  counselling_confirmed: 'Counselling confirmed',
  counselling_already: 'Already checked in',
  clinic_appointment_confirmed: 'Appointment checked in',
  clinic_queued: 'Clinic queue',
  duplicate: 'Duplicate scan',
};

function readBuffer(): BufferedScan[] {
  try {
    const raw = localStorage.getItem(BUFFER_KEY);
    return raw !== null ? (JSON.parse(raw) as BufferedScan[]) : [];
  } catch {
    return [];
  }
}

function writeBuffer(scans: BufferedScan[]): void {
  localStorage.setItem(BUFFER_KEY, JSON.stringify(scans));
}

function utcNowSql(): string {
  return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

// Module-level in-flight lock: the localStorage buffer is shared, so
// two controller instances (React StrictMode double-mount, or both
// kiosk surfaces open) must not replay the same buffered scans
// concurrently — the backend duplicate window would catch it, but the
// redundant POSTs are avoidable.
let syncInFlight = false;

export interface KioskController {
  identifier: string;
  setIdentifier: (v: string) => void;
  method: ScanMethod;
  setMethod: (m: ScanMethod) => void;
  station: string;
  setStation: (s: string) => void;
  result: ScanResult | null;
  /** Auto-clear countdown for the visible result (privacy, gap #5). */
  secondsLeft: number;
  clearResult: () => void;
  /**
   * Persistent inline error banner (replaces the previous red full-screen
   * flash). Cleared automatically when the user edits the identifier.
   */
  scanError: string | null;
  clearError: () => void;
  submit: () => void;
  /** Submit a decoded value directly (camera scan path, gap #8). */
  submitIdentifier: (id: string, method: ScanMethod) => void;
  scanPending: boolean;
  pending: number;
  syncing: boolean;
  syncBuffer: () => Promise<void>;
  rejected: Array<{ identifier: string; message: string }>;
  dismissRejected: () => void;
  /** Full-screen flash color for the last scan (gap #9). */
  flash: ChimeKind | null;
  inputRef: React.RefObject<HTMLInputElement>;
  focused: boolean;
  setFocused: (f: boolean) => void;
}

export function useKioskController(autoClearSeconds = 15): KioskController {
  const scan = useScan();
  const [identifier, setIdentifierState] = useState('');
  const [method, setMethod] = useState<ScanMethod>('manual');
  // Station id persists across sessions (gap #7).
  const [station, setStationState] = useState(
    () => localStorage.getItem(STATION_KEY) ?? 'Kiosk-01',
  );
  const [result, setResult] = useState<ScanResult | null>(null);
  const [secondsLeft, setSecondsLeft] = useState(0);
  const [pending, setPending] = useState<number>(() => readBuffer().length);
  const [syncing, setSyncing] = useState(false);
  const [rejected, setRejected] = useState<Array<{ identifier: string; message: string }>>([]);
  const [scanError, setScanError] = useState<string | null>(null);
  const [flash, setFlash] = useState<ChimeKind | null>(null);
  const [focused, setFocused] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const flashTimer = useRef<ReturnType<typeof setTimeout>>();

  useEffect(() => inputRef.current?.focus(), []);

  const setStation = useCallback((s: string) => {
    setStationState(s);
    localStorage.setItem(STATION_KEY, s);
  }, []);

  // Wrapped so any keystroke that mutates the identifier dismisses the
  // persistent inline error banner — the user has acknowledged the
  // failure and is now correcting the input.
  const setIdentifier = useCallback((v: string) => {
    setIdentifierState(v);
    if (scanError !== null) setScanError(null);
  }, [scanError]);

  const feedback = useCallback((kind: ChimeKind) => {
    playChime(kind);
    setFlash(kind);
    clearTimeout(flashTimer.current);
    flashTimer.current = setTimeout(() => setFlash(null), 700);
  }, []);

  // Privacy countdown: tick the visible result away (gap #5).
  useEffect(() => {
    if (result === null) return;
    setSecondsLeft(autoClearSeconds);
    const iv = setInterval(() => {
      setSecondsLeft((s) => {
        if (s <= 1) {
          clearInterval(iv);
          setResult(null);
          return 0;
        }
        return s - 1;
      });
    }, 1000);
    return () => clearInterval(iv);
  }, [result, autoClearSeconds]);

  const submitIdentifier = useCallback(
    (rawId: string, m: ScanMethod) => {
      const id = normalizeKioskIdentifier(rawId, m);
      if (id === '') return;
      // Clear any prior inline error so a fresh attempt starts clean.
      setScanError(null);
      const runAttempt = (attemptId: string): Promise<unknown> =>
        scan.mutateAsync({ identifier: attemptId, method: m, station_id: station });
      const attempt = async (first: string, retry: string | null): Promise<unknown> => {
        try {
          return await runAttempt(first);
        } catch (err) {
          const status =
            err instanceof Object && 'httpStatus' in err ? (err as { httpStatus: number }).httpStatus : 0;
          const isUnknown =
            status === 404 ||
            (err instanceof Object &&
              'errors' in err &&
              ((err as { errors?: { code: string }[] }).errors ?? []).some(
                (e) => e.code === 'resource.not_found',
              ));
          if (isUnknown && retry !== null) {
            // One-shot dashless fallback so `202412301` still resolves to
            // `2024-12301` against hyphenated seed data.
            return await runAttempt(retry);
          }
          throw err;
        }
      };

      const fallback = dashlessFallback(id);
      void attempt(id, fallback)
        .then((data) => {
          const r = scanResultSchema.parse(data);
          setResult(r);
          setIdentifierState('');
          feedback(r.outcome === 'duplicate' ? 'warn' : 'success');
          inputRef.current?.focus();
        })
        .catch((err: ApiEnvelopeError | { httpStatus: number; errors?: { message?: string }[] }) => {
          if (err instanceof Object && 'httpStatus' in err && err.httpStatus === 0) {
            // Network down — buffer for later sync (legacy offline flow).
            const buffered = readBuffer();
            buffered.push({ identifier: id, method: m, station_id: station, scanned_at: utcNowSql() });
            writeBuffer(buffered);
            setPending(buffered.length);
            setIdentifierState('');
            toast.warning('Offline — scan buffered locally.');
            return;
          }
          // Replace the previous jarring red full-screen flash with a
          // persistent, human-friendly inline banner. The typed value is
          // left in the input so the user can correct it without re-typing.
          const message = (err as { errors?: { message?: string }[] }).errors?.[0]?.message
            ?? 'Unknown ID. Please re-check your number and try again.';
          setScanError(message);
          inputRef.current?.focus();
        });
    },
    [scan, station, feedback],
  );

  const submit = useCallback(
    () => submitIdentifier(identifier, method),
    [submitIdentifier, identifier, method],
  );

  const syncBuffer = useCallback(async () => {
    const buffered = readBuffer();
    if (buffered.length === 0 || syncInFlight) return;
    syncInFlight = true;
    setSyncing(true);
    setRejected([]);
    let synced = 0;
    const dropped: Array<{ identifier: string; message: string }> = [];
    const remaining: BufferedScan[] = [];
    for (const s of buffered) {
      try {
        // Normalize on replay too — the user might have keyed the ID
        // with or without dashes during the offline session.
        await scan.mutateAsync({
          ...s,
          identifier: normalizeKioskIdentifier(s.identifier, s.method),
        });
        synced += 1;
      } catch (err) {
        const status = err instanceof Object && 'httpStatus' in err ? (err as { httpStatus: number }).httpStatus : 0;
        if (status === 0) {
          remaining.push(s); // still offline — keep buffered
        } else {
          // Rejected server-side (unknown ID etc.) — drop from the
          // buffer but record it so the operator can re-key it.
          const message =
            (err as { errors?: { message: string }[] }).errors?.[0]?.message ?? 'Rejected by server.';
          dropped.push({ identifier: s.identifier, message });
        }
      }
    }
    writeBuffer(remaining);
    setPending(remaining.length);
    setRejected(dropped);
    setSyncing(false);
    syncInFlight = false;
    toast.success(`Sync complete. Synced: ${synced}, rejected: ${dropped.length}, still buffered: ${remaining.length}.`);
  }, [scan]);

  // Auto-replay when connectivity returns (gap #4); also try once on
  // mount in case scans were buffered in a previous session.
  const syncRef = useRef(syncBuffer);
  syncRef.current = syncBuffer;
  useEffect(() => {
    const onOnline = () => void syncRef.current();
    window.addEventListener('online', onOnline);
    if (navigator.onLine && readBuffer().length > 0) void syncRef.current();
    return () => window.removeEventListener('online', onOnline);
  }, []);

  return {
    identifier,
    setIdentifier,
    method,
    setMethod,
    station,
    setStation,
    result,
    secondsLeft,
    clearResult: () => {
      setResult(null);
      setScanError(null);
    },
    scanError,
    clearError: () => setScanError(null),
    submit,
    submitIdentifier,
    scanPending: scan.isPending,
    pending,
    syncing,
    syncBuffer,
    rejected,
    dismissRejected: () => setRejected([]),
    flash,
    inputRef,
    focused,
    setFocused,
  };
}

/** Full-screen color flash on scan feedback (gap #9) — decorative only. */
export function ScanFlashOverlay({ flash }: { flash: ChimeKind | null }) {
  if (flash === null) return null;
  const color =
    flash === 'success' ? 'bg-emerald-500/30' : flash === 'warn' ? 'bg-amber-500/30' : 'bg-red-600/30';
  return (
    <div
      aria-hidden
      className={`pointer-events-none fixed inset-0 z-50 animate-in fade-in-0 ${color}`}
    />
  );
}

/**
 * Inline error banner — replaces the previous jarring red full-screen
 * flash. Stays visible until the user edits the identifier or submits a
 * new scan, so a brief glance is enough to understand what went wrong.
 */
export function ScanErrorBanner({
  message,
  errorId,
  large = false,
}: {
  message: string | null;
  errorId: string;
  large?: boolean;
}) {
  if (message === null) return null;
  return (
    <Alert
      id={errorId}
      role="alert"
      variant="destructive"
      className={large ? 'py-4 text-base' : undefined}
    >
      <XCircle className={large ? 'size-5' : 'size-4'} aria-hidden />
      <AlertTitle className={large ? 'text-base' : undefined}>
        {large ? 'We could not find that ID' : 'Check-in failed'}
      </AlertTitle>
      <AlertDescription className={large ? 'text-base' : undefined}>{message}</AlertDescription>
    </Alert>
  );
}

/**
 * Queue assignment dialog — shown after a successful walk-in or
 * appointment check-in so the patient walks away with their assigned
 * queue number (formatted `C-001`). The "Done" button hands control
 * back to the next kiosk user.
 */
export function QueueAssignmentDialog({
  result,
  onDone,
  large = false,
}: {
  result: ScanResult | null;
  onDone: () => void;
  large?: boolean;
}) {
  const open = hasQueueAssignment(result);
  const position = result !== null && result.queue !== null ? result.queue.position : 0;
  const waitMinutes =
    result !== null && result.queue !== null && result.queue.estimated_wait_minutes !== undefined
      ? result.queue.estimated_wait_minutes
      : null;
  return (
    <Dialog
      open={open}
      onOpenChange={(o) => {
        // Escape / overlay click / X close — clear result so the next user
        // can scan immediately. We never want this dialog to "stay open".
        if (!o) onDone();
      }}
    >
      {open && (
        <DialogContent
          className={large ? 'max-w-lg gap-6 p-8' : 'max-w-md gap-4'}
          onEscapeKeyDown={() => onDone()}
        >
          <DialogHeader className={large ? 'gap-2' : undefined}>
            <DialogTitle className={large ? 'text-2xl' : 'text-lg'}>
              You’re checked in
            </DialogTitle>
            <DialogDescription>
              Please remember your queue number and wait for your name to be called.
            </DialogDescription>
          </DialogHeader>
          {result !== null && (
            <div className="space-y-3 text-center">
              <p className={large ? 'text-lg font-medium text-muted-foreground' : 'text-sm text-muted-foreground'}>
                {result.student.name}
              </p>
              <p
                aria-label={`Queue number ${formatQueueNumber(position)}`}
                className={`font-mono font-bold tabular-nums text-primary ${
                  large ? 'text-8xl leading-none' : 'text-6xl leading-none'
                }`}
              >
                {formatQueueNumber(position)}
              </p>
              {waitMinutes !== null && (
                <p className={large ? 'text-base text-muted-foreground' : 'text-sm text-muted-foreground'}>
                  Estimated wait ~{waitMinutes} min
                </p>
              )}
            </div>
          )}
          <DialogFooter>
            <Button
              type="button"
              size={large ? 'lg' : 'default'}
              className={large ? 'min-h-12 w-full text-lg' : 'w-full'}
              onClick={onDone}
              autoFocus
            >
              Done
            </Button>
          </DialogFooter>
        </DialogContent>
      )}
    </Dialog>
  );
}

/** Last-result card shared by both kiosk surfaces. */
export function ScanResultCard({
  result,
  secondsLeft,
  large = false,
}: {
  result: ScanResult | null;
  secondsLeft: number;
  large?: boolean;
}) {
  return (
    <article className="space-y-3 rounded-xl border bg-card p-4" aria-live="polite">
      <p className="flex items-center justify-between text-sm font-semibold text-foreground">
        <span>Last result</span>
        {result !== null && secondsLeft > 0 && (
          <span className="text-xs font-normal tabular-nums text-muted-foreground" aria-label={`Clears in ${secondsLeft} seconds`}>
            clears in {secondsLeft}s
          </span>
        )}
      </p>
      {result === null && (
        <p className="text-sm text-muted-foreground">Scan an ID to see the check-in outcome here.</p>
      )}
      {result !== null && (
        <div className="space-y-3">
          <div className="flex items-center justify-between gap-2">
            <div className="min-w-0">
              <p className={`truncate font-semibold text-foreground ${large ? 'text-2xl' : 'text-lg'}`}>{result.student.name}</p>
              <p className="font-mono text-xs text-muted-foreground">
                {result.student.student_number}
                {result.student.kind === 'employee' ? ' · Employee' : ''}
                {result.student.course !== null ? ` · ${result.student.course}` : ''}
                {result.student.year_level !== null ? ` · Y${result.student.year_level}` : ''}
              </p>
            </div>
            <Badge variant={OUTCOME_VARIANT[result.outcome]}>{OUTCOME_LABEL[result.outcome]}</Badge>
          </div>
          <p className="text-sm text-foreground">{result.message}</p>
          {result.queue !== null && (
            <div>
              <p className={`font-bold text-primary ${large ? 'text-5xl' : 'text-3xl'}`}>
                Queue position {result.queue.position}
              </p>
              {result.queue.estimated_wait_minutes !== undefined && (
                <p className="mt-1 text-sm text-muted-foreground">
                  Estimated wait ~{result.queue.estimated_wait_minutes} min
                </p>
              )}
            </div>
          )}
          {result.allergy_alert !== null && (
            <Alert variant="destructive">
              <AlertTriangle className="size-4" />
              <AlertTitle>Allergy alert</AlertTitle>
              <AlertDescription>{result.allergy_alert}</AlertDescription>
            </Alert>
          )}
        </div>
      )}
    </article>
  );
}

/**
 * Camera QR scanner dialog (gap #8) — same html5-qrcode pattern as the
 * Referrals ScanDialog; decoded values are submitted as `qr` scans.
 */
export function KioskCameraDialog({
  onClose,
  onDecoded,
}: {
  onClose: () => void;
  onDecoded: (value: string) => void;
}) {
  const ref = useRef<Html5Qrcode | null>(null);
  const [running, setRunning] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  useEffect(() => {
    // The dialog content mounts through a portal, so the container div
    // may not be queryable by id at effect time — poll briefly instead
    // of constructing immediately, and NEVER let html5-qrcode's
    // synchronous throws (missing element, no camera) escape the
    // effect: they must land in the dialog's error line, not the
    // router error boundary.
    let attempts = 0;
    const timer = setInterval(() => {
      if (document.getElementById('kiosk-qr-reader') === null) {
        if (++attempts > 20) {
          clearInterval(timer);
          setErr('Camera view failed to initialise.');
        }
        return;
      }
      clearInterval(timer);
      try {
        const inst = new Html5Qrcode('kiosk-qr-reader');
        ref.current = inst;
        setRunning(true);
        inst
          .start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 240, height: 240 } },
            (decoded) => {
              void (async () => {
                try { await inst.stop(); } catch { /* noop */ }
                setRunning(false);
                onDecoded(decoded);
                onClose();
              })();
            },
            () => { /* ignore frame errors */ },
          )
          .catch((e: unknown) => {
            setErr((e as Error).message);
            setRunning(false);
          });
      } catch (e) {
        setErr((e as Error).message);
        setRunning(false);
      }
    }, 50);

    return () => {
      clearInterval(timer);
      const inst = ref.current;
      if (inst !== null) {
        // stop() throws synchronously when the scanner never started.
        try {
          inst.stop().catch(() => undefined).finally(() => {
            try { inst.clear(); } catch { /* noop */ }
          });
        } catch {
          try { inst.clear(); } catch { /* noop */ }
        }
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <Camera className="size-4" /> Scan QR with camera
        </DialogTitle>
      </DialogHeader>
      <div id="kiosk-qr-reader" className="rounded-md border" />
      {running && <p className="mt-2 text-xs text-muted-foreground">Point the camera at the QR code…</p>}
      {err !== null && <p role="alert" className="mt-2 text-xs text-destructive">{err}</p>}
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
      </DialogFooter>
    </DialogContent>
  );
}

/** Rejected-on-sync alert shared by both kiosk surfaces. */
export function RejectedScansAlert({
  rejected,
  onDismiss,
}: {
  rejected: Array<{ identifier: string; message: string }>;
  onDismiss: () => void;
}) {
  if (rejected.length === 0) return null;
  return (
    <Alert variant="destructive">
      <AlertTriangle className="size-4" />
      <AlertTitle className="flex items-center justify-between gap-2">
        <span>{rejected.length} offline scan{rejected.length === 1 ? '' : 's'} rejected on sync</span>
        <Button size="sm" variant="ghost" className="h-6 px-2 text-xs" onClick={onDismiss}>
          Dismiss
        </Button>
      </AlertTitle>
      <AlertDescription>
        <p className="mb-1">These were dropped from the buffer — re-key them manually if still needed:</p>
        <ul className="list-disc space-y-0.5 pl-4">
          {rejected.map((r, i) => (
            <li key={`${r.identifier}-${i}`}>
              <span className="font-mono">{r.identifier}</span> — {r.message}
            </li>
          ))}
        </ul>
      </AlertDescription>
    </Alert>
  );
}

/** "Ready to scan" indicator (gap #10) — reflects real input focus. */
export function ScanReadyBadge({ focused }: { focused: boolean }) {
  return focused ? (
    <Badge variant="success" className="gap-1">
      <span className="relative flex size-2">
        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-current opacity-60" />
        <span className="relative inline-flex size-2 rounded-full bg-current" />
      </span>
      Ready to scan
    </Badge>
  ) : (
    <Badge variant="warning" className="gap-1">
      <Loader2 className="size-3" aria-hidden /> Tap the ID field to resume scanning
    </Badge>
  );
}
