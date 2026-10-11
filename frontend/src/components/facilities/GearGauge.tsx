/**
 * GearGauge — the drum card's status graphic: a 3D mechanical gear that
 * doubles as a gauge. It turns while the batch is processing, slows and
 * turns amber when turning is overdue, sits still and grey while the drum
 * is waiting for waste, and wears a bezel arc showing decomposition
 * progress. What each state means is decided in `lib/gearModel`.
 *
 * Rendering is delegated to a lazily-loaded, shared three.js renderer
 * (see `gear/gearRenderer`), so Facilities does not pay for three.js up
 * front. Until the first frame — and permanently if WebGL is unavailable —
 * the original drum artwork stays on screen, so the card never has a hole
 * in it.
 *
 * Decorative for assistive tech: the card's link already carries a full
 * accessible name, so this is `aria-hidden`; sighted users get the
 * explanation as a tooltip.
 */
import { useEffect, useRef, useState } from 'react';

import { DrumArt } from '@/components/facilities/DrumArt';
import type { GearHandle } from '@/components/facilities/gear/gearRenderer';
import type { GearVisual } from '@/lib/gearModel';
import { cn } from '@/lib/utils';

export interface GearGaugeProps {
  visual: GearVisual;
  /** Edge length in CSS px. */
  size?: number;
  /** Offsets the starting angle so neighbouring gears are not in lockstep. */
  seed?: number;
  className?: string;
}

export function GearGauge({ visual, size = 64, seed = 0, className }: GearGaugeProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const handleRef = useRef<GearHandle | null>(null);
  // Latest visual, readable from the async attach without re-running it.
  const visualRef = useRef(visual);
  visualRef.current = visual;
  const [ready, setReady] = useState(false);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (canvas === null) return undefined;

    let cancelled = false;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(size * dpr);
    canvas.height = Math.round(size * dpr);

    // The whole card is the interactive surface; speed the gear up while
    // it is hovered or keyboard-focused.
    const host = canvas.closest<HTMLElement>('a, button, [data-gear-host]') ?? canvas;
    const boostOn = () => handleRef.current?.setBoost(true);
    const boostOff = () => handleRef.current?.setBoost(false);
    host.addEventListener('pointerenter', boostOn);
    host.addEventListener('pointerleave', boostOff);
    host.addEventListener('focusin', boostOn);
    host.addEventListener('focusout', boostOff);

    void import('@/components/facilities/gear/gearRenderer')
      .then(({ attachGear }) => {
        if (cancelled) return;
        const handle = attachGear(canvas, visualRef.current, {
          seed,
          onReady: () => setReady(true),
          onFail: () => setFailed(true),
        });
        if (handle === null) {
          setFailed(true);
          return;
        }
        handleRef.current = handle;
      })
      // A chunk that fails to load must degrade to the static drum art,
      // not throw into the card.
      .catch(() => {
        if (!cancelled) setFailed(true);
      });

    return () => {
      cancelled = true;
      host.removeEventListener('pointerenter', boostOn);
      host.removeEventListener('pointerleave', boostOff);
      host.removeEventListener('focusin', boostOn);
      host.removeEventListener('focusout', boostOff);
      handleRef.current?.dispose();
      handleRef.current = null;
    };
  }, [size, seed]);

  // `describeGear` returns a fresh object per render, so key the push on
  // its primitives to avoid repainting every gear on every parent render.
  const { mode, speed, progress, label } = visual;
  useEffect(() => {
    handleRef.current?.update({ mode, speed, progress, label });
  }, [mode, speed, progress, label]);

  return (
    <div
      aria-hidden
      title={visual.label}
      className={cn('relative shrink-0', className)}
      style={{ width: size, height: size }}
    >
      {(!ready || failed) && (
        <div className="absolute inset-0 grid place-items-center">
          <DrumArt className="h-[85%] w-auto" />
        </div>
      )}
      {!failed && (
        <canvas
          ref={canvasRef}
          className={cn(
            'absolute inset-0 size-full transition-opacity duration-300',
            ready ? 'opacity-100' : 'opacity-0',
          )}
        />
      )}
    </div>
  );
}
