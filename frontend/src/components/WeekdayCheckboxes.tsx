/**
 * WeekdayCheckboxes — the seven-weekday picker shared by the two recurring
 * schedule dialogs.
 *
 * Both "add a recurring schedule" surfaces used to make the operator open a
 * dropdown and pick ONE day, so setting up a five-day week meant five trips
 * through the dialog. A checkbox row makes the week a single decision, and the
 * set is submitted in one transaction on the backend (2026-09-23), so a
 * half-applied week is not a reachable state.
 *
 * Shared rather than duplicated because the Counselling availability dialog
 * and the Clinic staff-shift dialog must not drift apart — the operator sees
 * the same control for the same concept in both.
 *
 * The visible label is the three-letter form; the accessible name is the full
 * weekday, so a screen reader announces "Sunday", not "Sun".
 */
import { Checkbox } from '@/components/ui/checkbox';
import { DAY_NAMES, DAY_SHORT } from '@/schemas/schedule';
import { cn } from '@/lib/utils';

interface WeekdayCheckboxesProps {
  /** Selected weekdays, 0 = Sunday … 6 = Saturday. */
  value: readonly number[];
  onChange: (next: number[]) => void;
  /** Prefix for the input ids, so two dialogs cannot collide. */
  idPrefix: string;
  disabled?: boolean;
  invalid?: boolean;
}

export function WeekdayCheckboxes({
  value,
  onChange,
  idPrefix,
  disabled = false,
  invalid = false,
}: WeekdayCheckboxesProps) {
  function toggle(day: number) {
    const next = value.includes(day)
      ? value.filter((selected) => selected !== day)
      : [...value, day].sort((a, b) => a - b);
    onChange(next);
  }

  return (
    <div role="group" aria-label="Days of the week" className="flex flex-wrap gap-1.5">
      {DAY_NAMES.map((name, day) => {
        const id = `${idPrefix}-dow-${day}`;
        const checked = value.includes(day);
        return (
          <label
            key={name}
            htmlFor={id}
            className={cn(
              'flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1 text-xs transition-colors',
              checked
                ? 'border-primary bg-primary/5 text-foreground'
                : 'border-input text-muted-foreground hover:text-foreground',
              disabled && 'cursor-not-allowed opacity-50',
            )}
          >
            <Checkbox
              id={id}
              checked={checked}
              disabled={disabled}
              aria-label={name}
              aria-invalid={invalid}
              onCheckedChange={() => toggle(day)}
            />
            <span aria-hidden>{DAY_SHORT[day]}</span>
          </label>
        );
      })}
    </div>
  );
}
