/**
 * QueueDisplayPage — PUBLIC waiting-room board (Phase 14; kiosk gap
 * analysis July 2026).
 *
 * Meant for a lobby TV / kiosk: no auth, polls the public queue-state
 * endpoint every 5s. Shows the "now serving" entry plus the waiting
 * list — minimum disclosure (queue number, full name in `Last, First`
 * format, and school id) so patients in the waiting room can identify
 * themselves when their number is called.
 *
 * Gap #12: when the "now serving" entry changes, the hero panel
 * flashes and (once the operator enables sound — browsers require a
 * gesture) an audible chime plays so called patients get a cue beyond
 * the screen.
 *
 * Design — compact and glanceable: hero is mid-weight, the queue rows
 * are short cards with C-XXX, full name, and school id only.
 */
import { useEffect, useRef, useState } from 'react';
import { formatQueueNumber } from '@/components/KioskCheckin';
import { usePublicQueueState } from '@/hooks/useQueue';
import { loadKioskSettings } from '@/lib/kioskSettings';
import { playConfiguredChime } from '@/lib/chime';

function formatClock(d: Date): string {
  const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  let h = d.getHours();
  const m = d.getMinutes().toString().padStart(2, '0');
  const s = d.getSeconds().toString().padStart(2, '0');
  const ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  return `${days[d.getDay()]}, ${months[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()} · ${h}:${m}:${s} ${ampm}`;
}

export default function QueueDisplayPage() {
  const state = usePublicQueueState();
  const data = state.data;

  const [calledFlash, setCalledFlash] = useState(false);
  const [now, setNow] = useState(() => new Date());
  const lastServing = useRef<number | null>(null);

  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(id);
  }, []);

  // Gap #12: chime + flash when the "now serving" position changes.
  useEffect(() => {
    const position = data?.now_serving?.position ?? null;
    if (position !== null && lastServing.current !== null && position !== lastServing.current) {
      playConfiguredChime(loadKioskSettings(), formatQueueNumber(position));
      setCalledFlash(true);
      const t = setTimeout(() => setCalledFlash(false), 2500);
      return () => clearTimeout(t);
    }
    lastServing.current = position;
  }, [data?.now_serving?.position]);

  // Track the latest position even when the effect above early-returns.
  useEffect(() => {
    lastServing.current = data?.now_serving?.position ?? null;
  }, [data?.now_serving?.position]);

  return (
    <main className="flex min-h-dvh flex-col bg-background p-4 text-foreground md:p-6">
      <header className="mb-6 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <img src="/favicon-192.png" alt="SYNAPSE" className="size-14 object-contain [mix-blend-mode:multiply] md:size-16" />
          <div className="leading-tight">
            <h1 className="font-serif text-2xl font-bold tracking-tight md:text-3xl">SYNAPSE Clinic Queue</h1>
            <p className="font-serif text-[0.65rem] font-semibold uppercase tracking-[0.35em] text-muted-foreground md:text-[0.7rem]">
              Foundation&nbsp;University
            </p>
          </div>
        </div>
        <div aria-label="Current date and time" className="text-right font-mono text-sm tabular-nums text-foreground md:text-base">
          {formatClock(now)}
        </div>
      </header>

      {state.isError && (
        <p role="alert" className="rounded-lg border border-destructive/50 p-4 text-center text-destructive">
          Queue board temporarily unavailable. Retrying…
        </p>
      )}

      {data !== undefined && (
        <div className="flex flex-1 flex-col gap-4 lg:flex-row">
          {/* Left column — NOW SERVING sits at its natural default height
              and any future content (image carousels, announcements, etc.)
              stacks below it in this wrapper. `self-start` keeps the
              column compact so it never stretches to match the right
              panel's height. */}
          <div className="flex w-full flex-col gap-4 self-start lg:w-2/3">
            <section
              aria-label="Now serving"
              className={`flex flex-col items-center justify-center rounded-2xl border p-6 text-center shadow-sm transition-colors duration-500 md:p-8 ${
                calledFlash ? 'border-primary bg-primary/10' : 'bg-card'
              }`}
            >
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground md:text-sm">Now serving</p>
              {data.now_serving !== null ? (
                <>
                  <p
                    aria-label={`Now serving ${formatQueueNumber(data.now_serving.position)}`}
                    className="mt-3 font-mono font-bold leading-none tracking-tight text-primary tabular-nums text-6xl md:text-7xl"
                  >
                    {formatQueueNumber(data.now_serving.position)}
                  </p>
                  <p className="mt-3 text-xl font-semibold text-foreground md:text-2xl">
                    {data.now_serving.display_name}
                  </p>
                  <p className="mt-0.5 font-mono text-sm text-muted-foreground md:text-base">
                    {data.now_serving.patient_school_id}
                  </p>
                </>
              ) : (
                <p className="mt-6 text-2xl text-muted-foreground md:text-3xl">No one in service.</p>
              )}
            </section>
          </div>

          {/* Up next — right column that fills the remaining vertical
             space (flex-1 makes it stretch the full row height). The
             inner `flex flex-col` keeps the queue cards pinned to the
             top so empty space accumulates at the bottom, matching the
             user's design. */}
          <section
            aria-label="Waiting"
            className="flex min-h-[300px] w-full flex-col rounded-2xl border bg-card p-4 shadow-sm md:p-5 lg:w-1/3 lg:flex-1"
          >
            <p className="mb-3 text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground md:text-sm">
              Up next ({data.waiting.length})
            </p>
            {data.waiting.length === 0 ? (
              <p className="flex-1 text-base text-muted-foreground md:text-lg">No one waiting.</p>
            ) : (
              <ul className="flex-1 space-y-2 overflow-auto">
                {data.waiting.map((w) => (
                  <li
                    key={w.position}
                    className="flex items-center gap-3 rounded-xl bg-muted/60 px-3 py-2"
                  >
                    <span
                      aria-label={formatQueueNumber(w.position)}
                      className="grid shrink-0 place-items-center rounded-lg bg-background px-2 py-1 font-mono text-lg font-bold tabular-nums text-primary md:text-xl"
                    >
                      {formatQueueNumber(w.position)}
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-base font-medium text-foreground md:text-lg">
                        {w.display_name}
                      </p>
                      <p className="truncate font-mono text-xs text-muted-foreground md:text-sm">
                        {w.patient_school_id}
                      </p>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      )}
    </main>
  );
}