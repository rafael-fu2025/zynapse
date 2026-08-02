/**
 * ComboboxField — form-integrated combobox for catalogue fields that
 * used to be free-text Inputs (medicine category, dosage form, unit,
 * supplier name, etc.).
 *
 * Why not `<Input>` + `<Select>`:
 *   - Catalogue fields are mostly OPEN (the operator can legitimately
 *     introduce a new value), but a SELECT makes that impossible.
 *   - Pure INPUT lets duplicates pile up ("tab" / "tablet" / "tabs").
 *   - Comboboxes give us: curated suggestions, keyboard navigation,
 *     create-on-the-fly, and value still serializes as a string the
 *     backend accepts without any column migration.
 *
 * WAI-ARIA combobox pattern (list with manual selection):
 *   - The Input is always focusable — DOM focus never leaves it.
 *   - `aria-activedescendant` points at the highlighted option id.
 *   - `role="listbox"` is on the popover, `role="option"` on each row.
 *   - Down/Up arrows move the highlight; Enter selects; Escape closes.
 *   - Tab moves to the next form field, closing the popover.
 *
 * The class also runs a session-scoped Zustand cache of "values seen
 * this session" so newly-created options stay ranked alongside curated
 * ones in the same form / page lifetime.
 */

import {
  Check,
  ChevronDown,
  Loader2,
  Plus,
  Search,
  X,
} from 'lucide-react';
import {
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
} from 'react';
import type { ChangeEvent, KeyboardEvent } from 'react';
import { create } from 'zustand';

import { cn } from '@/lib/utils';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { Input } from '@/components/ui/input';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import {
  type TaxonomyEntry,
  filterTaxonomy,
  normalizeTaxonomyValue,
} from '@/data/taxonomy';

// -------------------------------------------------------- session cache

interface TaxonomyExtrasState {
  /** Values the operator has added this session, keyed by the source list name. */
  bySource: Record<string, string[]>;
  add: (sourceKey: string, value: string) => void;
}

/**
 * Session-scoped store of values added via the "Create new" affordance.
 * Values are in-memory only — the backend already accepts arbitrary
 * strings, so the next reload re-reads them from the API anyway. This
 * cache just keeps the suggestion ranking consistent inside the page.
 */
const useTaxonomyExtras = create<TaxonomyExtrasState>((set) => ({
  bySource: {},
  add: (sourceKey, value) =>
    set((s) => {
      const bucket = s.bySource[sourceKey] ?? [];
      if (bucket.includes(value)) return s;
      return { bySource: { ...s.bySource, [sourceKey]: [...bucket, value] } };
    }),
}));

// -------------------------------------------------------- public types

export interface ComboboxFieldProps {
  /** Form-side value (string). Empty string means "no selection yet". */
  value: string;
  /** RHF-style setter. Receives the raw chosen value. */
  onChange: (next: string) => void;
  /** Stable identifier for the source list (used by the extras cache). */
  sourceKey: string;
  /** Curated starter values, in display order. */
  options: ReadonlyArray<TaxonomyEntry>;
  /** Placeholder text shown inside the empty input. */
  placeholder?: string;
  /** Optional: mark this field as required for `aria-required`. */
  required?: boolean;
  /** Optional: pass-through for the underlying <input> id (forms use it). */
  id?: string;
  /** Optional: marks the input as invalid (matches Input's `aria-invalid`). */
  invalid?: boolean;
  /** Optional: disable the field. */
  disabled?: boolean;
  /**
   * Allow the operator to type a new value that is NOT in the curated
   * list. Off by default — switch on for catalogue-creating forms.
   */
  allowCreate?: boolean;
  /** Trim the typed value into a canonical token (lowercase, single spaces). */
  normalize?: boolean;
  /**
   * Optional HTML5 `pattern` for the underlying input — flags malformed
   * create-on-the-fly values via the browser's native validation tooltip
   * on form submit. The pattern is matched as a substring (no `^`/`$`
   * needed — the browser wraps it). Use only when the field has a
   * constrained shape (e.g. medicine strengths).
   */
  pattern?: string;
  /**
   * Title text shown by the browser when the `pattern` rejects a value.
   * Browsers display this as the validation tooltip.
   */
  patternTitle?: string;
  /**
   * Optional async fetcher for catalogue values that live on the
   * server (e.g. medicine names against `/clinic/medicines?q=`). When
   * provided, the field debounces the typed query, calls the fetcher
   * with an AbortSignal, and merges the results with the static
   * options + session cache. Returning an empty array (or rejecting)
   * falls back to the static behaviour.
   *
   * The fetcher should be stable (wrap in `useCallback([])`) — its
   * identity gates the debounce effect.
   */
  fetchOptions?: (query: string, signal: AbortSignal) => Promise<ReadonlyArray<TaxonomyEntry>>;
  /** Debounce window before calling `fetchOptions`. Default 250ms. */
  fetchDebounceMs?: number;
  /** Text shown in the menu while `fetchOptions` is in flight. */
  loadingLabel?: string;
  /** Text shown in the empty menu when no options exist yet. */
  emptyHintLabel?: string;
  /** Render an extra row above the curated list (e.g. recent / pinned). */
  pinnedSection?: { heading: string; entries: ReadonlyArray<TaxonomyEntry> };
}

