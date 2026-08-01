/**
 * QueueDisplayPage — PUBLIC waiting-room board (Phase 14; kiosk gap
 * analysis July 2026).
 *
 * Meant for a lobby TV / kiosk: no auth, polls the public queue-state
 * endpoint every 5s. Shows only the "now serving" position + first
 * name and the waiting list — the minimum-disclosure contract.
 *
 * Gap #12: when the "now serving" entry changes, the hero panel
 * flashes and (once the operator enables sound — browsers require a
 * gesture) an audible chime plays so called patients get a cue beyond
 * the screen. Gap #3: each waiting row carries an indicative wait.
 */
import { Activity, Loader2, Volume2, VolumeX } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { usePublicQueueState } from '@/hooks/useQueue';
import { playChime, unlockAudio } from '@/lib/chime';

export default function QueueDisplayPage() {
  const state = usePublicQueueState();
  const data = state.data;

  const [sound, setSound] = useState(false);
  const [calledFlash, setCalledFlash] = useState(false);
  const lastServing = useRef<number | null>(null);
  const soundRef = useRef(sound);
  soundRef.current = sound;

  // Gap #12: chime + flash when the "now serving" position changes.
  useEffect(() => {
    const position = data?.now_serving?.position ?? null;
    if (position !== null && lastServing.current !== null && position !== lastServing.current) {
      if (soundRef.current) playChime('success');
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
    <main className="min-h-dvh bg-background p-8 text-foreground">
      <header className="mb-8 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <span className="grid size-12 place-items-center rounded-xl bg-primary text-primary-foreground">
            <Activity className="size-6" aria-hidden />
          </span>
          <div>
            <h1 className="text-3xl font-semibold tracking-tight">Clinic Queue</h1>
            <p className="text-sm text-muted-foreground">SYNAPSE — please watch for your number.</p>
          </div>
        </div>
        <div className="flex items-center gap-3">
          {state.isFetching && <Loader2 className="size-5 animate-spin text-muted-foreground" aria-label="Refreshing" />}
          <Button
            variant={sound ? 'default' : 'outline'}
            size="icon"
            aria-label={sound ? 'Mute call chime' : 'Enable call chime'}
            aria-pressed={sound}
            onClick={() => {
              unlockAudio();
              setSound((v) => !v);
            }}
          >
            {sound ? <Volume2 /> : <VolumeX />}
          </Button>
        </div>
      </header>

      {state.isError && (
        <p role="alert" className="rounded-lg border border-destructive/50 p-6 text-center text-destructive">
          Queue board temporarily unavailable. Retrying…
        </p>
      )}

      {data !== undefined && (
        <div className="grid gap-8 lg:grid-cols-3">
          {/* Now serving — the hero panel; flashes on a new call. */}
          <section
            aria-label="Now serving"
            className={`flex flex-col items-center justify-center rounded-3xl border p-10 text-center shadow-sm transition-colors duration-500 lg:col-span-2 ${
              calledFlash ? 'border-primary bg-primary/10' : 'bg-card'
            }`}
          >
            <p className="text-sm font-semibold uppercase tracking-[0.2em] text-muted-foreground">Now serving</p>
            {data.now_serving !== null ? (
              <>
                <p className="mt-4 font-mono text-8xl font-bold text-primary tabular-nums">
                  {String(data.now_serving.position).padStart(2, '0')}
                </p>
                <p className="mt-2 text-2xl font-medium">{data.now_serving.display_name}</p>
              </>
            ) : (
              <p className="mt-6 text-3xl text-muted-foreground">— waiting —</p>
            )}
          </section>

          {/* Up next. */}
          <section aria-label="Waiting" className="rounded-3xl border bg-card p-6 shadow-sm">
            <p className="mb-4 text-sm font-semibold uppercase tracking-[0.2em] text-muted-foreground">
              Up next ({data.waiting.length})
            </p>
            {data.waiting.length === 0 ? (
              <p className="text-muted-foreground">No one waiting.</p>
            ) : (
              <ul className="space-y-2">
                {data.waiting.map((w) => (
                  <li
                    key={w.position}
                    className="flex items-center gap-3 rounded-xl bg-muted/50 px-4 py-3"
                  >
                    <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-background font-mono text-lg font-semibold tabular-nums">
                      {w.position}
                    </span>
                    <span className="min-w-0 flex-1 truncate text-lg">{w.display_name}</span>
                    {w.est_wait_minutes !== undefined && (
                      <span className="shrink-0 text-sm tabular-nums text-muted-foreground">
                        ~{w.est_wait_minutes} min
                      </span>
                    )}
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
