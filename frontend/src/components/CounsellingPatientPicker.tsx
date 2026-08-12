/**
 * CounsellingPatientPicker — patient typeahead for the counselling forms
 * (open session + book appointment).
 *
 * Counsellors and clinical supervisors do NOT hold `clinic.patients.read`
 * (the permission behind the generic `PatientPicker`), so this component
 * searches the counselling-scoped `GET /counselling/patient-lookup`
 * endpoint instead (gated by `counselling.records.create`). Searching by
 * number OR name; the submitted value is always the exact school id, but
 * the operator can still type a raw id if the lookup misses.
 *
 * The suggestion list is rendered in a Radix Popover (portaled) rather
 * than an absolutely-positioned element: the host dialog's
 * `overflow-y-auto` container would otherwise clip/crop the dropdown.
 * Mirrors the generic `PatientPicker` (z-[60] so the panel floats above
 * the dialog overlay).
 */
import { CircleAlert, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useCounsellingPatientLookup } from '@/hooks/useCounselling';
import type { KioskLookupResult } from '@/hooks/usePatientLookup';

interface CounsellingPatientPickerProps {
  id?: string;
  value: string;
  onValue: (v: string) => void;
  onPick?: (p: KioskLookupResult) => void;
  error?: string | undefined;
}

export function CounsellingPatientPicker({
  id = 'patient_school_id',
  value,
  onValue,
  onPick,
  error,
}: CounsellingPatientPickerProps) {
  const [open, setOpen] = useState(false);
  const debounced = useDebouncedValue(value, 300);
  const lookup = useCounsellingPatientLookup(debounced);
  const results = lookup.data ?? [];
  const showList = open && debounced.trim().length >= 2;

  return (
    <div className="space-y-1.5">
      <Popover open={showList}>
        {/* The trigger child is a DIV wrapper (not the Input itself) —
            PopoverTrigger's asChild clones its child with trigger props
            (including type="button"), which would turn the text field
            into a button and break typing. Same structure as the
            generic PatientPicker. */}
        <PopoverTrigger asChild>
          <div className="relative">
            <Input
              id={id}
              autoComplete="off"
              role="combobox"
              aria-expanded={showList}
              aria-invalid={error !== undefined}
              aria-controls="counselling-patient-suggestions"
              value={value}
              onChange={(e) => { onValue(e.target.value); setOpen(true); }}
              onFocus={() => setOpen(true)}
              onBlur={() => setOpen(false)}
            />
          </div>
        </PopoverTrigger>
        {/* z-[60] so the floating list sits above the dialog overlay
            (which is also z-50). Width is keyed off the trigger so the
            panel lines up with the input. onOpenAutoFocus is suppressed
            so the input keeps focus while the user types. */}
        <PopoverContent
          id="counselling-patient-suggestions"
          className="w-[var(--radix-popover-trigger-width)] p-1.5"
          align="start"
          onOpenAutoFocus={(e) => e.preventDefault()}
        >
          <div role="listbox" className="max-h-72 space-y-1 overflow-auto text-xs">
            {lookup.isError && (
              <p className="flex items-center gap-2 px-3 py-2.5 text-sm text-destructive">
                <CircleAlert className="size-4" /> Couldn't search patients — try typing the ID directly.
              </p>
            )}
            {lookup.isLoading && (
              <p className="flex items-center gap-2 px-3 py-2.5 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" /> Searching…
              </p>
            )}
            {!lookup.isLoading && !lookup.isError && results.length === 0 && (
              <p className="px-3 py-2.5 text-sm text-muted-foreground">
                No matches — you can still type the ID manually.
              </p>
            )}
            {results.map((p) => (
              <button
                type="button"
                key={p.id}
                role="option"
                aria-selected="false"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => { onValue(p.school_id); onPick?.(p); setOpen(false); }}
                className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left transition-colors hover:bg-accent"
              >
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium">{p.name}</span>
                  <span className="block font-mono text-xs text-muted-foreground">{p.school_id}</span>
                </span>
                <Badge variant={p.kind === 'student' ? 'info' : 'secondary'}>
                  {p.kind === 'student' ? 'Student' : 'Employee'}
                </Badge>
              </button>
            ))}
          </div>
        </PopoverContent>
      </Popover>
    </div>
  );
}
