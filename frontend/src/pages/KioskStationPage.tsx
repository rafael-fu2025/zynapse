/**
 * KioskStationPage — fullscreen locked-down check-in station (kiosk
 * gap analysis #6). Rendered OUTSIDE the staff shell: no sidebar, no
 * topbar, no notifications — just the scan surface, sized for lobby
 * touch hardware. Auth still applies (the station runs under a
 * dedicated staff login with `clinic.checkin.record`).
 *
 * Flow (2026-08):
 *   1. TWO separate entry fields:
 *        - ID — scan / type a Student / Employee ID (registered fast path).
 *        - Name — "Last name, First name" with an inline autocomplete;
 *          the last suggestion is "Check in without an account" for
 *          guests (no account / patient record).
 *   2. Purpose cards — one tap picks the reason; the last card is
 *      "Other" with a free-text box. A purpose is REQUIRED to check in.
 *   3. Check in submits the resolved patient's school id (or guest name)
 *      + purpose; success shows the queue number modal.
 *
 * Kiosk-mode behaviors kept: attract/idle screen + global refocus so the
 * HID scanner never loses the ID field. No check-in trail here — the
 * station screen is bystander-readable, so results only show in the
 * auto-clearing queue modal.
 */
import { Activity, CloudOff, Loader2, ScanLine } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  DuplicateResultDialog,
  QueueAssignmentDialog,
  RejectedScansAlert,
  ScanErrorBanner,
  ScanFlashOverlay,
  hasQueueAssignment,
  useKioskController,
} from '@/components/KioskCheckin';
import { usePatientLookup, type KioskLookupResult } from '@/hooks/usePatientLookup';
import { cn } from '@/lib/utils';
import { unlockAudio } from '@/lib/chime';

const PURPOSE_OPTIONS = [
  'Consultation',
  'Medical Certificate',
  'Dental',
  'Physical Exam',
  'Vaccination',
  'Laboratory',
  'Pharmacy',
  'Injury',
] as const;

function useDebounced<T>(value: T, ms: number): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

