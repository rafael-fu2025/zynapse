/**
 * SearchBox — small debounced search field shared across list pages.
 *
 * Parents own the live `value`; debounce the value (300 ms) and feed
 * the debounced copy to the server query. An inline X clears the
 * input, and an optional spinner shows when the parent is fetching
 * but the previous data is still on-screen (so the user gets a quiet
 * hint that the next page is loading).
 */
import { Loader2, Search, X } from 'lucide-react';
import { type ReactNode } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export interface SearchBoxProps {
  value: string;
  onValueChange: (next: string) => void;
  placeholder: string;
  /** Optional id forwarded to the underlying <input> for label association. */
  inputId?: string;
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
  ariaLabel = 'Search',
  isFetching = false,
  className,
}: SearchBoxProps) {
  return (
    <div className={cn('relative min-w-0', className)}>
      <Search
        className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
        aria-hidden
      />
      <Input
        id={inputId}
        type="search"
        // Hide the native Webkit clear/decoration button so we don't get
        // a second X next to our custom one.
        className="h-9 pl-9 pr-9 [&::-webkit-search-cancel-button]:appearance-none [&::-webkit-search-decoration]:appearance-none"
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
