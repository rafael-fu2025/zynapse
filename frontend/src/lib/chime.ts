/**
 * chime.ts — tiny WebAudio feedback tones (kiosk gap analysis #9/#12).
 *
 * No audio assets: short oscillator envelopes are enough for scan
 * confirmation and queue-call chimes. Browsers gate AudioContext
 * behind a user gesture — call `unlockAudio()` from a click handler
 * (e.g. the queue board's sound toggle) before relying on playback.
 */

let ctx: AudioContext | null = null;

function context(): AudioContext | null {
  try {
    ctx ??= new AudioContext();
    if (ctx.state === 'suspended') void ctx.resume();
    return ctx;
  } catch {
    return null; // audio unavailable (headless / permission) — silent no-op
  }
}

/** Pre-create/resume the context from a user gesture. */
export function unlockAudio(): void {
  void context();
}

export type ChimeKind = 'success' | 'warn' | 'error';

/** Fire-and-forget feedback tone; silently no-ops when audio is unavailable. */
export function playChime(kind: ChimeKind): void {
  const ac = context();
  if (ac === null) return;

  const notes: Array<{ freq: number; at: number; dur: number }> =
    kind === 'success'
      ? [{ freq: 880, at: 0, dur: 0.12 }, { freq: 1318, at: 0.13, dur: 0.18 }]
      : kind === 'warn'
        ? [{ freq: 660, at: 0, dur: 0.2 }]
        : [{ freq: 220, at: 0, dur: 0.16 }, { freq: 175, at: 0.17, dur: 0.24 }];

  for (const n of notes) {
    const osc = ac.createOscillator();
    const gain = ac.createGain();
    osc.type = 'sine';
    osc.frequency.value = n.freq;
    const t0 = ac.currentTime + n.at;
    gain.gain.setValueAtTime(0.0001, t0);
    gain.gain.exponentialRampToValueAtTime(0.25, t0 + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + n.dur);
    osc.connect(gain).connect(ac.destination);
    osc.start(t0);
    osc.stop(t0 + n.dur + 0.05);
  }
}
