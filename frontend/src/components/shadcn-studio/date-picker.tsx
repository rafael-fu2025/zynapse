/**
 * DatePickerAndTimePicker — shadcn studio "date-picker-10" variant:
 * a calendar-popover date trigger beside a native time input with the
 * spinner indicator hidden.
 *
 * Adapted to this project's Radix-based ui primitives — the studio
 * snippet's `render={<Button />}` (Base UI registry syntax) became
 * `asChild`, and the demo's internal state became the controlled
 * string contract the forms here use (same one as DateTimeField):
 * reads `YYYY-MM-DD[T ]HH:mm[:ss]`, emits `YYYY-MM-DDTHH:mm`.
 * Nothing is emitted until BOTH a date and a time are present;
 * picking a date with no time emits `00:00`.
 */
import { useEffect, useState } from 'react';
import { format, isValid, parse } from 'date-fns';
import { ChevronDownIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

const DATETIME_RE = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/;

interface DatePickerAndTimePickerProps {
  id?: string;
  value: string | null | undefined;
  onChange: (value: string) => void;
  disabled?: boolean;
  'aria-invalid'?: boolean;
}

function parseYmd(value: string): Date | undefined {
  const parsed = parse(value, 'yyyy-MM-dd', new Date());
  return isValid(parsed) ? parsed : undefined;
}

export function DatePickerAndTimePicker({
  id,
  value,
  onChange,
  disabled = false,
  'aria-invalid': ariaInvalid,
}: DatePickerAndTimePickerProps) {
  const [open, setOpen] = useState(false);
  const [draftDate, setDraftDate] = useState('');
  const [draftTime, setDraftTime] = useState('');
  const match = (value ?? '').match(DATETIME_RE);
  const date = match?.[1] ?? draftDate;
  const time = match?.[2] ?? draftTime;
  const selected = date === '' ? undefined : parseYmd(date);
  const now = new Date();

  // A cleared controlled value must also clear the local drafts, or the
  // field would keep showing the previous pick.
  useEffect(() => {
    const normalizedValue = value ?? '';
    if (normalizedValue === '' || normalizedValue.match(DATETIME_RE) === null) {
      setDraftDate('');
      setDraftTime('');
    }
  }, [value]);

  function emit(nextDate: string, nextTime: string) {
    if (nextDate !== '' && nextTime !== '') onChange(`${nextDate}T${nextTime}`);
  }

  return (
    <div className='flex gap-2'>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            variant='outline'
            {...(id !== undefined ? { id } : {})}
            disabled={disabled}
            {...(ariaInvalid !== undefined ? { 'aria-invalid': ariaInvalid } : {})}
            className='flex-1 justify-between font-normal'
          >
            {selected !== undefined ? (
              format(selected, 'PP')
            ) : (
              // Muted only while closed — the button's data-[state=open]
              // white must win over the placeholder once the popover opens.
              <span className={open ? undefined : 'text-muted-foreground'}>Pick a date</span>
            )}
            <ChevronDownIcon className={cn('transition-transform', open && 'rotate-180')} />
          </Button>
        </PopoverTrigger>
        <PopoverContent className='w-auto overflow-hidden p-0' align='start'>
          <Calendar
            mode='single'
            selected={selected}
            {...(selected !== undefined ? { defaultMonth: selected } : {})}
            captionLayout='dropdown'
            startMonth={new Date(now.getFullYear() - 5, 0)}
            endMonth={new Date(now.getFullYear() + 10, 11)}
            onSelect={(next) => {
              if (next !== undefined) {
                const ymd = format(next, 'yyyy-MM-dd');
                setDraftDate(ymd);
                emit(ymd, time === '' ? '00:00' : time);
              }
              setOpen(false);
            }}
          />
        </PopoverContent>
      </Popover>
      <Input
        {...(id !== undefined ? { id: `${id}-time` } : {})}
        type='time'
        disabled={disabled}
        {...(ariaInvalid !== undefined ? { 'aria-invalid': ariaInvalid } : {})}
        value={time}
        onChange={(event) => {
          setDraftTime(event.target.value);
          emit(date, event.target.value);
        }}
        className='w-32 bg-background appearance-none [&::-webkit-calendar-picker-indicator]:hidden [&::-webkit-calendar-picker-indicator]:appearance-none'
      />
    </div>
  );
}
