/**
 * DateRangePicker — shadcn Popover + Calendar (range mode) with a
 * STRING contract. Drop-in twin of the single-date `DatePicker`,
 * trading one input for a [from, to] range.
 *
 * Both bounds are exchanged as `YYYY-MM-DD` strings (local-date
 * semantics via date-fns) so every Zod schema and API payload stays
 * unchanged. Either side may be `undefined` while the user is still
 * picking; `onChange` is fired with the latest committed pair.
 *
 * The popover body scrolls when the viewport is too short for the
 * two-month calendar + footer rows (2026-09-26). The scroll edges use
 * animated fade masks — content dissolves toward the top/bottom edge
 * proportionally to how much is still off-screen — instead of a hard
 * clip with no hint that more calendar is reachable.
 *
 * NOTE: the Calendar's `autoFocus` was removed for the scroll to work
 * at all — with it set, react-day-picker refocuses a day button on
 * every render, and each `focus()` call yanks the scroll container
 * back to that day (any scroll snapped to 0 within a frame). Radix
 * still moves focus into the popover on open, so keyboard users Tab
 * into the day grid from there.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { addDays, format, isValid, parse, startOfDay } from 'date-fns';
import { Calendar as CalendarIcon } from 'lucide-react';
import type { DateRange } from 'react-day-picker';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { wholeMonthSpanLabel } from '@/utils/date';

const FMT = 'yyyy-MM-dd';
const HUMAN = 'LLL dd, y';

/** Short month names (Jan…Dec) for the month-span selects. */
const MONTH_OPTIONS = Array.from({ length: 12 }, (_, index) => format(new Date(2000, index, 1), 'LLL'));

/**
 * The month-span row renders native selects — the same control
 * react-day-picker uses for its caption dropdowns. Radix selects inside a
 * Radix popover fight over outside-click dismissal (see TimePicker's
 * docblock), and these never need a portal.
 */
const SPAN_SELECT_CLASS =
  'h-7 rounded-md border border-input bg-transparent px-1.5 text-xs text-foreground shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 [color-scheme:light] dark:[color-scheme:dark]';

export interface DateRangeValue {
  start: string;
  end: string;
}

interface DateRangePickerProps {
  id?: string;
  /** Start of the range (`YYYY-MM-DD`). Empty string when unset. */
  start: string;
  /** End of the range (`YYYY-MM-DD`). Empty string when unset. */
  end: string;
  /** Called whenever either bound changes (or both clear). */
  onChange: (value: DateRangeValue) => void;
  onBlur?: () => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  /** First selectable year (default: 5 years ago). */
  fromYear?: number;
  /** Last selectable year (default: 10 years ahead). */
  toYear?: number;
  'aria-invalid'?: boolean;
}

function parseYmd(value: string): Date | undefined {
  if (value === '') return undefined;
  const parsed = parse(value, FMT, new Date());
  return isValid(parsed) ? parsed : undefined;
}

function formatYmd(date: Date | undefined): string {
  return date !== undefined ? format(date, FMT) : '';
}

/** Distance (px) over which content fully dissolves at a scroll edge. */
const FADE_DEPTH = 48;

interface EdgeFades {
  /** 0→1 mask strength at the top edge (grows as content scrolls under it). */
  top: number;
  /** 0→1 mask strength at the bottom edge (fades away as the end scrolls into view). */
  bottom: number;
}

/**
 * Scroll-edge fade strengths for a container, recomputed on scroll and
 * on box resize. Zero on both edges when nothing overflows — the fades
 * must never suggest scrollable content that isn't there.
 */
function useEdgeFades(scrollRef: React.RefObject<HTMLDivElement | null>, active: boolean): { fades: EdgeFades; onScroll: () => void } {
  const [fades, setFades] = useState<EdgeFades>({ top: 0, bottom: 0 });

  const measure = useCallback((): void => {
    const el = scrollRef.current;
    if (el === null) return;
    const remaining = el.scrollHeight - el.clientHeight;
    if (remaining <= 0) {
      setFades({ top: 0, bottom: 0 });
      return;
    }
    setFades({
      top: Math.min(1, el.scrollTop / FADE_DEPTH),
      bottom: Math.min(1, (remaining - el.scrollTop) / FADE_DEPTH),
    });
  }, [scrollRef]);

  // The open flag re-measures after the popover paints (its height
  // depends on Radix's available-height ceiling); the observer keeps
  // the masks honest across viewport changes while it stays open.
  useEffect(() => {
    if (!active) return;
    const frame = requestAnimationFrame(measure);
    const el = scrollRef.current;
    const observer = el !== null && typeof ResizeObserver !== 'undefined' ? new ResizeObserver(measure) : null;
    if (el !== null && observer !== null) observer.observe(el);
    return () => {
      cancelAnimationFrame(frame);
      observer?.disconnect();
    };
  }, [active, measure, scrollRef]);

  return { fades, onScroll: measure };
}

