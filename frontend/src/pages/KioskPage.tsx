/**
 * KioskPage — self-service check-in station (Phase 17, recycled from
 * legacy synapse_ag IoT kiosk).
 *
 * A barcode/QR scanner acts as a keyboard: the identifier input stays
 * focused and Enter submits. Dispatch happens server-side (counselling
 * appointment today → confirm; otherwise open a pending-triage
 * encounter and join the walk-in queue). Network-failed scans are
 * buffered in localStorage (the legacy offline buffer, moved client-
 * side) and replayed with their original `scanned_at` so the backend's
 * ±5-minute duplicate window still applies on sync.
 */
import {
  AlertTriangle,
  CloudOff,
  Loader2,
  RefreshCw,
  ScanLine,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { QueryErrorRow } from '@/components/QueryErrorState';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useCheckinsToday, useScan } from '@/hooks/useCheckin';
import {
  SCAN_METHODS,
  type BufferedScan,
  type CheckinOutcome,
  type ScanMethod,
  type ScanResult,
} from '@/schemas/checkin';
import { fmtUtcToApp } from '@/utils/date';

const BUFFER_KEY = 'kiosk_offline_scans';

const OUTCOME_VARIANT: Record<CheckinOutcome, 'success' | 'info' | 'warning' | 'secondary'> = {
  counselling_confirmed: 'success',
  clinic_queued: 'info',
  counselling_already: 'secondary',
  duplicate: 'warning',
};

