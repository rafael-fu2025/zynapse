/**
 * DateRangePicker — shadcn Popover + Calendar (range mode) with a
 * STRING contract. Drop-in twin of the single-date `DatePicker`,
 * trading one input for a [from, to] range.
 *
 * Both bounds are exchanged as `YYYY-MM-DD` strings (local-date
 * semantics via date-fns) so every Zod schema and API payload stays
 * unchanged. Either side may be `undefined` while the user is still
 * picking; `onChange` is fired with the latest committed pair.
 */
import { useState } from 'react';
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
      <PopoverContent className="w-auto p-0" align="start">
        <Calendar
          mode="range"
          selected={selected}
          defaultMonth={defaultMonth}
          captionLayout="dropdown"
          startMonth={new Date(startYear, 0)}
          endMonth={new Date(endYear, 11)}
          autoFocus
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