/**
 * Downward page scroll dismisses the popover — a picker anchored to
 * content that has scrolled away is pointing at dates that are no
 * longer on screen (2026-09-26).
 *
 * Mechanics, matching the request:
 *   - one `scroll` listener on `window` in the CAPTURE phase (scroll
 *     does not bubble) while the popover is open, rAF-throttled to one
 *     check per frame;
 *   - scroll events whose target lives INSIDE the panel (month grid,
 *     footer rows) are ignored, so interacting with the picker never
 *     dismisses it;
 *   - downward movement accumulates per scrolling container and trips
 *     at {@link SCROLL_CLOSE_THRESHOLD_PX} (scroll-up resets it);
 *   - tripping closes the Radix state directly — the panel does NOT
 *     vanish instantly, because Radix plays its exit animation before
 *     unmounting. The content lengthens that exit to 200ms with a
 *     small upward lift (see the `data-[state=closed]` classes on the
 *     PopoverContent), so the dismissal reads as a smooth fade-and-
 *     lift. The effect cleanup drops the listener whichever way the
 *     panel closes.
 *
 * NOTE: deliberately NOT a two-phase close (fade via class, then
 * unmount): Radix's own exit animation would replay from opacity 1
 * afterwards and flash the panel back on screen.
 */
const SCROLL_CLOSE_THRESHOLD_PX = 12;

function useCloseOnPageScroll(
  panelRef: React.RefObject<HTMLDivElement | null>,
  open: boolean,
  onClose: () => void,
): void {
  useEffect(() => {
    if (!open) return;

    const startTop = new WeakMap<EventTarget, number>();
    // Baseline the page scroller at open time — a single fast wheel
    // flick can arrive as ONE scroll event, and a lazily-captured
    // baseline would discard that whole movement. The KEY is the
    // document itself: window scrolls target the Document, not
    // `scrollingElement`, and the lookup in `judge` must agree.
    if (document.scrollingElement !== null) {
      startTop.set(document, document.scrollingElement.scrollTop);
    }
    let accruedDown = 0;
    let frame = 0;
    let done = false;

    const judge = (event: Event): void => {
      const target = event.target;
      if (target === null) return;
      // Panel-internal scroll (the month grid / footer rows) — never a
      // dismissal signal.
      if (target instanceof Node && panelRef.current?.contains(target)) return;

      // Element scrollers key on themselves; the document scroll keys
      // on the Document (its `scrollingElement` is a different object).
      const key: EventTarget = target instanceof Element ? target : document;
      const scroller =
        target instanceof Element ? target : document.scrollingElement;
      if (scroller === null) return;

      const top = scroller.scrollTop;
      const baseline = startTop.get(key);
      startTop.set(key, top);
      if (baseline === undefined || top < baseline) {
        // First sight of this container, or the user scrolled up —
        // neither counts toward downward travel.
        accruedDown = 0;
        return;
      }
      accruedDown += top - baseline;
      if (!done && accruedDown > SCROLL_CLOSE_THRESHOLD_PX) {
        done = true;
        onClose();
      }
    };

    const throttled = (event: Event): void => {
      if (frame !== 0) return;
      frame = requestAnimationFrame(() => {
        frame = 0;
        judge(event);
      });
    };

    window.addEventListener('scroll', throttled, { capture: true, passive: true });
    return () => {
      window.removeEventListener('scroll', throttled, { capture: true });
      if (frame !== 0) cancelAnimationFrame(frame);
    };
  }, [open, panelRef, onClose]);
}

