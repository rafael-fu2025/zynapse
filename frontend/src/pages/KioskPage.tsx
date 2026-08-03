/**
 * KioskPage — self-service check-in station (Phase 17, recycled from
 * legacy synapse_ag IoT kiosk; reworked in the kiosk gap analysis,
 * July 2026 — shared core lives in components/KioskCheckin).
 *
 * A barcode/QR scanner acts as a keyboard: the identifier input stays
 * focused and Enter submits. Dispatch happens server-side (counselling
 * appointment today → confirm; clinic appointment today → checked in
 * via the appointment transition; otherwise open a pending-triage
 * encounter and join the walk-in queue). Network-failed scans are
 * buffered in localStorage and replayed automatically when the browser
 * comes back online, with the original `scanned_at` so the backend's
 * ±5-minute duplicate window still applies on sync.
 *
 * This is the STAFF surface (trail + stats). The fullscreen station
 * variant for lobby hardware is /kiosk-station (KioskStationPage).
 */
import {
  Camera,
  CloudOff,
  Loader2,
  MonitorSmartphone,
  RefreshCw,
  ScanLine,
  Settings2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
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
import {
  KioskCameraDialog,
  OUTCOME_LABEL,
  OUTCOME_VARIANT,
  QueueAssignmentDialog,
  RejectedScansAlert,
  ScanErrorBanner,
  ScanFlashOverlay,
  ScanReadyBadge,
  ScanResultCard,
  hasQueueAssignment,
  useKioskController,
} from '@/components/KioskCheckin';
import { useCheckinsToday } from '@/hooks/useCheckin';
import { SCAN_METHODS, type ScanMethod } from '@/schemas/checkin';
import { fmtUtcToApp } from '@/utils/date';

export default function KioskPage() {
  const k = useKioskController();
  const trail = useCheckinsToday();
  const [openCamera, setOpenCamera] = useState(false);
  const [editStation, setEditStation] = useState(false);
  const modalOpen = hasQueueAssignment(k.result);

  // Gap #10: a stray tap must not silently swallow scanner keystrokes.
  // Any printable key typed while nothing text-editable is focused
  // refocuses the identifier input so the keystroke lands there. While
  // the queue-assignment dialog is open, leave focus inside it so the
  // Radix focus trap wins (and HID keystrokes don't reopen the input).
  useEffect(() => {
    if (modalOpen) return;
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.ctrlKey || e.metaKey || e.altKey || e.key.length !== 1) return;
      const el = document.activeElement;
      const editable =
        el instanceof HTMLInputElement ||
        el instanceof HTMLTextAreaElement ||
        (el instanceof HTMLElement && el.isContentEditable);
      if (!editable) k.inputRef.current?.focus();
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [k.inputRef, modalOpen]);

  // Gap #11: daily stat strip from the already-fetched trail.
  const stats = useMemo(() => {
    const rows = trail.data ?? [];
    return {
      total: rows.length,
      queued: rows.filter((r) => r.outcome === 'clinic_queued').length,
      confirmed: rows.filter(
        (r) => r.outcome === 'counselling_confirmed' || r.outcome === 'clinic_appointment_confirmed',
      ).length,
      duplicates: rows.filter((r) => r.outcome === 'duplicate').length,
    };
  }, [trail.data]);

  return (
    <main className="mx-auto max-w-5xl space-y-4 p-4 md:p-6">
      <ScanFlashOverlay flash={k.flash} />
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Check-in Kiosk</h1>
          <p className="text-sm text-muted-foreground">
            Bookings today are confirmed on scan; everyone else joins the clinic queue for triage.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" asChild>
            <Link to="/kiosk-station">
              <MonitorSmartphone /> Station mode
            </Link>
          </Button>
          {k.pending > 0 && (
            <Button variant="outline" size="sm" onClick={() => void k.syncBuffer()} disabled={k.syncing}>
              {k.syncing ? <Loader2 className="animate-spin" /> : <RefreshCw />}
              Sync {k.pending} offline scan{k.pending === 1 ? '' : 's'}
            </Button>
          )}
        </div>
      </header>

      {/* Gap #11: today-at-a-glance stat strip. */}
      <section aria-label="Today's check-in stats" className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {(
          [
            ['Total scans', stats.total],
            ['Queued', stats.queued],
            ['Appointments confirmed', stats.confirmed],
            ['Duplicates', stats.duplicates],
          ] as const
        ).map(([label, value]) => (
          <article key={label} className="rounded-xl border bg-card p-3">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="text-2xl font-bold tabular-nums text-foreground">{value}</p>
          </article>
        ))}
      </section>

      <section className="grid gap-4 lg:grid-cols-2">
        <article className="space-y-4 rounded-xl border bg-card p-4">
          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <Label htmlFor="kiosk-id">Student / Employee ID / QR / RFID</Label>
              <ScanReadyBadge focused={k.focused} />
            </div>
            <div className="flex gap-2">
              <Input
                id="kiosk-id"
                ref={k.inputRef}
                autoComplete="off"
                placeholder="Scan or type, then press Enter…"
                value={k.identifier}
                onChange={(e) => k.setIdentifier(e.target.value)}
                onFocus={() => k.setFocused(true)}
                onBlur={() => k.setFocused(false)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    k.submit();
                  }
                }}
                aria-invalid={k.scanError !== null}
                aria-describedby={k.scanError !== null ? 'kiosk-id-error' : undefined}
                className="h-12 text-lg"
              />
              <Button className="h-12" onClick={k.submit} disabled={k.scanPending || k.identifier.trim() === ''}>
                {k.scanPending ? <Loader2 className="animate-spin" /> : <ScanLine />}
                Check in
              </Button>
            </div>
            <ScanErrorBanner message={k.scanError} errorId="kiosk-id-error" />
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label id="kiosk-method-label">Method</Label>
              <div className="flex gap-2">
                <Select value={k.method} onValueChange={(v) => k.setMethod(v as ScanMethod)}>
                  <SelectTrigger aria-labelledby="kiosk-method-label"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {SCAN_METHODS.map((m) => (
                      <SelectItem key={m} value={m}>{m.toUpperCase()}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Button
                  variant="outline"
                  size="icon"
                  aria-label="Scan QR with camera"
                  onClick={() => setOpenCamera(true)}
                >
                  <Camera />
                </Button>
              </div>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="kiosk-station">Station</Label>
              {/* Gap #7: persisted station id behind a gear affordance. */}
              {editStation ? (
                <div className="flex gap-2">
                  <Input
                    id="kiosk-station"
                    maxLength={64}
                    value={k.station}
                    onChange={(e) => k.setStation(e.target.value)}
                  />
                  <Button variant="outline" onClick={() => setEditStation(false)}>Done</Button>
                </div>
              ) : (
                <div className="flex h-10 items-center gap-2 md:h-9">
                  <Badge variant="secondary" className="font-mono">{k.station}</Badge>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Edit station id"
                    onClick={() => setEditStation(true)}
                  >
                    <Settings2 />
                  </Button>
                </div>
              )}
            </div>
          </div>
          {k.pending > 0 && (
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <CloudOff className="size-3.5" /> {k.pending} scan{k.pending === 1 ? '' : 's'} buffered offline — auto-syncs when back online.
            </p>
          )}
          <RejectedScansAlert rejected={k.rejected} onDismiss={k.dismissRejected} />
        </article>

        <ScanResultCard result={k.result} secondsLeft={k.secondsLeft} />
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

      <Dialog open={openCamera} onOpenChange={setOpenCamera}>
        {openCamera && (
          <KioskCameraDialog
            onClose={() => setOpenCamera(false)}
            onDecoded={(value) => k.submitIdentifier(value, 'qr')}
          />
        )}
      </Dialog>
      <QueueAssignmentDialog result={k.result} onDone={k.clearResult} />
    </main>
  );
}
