/**
 * SearchBox — small debounced search field shared across list pages.
 *
 * Parents own the live `value`; debounce the value (300 ms) and feed
 * the debounced copy to the server query. An inline X clears the
 * input, and an optional spinner shows when the parent is fetching
 * but the previous data is still on-screen (so the user gets a quiet
 * hint that the next page is loading).
 *
 * `label` renders a visible `<Label>` above the field, matching the
 * labelled-toolbar layout on the Appointments page. Toolbars that use it
 * must align with `items-end` (as `PageToolbar` does) so the field's
 * bottom lines up with any sibling buttons/selects — with `items-center`
 * the label pushes the field down and the siblings float mid-column.
 */
import { Loader2, Search, X } from 'lucide-react';
import { type ReactNode } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export interface SearchBoxProps {
  value: string;
  onValueChange: (next: string) => void;
  placeholder: string;
  /** Optional id forwarded to the underlying <input> for label association. */
  inputId?: string;
  /**
   * Optional visible label rendered above the field. Omit it and the
   * field renders unlabelled exactly as before, so existing callers are
   * unaffected. Set `inputId` too, or the label has nothing to point at.
   */
  label?: string;
  /** Accessible label override (defaults to "Search"). */
  ariaLabel?: string;
  /** Show a small spinner when the parent is fetching new data. */
  isFetching?: boolean;
  /** Extra classes applied to the outer wrapper — use this to size/position
   *  the search box inside a flex row (e.g. "w-full sm:w-64" or
   *  "min-w-0 sm:flex-[2_1_240px]"). */
  className?: string;
}

export function SearchBox({
  value,
  onValueChange,
  placeholder,
  inputId,
  label,
  ariaLabel = 'Search',
  isFetching = false,
  className,
}: SearchBoxProps) {
  // The right-hand icon zone (spinner / clear X) is empty until the user
  // types or a fetch is in flight — reserve its 36px ONLY then, so the
  // placeholder gets the full width on an idle field instead of
  // truncating ~36px early with dead space after it.
  const reserveRightZone = isFetching || value !== '';
  return (
    <div className={cn('min-w-0', label !== undefined && 'space-y-1', className)}>
      {label !== undefined && (
        <Label htmlFor={inputId} className="text-xs">
          {label}
        </Label>
      )}
      <div className="relative">
        <Search
          className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden
        />
        <Input
          id={inputId}
          type="search"
          // Hide the native Webkit clear/decoration button so we don't get
          // a second X next to our custom one. `placeholder:truncate` is
          // the full trio (overflow-hidden + text-ellipsis + nowrap) —
          // `text-overflow` alone renders nothing without `overflow:
          // hidden`, which is why the placeholder hard-cropped before.
          className={cn(
            'h-9 pl-9 placeholder:truncate [&::-webkit-search-cancel-button]:appearance-none [&::-webkit-search-decoration]:appearance-none',
            reserveRightZone ? 'pr-9' : 'pr-3',
          )}
          value={value}
          onChange={(event) => onValueChange(event.target.value)}
          placeholder={placeholder}
          aria-label={ariaLabel}
        />
        <div className="pointer-events-none absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1">
          {isFetching && (
            <Loader2
              className="size-3.5 animate-spin text-muted-foreground"
              aria-label="Searching"
              role="status"
            />
          )}
          {value !== '' && (
            <button
              type="button"
              aria-label="Clear search"
              className="pointer-events-auto rounded p-1 text-muted-foreground hover:bg-muted/50 hover:text-foreground"
              onClick={() => onValueChange('')}
            >
              <X className="size-3.5" aria-hidden />
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

/**
 * Highlight every case-insensitive occurrence of `query` inside `text`
 * with a <mark>. Special regex characters in the query are escaped so
 * users can search for things like "user.name" without surprises.
 *
 * The split-with-capturing-group pattern is the canonical idiom here:
 * matched groups land at odd indices of the resulting array, unmatched
 * chunks at even indices. No manual exec loop, no edge cases on
 * zero-length matches.
 */
export function highlightMatch(text: string | null | undefined, query: string): ReactNode {
  if (text === null || text === undefined) return '';
  const trimmed = query.trim();
  if (trimmed === '') return text;
  const escaped = trimmed.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const regex = new RegExp(`(${escaped})`, 'gi');
  const parts = text.split(regex);
  return parts.map((part, index) =>
    index % 2 === 1 ? (
      <mark
        key={index}
        className="rounded-sm bg-yellow-200/70 px-0.5 text-foreground dark:bg-yellow-500/30"
      >
        {part}
      </mark>
    ) : (
      <span key={index}>{part}</span>
    ),
  );
}
