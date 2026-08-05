/**
 * PatientPicker — debounced student search used by both the
 * appointment scheduling dialog and the new-encounter dialog.
 *
 * The user types a school id or a name (>= 2 chars) and a floating
 * popover lists matches from the backend's
 * `/clinic/students/search`. The popover is rendered in a Radix
 * Portal so it floats below the input instead of pushing the
 * surrounding dialog body down. Picking a row fires `onChange` with
 * the chosen `student_number`; the parent form is responsible for
 * writing that into its `patient_school_id` field via react-hook-form
 * `setValue` (or any other state).
 */
import { Loader2, Search } from 'lucide-react';
import { useState } from 'react';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useStudentSearch } from '@/hooks/usePatients';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';

export function PatientPicker({
  id,
  value,
  onChange,
  invalid = false,
}: {
  id?: string;
  value: string;
  onChange: (next: string) => void;
  invalid?: boolean;
}) {
  const [q, setQ] = useState(value);
  const debouncedQ = useDebouncedValue(q, 300);
  const search = useStudentSearch(debouncedQ);
  // Show the results list while the user is typing a query (>= 2 chars)
  // and hide it once they pick a concrete student. Tracking a `picked`
  // flag (rather than comparing q to the committed value, which the
  // keystroke handler keeps in sync) is what makes the list actually
  // appear during typing.
  const [picked, setPicked] = useState(false);
  const showList = q.trim().length >= 2 && !picked;
  const results = (search.data ?? []).slice(0, 8);

  return (
    <div className="space-y-1.5">
      <Label htmlFor={id ?? 'patient_school_id'}>Patient school ID</Label>
      <Popover open={showList}>
        <PopoverTrigger asChild>
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              id={id ?? 'patient_school_id'}
              className="pl-9"
              role="combobox"
              aria-expanded={showList}
              aria-autocomplete="list"
              aria-invalid={invalid}
              aria-controls="patient-picker-listbox"
              value={q}
              onChange={(e) => {
                setPicked(false);
                setQ(e.target.value);
                onChange(e.target.value);
              }}
              placeholder="Type school id or name (min 2 chars)…"
            />
          </div>
        </PopoverTrigger>
        {/* z-[60] so the floating list sits above the dialog overlay
            (which is also z-50). Width is keyed off the trigger so the
            panel lines up with the input. onOpenAutoFocus is
            suppressed so the input keeps focus while the user types. */}
        <PopoverContent
          className="w-[var(--radix-popover-trigger-width)] p-1.5"
          align="start"
          onOpenAutoFocus={(e) => e.preventDefault()}
        >
          <div
            id="patient-picker-listbox"
            role="listbox"
            className="max-h-40 space-y-1 overflow-auto text-xs"
          >
            {search.isLoading && (
              <Loader2 className="mx-auto my-2 size-3.5 animate-spin text-muted-foreground" />
            )}
            {!search.isLoading && results.length === 0 && (
              <p className="px-2 py-1 text-muted-foreground">No matches.</p>
            )}
            {results.map((s) => (
              <button
                type="button"
                key={s.id}
                role="option"
                aria-selected={false}
                className="flex w-full items-center justify-between rounded px-2 py-1 text-left hover:bg-muted/50"
                onClick={() => {
                  setPicked(true);
                  onChange(s.student_number ?? '');
                  setQ(s.student_number ?? '');
                }}
              >
                <span className="font-mono">{s.student_number}</span>
                <span className="text-muted-foreground">{s.last_name}, {s.first_name}</span>
              </button>
            ))}
          </div>
        </PopoverContent>
      </Popover>
    </div>
  );
}
