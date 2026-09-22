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
 *
 * Layout (2026-09-14 redesign — the old grid was a wall of equal boxes):
 * destination → purpose are the once-per-session settings (muted chips,
 * not outlined buttons), the scan input is the ONE hero control, and
 * method / camera / station live in a quiet footer row. Today's stats
 * ride the trail header instead of their own card strip, and the trail
 * drops the # and Station columns (constants for a single kiosk).
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
import { PageHeader } from '@/components/PageHeader';
import { Dialog } from '@/components/ui/dialog';
import { TableStateRows } from '@/components/TableStates';
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
import { KIOSK_DESTINATIONS, KIOSK_PURPOSES } from '@/lib/kioskPurposes';
import { SCAN_METHODS, type ScanMethod } from '@/schemas/checkin';
import { fmtUtcToApp } from '@/utils/date';

export default function KioskPage() {
  const k = useKioskController();
  const trail = useCheckinsToday();
  const trailRows = trail.data ?? [];
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

  // Gap #11: daily stats from the already-fetched trail — rendered as a
  // quiet inline line in the trail header, not a card strip.
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
    <main className="space-y-4 p-6">
      <ScanFlashOverlay flash={k.flash} />
      <PageHeader
        title="Check-in Kiosk"
        description="Scan an ID to check in — today's bookings are confirmed automatically; walk-ins join the triage queue."
        actions={
          <>
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
          </>
        }
      />

      <section className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
        {/* Check-in console. Reads top-down as the workflow: set the
            destination and purpose once per session, then scan each
            patient against the hero input. */}
        <article className="space-y-5 rounded-xl border bg-card p-4 md:p-5">
          <div className="space-y-1.5">
            <Label id="kiosk-destination-label" className="text-xs font-normal uppercase tracking-wide text-muted-foreground">Destination</Label>
            <div className="grid grid-cols-2 gap-2" role="group" aria-labelledby="kiosk-destination-label">
              {KIOSK_DESTINATIONS.map((d) => (
                <Button
                  key={d.value}
                  type="button"
                  size="sm"
                  variant={k.destination === d.value ? 'default' : 'secondary'}
                  aria-pressed={k.destination === d.value}
                  onClick={() => k.setDestination(d.value)}
                >
                  {d.label}
                </Button>
              ))}
            </div>
          </div>

          <div className="space-y-1.5">
            <Label id="kiosk-purpose-label" className="text-xs font-normal uppercase tracking-wide text-muted-foreground">Purpose</Label>
            <div className="flex flex-wrap gap-1.5" role="group" aria-labelledby="kiosk-purpose-label">
              {k.destination !== null && KIOSK_PURPOSES[k.destination].map((p) => (
                <Button
                  key={p}
                  type="button"
                  size="sm"
                  variant={k.purpose === p ? 'default' : 'secondary'}
                  aria-pressed={k.purpose === p}
                  onClick={() => k.setPurpose(p)}
                >
                  {p}
                </Button>
              ))}
            </div>
            {k.purpose === '' && (
              <p className="text-xs text-muted-foreground">Pick a purpose to enable check-in.</p>
            )}
          </div>

          {/* The hero — this is the control the operator hits for every
              patient, so it gets the size and the focus. */}
          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <Label htmlFor="kiosk-id">Student / Employee ID</Label>
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
                className="h-14 text-lg"
              />
              <Button
                className="h-14 px-6 text-base"
                onClick={k.submit}
                disabled={k.scanPending || k.identifier.trim() === '' || k.purpose === ''}
                title={k.purpose === '' ? 'Pick a purpose to check in.' : undefined}
              >
                {k.scanPending ? <Loader2 className="animate-spin" /> : <ScanLine />}
                Check in
              </Button>
            </div>
            <ScanErrorBanner message={k.scanError} errorId="kiosk-id-error" />
          </div>

          {/* Quiet footer — rarely-touched settings, one row, no labels
              shouting. Method records how the scan arrived; the camera
              opens the QR decoder; the station tags this console. */}
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t pt-3">
            <div className="flex items-center gap-2">
              <span id="kiosk-method-label" className="text-xs text-muted-foreground">Method</span>
              <Select value={k.method} onValueChange={(v) => k.setMethod(v as ScanMethod)}>
                <SelectTrigger aria-labelledby="kiosk-method-label" className="h-8 w-24 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {SCAN_METHODS.map((m) => (
                    <SelectItem key={m} value={m}>{m.toUpperCase()}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label="Scan QR with camera"
              onClick={() => setOpenCamera(true)}
            >
              <Camera />
            </Button>
            {editStation ? (
              <div className="flex items-center gap-1.5">
                <Input
                  id="kiosk-station"
                  className="h-8 w-32 text-xs"
                  maxLength={64}
                  value={k.station}
                  onChange={(e) => k.setStation(e.target.value)}
                />
                <Button variant="outline" size="sm" className="h-8" onClick={() => setEditStation(false)}>Done</Button>
              </div>
            ) : (
              <div className="flex items-center gap-1">
                <Badge variant="secondary" className="font-mono text-xs">{k.station}</Badge>
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
            {k.pending > 0 && (
              <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <CloudOff className="size-3.5" /> {k.pending} scan{k.pending === 1 ? '' : 's'} buffered — auto-syncs when back online.
              </p>
            )}
          </div>
          <RejectedScansAlert rejected={k.rejected} onDismiss={k.dismissRejected} />
        </article>

        <ScanResultCard result={k.result} secondsLeft={k.secondsLeft} />
      </section>

      {/* Today's trail — stats live in the header line; the table keeps
          only the columns staff actually scan (method hides on mobile). */}
      <section className="overflow-hidden rounded-xl border bg-card">
        <header className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b px-3 py-2">
          <span className="text-sm font-semibold text-foreground">Today's check-ins</span>
          <span className="text-xs tabular-nums text-muted-foreground">
            {stats.total} scans · {stats.queued} queued · {stats.confirmed} confirmed · {stats.duplicates} duplicates
          </span>
        </header>
        <div className="max-h-[480px] overflow-y-auto">
          <Table ariaLabel="Today's kiosk check-ins">
            <TableHeader className="bg-muted/50">
              <TableRow>
                <TableHead className="px-3">Patient</TableHead>
                <TableHead className="hidden px-3 md:table-cell">Method</TableHead>
                <TableHead className="px-3">Outcome</TableHead>
                <TableHead className="px-3 text-right">Scanned</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableStateRows
                colSpan={4}
                isLoading={trail.isLoading}
                isError={trail.isError}
                isEmpty={trailRows.length === 0}
                onRetry={() => void trail.refetch()}
                pending={trail.isFetching}
                errorMessage="Failed to load today's check-ins."
                loadingLabel="Loading today's check-ins"
                empty={{
                  title: 'No check-ins yet today.',
                  description: 'Scans appear here as students and employees check in.',
                }}
              />
              {trail.data?.map((c) => (
                <TableRow key={c.id}>
                  <TableCell className="px-3">
                    {/* Guest check-ins carry no school ID — showing the cell
                        blank left the operator unable to identify the person.
                        Fall back to the recorded guest name, then an em dash. */}
                    {c.patient_school_id !== null ? (
                      <span className="font-mono text-xs">{c.patient_school_id}</span>
                    ) : c.guest_name !== null && c.guest_name !== '' ? (
                      <span>
                        {c.guest_name} <span className="text-xs text-muted-foreground">(guest)</span>
                      </span>
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                  </TableCell>
                  <TableCell className="hidden px-3 text-xs uppercase md:table-cell">{c.method}</TableCell>
                  <TableCell className="px-3">
                    <Badge variant={OUTCOME_VARIANT[c.outcome]}>{OUTCOME_LABEL[c.outcome]}</Badge>
                  </TableCell>
                  <TableCell className="px-3 text-right text-xs text-muted-foreground">{fmtUtcToApp(c.scanned_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </section>

      <Dialog open={openCamera} onOpenChange={setOpenCamera}>
        {openCamera && (
          <KioskCameraDialog
            onClose={() => setOpenCamera(false)}
            onDecoded={(value) => k.submitIdentifier(value, 'qr', k.purpose)}
          />
        )}
      </Dialog>
      <QueueAssignmentDialog result={k.result} onDone={k.clearResult} />
    </main>
  );
}
