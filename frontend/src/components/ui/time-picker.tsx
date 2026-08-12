/**
 * TimePicker — 12-hour shadcn dropdown selects (hour 1–12 + minute + AM/PM).
 *
 * Drop-in replacement for `<Input type="time" />`: accepts and emits an
 * `HH:MM` 24-hour string (e.g. "14:30"), so Zod schemas and API payloads
 * are unchanged, but the UI shows a familiar 12-hour clock with an AM/PM
 * indicator — matching the mobile `showTimePicker` behaviour. Rendered as
 * three inline Selects (`[ 02 ▾] : [ 30 ▾] [ PM ▾]`) rather than a
 * Select-inside-Popover, which avoids Radix's portal/outside-click
 * conflict and needs fewer clicks.
 */
import { Clock } from 'lucide-react';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

interface TimePickerProps {
  id?: string;
  value: string | null | undefined;
  onChange: (value: string) => void;
  onBlur?: () => void;
  disabled?: boolean;
  className?: string;
  /** Minute granularity of the dropdown (default 1 => 00..59). */
  minuteStep?: number;
  'aria-invalid'?: boolean;
}

const pad = (n: number): string => n.toString().padStart(2, '0');

/** Convert a 24-hour hour (0-23) to its 12-hour display (1-12). */
function to12Hour(hh24: number): number {
  const h = hh24 % 12;
  return h === 0 ? 12 : h;
}

/** Convert a 12-hour display hour (1-12) + AM/PM back to 24-hour (0-23). */
function to24Hour(h12: number, meridiem: 'AM' | 'PM'): number {
  if (meridiem === 'AM') return h12 % 12;
  return h12 === 12 ? 12 : h12 + 12;
}

function splitHhMm(value: string | null | undefined): { hh: string; mm: string } {
  if (typeof value === 'string' && /^\d{2}:\d{2}$/.test(value)) {
    const [hh, mm] = value.split(':');
    return { hh: hh ?? '', mm: mm ?? '' };
  }
  return { hh: '', mm: '' };
}

export function TimePicker({
  id,
  value,
  onChange,
  onBlur,
  disabled = false,
  className,
  minuteStep = 1,
  'aria-invalid': ariaInvalid,
}: TimePickerProps) {
  const { hh, mm } = splitHhMm(value);

  // Display pieces derived from the stored 24-hour hour.
  const hh24 = hh !== '' ? parseInt(hh, 10) : NaN;
  const hasHour = !Number.isNaN(hh24);
  const displayHour = hasHour ? String(to12Hour(hh24)) : '';
  const meridiem: 'AM' | 'PM' | '' = hasHour ? (hh24 >= 12 ? 'PM' : 'AM') : '';

  const hourOptions = Array.from({ length: 12 }, (_, i) => String(i + 1));
  const minuteOptions = Array.from(
    { length: Math.ceil(60 / minuteStep) },
    (_, i) => pad(i * minuteStep),
  );

  /** Compose a 24-hour `HH:MM` from the 12-hour parts and emit it. */
  const emit = (nextHour: string, nextMm: string, nextMeridiem: string): void => {
    if (nextHour === '' || nextMm === '' || nextMeridiem === '') return;
    const h = to24Hour(parseInt(nextHour, 10), nextMeridiem as 'AM' | 'PM');
    onChange(`${pad(h)}:${nextMm}`);
  };

  return (
    <div className={cn('flex items-center gap-1.5', className)} aria-invalid={ariaInvalid}>
      <Clock className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
      <Select
        value={displayHour}
        disabled={disabled}
        onValueChange={(next) =>
          emit(next, mm === '' ? '00' : mm, meridiem === '' ? 'AM' : meridiem)
        }
      >
        <SelectTrigger id={id} aria-invalid={ariaInvalid} className="w-[4.25rem]" onBlur={onBlur} aria-label="Hour">
          <SelectValue placeholder="HH" />
        </SelectTrigger>
        <SelectContent className="max-h-60">
          {hourOptions.map((h) => (
            <SelectItem key={h} value={h}>
              {h}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <span className="text-muted-foreground">:</span>
      <Select
        value={mm}
        disabled={disabled}
        onValueChange={(next) =>
          emit(displayHour === '' ? '12' : displayHour, next, meridiem === '' ? 'AM' : meridiem)
        }
      >
        <SelectTrigger aria-invalid={ariaInvalid} className="w-[4.25rem]" aria-label="Minute">
          <SelectValue placeholder="MM" />
        </SelectTrigger>
        <SelectContent className="max-h-60">
          {minuteOptions.map((m) => (
            <SelectItem key={m} value={m}>
              {m}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <Select
        value={meridiem}
        disabled={disabled}
        onValueChange={(next) =>
          emit(displayHour === '' ? '12' : displayHour, mm === '' ? '00' : mm, next)
        }
      >
        <SelectTrigger aria-invalid={ariaInvalid} className="w-[4.25rem]" aria-label="AM/PM">
          <SelectValue placeholder="--" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="AM">AM</SelectItem>
          <SelectItem value="PM">PM</SelectItem>
        </SelectContent>
      </Select>
    </div>
  );
}