export function DateRangePicker({
  id,
  start,
  end,
  onChange,
  onBlur,
  placeholder = 'Pick a date range',
  disabled = false,
  className,
  fromYear,
  toYear,
  'aria-invalid': ariaInvalid,
}: DateRangePickerProps) {
  const [open, setOpen] = useState(false);
  const panelRef = useRef<HTMLDivElement | null>(null);
  const scrollRef = useRef<HTMLDivElement | null>(null);
  useCloseOnPageScroll(
    panelRef,
    open,
    useCallback(() => setOpen(false), []),
  );
  const { fades, onScroll } = useEdgeFades(scrollRef, open);
  const from = parseYmd(start);
  const to = parseYmd(end);
  const now = new Date();
  const startYear = fromYear ?? now.getFullYear() - 5;
  const endYear = toYear ?? now.getFullYear() + 10;

  // Month-span controls: seeded from the active range so they always
  // describe what is on screen; changing one re-materialises a whole-month
  // range (an end month before the start month rolls into the next year —
  // e.g. Nov 2026 → Feb 2027 — never truncated to a single year).
  const spanAnchor = from ?? to ?? now;
  const spanStartMonth = spanAnchor.getMonth() + 1;
  const spanEndMonth = (to ?? spanAnchor).getMonth() + 1;
  const spanYear = spanAnchor.getFullYear();
  const yearOptions = Array.from({ length: endYear - startYear + 1 }, (_, index) => startYear + index);

  function commitMonthSpan(nextStartMonth: number, nextEndMonth: number, nextYear: number): void {
    const startDate = new Date(nextYear, nextStartMonth - 1, 1);
    const resolvedEndYear = nextEndMonth < nextStartMonth ? nextYear + 1 : nextYear;
    // Day 0 of the following month = the last day of the end month, so
    // 28/29/30/31-day months all land exactly on their calendar end.
    const endDate = new Date(resolvedEndYear, nextEndMonth, 0);
    onChange({ start: formatYmd(startDate), end: formatYmd(endDate) });
  }

  // Build the rdp selected value; the defaultMonth follows `from`,
  // otherwise today.
  const selected: DateRange | undefined =
    from !== undefined || to !== undefined
      ? { from, to }
      : undefined;
  const defaultMonth = from ?? startOfDay(now);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          id={id}
          type="button"
          variant="outline"
          disabled={disabled}
          aria-invalid={ariaInvalid}
          onBlur={onBlur}
          className={cn(
            'min-w-0 w-full justify-start gap-2 text-left font-normal',
            from === undefined && to === undefined && 'text-muted-foreground',
            className,
          )}
        >
          <CalendarIcon className="size-4 shrink-0" />
          {from !== undefined ? (
            to !== undefined ? (
              <span className="min-w-0 truncate">
                {wholeMonthSpanLabel(formatYmd(from), formatYmd(to))
                  ?? `${format(from, HUMAN)} to ${format(to, HUMAN)}`}
              </span>
            ) : (
              <span className="min-w-0 truncate">{format(from, HUMAN)}</span>
            )
          ) : (
            <span className="min-w-0 truncate">{placeholder}</span>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent
        ref={panelRef}
        align="start"
        className={cn(
          'relative w-auto overflow-hidden p-0',
          // Scroll-away dismissal: the Radix exit animation IS the fade
          // — lengthened to 200ms ease-out with a small upward lift so
          // the panel dissolves toward where the page went. Radix
          // unmounts the panel when the animation completes.
          'data-[state=closed]:[animation-duration:200ms] data-[state=closed]:[animation-timing-function:ease-out] data-[state=closed]:slide-out-to-top-1',
        )}
      >
        {/* Scroll body — capped by Radix's available-height so a short
            viewport scrolls instead of pushing the footer rows offscreen. */}
        <div
          ref={scrollRef}
          onScroll={onScroll}
          className="max-h-[var(--radix-popover-content-available-height)] overscroll-contain overflow-y-auto"
        >
          <Calendar
            mode="range"
            selected={selected}
            defaultMonth={defaultMonth}
            captionLayout="dropdown"
            startMonth={new Date(startYear, 0)}
            endMonth={new Date(endYear, 11)}
            numberOfMonths={2}
            onSelect={(range) => {
              // react-day-picker emits `undefined` when the selection is
              // cleared; otherwise a partial `{ from, to? }`. We commit
              // whatever was last picked, treating `to` as "today + 1
              // day" only when the user explicitly opens the picker and
              // clicks a single day (rdp's "draft" mode). For our use
              // case we just forward exactly what rdp gave us.
              const nextFrom = range?.from !== undefined ? formatYmd(range.from) : '';
              const nextTo = range?.to !== undefined ? formatYmd(range.to) : '';
              onChange({ start: nextFrom, end: nextTo });
              // Auto-close once the user has picked a full range, but
              // leave the popover open while they're still selecting
              // (single-day draft) so the second click registers.
              if (range?.from !== undefined && range.to !== undefined) {
                setOpen(false);
              }
            }}
          />
          {/* Month span: whole-month ranges (Aug → Dec, incl. year rollover).
              Stays open across both picks so start and end can be set in one
              visit; the trigger label echoes the span back. */}
          <div className="flex flex-wrap items-center gap-1.5 border-t px-3 py-2">
            <span className="text-xs font-medium text-muted-foreground">Month span</span>
            <select
              aria-label="Start month"
              className={SPAN_SELECT_CLASS}
              value={String(spanStartMonth)}
              onChange={(event) => commitMonthSpan(Number(event.target.value), spanEndMonth, spanYear)}
            >
              {MONTH_OPTIONS.map((label, index) => (
                <option key={label} value={index + 1}>{label}</option>
              ))}
            </select>
            <span className="text-xs text-muted-foreground">–</span>
            <select
              aria-label="End month"
              className={SPAN_SELECT_CLASS}
              value={String(spanEndMonth)}
              onChange={(event) => commitMonthSpan(spanStartMonth, Number(event.target.value), spanYear)}
            >
              {MONTH_OPTIONS.map((label, index) => (
                <option key={label} value={index + 1}>{label}</option>
              ))}
            </select>
            <select
              aria-label="Span year"
              className={SPAN_SELECT_CLASS}
              value={String(spanYear)}
              onChange={(event) => commitMonthSpan(spanStartMonth, spanEndMonth, Number(event.target.value))}
            >
              {yearOptions.map((year) => (
                <option key={year} value={year}>{year}</option>
              ))}
            </select>
          </div>
          {/* Preset row: common reporting windows. */}
          <div className="flex flex-wrap items-center gap-1.5 border-t px-3 py-2">
            <PresetButton
              label="Last 7 Days"
              onClick={() => {
                const e = startOfDay(now);
                const f = addDays(e, -6);
                onChange({ start: formatYmd(f), end: formatYmd(e) });
                setOpen(false);
              }}
            />
            <PresetButton
              label="Last 30 Days"
              onClick={() => {
                const e = startOfDay(now);
                const f = addDays(e, -29);
                onChange({ start: formatYmd(f), end: formatYmd(e) });
                setOpen(false);
              }}
            />
            <PresetButton
              label="This Month"
              onClick={() => {
                const f = new Date(now.getFullYear(), now.getMonth(), 1);
                const e = startOfDay(now);
                onChange({ start: formatYmd(f), end: formatYmd(e) });
                setOpen(false);
              }}
            />
            <PresetButton
              label="Year to Date"
              onClick={() => {
                const f = new Date(now.getFullYear(), 0, 1);
                const e = startOfDay(now);
                onChange({ start: formatYmd(f), end: formatYmd(e) });
                setOpen(false);
              }}
            />
          </div>
        </div>
        {/* Scroll-edge fades — content dissolves toward the edges while
            there is more calendar beyond them, and both animate away to
            nothing once the end scrolls into view. Purely decorative. */}
        <div
          aria-hidden
          className="pointer-events-none absolute inset-x-0 bottom-0 h-10 bg-gradient-to-t from-popover via-popover/60 to-transparent transition-opacity duration-300 ease-out"
          style={{ opacity: fades.bottom }}
        />
        <div
          aria-hidden
          className="pointer-events-none absolute inset-x-0 top-0 h-8 bg-gradient-to-b from-popover via-popover/60 to-transparent transition-opacity duration-300 ease-out"
          style={{ opacity: fades.top }}
        />
      </PopoverContent>
    </Popover>
  );
}

function PresetButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="min-h-10 rounded-md border px-3 py-2 text-xs text-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 md:min-h-8 md:py-1"
    >
      {label}
    </button>
  );
}
