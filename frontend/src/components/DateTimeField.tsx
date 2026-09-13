/**
 * DateTimeField — DatePicker + TimePicker pair over a datetime string
 * contract, the app-standard replacement for
 * `<Input type="datetime-local" />` (which cannot parse the
 * `YYYY-MM-DD HH:mm:ss` wire format our API returns, so edit dialogs
 * showed blank windows).
 *
 * Reads `YYYY-MM-DD[T ]HH:mm[:ss]` and emits `YYYY-MM-DDTHH:mm`
 * (datetime-local format — `strtotime()` accepts both on the backend).
 * Nothing is emitted until BOTH a date and a time are picked, so no
 * partial values reach the API; the picked pieces live in local draft
 * state until the pair is complete.
 */
import { useState } from 'react';
import { DatePicker } from '@/components/ui/date-picker';
import { TimePicker } from '@/components/ui/time-picker';

const DATETIME_RE = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/;

interface DateTimeFieldProps {
  id?: string;
  value: string | null | undefined;
  onChange: (value: string) => void;
  disabled?: boolean;
  'aria-invalid'?: boolean;
}

export function DateTimeField({
  id,
  value,
  onChange,
  disabled = false,
  'aria-invalid': ariaInvalid,
}: DateTimeFieldProps) {
  const match = DATETIME_RE.exec(value ?? '');
  const [draftDate, setDraftDate] = useState('');
  const [draftTime, setDraftTime] = useState('');

  const date = match?.[1] ?? draftDate;
  const time = match?.[2] ?? draftTime;

  function emit(nextDate: string, nextTime: string) {
    if (nextDate !== '' && nextTime !== '') {
      onChange(`${nextDate}T${nextTime}`);
    }
  }

  return (
    <div className="grid gap-1.5">
      <DatePicker
        {...(id !== undefined ? { id } : {})}
        {...(ariaInvalid !== undefined ? { 'aria-invalid': ariaInvalid } : {})}
        value={date}
        disabled={disabled}
        placeholder="Pick date"
        onChange={(nextDate) => {
          setDraftDate(nextDate);
          emit(nextDate, time === '' ? '00:00' : time);
        }}
      />
      <TimePicker
        {...(id !== undefined ? { id: `${id}-time` } : {})}
        {...(ariaInvalid !== undefined ? { 'aria-invalid': ariaInvalid } : {})}
        value={time}
        disabled={disabled}
        onChange={(nextTime) => {
          setDraftTime(nextTime);
          emit(date, nextTime);
        }}
      />
    </div>
  );
}
