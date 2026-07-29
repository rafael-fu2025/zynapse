/**
 * TimePicker — two 24-hour shadcn dropdown selects (hour + minute).
 *
 * Drop-in replacement for `<Input type="time" />`: accepts and emits an
 * `HH:MM` 24-hour string, so Zod schemas and API payloads are unchanged.
 * Rendered as two inline Selects (`[ 09 ▾] : [ 30 ▾]`) rather than a
 * Select-inside-Popover, which avoids Radix's portal/outside-click
 * conflict and needs one fewer click.
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

  const hours = Array.from({ length: 24 }, (_, i) => pad(i));
  const minutes = Array.from(
    { length: Math.ceil(60 / minuteStep) },
    (_, i) => pad(i * minuteStep),
  );

  const emit = (nextHh: string, nextMm: string): void => {
    onChange(`${nextHh}:${nextMm}`);
  };

  return (
    <div className={cn('flex items-center gap-1.5', className)} aria-invalid={ariaInvalid}>
      <Clock className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
      <Select
        value={hh}
        disabled={disabled}
        onValueChange={(next) => emit(next, mm === '' ? '00' : mm)}
      >
        <SelectTrigger id={id} aria-invalid={ariaInvalid} className="w-[4.5rem]" onBlur={onBlur} aria-label="Hour">
          <SelectValue placeholder="HH" />
        </SelectTrigger>
        <SelectContent className="max-h-60">
          {hours.map((h) => (
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
        onValueChange={(next) => emit(hh === '' ? '00' : hh, next)}
      >
        <SelectTrigger aria-invalid={ariaInvalid} className="w-[4.5rem]" aria-label="Minute">
          <SelectValue placeholder="MM" />
        </SelectTrigger>
        <SelectContent className="max-h-60">
          {minutes.map((m) => (
            <SelectItem key={m} value={m}>
              {m}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}
