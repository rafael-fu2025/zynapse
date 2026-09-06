import { useEffect, useRef, useState } from 'react';
import { MediaPlaylistPanel } from '@/components/MediaPlaylistPanel';
import { useKioskSettings } from '@/hooks/useKioskSettings';
import { usePublicQueueState } from '@/hooks/useQueue';
import { playConfiguredChime, unlockAudio } from '@/lib/chime';
import { cn } from '@/lib/utils';
import type { PublicQueueState } from '@/schemas/queue';

type QueueColumn = PublicQueueState['clinic'];

// Lobby TVs boot unattended and their OS clock/timezone can drift —
// pin the header clock to the clinic's Manila calendar, never the
// browser zone (2026-09 audit).
function formatClock(date: Date): string {
  return new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(date);
}

function NowServingCard({ title, data, flash }: { title: string; data: QueueColumn; flash: boolean }) {
  const active = data.active ?? (data.now_serving !== null ? [data.now_serving] : []);
  return <article aria-label={`${title} now serving`} className={cn('flex h-40 min-h-40 max-h-40 min-w-0 flex-col overflow-hidden rounded-xl border bg-card p-4 text-center shadow-sm transition-colors duration-500 motion-reduce:transition-none', flash && 'border-primary bg-primary/10')}>
    <h3 className="shrink-0 text-sm font-bold uppercase tracking-[0.18em] text-muted-foreground">{title}</h3>
    <div className="flex min-h-0 flex-1 flex-col items-center justify-center overflow-hidden">
      {active.length === 0 ? <p className="text-lg text-muted-foreground">No one in service.</p> : <div className="grid w-full min-h-0 gap-1 overflow-y-auto">{active.map((entry) => <div key={entry.queue_number} className="flex items-center justify-center gap-3 rounded-md bg-muted/40 px-2 py-1"><p aria-label={`Now serving ${entry.queue_number}`} className={cn('font-mono font-bold leading-none text-primary tabular-nums', active.length > 1 ? 'text-2xl' : 'text-5xl xl:text-6xl')}>{entry.queue_number}</p><div className="min-w-0 text-left">{entry.display_name !== undefined && <p className="truncate text-sm font-semibold">{entry.display_name}</p>}{entry.patient_school_id !== undefined && <p className="truncate font-mono text-xs text-muted-foreground">{entry.patient_school_id}</p>}</div></div>)}</div>}
    </div>
  </article>;
}

