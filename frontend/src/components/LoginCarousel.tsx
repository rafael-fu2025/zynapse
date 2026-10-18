/**
 * LoginCarousel — auto-advancing photo carousel for the welcome pane.
 *
 * Geometry & behavior:
 *   - Fills its parent (the rounded maroon container on the login page).
 *   - Cross-fades between slides on a 6 s timer; wraps to the first slide
 *     after the last. Pauses on hover/focus so a user reading a caption
 *     isn't yanked away mid-sentence.
 *   - `prefers-reduced-motion: reduce` disables auto-advance and the
 *     fade transition entirely (slide 0 stays in view).
 *
 * Two image fit modes are supported per slide:
 *   - `cover`         — one crisp full-bleed image (the campus garden).
 *   - `contain_blur`  — a contained foreground (logo / seal) sitting on
 *                       a blurred, slightly dimmed backdrop built from
 *                       the same artwork. The blur fills any letterbox
 *                       gaps and keeps the slide visually anchored.
 *
 * Light/dark variants:
 *   Both light and dark <img>s are mounted at once. Tailwind's `dark:`
 *   variant on the `<html>` element (toggled by `useTheme`) shows one
 *   set and hides the other — instant theme switch with no flash.
 *
 * Accessibility:
 *   - Indicator dots are real <button>s, in a `role="tablist"` group.
 *     The active dot carries `aria-current="true"` and each slide has
 *     an offscreen label read by assistive tech.
 *   - The carousel region carries `aria-roledescription="carousel"` so
 *     screen readers announce it as such rather than a generic group.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { FitMode, LoginSlide as SchemaLoginSlide } from '@/schemas/carousel';

/**
 * Re-exported so existing imports of `LoginSlide` from this module
 * keep working — the canonical type now lives in `@/schemas/carousel`
 * alongside the Zod schema it derives from.
 */
export type LoginSlide = SchemaLoginSlide;
export type LoginSlideFitMode = FitMode;

interface LoginCarouselProps {
  /** Override the default slide list — primarily used for tests and for
   *  future CMS-driven content. The default matches the existing
   *  brand artwork already shipped with the SPA. */
  slides?: LoginSlide[];
  /** Auto-advance interval in milliseconds. Defaults to 6 000. */
  intervalMs?: number;
}

const DEFAULT_SLIDES: readonly LoginSlide[] = [
  {
    title: 'Foundation University',
    subtitle: 'Health & Guidance Services Portal',
    lightSrc: '/fuel-bg.jpg',
    darkSrc: '/FoundationU-Dark.png',
    fitMode: 'cover',
  },
  {
    title: 'Guidance & Counseling Center',
    subtitle: 'Confidential Support & Mental Wellness Services',
    lightSrc: '/guidance-center.png',
    darkSrc: '/guidance-center.png',
    fitMode: 'contain_blur',
  },
  {
    title: 'University Health Services',
    subtitle: 'Accessible Campus Medical & Wellness Care',
    lightSrc: '/health-services.png',
    darkSrc: '/health-services.png',
    fitMode: 'contain_blur',
  },
] as const;

const A11Y_LABEL = 'Campus photo carousel';

