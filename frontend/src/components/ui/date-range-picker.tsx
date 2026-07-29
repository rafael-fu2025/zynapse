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

const FMT = 'yyyy-MM-dd';
const HUMAN = 'LLL dd, y';

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
            'w-full justify-start text-left font-normal',
            from === undefined && to === undefined && 'text-muted-foreground',
            className,
          )}
        >
          <CalendarIcon className="mr-2 size-4 shrink-0" />
          {from !== undefined ? (
            to !== undefined ? (
              <>
                {format(from, HUMAN)} – {format(to, HUMAN)}
              </>
            ) : (
              format(from, HUMAN)
            )
          ) : (
            <span>{placeholder}</span>
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
        {/* Preset row: common reporting windows. */}
        <div className="flex flex-wrap items-center gap-1.5 border-t px-3 py-2">
          <PresetButton
            label="Last 7 days"
            onClick={() => {
              const f = startOfDay(now);
              onChange({ start: formatYmd(f), end: formatYmd(f) });
              setOpen(false);
            }}
          />
          <PresetButton
            label="Last 30 days"
            onClick={() => {
              const e = startOfDay(now);
              const f = addDays(e, -29);
              onChange({ start: formatYmd(f), end: formatYmd(e) });
              setOpen(false);
            }}
          />
          <PresetButton
            label="This month"
            onClick={() => {
              const f = new Date(now.getFullYear(), now.getMonth(), 1);
              const e = startOfDay(now);
              onChange({ start: formatYmd(f), end: formatYmd(e) });
              setOpen(false);
            }}
          />
          <PresetButton
            label="Year to date"
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
      className="rounded-md border px-2 py-1 text-xs text-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
    >
      {label}
    </button>
  );
}
