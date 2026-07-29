/**
 * DatePicker — shadcn Popover + Calendar, with a STRING contract.
 *
 * Drop-in replacement for `<Input type="date" />`: it accepts and emits
 * `YYYY-MM-DD` strings (parsed/formatted with date-fns as LOCAL dates, so
 * there is no UTC off-by-one), keeping every Zod schema and API payload
 * unchanged. `captionLayout="dropdown"` with a wide month range makes
 * far-future dates (e.g. medicine expiry) reachable without click-spam.
 */
import { useState } from 'react';
import { format, isValid, parse } from 'date-fns';
import { Calendar as CalendarIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

const FMT = 'yyyy-MM-dd';

interface DatePickerProps {
  id?: string;
  value: string | null | undefined;
  onChange: (value: string) => void;
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

function parseYmd(value: string | null | undefined): Date | undefined {
  if (value === null || value === undefined || value === '') {
    return undefined;
  }
  const parsed = parse(value, FMT, new Date());
  return isValid(parsed) ? parsed : undefined;
}

export function DatePicker({
  id,
  value,
  onChange,
  onBlur,
  placeholder = 'Pick a date',
  disabled = false,
  className,
  fromYear,
  toYear,
  'aria-invalid': ariaInvalid,
}: DatePickerProps) {
  const [open, setOpen] = useState(false);
  const selected = parseYmd(value);
  const now = new Date();
  const startYear = fromYear ?? now.getFullYear() - 5;
  const endYear = toYear ?? now.getFullYear() + 10;

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
            selected === undefined && 'text-muted-foreground',
            className,
          )}
        >
          <CalendarIcon className="mr-2 size-4 shrink-0" />
          {selected !== undefined ? format(selected, 'PP') : <span>{placeholder}</span>}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        <Calendar
          mode="single"
          selected={selected}
          {...(selected !== undefined ? { defaultMonth: selected } : {})}
          captionLayout="dropdown"
          startMonth={new Date(startYear, 0)}
          endMonth={new Date(endYear, 11)}
          autoFocus
          onSelect={(date) => {
            if (date !== undefined) {
              onChange(format(date, FMT));
            }
            setOpen(false);
          }}
        />
      </PopoverContent>
    </Popover>
  );
}