// -------------------------------------------------------- component

/**
 * ComboboxField — controlled, RHF-friendly combobox with create-on-the-fly.
 *
 * Selection model: the field always reports a string. The listbox can
 * either (a) match an existing taxonomy entry, or (b) trigger a "Create
 * new" row that accepts the typed value. Enter / click on the create
 * row commits the typed string through `onChange`.
 */
export function ComboboxField(props: ComboboxFieldProps): JSX.Element {
  const {
    value,
    onChange,
    sourceKey,
    options,
    placeholder,
    required = false,
    id,
    invalid = false,
    disabled = false,
    allowCreate = false,
    normalize = true,
    pattern,
    patternTitle,
    fetchOptions,
    fetchDebounceMs = 250,
    loadingLabel = 'Searching…',
    emptyHintLabel,
    pinnedSection,
  } = props;

  // ----- controlled open + highlight index.
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState<string>(value);
  const [highlight, setHighlight] = useState<number>(0);

  // ----- async server results (only when `fetchOptions` is provided).
  //       Stays empty when the field is purely static.
  const [asyncResults, setAsyncResults] = useState<ReadonlyArray<TaxonomyEntry>>([]);
  const [asyncLoading, setAsyncLoading] = useState(false);
  const [asyncError, setAsyncError] = useState<string | null>(null);
  const abortRef = useRef<AbortController | null>(null);
  const debouncedQuery = useDebouncedValue(
    query.trim(),
    fetchOptions !== undefined ? fetchDebounceMs : 0,
  );
  const fetchOptionsRef = useRef(fetchOptions);
  fetchOptionsRef.current = fetchOptions;
  useEffect(() => {
    if (fetchOptions === undefined) return;
    const trimmed = debouncedQuery;
    if (trimmed === '') {
      // Empty query — drop server results so stale rows from a
      // previous typing session don't linger in the menu.
      abortRef.current?.abort();
      setAsyncResults([]);
      setAsyncLoading(false);
      setAsyncError(null);
      return;
    }
    // Abort any in-flight request; only the latest keystroke's
    // response should commit to state.
    abortRef.current?.abort();
    const ctl = new AbortController();
    abortRef.current = ctl;
    setAsyncLoading(true);
    setAsyncError(null);
    fetchOptionsRef.current?.(trimmed, ctl.signal)
      .then((res) => {
        if (ctl.signal.aborted) return;
        setAsyncResults(res);
        setAsyncLoading(false);
      })
      .catch((err: unknown) => {
        if (ctl.signal.aborted) return;
        // AbortError is the expected race-cancel signal; ignore it.
        if (err instanceof Error && err.name === 'AbortError') return;
        setAsyncError('Search failed — showing local matches');
        setAsyncResults([]);
        setAsyncLoading(false);
      });
    return () => {
      ctl.abort();
    };
  }, [debouncedQuery, fetchOptions]);

  // ----- session-only extras, merged with curated options + async results.
  const extras = useTaxonomyExtras((s) => s.bySource[sourceKey] ?? []);
  const addExtra = useTaxonomyExtras((s) => s.add);
  const merged: TaxonomyEntry[] = useMemo(() => {
    const seen = new Set<string>();
    const out: TaxonomyEntry[] = [];
    // Async results go first — they're the most relevant (server
    // already matched the debounced query) and the user typically
    // picks an existing row when this field is wired to one.
    for (const e of asyncResults) {
      if (!seen.has(e.value)) {
        seen.add(e.value);
        out.push(e);
      }
    }
    for (const e of options) {
      if (!seen.has(e.value)) {
        seen.add(e.value);
        out.push(e);
      }
    }
    for (const v of extras) {
      if (!seen.has(v)) {
        seen.add(v);
        out.push({ value: v });
      }
    }
    return out;
  }, [asyncResults, options, extras]);

  // ----- keep the visible query in sync when the form value is cleared/seeded.
  useEffect(() => {
    setQuery((q) => (q === value ? q : value));
  }, [value]);

  // ----- filter the merged list by the live query. This re-applies
  //       to the async results too, so stale matches drop off as the
  //       user keeps typing past the debounced window.
  const filtered = useMemo(() => filterTaxonomy(merged, query), [merged, query]);

  // Allow a typed-but-not-yet-committed new value to appear as a create row.
  const trimmedQuery = query.trim();
  const normalizedQuery = normalize ? normalizeTaxonomyValue(trimmedQuery) : trimmedQuery;
  const exactMatch = filtered.some(
    (e) =>
      e.value.toLowerCase() === normalizedQuery.toLowerCase() ||
      (e.label ?? '').toLowerCase() === normalizedQuery.toLowerCase(),
  );
  const showCreateRow =
    allowCreate &&
    normalizedQuery !== '' &&
    !exactMatch;

  // ----- the flat list the keyboard navigator walks. Indices here map
  //       1:1 to the JSX rows below; the create row is appended last.
  const totalCount = filtered.length + (showCreateRow ? 1 : 0);

  const inputRef = useRef<HTMLInputElement | null>(null);
  const listRef = useRef<HTMLUListElement | null>(null);
  const optionRefs = useRef<Array<HTMLLIElement | null>>([]);
  /**
   * True only when the user moved the highlight via ArrowDown/ArrowUp.
   * Pointer-driven changes (mouseEnter on a list row) leave this false
   * so the auto-scroll-into-view effect below does NOT re-scroll the
   * list every time the cursor crosses a row during a wheel/touchpad
   * scroll — that fight was making the list appear un-scrollable.
   */
  const keyboardNavRef = useRef(false);
  const listboxId = useId();
  const labelId = useId();

  // Reset highlight whenever the menu opens or the filter set changes.
  useEffect(() => {
    setHighlight(0);
  }, [open, normalizedQuery]);

  // Scroll the highlighted option into view — only when the highlight
  // changed because of keyboard navigation, never when the pointer is
  // simply rolling over rows.
  useEffect(() => {
    if (!open || !keyboardNavRef.current) return;
    optionRefs.current[highlight]?.scrollIntoView({ block: 'nearest' });
    keyboardNavRef.current = false;
  }, [highlight, open]);

  function commit(valueRaw: string): void {
    const final = normalize ? normalizeTaxonomyValue(valueRaw) : valueRaw.trim();
    if (final === '') return;
    onChange(final);
    if (
      allowCreate &&
      !options.some(
        (e) => e.value === final || (e.label ?? '').toLowerCase() === final.toLowerCase(),
      )
    ) {
      addExtra(sourceKey, final);
    }
    setQuery(final);
    setOpen(false);
    inputRef.current?.focus();
  }

  function clear(): void {
    onChange('');
    setQuery('');
    inputRef.current?.focus();
  }

  function onKeyDown(e: KeyboardEvent<HTMLInputElement>): void {
    if (disabled) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (!open) {
        setOpen(true);
        return;
      }
      if (totalCount > 0) {
        keyboardNavRef.current = true;
        setHighlight((h) => (h + 1) % totalCount);
      }
      return;
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (!open) {
        setOpen(true);
        return;
      }
      if (totalCount > 0) {
        keyboardNavRef.current = true;
        setHighlight((h) => (h - 1 + totalCount) % totalCount);
      }
      return;
    }
    if (e.key === 'Enter') {
      // Only intercept when the menu is open; otherwise let the form submit.
      if (!open) return;
      e.preventDefault();
      if (highlight < filtered.length) {
        commit(filtered[highlight]!.value);
        return;
      }
      if (showCreateRow && normalizedQuery !== '') {
        commit(normalizedQuery);
      }
      return;
    }
    if (e.key === 'Escape') {
      if (open) {
        e.preventDefault();
        setOpen(false);
        setQuery(value);
      } else if (value !== '') {
        e.preventDefault();
        clear();
      }
      return;
    }
  }

  function onInputChange(e: ChangeEvent<HTMLInputElement>): void {
    const next = e.target.value;
    setQuery(next);
    if (!open && next !== '') setOpen(true);
  }

  const displayValue =
    value === ''
      ? ''
      : merged.find((e) => e.value === value)?.label ?? value;

  return (
    <Popover
      open={open}
      onOpenChange={(o) => { setOpen(o); if (!o) setQuery(value); }}
      // Radix Dialog installs `RemoveScroll` on the body when the
      // modal is open — without `modal={true}` on this Popover,
      // wheel/scroll events from the (portaled) menu get locked along
      // with the dialog body. Setting `modal` tells Radix to manage
      // scroll inside the popover itself, so wheel-to-scroll works.
      modal
    >
      <PopoverAnchor>
        <div className="relative w-full">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden
          />
          <Input
            id={id}
            ref={inputRef}
            role="combobox"
            aria-expanded={open}
            aria-controls={listboxId}
            aria-activedescendant={
              open && totalCount > 0
                ? `${listboxId}-opt-${highlight}`
                : undefined
            }
            aria-autocomplete="list"
            aria-invalid={invalid}
            aria-required={required}
            disabled={disabled}
            autoComplete="off"
            spellCheck={false}
            placeholder={placeholder}
            value={open ? query : displayValue}
            onChange={onInputChange}
            onKeyDown={onKeyDown}
            onFocus={() => {
              if (value !== '' && !open) {
                // On focus of a populated field, drop the label so the
                // operator can type to search (avoids deleting the
                // label to start typing).
                setQuery('');
              }
              setOpen(true);
            }}
            // Pass-through: when the call site provides a `pattern`,
            // the browser surfaces a native validation tooltip on form
            // submit if a create-on-the-fly value doesn't match any
            // branch of the regex (e.g. `500mgX` after the operator
            // added a typo by hand).
            {...(pattern !== undefined ? { pattern } : {})}
            {...(patternTitle !== undefined ? { title: patternTitle } : {})}
            className="h-10 pl-9 pr-16 md:h-9"
          />
          <div className="absolute right-1 top-1/2 flex -translate-y-1/2 items-center gap-0.5">
            {value !== '' && (
              <button
                type="button"
                aria-label="Clear value"
                className="rounded p-1 text-muted-foreground hover:bg-muted/50 hover:text-foreground"
                onMouseDown={(e) => {
                  // Use onMouseDown so the click clears focus first;
                  // letting onClick run would dismiss the popover
                  // before clear() executes.
                  e.preventDefault();
                }}
                onClick={() => clear()}
                disabled={disabled}
              >
                <X className="size-3.5" />
              </button>
            )}
            <button
              type="button"
              aria-label={open ? 'Close suggestions' : 'Open suggestions'}
              aria-haspopup="listbox"
              aria-expanded={open}
              className="rounded p-1 text-muted-foreground hover:bg-muted/50 hover:text-foreground"
              onClick={() => {
                if (open) {
                  setOpen(false);
                } else {
                  inputRef.current?.focus();
                  setOpen(true);
                }
              }}
              disabled={disabled}
            >
              <ChevronDown
                className={cn('size-3.5 transition-transform', open && 'rotate-180')}
              />
            </button>
          </div>
        </div>
      </PopoverAnchor>
      {open ? (
        <PopoverContent
          align="start"
          sideOffset={4}
          // We drive focus entirely through the Input — Radix's
          // default onOpenAutoFocus would otherwise blur the field
          // after the first keystroke (the menu has no naturally
          // focusable element to land on, so the side effect feels
          // like "the field died after one character").
          onOpenAutoFocus={(e) => e.preventDefault()}
          // Wheel scroll: with `modal` on the parent Popover, the
          // Dialog's scroll lock releases for this content. We still
          // stopPropagation here as a defensive measure — if a
          // consumer ever flips this popover into a non-modal parent,
          // the wheel delta won't leak into any overlay.
          onWheel={(e) => e.stopPropagation()}
          onTouchMove={(e) => e.stopPropagation()}
          // Override the canned `w-72 p-4` chrome; Radix manages
          // positioning, the data-state attribute, and collision.
          className="max-h-72 w-[var(--radix-popover-trigger-width)] overflow-y-auto overflow-x-hidden rounded-md border bg-popover p-0 text-popover-foreground shadow-md"
        >
            {totalCount === 0 ? (
              <p
                id={listboxId}
                role="status"
                className="px-3 py-2 text-xs text-muted-foreground"
              >
                {asyncError ?? (asyncLoading
                  ? loadingLabel
                  : emptyHintLabel ?? 'No matches.')}
                {allowCreate && normalizedQuery !== '' && !asyncLoading && asyncError === null ? (
                  <>
                    {' '}
                    Press Enter to add{' '}
                    <span className="font-medium text-foreground">
                      {normalizedQuery}
                    </span>
                    .
                  </>
                ) : null}
              </p>
            ) : (
              <ul
                ref={listRef}
                id={listboxId}
                role="listbox"
                aria-labelledby={labelId}
                // No overflow / max-height on the list itself anymore —
                // the surrounding PopoverContent is the scroll container.
                // `select-none` prevents the browser from highlighting
                // option text when the user drag-scrolls the menu on
                // touch devices.
                className="select-none py-1"
              >
                {asyncLoading ? (
                  <li
                    role="presentation"
                    aria-live="polite"
                    className="flex items-center gap-2 px-3 py-1.5 text-xs text-muted-foreground"
                  >
                    <Loader2 className="size-3 shrink-0 animate-spin" aria-hidden />
                    <span>{loadingLabel}</span>
                  </li>
                ) : null}
                {asyncError !== null ? (
                  <li
                    role="presentation"
                    className="px-3 py-1.5 text-xs text-amber-600"
                  >
                    {asyncError}
                  </li>
                ) : null}
                {pinnedSection !== undefined && pinnedSection.entries.length > 0 ? (
                  <li
                    role="presentation"
                    className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground"
                  >
                    {pinnedSection.heading}
                  </li>
                ) : null}
                {pinnedSection !== undefined && pinnedSection.entries.length > 0
                  ? null
                  : null}
                {filtered.map((entry, idx) => {
                  const isSelected = entry.value === value;
                  const isActive = idx === highlight;
                  const hasHint = entry.hint !== undefined && entry.hint !== '';
                  return (
                    <li
                      key={entry.value}
                      ref={(el) => {
                        optionRefs.current[idx] = el;
                      }}
                      id={`${listboxId}-opt-${idx}`}
                      role="option"
                      aria-selected={isSelected}
                      // tabIndex={-1} so the option never enters the page Tab sequence;
                      // DOM focus stays on the input.
                      tabIndex={-1}
                      className={cn(
                        'flex cursor-pointer items-start gap-2 px-3 py-1.5 text-sm',
                        isActive && 'bg-accent text-accent-foreground',
                        !isActive && 'text-foreground',
                      )}
                      onMouseEnter={() => {
                        keyboardNavRef.current = false;
                        setHighlight(idx);
                      }}
                      onMouseDown={(e) => {
                        // preventDefault so the click doesn't blur the input
                        // before commit() runs.
                        e.preventDefault();
                      }}
                      onClick={() => commit(entry.value)}
                    >
                      <Check
                        className={cn(
                          'mt-0.5 size-3.5 shrink-0',
                          isSelected ? 'opacity-100' : 'opacity-0',
                        )}
                        aria-hidden
                      />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate font-medium">
                          {entry.label ?? entry.value}
                        </span>
                        {hasHint ? (
                          <span
                            className={cn(
                              'block truncate text-xs',
                              isActive ? 'text-accent-foreground/80' : 'text-muted-foreground',
                            )}
                          >
                            {entry.hint}
                          </span>
                        ) : null}
                      </span>
                    </li>
                  );
                })}
                {showCreateRow ? (
                  <li
                    ref={(el) => {
                      optionRefs.current[filtered.length] = el;
                    }}
                    id={`${listboxId}-opt-${filtered.length}`}
                    role="option"
                    aria-selected={false}
                    tabIndex={-1}
                    className={cn(
                      'flex cursor-pointer items-center gap-2 border-t border-dashed px-3 py-1.5 text-sm',
                      highlight === filtered.length
                        ? 'bg-accent text-accent-foreground'
                        : 'text-foreground',
                    )}
                    onMouseEnter={() => {
                    keyboardNavRef.current = false;
                    setHighlight(filtered.length);
                  }}
                    onMouseDown={(e) => e.preventDefault()}
                    onClick={() => commit(normalizedQuery)}
                  >
                    <Plus className="size-3.5 shrink-0" aria-hidden />
                    <span className="truncate">
                      Add new{' '}
                      <span className="font-medium">{normalizedQuery}</span>
                    </span>
                  </li>
                ) : null}
              </ul>
            )}
          </PopoverContent>
      ) : null}
    </Popover>
  );
}