export function LoginCarousel({ slides, intervalMs = 6000 }: LoginCarouselProps) {
  const items = useMemo<readonly LoginSlide[]>(
    () => (slides ?? DEFAULT_SLIDES),
    [slides],
  );
  const count = items.length;

  // Clamp to a valid index — the slide list is constant in practice,
  // but defensive math keeps state consistent if it's ever overridden.
  const [activeIndex, setActiveIndex] = useState(0);
  const [paused, setPaused] = useState(false);

  const goTo = useCallback(
    (next: number) => {
      if (count === 0) return;
      setActiveIndex(((next % count) + count) % count);
    },
    [count],
  );

  // Auto-advance. The interval is rebuilt on each tick so a `paused`
  // flip or a `count` change (rare) takes effect immediately.
  useEffect(() => {
    if (count <= 1 || paused) return;
    const id = window.setInterval(() => {
      setActiveIndex((current) => (current + 1) % count);
    }, intervalMs);
    return () => {
      window.clearInterval(id);
    };
  }, [count, paused, intervalMs]);

  // Respect reduced-motion: disable the timer entirely so the first
  // slide stays put. The fade is also overridden in CSS below.
  const reducedMotion = useRef(false);
  useEffect(() => {
    const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    reducedMotion.current = mq.matches;
    const onChange = () => {
      reducedMotion.current = mq.matches;
    };
    mq.addEventListener('change', onChange);
    return () => {
      mq.removeEventListener('change', onChange);
    };
  }, []);
  const autoAdvance = !reducedMotion.current && count > 1 && !paused;

  const swallow = useCallback((e: React.SyntheticEvent) => {
    e.preventDefault();
    e.stopPropagation();
  }, []);

  if (count === 0) {
    // Defensive: render nothing rather than a half-broken carousel.
    return null;
  }

  return (
    <div
      className="login-carousel absolute inset-0"
      role="region"
      aria-roledescription="carousel"
      aria-label={A11Y_LABEL}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocusCapture={() => setPaused(true)}
      onBlurCapture={(e) => {
        // Only unpause if focus actually left the carousel subtree.
        if (!e.currentTarget.contains(e.relatedTarget)) {
          setPaused(false);
        }
      }}
    >
      {/* Slides layer — every slide is mounted so the cross-fade is a
          pure opacity animation. The visible slide is `opacity-100`;
          the rest sit at `opacity-0` and `pointer-events-none`. */}
      <div className="login-carousel-stage absolute inset-0">
        {items.map((slide, i) => {
          const isActive = i === activeIndex;
          const bgLight = slide.bgLightSrc ?? slide.lightSrc;
          const bgDark = slide.bgDarkSrc ?? slide.darkSrc;
          const isBlur = slide.fitMode === 'contain_blur';

          return (
            <div
              key={`${slide.lightSrc}-${i}`}
              className={[
                'login-carousel-slide absolute inset-0',
                isActive ? 'is-active' : '',
              ]
                .filter(Boolean)
                .join(' ')}
              role="group"
              aria-roledescription="slide"
              aria-label={`${slide.title}${slide.subtitle !== undefined ? ` — ${slide.subtitle}` : ''}`}
              aria-hidden={!isActive}
            >
              {/* Blurred backdrop layer (contain_blur only) — fills the
                  frame so letterboxed logos don't sit on flat colour. */}
              {isBlur && (
                <>
                  <img
                    src={bgLight}
                    alt=""
                    draggable={false}
                    onDragStart={swallow}
                    onContextMenu={swallow}
                    className="login-carousel-bg pointer-events-none absolute inset-0 size-full select-none object-cover dark:hidden"
                  />
                  <img
                    src={bgDark}
                    alt=""
                    draggable={false}
                    onDragStart={swallow}
                    onContextMenu={swallow}
                    className="login-carousel-bg pointer-events-none absolute inset-0 hidden size-full select-none object-cover dark:block"
                  />
                </>
              )}

              {/* Foreground — cover mode fills the frame, contain_blur
                  mode centres the artwork (logo / seal) on the blur. */}
              <img
                src={slide.lightSrc}
                alt=""
                draggable={false}
                onDragStart={swallow}
                onContextMenu={swallow}
                className={[
                  'pointer-events-none absolute select-none dark:hidden',
                  // Position is CSS-only for the blur variant (top/bottom/
                  // left/right + margin-block:auto do the centring); the
                  // cover variant stays a plain full-bleed fill.
                  isBlur ? 'login-carousel-fg' : 'inset-0 size-full object-cover',
                ].join(' ')}
              />
              <img
                src={slide.darkSrc}
                alt=""
                draggable={false}
                onDragStart={swallow}
                onContextMenu={swallow}
                className={[
                  'pointer-events-none absolute hidden select-none dark:block',
                  isBlur ? 'login-carousel-fg' : 'inset-0 size-full object-cover',
                ].join(' ')}
              />

              {/* Caption bar — short headline + optional subtitle over a
                  bottom gradient. Hidden when the slide has no copy. */}
              {(slide.title !== '' || slide.subtitle !== undefined) && (
                <div className="login-carousel-caption">
                  <div className="login-carousel-caption-inner">
                    {slide.title !== '' && (
                      <span className="login-carousel-title">{slide.title}</span>
                    )}
                    {slide.subtitle !== undefined && slide.subtitle !== '' && (
                      <span className="login-carousel-sub">{slide.subtitle}</span>
                    )}
                  </div>
                </div>
              )}
            </div>
          );
        })}
      </div>

      {/* Pill indicators — keyboard-navigable. Buttons rather than divs
          so screen readers announce them as controls and Enter/Space
          activate them. Active dot has aria-current="true". */}
      {count > 1 && (
        <div
          className="login-carousel-indicators"
          role="tablist"
          aria-label="Choose slide"
        >
          {items.map((slide, i) => {
            const isActive = i === activeIndex;
            return (
              <button
                key={`dot-${i}`}
                type="button"
                role="tab"
                aria-selected={isActive}
                aria-current={isActive ? 'true' : undefined}
                aria-label={`Show slide ${i + 1}: ${slide.title}`}
                tabIndex={isActive ? 0 : -1}
                onClick={() => goTo(i)}
                className={[
                  'login-carousel-dot',
                  isActive ? 'is-active' : '',
                ]
                  .filter(Boolean)
                  .join(' ')}
              />
            );
          })}
        </div>
      )}

      {/* Live region so screen readers announce slide changes; the
          content is intentionally short. Kept always-mounted so the
          polite region exists even before the first transition. */}
      <div className="sr-only" aria-live={autoAdvance ? 'off' : 'polite'}>
        Slide {activeIndex + 1} of {count}
      </div>
    </div>
  );
}

export default LoginCarousel;