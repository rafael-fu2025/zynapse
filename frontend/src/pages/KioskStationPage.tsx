/**
 * KioskStationPage — fullscreen locked-down check-in station (kiosk
 * gap analysis #6). Rendered OUTSIDE the staff shell: no sidebar, no
 * topbar, no notifications — just the scan surface, sized for lobby
 * touch hardware. Auth still applies (the station runs under a
 * dedicated staff login with `clinic.checkin.record`).
 *
 * Adds the kiosk-mode behaviors the shell page cannot own:
 *   - attract/idle screen after inactivity (returns on any input)
 *   - text-size toggle (gap #9 accessibility)
 *   - global refocus so the HID scanner never loses the input (#10)
 * No check-in trail here — the station screen is bystander-readable,
 * so it shows only the operator's own last result (auto-cleared).
 */
import {
  Activity,
  Camera,
  CloudOff,
  Loader2,
  ScanLine,
  Settings2,
  ZoomIn,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  KioskCameraDialog,
  RejectedScansAlert,
  ScanFlashOverlay,
  ScanReadyBadge,
  ScanResultCard,
  useKioskController,
} from '@/components/KioskCheckin';
import { unlockAudio } from '@/lib/chime';

const IDLE_MS = 45_000;

export default function KioskStationPage() {
  const k = useKioskController();
  const [openCamera, setOpenCamera] = useState(false);
  const [editStation, setEditStation] = useState(false);
  const [largeText, setLargeText] = useState(false);
  const [idle, setIdle] = useState(false);
  const idleTimer = useRef<ReturnType<typeof setTimeout>>();

  // Attract screen: arm an inactivity timer; any interaction resets it.
  useEffect(() => {
    const arm = () => {
      setIdle(false);
      clearTimeout(idleTimer.current);
      idleTimer.current = setTimeout(() => setIdle(true), IDLE_MS);
    };
    arm();
    const events: Array<keyof WindowEventMap> = ['pointerdown', 'keydown', 'touchstart'];
    for (const ev of events) window.addEventListener(ev, arm);
    return () => {
      clearTimeout(idleTimer.current);
      for (const ev of events) window.removeEventListener(ev, arm);
    };
  }, []);

  // A result appearing counts as activity (a scan just happened).
  useEffect(() => {
    if (k.result !== null) setIdle(false);
  }, [k.result]);

  // Gap #10: never let a stray tap swallow HID-scanner keystrokes.
  useEffect(() => {
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
  }, [k.inputRef]);

  return (
    <main
      className={`min-h-dvh bg-background p-6 text-foreground md:p-10 ${largeText ? 'text-lg' : ''}`}
      onPointerDown={unlockAudio}
    >
      <ScanFlashOverlay flash={k.flash} />

      {/* Attract screen — dismissed by any interaction. */}
      {idle && k.result === null && (
        <button
          type="button"
          className="fixed inset-0 z-40 grid place-items-center bg-background"
          // pointerdown, not click: the window-level idle-reset listener
          // also fires on pointerdown and unmounts this overlay before a
          // click event could ever be delivered. The deferred focus runs
          // after React removes the overlay so it lands on the input.
          onPointerDown={() => {
            setIdle(false);
            setTimeout(() => k.inputRef.current?.focus(), 0);
          }}
        >
          <span className="flex flex-col items-center gap-6 text-center">
            <span className="grid size-24 animate-pulse place-items-center rounded-3xl bg-primary text-primary-foreground">
              <ScanLine className="size-12" aria-hidden />
            </span>
            <span className="text-4xl font-bold">Scan your ID to check in</span>
            <span className="text-lg text-muted-foreground">
              Hold your ID, QR code or RFID card to the scanner — or tap to type your number.
            </span>
          </span>
        </button>
      )}

      <div className="mx-auto max-w-3xl space-y-6">
        <header className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <span className="grid size-12 place-items-center rounded-xl bg-primary text-primary-foreground">
              <Activity className="size-6" aria-hidden />
            </span>
            <div>
              <h1 className="text-2xl font-semibold tracking-tight">Clinic Check-in</h1>
              <p className="text-sm text-muted-foreground">SYNAPSE self-service station</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Button
              variant={largeText ? 'default' : 'outline'}
              size="icon"
              aria-label={largeText ? 'Use standard text size' : 'Use larger text size'}
              aria-pressed={largeText}
              onClick={() => setLargeText((v) => !v)}
            >
              <ZoomIn />
            </Button>
            <Button variant="ghost" size="sm" asChild>
              <Link to="/kiosk">Exit station mode</Link>
            </Button>
          </div>
        </header>

        <section className="space-y-4 rounded-2xl border bg-card p-6 shadow-sm">
          <div className="flex items-center justify-between">
            <Label htmlFor="station-id" className={largeText ? 'text-lg' : ''}>
              Student / Employee ID
            </Label>
            <ScanReadyBadge focused={k.focused} />
          </div>
          <div className="flex gap-3">
            <Input
              id="station-id"
              ref={k.inputRef}
              autoComplete="off"
              placeholder="Scan or type your ID…"
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
              className="h-16 text-2xl md:h-16 md:text-2xl"
            />
            <Button
              className="h-16 px-6 text-lg"
              onClick={k.submit}
              disabled={k.scanPending || k.identifier.trim() === ''}
            >
              {k.scanPending ? <Loader2 className="animate-spin" /> : <ScanLine />}
              Check in
            </Button>
          </div>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <Button variant="outline" onClick={() => setOpenCamera(true)}>
              <Camera /> Scan QR with camera
            </Button>
            <div className="flex items-center gap-2 text-xs text-muted-foreground">
              {k.pending > 0 && (
                <span className="flex items-center gap-1.5">
                  <CloudOff className="size-3.5" /> {k.pending} offline — auto-syncs
                </span>
              )}
              {editStation ? (
                <span className="flex items-center gap-2">
                  <Input
                    aria-label="Station id"
                    maxLength={64}
                    value={k.station}
                    onChange={(e) => k.setStation(e.target.value)}
                    className="h-8 w-36 md:h-8"
                  />
                  <Button size="sm" variant="outline" onClick={() => setEditStation(false)}>Done</Button>
                </span>
              ) : (
                <span className="flex items-center gap-1">
                  <Badge variant="secondary" className="font-mono">{k.station}</Badge>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Edit station id"
                    onClick={() => setEditStation(true)}
                  >
                    <Settings2 />
                  </Button>
                </span>
              )}
            </div>
          </div>
          <RejectedScansAlert rejected={k.rejected} onDismiss={k.dismissRejected} />
        </section>

        <ScanResultCard result={k.result} secondsLeft={k.secondsLeft} large />
      </div>

      <Dialog open={openCamera} onOpenChange={setOpenCamera}>
        {openCamera && (
          <KioskCameraDialog
            onClose={() => setOpenCamera(false)}
            onDecoded={(value) => k.submitIdentifier(value, 'qr')}
          />
        )}
      </Dialog>
    </main>
  );
}
