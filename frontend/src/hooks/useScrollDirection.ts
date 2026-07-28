/**
 * useScrollDirection — tracks vertical scroll on `window` and reports
 * whether the user is scrolling up, down, or idle. Used by the mobile
 * topbar to auto-hide on scroll-down (and reappear on scroll-up),
 * which is the common "scroll-direction-aware chrome" pattern.
 *
 * The hook reports the most recent meaningful direction. A small
 * delta threshold (~4px) suppresses jitter from touchpads and inertial
 * scrolls; the state is left in place for `resetMs` after the last
 * motion so a momentary pause doesn't snap the bar back into view.
 *
 * `atTopPx` (default 8) snaps the direction to 'up' whenever the page
 * is scrolled near the top — so the chrome is always visible when the
 * user lands on a page, regardless of the last scroll direction.
 */
import { useEffect, useState } from 'react';

export type ScrollDirection = 'up' | 'down' | 'idle';

interface Options {
  /** Min absolute pixel delta to count as motion. Default 4. */
  threshold?: number;
  /** Idle delay before flipping to 'idle'. Default 120ms. */
  resetMs?: number;
  /** Force 'up' (=> bar visible) when scrollY < this many px. Default 8. */
  atTopPx?: number;
}

export function useScrollDirection(options: Options = {}): ScrollDirection {
  const { threshold = 4, resetMs = 120, atTopPx = 8 } = options;
  const [direction, setDirection] = useState<ScrollDirection>('idle');

  useEffect(() => {
    if (typeof window === 'undefined') return;

    let lastY = window.scrollY;
    let resetTimer: number | null = null;
    let active = true;

    const onScroll = () => {
      const y = window.scrollY;

      // Always show the bar when near the top of the page.
      if (y < atTopPx) {
        if (active) setDirection('up');
        lastY = y;
        if (resetTimer !== null) window.clearTimeout(resetTimer);
        return;
      }

      const delta = y - lastY;

      if (Math.abs(delta) >= threshold) {
        if (active) {
          setDirection(delta > 0 ? 'down' : 'up');
        }
        lastY = y;
      }

      if (resetTimer !== null) window.clearTimeout(resetTimer);
      resetTimer = window.setTimeout(() => {
        if (active) setDirection('idle');
      }, resetMs);
    };

    window.addEventListener('scroll', onScroll, { passive: true });
    return () => {
      active = false;
      window.removeEventListener('scroll', onScroll);
      if (resetTimer !== null) window.clearTimeout(resetTimer);
    };
  }, [threshold, resetMs, atTopPx]);

  return direction;
}