function WaitingColumn({ title, data, textSize, autoScroll, speed }: { title: string; data: QueueColumn; textSize: 'compact' | 'standard' | 'large'; autoScroll: boolean; speed: number }) {
  const listRef = useRef<HTMLUListElement>(null);
  useEffect(() => {
    if (!autoScroll || data.waiting.length === 0 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    let frame = 0;
    let last = performance.now();
    const tick = (time: number) => {
      const list = listRef.current;
      if (list !== null && !document.hidden && list.scrollHeight > list.clientHeight) {
        list.scrollTop += ((time - last) / 1000) * speed;
        if (list.scrollTop + list.clientHeight >= list.scrollHeight - 1) list.scrollTop = 0;
      }
      last = time;
      frame = requestAnimationFrame(tick);
    };
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [autoScroll, data.waiting.length, speed]);
  const size = textSize === 'large' ? 'text-lg xl:text-xl' : textSize === 'compact' ? 'text-sm' : 'text-base xl:text-lg';
  return <section aria-label={`${title} waiting`} className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
    <div className="mb-3 flex items-center justify-between gap-2 border-b pb-2"><h3 className="font-semibold">{title}</h3><span aria-label={`${data.waiting.length} waiting`} className="rounded-full bg-primary/10 px-2 py-0.5 font-mono text-sm font-bold text-primary">{data.waiting.length}</span></div>
    {data.waiting.length === 0 ? <p className="text-sm text-muted-foreground">No one waiting.</p> : <ul ref={listRef} className="min-h-0 flex-1 space-y-2 overflow-y-auto overflow-x-hidden pr-1 [scrollbar-color:hsl(var(--muted-foreground))_transparent] [scrollbar-width:thin]">
      {data.waiting.map((entry) => <li key={entry.queue_number} className="min-w-0 rounded-lg bg-muted/60 p-2.5">
        <p className={cn('font-mono font-bold text-primary tabular-nums', size)}>{entry.queue_number}</p>{entry.display_name !== undefined && <p className={cn('truncate font-medium', size)}>{entry.display_name}</p>}{entry.patient_school_id !== undefined && <p className="truncate font-mono text-xs text-muted-foreground">{entry.patient_school_id}</p>}
      </li>)}
    </ul>}
  </section>;
}

export default function QueueDisplayPage() {
  const queue = usePublicQueueState();
  const settings = useKioskSettings();
  const [now, setNow] = useState(() => new Date());
  const [flash, setFlash] = useState({ guidance: false, clinic: false });
  const previous = useRef<{ guidance: string | null; clinic: string | null }>({ guidance: null, clinic: null });

  useEffect(() => { const id = window.setInterval(() => setNow(new Date()), 1_000); return () => window.clearInterval(id); }, []);
  // Browsers start AudioContexts SUSPENDED until a user gesture. The
  // board has no sound toggle any more, so any first pointer/key
  // interaction with the board (a technician checking it works)
  // unlocks the chime for the session (2026-09 audit).
  useEffect(() => {
    const unlock = () => unlockAudio();
    window.addEventListener('pointerdown', unlock, { once: true });
    window.addEventListener('keydown', unlock, { once: true });
    return () => {
      window.removeEventListener('pointerdown', unlock);
      window.removeEventListener('keydown', unlock);
    };
  }, []);
  useEffect(() => {
    const timers: number[] = [];
    // Watch the full active set per destination — Guidance can serve
    // several patients at once, and chime-watch on `now_serving` alone
    // missed every call after the first (2026-09 audit).
    (['guidance', 'clinic'] as const).forEach((destination) => {
      const col = queue.data?.[destination];
      const active = col?.active ?? (col?.now_serving !== null && col?.now_serving !== undefined ? [col.now_serving] : []);
      const signature = active.map((e) => e.queue_number).sort().join(',');
      const last = previous.current[destination];
      if (signature !== '' && last !== null && signature !== last) {
        playConfiguredChime(settings, active[active.length - 1]?.queue_number ?? '');
        setFlash((current) => ({ ...current, [destination]: true }));
        timers.push(window.setTimeout(() => setFlash((current) => ({ ...current, [destination]: false })), 2_500));
      }
      previous.current[destination] = signature;
    });
    return () => timers.forEach((timer) => window.clearTimeout(timer));
  }, [queue.data, settings]);

  return <main className="flex min-h-dvh flex-col bg-background p-3 text-foreground lg:h-dvh lg:overflow-hidden lg:p-4">
    <header className="mb-3 flex shrink-0 items-center justify-between gap-4" aria-label="Queue display header">
      <div className="flex min-w-0 items-center gap-3"><img src="/favicon-192.png" alt="SYNAPSE" className="size-12 shrink-0 object-contain [mix-blend-mode:multiply] lg:size-14" /><div className="min-w-0"><h1 className="truncate font-serif text-xl font-bold tracking-tight lg:text-2xl">SYNAPSE Queues</h1><p className="truncate font-serif text-[0.6rem] font-semibold uppercase tracking-[0.3em] text-muted-foreground">Foundation University</p></div></div>
      <time aria-label="Current date and time" className="text-right font-mono text-xs tabular-nums sm:text-sm lg:text-base">{formatClock(now)}</time>
    </header>
    {queue.isError && <p role="alert" className="mb-3 shrink-0 rounded-lg border border-destructive/50 p-3 text-center text-destructive">Queue board temporarily unavailable. Retrying…</p>}
    {queue.data !== undefined && <div className="grid min-h-0 flex-1 gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
      <div className="flex min-h-0 min-w-0 flex-col gap-3 overflow-hidden">
        <section aria-labelledby="now-serving-heading" className="h-96 min-h-96 max-h-96 shrink-0 overflow-hidden rounded-2xl border bg-muted/20 p-3 sm:h-[13.25rem] sm:min-h-[13.25rem] sm:max-h-[13.25rem]"><h2 id="now-serving-heading" className="mb-2 h-5 shrink-0 text-center text-sm font-bold uppercase tracking-[0.2em] text-muted-foreground">Now Serving</h2><div className="grid grid-cols-1 gap-3 sm:grid-cols-2"><NowServingCard title="Guidance" data={queue.data.guidance} flash={flash.guidance} /><NowServingCard title="Clinic" data={queue.data.clinic} flash={flash.clinic} /></div></section>
        <MediaPlaylistPanel settings={settings} fillAvailable />
      </div>
      <aside aria-labelledby="waiting-list-heading" className="flex min-h-[28rem] min-w-0 flex-col rounded-2xl border bg-card p-4 shadow-sm lg:min-h-0 lg:overflow-hidden">
        <h2 id="waiting-list-heading" className="mb-4 shrink-0 text-lg font-bold uppercase tracking-[0.16em]">Waiting List</h2>
        <div className="grid min-h-0 flex-1 grid-cols-2 gap-3 overflow-hidden"><WaitingColumn title="Guidance" data={queue.data.guidance} textSize={settings.display.waitingTextSize} autoScroll={settings.display.autoScroll} speed={settings.display.autoScrollSpeed} /><WaitingColumn title="Clinic" data={queue.data.clinic} textSize={settings.display.waitingTextSize} autoScroll={settings.display.autoScroll} speed={settings.display.autoScrollSpeed} /></div>
      </aside>
    </div>}
  </main>;
}