export default function KioskStationPage() {
  const k = useKioskController();
  const nameInputRef = useRef<HTMLInputElement>(null);
  // In-flight guard: blocks a rapid double-trigger (double-tap, or Enter
  // followed by a click) from submitting the same check-in twice. Released
  // once the mutation settles (scanPending flips back to false).
  const submitLockRef = useRef(false);
  // Any open dialog (queue number OR duplicate notice) suspends the global
  // refocus handler so the dialog's focus trap wins.
  const modalOpen =
    hasQueueAssignment(k.result) ||
    (k.result !== null && k.result.outcome === 'duplicate');

  // ID combobox state — the ID field drives the autocomplete (name or number).
  const debouncedId = useDebounced(k.identifier, 300);
  const lookup = usePatientLookup(debouncedId);
  const [suggestOpen, setSuggestOpen] = useState(false);
  const [otherOpen, setOtherOpen] = useState(false);
  // Guest name entry — the name field is a plain underline (letters only).
  const [nameValue, setNameValue] = useState('');

  // Attract/idle screen removed (2026-08) — the station stays live so
  // the ID field never loses scanner focus.

  // After a successful check-in the controller resets identifier +
  // resolvedPatient; close the Other box and the name field too.
  useEffect(() => {
    if (k.identifier === '' && k.resolvedPatient === null) {
      setOtherOpen(false);
      setNameValue('');
    }
  }, [k.identifier, k.resolvedPatient]);

  // A completed scan (queue, duplicate, appointment…) clears the guest
  // name field too — the controller resets identifier/resolvedPatient/
  // purpose, but nameValue is page-local and would otherwise linger for
  // the next kiosk user (name-field guest check-ins don't change those
  // values, so the reset effect above never fires for them).
  useEffect(() => {
    if (k.result !== null) setNameValue('');
  }, [k.result]);

  // Release the check-in lock once the in-flight mutation settles
  // (success or error), so a corrected retry is allowed.
  useEffect(() => {
    if (!k.scanPending) submitLockRef.current = false;
  }, [k.scanPending]);

  // Gap #10: never let a stray tap swallow HID-scanner keystrokes.
  // Skip while the queue-assignment dialog is open so its focus trap wins.
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

  const suggestionsVisible =
    suggestOpen && k.resolvedPatient === null && debouncedId.trim().length >= 2;

  const isNumericId = /^\d+$/.test(k.identifier.trim());
  const patientReady =
    k.resolvedPatient !== null || isNumericId || nameValue.trim() !== '';
  const canCheckIn = patientReady && k.purpose.trim() !== '' && !k.scanPending;

  function handleIdChange(v: string): void {
    // ID field accepts integers only; the combobox then matches by number.
    const clean = v.replace(/\D/g, '').slice(0, 20);
    k.setIdentifier(clean);
    if (k.resolvedPatient !== null) k.setResolvedPatient(null);
  }

  function handleNameChange(v: string): void {
    // Guest name only — letters, spaces, and the comma for "Last, First".
    const clean = v.replace(/[^A-Za-z ,]/g, '').slice(0, 60);
    setNameValue(clean);
    if (k.resolvedPatient !== null) k.setResolvedPatient(null);
  }

  function selectPatient(p: KioskLookupResult): void {
    k.setResolvedPatient({ id: p.id, kind: p.kind, name: p.name, schoolId: p.school_id });
    // Populate BOTH fields with the chosen match: ID number + name.
    k.setIdentifier(p.school_id);
    setNameValue(p.name);
    setSuggestOpen(false);
  }

  function selectGuest(name: string): void {
    k.setResolvedPatient({ id: 0, kind: 'guest', name, schoolId: null });
    k.setIdentifier('');
    setNameValue(name);
    setSuggestOpen(false);
  }

  function handleCheckIn(): void {
    if (submitLockRef.current || k.scanPending) return;
    // 1) A name/ID was picked from the ID autocomplete (registered or guest).
    if (k.resolvedPatient !== null) {
      submitLockRef.current = true;
      k.submit();
      return;
    }
    // 2) The ID field holds a plain numeric ID — registered fast path.
    if (isNumericId) {
      submitLockRef.current = true;
      k.submit();
      return;
    }
    // 3) The name field holds a guest name (no account / patient record).
    if (nameValue.trim() !== '') {
      submitLockRef.current = true;
      k.submitGuest(nameValue.trim(), k.purpose);
    }
  }

  function handleIdEnter(): void {
    const top = lookup.data?.[0];
    if (suggestionsVisible && top !== undefined) {
      selectPatient(top);
      return;
    }
    if (canCheckIn) handleCheckIn();
  }

  return (
    <main
      className="min-h-dvh bg-background p-6 text-foreground md:p-10"
      onPointerDown={unlockAudio}
    >
      <ScanFlashOverlay flash={k.flash} />

      <div className="mx-auto max-w-3xl space-y-6">
        <header className="flex items-center gap-3">
          <span className="grid size-12 place-items-center rounded-xl bg-primary text-primary-foreground">
            <Activity className="size-6" aria-hidden />
          </span>
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">Clinic Check-in</h1>
            <p className="text-sm text-muted-foreground">SYNAPSE self-service station</p>
          </div>
        </header>

        <section className="space-y-4 rounded-2xl border bg-card p-6 shadow-sm">
          {/* ID + Name in one row — ID keeps a maroon outline; the name
              field is underline-only (no container). Both normal size. */}
          {/* ID (combobox) + Name (guest) in one row — the autocomplete
              lives on the ID field; the name field is a plain underline
              for walk-in guests. */}
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div className="relative w-full sm:w-44">
              <Input
                id="station-id"
                ref={k.inputRef}
                autoComplete="off"
                role="combobox"
                aria-expanded={suggestionsVisible}
                aria-controls="station-suggestions"
                placeholder="ID Number"
                value={k.identifier}
                onChange={(e) => handleIdChange(e.target.value)}
                onFocus={() => setSuggestOpen(true)}
                onBlur={() => setSuggestOpen(false)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    handleIdEnter();
                  } else if (e.key === 'Escape') {
                    setSuggestOpen(false);
                  }
                }}
                aria-invalid={k.scanError !== null}
                aria-describedby={k.scanError !== null ? 'station-id-error' : undefined}
                className="border-primary focus-visible:border-primary focus-visible:ring-primary"
              />
              {suggestionsVisible && (
                <ul
                  id="station-suggestions"
                  role="listbox"
                  className="absolute left-0 top-full z-20 mt-1 max-h-72 w-96 overflow-auto rounded-xl border bg-popover shadow-lg"
                >
                  {lookup.isLoading && (
                    <li className="flex items-center gap-2 px-4 py-4 text-sm text-muted-foreground">
                      <Loader2 className="size-4 animate-spin" /> Searching…
                    </li>
                  )}
                  {lookup.isError && !lookup.isLoading && (
                    <li className="px-4 py-4 text-sm text-destructive">
                      Could not search. Please try again.
                    </li>
                  )}
                  {lookup.isSuccess && (lookup.data?.length ?? 0) === 0 && (
                    <li className="px-4 py-4 text-sm text-muted-foreground">
                      No matches for “{debouncedId}”. Try the last name, or scan your ID.
                    </li>
                  )}
                  {(lookup.data ?? []).map((p) => {
                    return (
                      <li key={p.id}>
                        <button
                          type="button"
                          role="option"
                          aria-selected="false"
                          onMouseDown={(e) => e.preventDefault()}
                          onClick={() => selectPatient(p)}
                          className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-accent"
                        >
                          <span className="min-w-0 flex-1">
                            <span className="block truncate text-base font-medium">{p.name}</span>
                            <span className="block font-mono text-xs text-muted-foreground">
                              {p.school_id}
                            </span>
                          </span>
                          <Badge variant={p.kind === 'student' ? 'info' : 'secondary'}>
                            {p.kind === 'student' ? 'Student' : 'Employee'}
                          </Badge>
                        </button>
                      </li>
                    );
                  })}
                  {/* Guest walk-in — no account / patient record. */}
                  <li>
                    <button
                      type="button"
                      role="option"
                      aria-selected="false"
                      onMouseDown={(e) => e.preventDefault()}
                      onClick={() => selectGuest(debouncedId)}
                      className="flex w-full items-center gap-3 border-t px-4 py-3 text-left transition-colors hover:bg-accent"
                    >
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-base font-medium">Check in without an account</span>
                        <span className="block truncate text-xs text-muted-foreground">
                          as “{debouncedId}”
                        </span>
                      </span>
                      <Badge variant="outline">Guest</Badge>
                    </button>
                  </li>
                </ul>
              )}
            </div>

            {/* Guest name entry — plain underline, letters only. */}
            <Input
              id="station-name"
              ref={nameInputRef}
              autoComplete="off"
              value={nameValue}
              onChange={(e) => handleNameChange(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  if (canCheckIn) handleCheckIn();
                }
              }}
              className="rounded-none border-0 border-b-2 border-primary bg-transparent px-0 shadow-none focus-visible:border-primary focus-visible:ring-0 sm:flex-1"
            />
          </div>

          {/* Purpose cards — one tap, plus "Other" for a custom reason. */}
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
              {PURPOSE_OPTIONS.map((p) => (
                <button
                  key={p}
                  type="button"
                  onClick={() => {
                    k.setPurpose(p);
                    setOtherOpen(false);
                  }}
                  aria-pressed={!otherOpen && k.purpose === p}
                  className={cn(
                    'rounded-xl border px-3 py-3 text-center text-sm font-medium transition-colors',
                    !otherOpen && k.purpose === p
                      ? 'border-primary bg-primary text-primary-foreground shadow'
                      : 'border-input bg-background hover:border-primary hover:bg-accent hover:text-accent-foreground',
                  )}
                >
                  {p}
                </button>
              ))}
              <button
                type="button"
                onClick={() => {
                  setOtherOpen(true);
                  k.setPurpose('');
                }}
                aria-pressed={otherOpen}
                className={cn(
                  'rounded-xl border px-3 py-3 text-center text-sm font-medium transition-colors',
                  otherOpen
                    ? 'border-primary bg-primary text-primary-foreground shadow'
                    : 'border-input bg-background hover:border-primary hover:bg-accent hover:text-accent-foreground',
                )}
              >
                Other
              </button>
            </div>
            {otherOpen && (
              <Input
                aria-label="Custom check-in purpose"
                placeholder="Type your purpose (e.g. follow-up check-up)"
                value={k.purpose}
                onChange={(e) => k.setPurpose(e.target.value)}
                maxLength={120}
                className="h-12 text-base"
                autoFocus
              />
            )}
          <ScanErrorBanner message={k.scanError} errorId="station-id-error" />

          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-2 text-xs text-muted-foreground">
              {k.pending > 0 && (
                <span className="flex items-center gap-1.5">
                  <CloudOff className="size-3.5" /> {k.pending} offline — auto-syncs
                </span>
              )}
            </div>
            <Button
              className="h-14 px-12 text-lg"
              onClick={handleCheckIn}
              disabled={!canCheckIn}
            >
              {k.scanPending ? <Loader2 className="animate-spin" /> : <ScanLine />}
              Check in
            </Button>
          </div>
          <RejectedScansAlert rejected={k.rejected} onDismiss={k.dismissRejected} />
        </section>
      </div>

      <QueueAssignmentDialog result={k.result} onDone={k.clearResult} />
      <DuplicateResultDialog result={k.result} onDone={k.clearResult} />
    </main>
  );
}