const OUTCOME_LABEL: Record<CheckinOutcome, string> = {
  counselling_confirmed: 'Counselling confirmed',
  counselling_already: 'Already checked in',
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

export default function KioskPage() {
  const scan = useScan();
  const trail = useCheckinsToday();
  const [identifier, setIdentifier] = useState('');
  const [method, setMethod] = useState<ScanMethod>('manual');
  const [station, setStation] = useState('Kiosk-01');
  const [result, setResult] = useState<ScanResult | null>(null);
  const [pending, setPending] = useState<number>(() => readBuffer().length);
  const [syncing, setSyncing] = useState(false);
  // Buffered scans the server rejected on sync (e.g. unknown ID). We
  // surface these so the operator can see what failed instead of
  // silently dropping them.
  const [rejected, setRejected] = useState<Array<{ identifier: string; message: string }>>([]);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => inputRef.current?.focus(), []);

  const submit = useCallback(() => {
    const id = identifier.trim();
    if (id === '') return;
    scan.mutate(
      { identifier: id, method, station_id: station },
      {
        onSuccess: (r) => {
          setResult(r);
          setIdentifier('');
          inputRef.current?.focus();
        },
        onError: (err) => {
          if (err.httpStatus === 0) {
            // Network down — buffer for later sync (legacy offline flow).
            const buffered = readBuffer();
            buffered.push({ identifier: id, method, station_id: station, scanned_at: utcNowSql() });
            writeBuffer(buffered);
            setPending(buffered.length);
            setIdentifier('');
            toast.warning('Offline — scan buffered locally.');
            return;
          }
          setResult(null);
          toast.error(err.errors[0]?.message ?? 'Check-in failed.');
          inputRef.current?.focus();
        },
      },
    );
  }, [identifier, method, station, scan]);

  async function syncBuffer() {
    const buffered = readBuffer();
    if (buffered.length === 0) return;
    setSyncing(true);
    setRejected([]);
    let synced = 0;
    const dropped: Array<{ identifier: string; message: string }> = [];
    const remaining: BufferedScan[] = [];
    for (const s of buffered) {
      try {
        await scan.mutateAsync({ ...s });
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
    toast.success(`Sync complete. Synced: ${synced}, rejected: ${dropped.length}, still buffered: ${remaining.length}.`);
  }

  return (
    <main className="mx-auto max-w-5xl space-y-4 p-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Check-in Kiosk</h1>
          <p className="text-sm text-muted-foreground">
            Counselling bookings today are confirmed on scan; everyone else joins the clinic queue for triage.
          </p>
        </div>
        {pending > 0 && (
          <Button variant="outline" onClick={() => void syncBuffer()} disabled={syncing}>
            {syncing ? <Loader2 className="animate-spin" /> : <RefreshCw />}
            Sync {pending} offline scan{pending === 1 ? '' : 's'}
          </Button>
        )}
      </header>

      <section className="grid gap-4 lg:grid-cols-2">
        <article className="space-y-4 rounded-xl border bg-card p-4">
          <div className="space-y-1.5">
            <Label htmlFor="kiosk-id">Student ID / QR / RFID</Label>
            <div className="flex gap-2">
              <Input
                id="kiosk-id"
                ref={inputRef}
                autoComplete="off"
                placeholder="Scan or type, then press Enter…"
                value={identifier}
                onChange={(e) => setIdentifier(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    submit();
                  }
                }}
                className="h-12 text-lg"
              />
              <Button className="h-12" onClick={submit} disabled={scan.isPending || identifier.trim() === ''}>
                {scan.isPending ? <Loader2 className="animate-spin" /> : <ScanLine />}
                Check in
              </Button>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label id="kiosk-method-label">Method</Label>
              <Select value={method} onValueChange={(v) => setMethod(v as ScanMethod)}>
                <SelectTrigger aria-labelledby="kiosk-method-label"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {SCAN_METHODS.map((m) => (
                    <SelectItem key={m} value={m}>{m.toUpperCase()}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="kiosk-station">Station</Label>
              <Input id="kiosk-station" maxLength={64} value={station} onChange={(e) => setStation(e.target.value)} />
            </div>
          </div>
          {pending > 0 && (
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <CloudOff className="size-3.5" /> {pending} scan{pending === 1 ? '' : 's'} buffered offline.
            </p>
          )}
          {rejected.length > 0 && (
            <Alert variant="destructive">
              <AlertTriangle className="size-4" />
              <AlertTitle className="flex items-center justify-between gap-2">
                <span>{rejected.length} offline scan{rejected.length === 1 ? '' : 's'} rejected on sync</span>
                <Button
                  size="sm"
                  variant="ghost"
                  className="h-6 px-2 text-xs"
                  onClick={() => setRejected([])}
                >
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
          )}
        </article>

        <article className="space-y-3 rounded-xl border bg-card p-4" aria-live="polite">
          <p className="text-sm font-semibold text-foreground">Last result</p>
          {result === null && (
            <p className="text-sm text-muted-foreground">Scan an ID to see the check-in outcome here.</p>
          )}
          {result !== null && (
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-lg font-semibold text-foreground">{result.student.name}</p>
                  <p className="font-mono text-xs text-muted-foreground">
                    {result.student.student_number}
                    {result.student.course !== null ? ` · ${result.student.course}` : ''}
                    {result.student.year_level !== null ? ` · Y${result.student.year_level}` : ''}
                  </p>
                </div>
                <Badge variant={OUTCOME_VARIANT[result.outcome]}>{OUTCOME_LABEL[result.outcome]}</Badge>
              </div>
              <p className="text-sm text-foreground">{result.message}</p>
              {result.queue !== null && (
                <p className="text-3xl font-bold text-primary">Queue position {result.queue.position}</p>
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
      </section>

      <section className="overflow-hidden rounded-xl border bg-card">
        <header className="border-b px-3 py-2 text-sm font-semibold text-foreground">
          Today's check-ins
        </header>
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">#</TableHead>
              <TableHead className="px-3">Patient</TableHead>
              <TableHead className="px-3">Method</TableHead>
              <TableHead className="px-3">Station</TableHead>
              <TableHead className="px-3">Outcome</TableHead>
              <TableHead className="px-3">Scanned</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {trail.isLoading && (
              <TableRow>
                <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!trail.isLoading && (trail.data?.length ?? 0) === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                  No check-ins yet today.
                </TableCell>
              </TableRow>
            )}
            {trail.isError && !trail.isLoading && (
              <QueryErrorRow colSpan={6} message="Failed to load today's check-ins." onRetry={() => void trail.refetch()} pending={trail.isFetching} />
            )}
            {trail.data?.map((c) => (
              <TableRow key={c.id}>
                <TableCell className="px-3 font-mono text-xs">{c.id}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{c.patient_school_id}</TableCell>
                <TableCell className="px-3 text-xs uppercase">{c.method}</TableCell>
                <TableCell className="px-3 text-xs">{c.station_id ?? '—'}</TableCell>
                <TableCell className="px-3">
                  <Badge variant={OUTCOME_VARIANT[c.outcome]}>{OUTCOME_LABEL[c.outcome]}</Badge>
                </TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">{fmtUtcToApp(c.scanned_at)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>
    </main>
  );
}
