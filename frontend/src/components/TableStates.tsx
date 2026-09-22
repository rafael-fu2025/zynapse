/**
 * TableStates — the single owner of a data table's non-row states.
 *
 * Every list table in the app has to answer the same four questions in the
 * same order: is it loading, did it fail, is there nothing to show, or are
 * there rows? Answering them ad hoc is how the app ended up with desktop
 * guards that forgot `isError` — rendering "No medicines in the catalog."
 * directly above "Failed to load medicines." — while the mobile guard in the
 * same file checked it correctly.
 *
 * These two exports render that decision once:
 *   - TableStateRows  — inside a <TableBody>, as full-width rows.
 *   - TableStateBlock — outside a table, as a bordered panel.
 *
 * Precedence is deliberate and is the whole point:
 *
 *     loading  →  error (only when there are no rows)  →  empty  →  rows
 *
 * An error therefore *never* co-renders with the empty message, which is the
 * defect this component exists to prevent. A failed refetch that still has
 * rows keeps showing the rows rather than blanking the table.
 *
 * Empty-state taxonomy: "nothing exists yet" and "your filters excluded
 * everything" are different situations and need different copy — the second
 * needs a way out. Pass `noResults` (and `hasFilters`) to get that split; the
 * component picks, so no caller re-implements the branch.
 */
import type { ReactNode } from 'react';

import { QueryErrorRow, QueryErrorState } from '@/components/QueryErrorState';
import { Skeleton } from '@/components/ui/skeleton';
import { TableCell, TableRow } from '@/components/ui/table';

export interface TableEmptyCopy {
  /** Optional decorative glyph shown above the title. */
  icon?: ReactNode;
  /** Primary line. States the situation, not just "no data". */
  title: string;
  /** Optional supporting line: why it is empty, or what to do next. */
  description?: ReactNode;
  /** Optional escape hatch — typically a "Clear filters" or "Create" button. */
  action?: ReactNode;
}

interface TableStateProps {
  isLoading: boolean;
  isError: boolean;
  /** True when the table has zero rows to render. */
  isEmpty: boolean;
  onRetry: () => void;
  /** True while a retry/refetch is in flight — spins and disables the button. */
  pending?: boolean;
  errorMessage?: string;
  /** Copy for "there is genuinely nothing here yet". */
  empty: TableEmptyCopy;
  /** Copy for "filters or search excluded every row". Falls back to `empty`. */
  noResults?: TableEmptyCopy;
  /** True when any search/filter is active. Selects `noResults`. */
  hasFilters?: boolean;
  /** Announced to assistive tech while loading. */
  loadingLabel?: string;
  /** How many skeleton bars to show while loading. */
  skeletonRows?: number;
}

type TableStateRowsProps = TableStateProps & {
  /** Number of columns to span. Must match the table's real column count. */
  colSpan: number;
};

type TableStateBlockProps = TableStateProps;

export type TableStateKind = 'loading' | 'error' | 'empty' | 'rows';

/**
 * The precedence rule, isolated from the markup so it can be unit-tested
 * without a DOM. Both exports below branch on exactly this.
 *
 * `error` is checked only while the table is empty: a failed *refetch* that
 * still holds rows must keep showing them rather than blanking the table.
 * Because `error` precedes `empty`, the two can never co-render — which is
 * the whole point of this component.
 */
export function resolveTableState({
  isLoading,
  isError,
  isEmpty,
}: {
  isLoading: boolean;
  isError: boolean;
  isEmpty: boolean;
}): TableStateKind {
  if (isLoading) return 'loading';
  if (isEmpty && isError) return 'error';
  if (isEmpty) return 'empty';
  return 'rows';
}

/**
 * Picks the empty-state copy. "Filters excluded everything" needs different
 * words (and an escape hatch) from "there is nothing here yet", so callers
 * supply both and this decides — rather than every caller re-implementing
 * the branch.
 */
export function resolveEmpty({
  empty,
  noResults,
  hasFilters = false,
}: Pick<TableStateProps, 'empty' | 'noResults' | 'hasFilters'>): TableEmptyCopy {
  return hasFilters && noResults !== undefined ? noResults : empty;
}

function EmptyCopy({ copy }: { copy: TableEmptyCopy }) {
  return (
    <>
      {copy.icon !== undefined && (
        <div className="mb-2 flex justify-center text-muted-foreground" aria-hidden>
          {copy.icon}
        </div>
      )}
      <p className="font-medium text-foreground">{copy.title}</p>
      {copy.description !== undefined && (
        <p className="mt-1 text-sm text-muted-foreground">{copy.description}</p>
      )}
      {copy.action !== undefined && <div className="mt-4 flex justify-center">{copy.action}</div>}
    </>
  );
}

function LoadingBars({ count }: { count: number }) {
  return (
    <div className="space-y-2">
      {Array.from({ length: count }, (_, index) => (
        <Skeleton key={index} className="h-6 w-full" />
      ))}
    </div>
  );
}

/**
 * Renders the loading / error / empty rows for a table body. Renders nothing
 * when there are rows to show — the caller keeps ownership of the row markup.
 */
export function TableStateRows({
  colSpan,
  isLoading,
  isError,
  isEmpty,
  onRetry,
  pending = false,
  errorMessage,
  loadingLabel = 'Loading results',
  skeletonRows = 3,
  ...emptyProps
}: TableStateRowsProps) {
  const kind = resolveTableState({ isLoading, isError, isEmpty });

  if (kind === 'loading') {
    return (
      <TableRow>
        <TableCell colSpan={colSpan} className="px-3 py-3">
          <div role="status" aria-label={loadingLabel}>
            <span className="sr-only">{loadingLabel}</span>
            <LoadingBars count={skeletonRows} />
          </div>
        </TableCell>
      </TableRow>
    );
  }

  if (kind === 'error') {
    return (
      <QueryErrorRow
        colSpan={colSpan}
        onRetry={onRetry}
        pending={pending}
        {...(errorMessage === undefined ? {} : { message: errorMessage })}
      />
    );
  }

  if (kind === 'empty') {
    return (
      <TableRow>
        <TableCell colSpan={colSpan} className="px-3 py-8 text-center">
          <EmptyCopy copy={resolveEmpty(emptyProps)} />
        </TableCell>
      </TableRow>
    );
  }

  return null;
}

/**
 * Block-level equivalent for surfaces whose states render outside a <table>
 * (card lists, dialog bodies, whole-tab panels).
 */
export function TableStateBlock({
  isLoading,
  isError,
  isEmpty,
  onRetry,
  pending = false,
  errorMessage,
  loadingLabel = 'Loading results',
  skeletonRows = 3,
  ...emptyProps
}: TableStateBlockProps) {
  const kind = resolveTableState({ isLoading, isError, isEmpty });

  if (kind === 'loading') {
    return (
      <div role="status" aria-label={loadingLabel} className="space-y-3">
        <span className="sr-only">{loadingLabel}</span>
        {Array.from({ length: skeletonRows }, (_, index) => (
          <Skeleton key={index} className="h-14 w-full" />
        ))}
      </div>
    );
  }

  if (kind === 'error') {
    return (
      <QueryErrorState
        onRetry={onRetry}
        pending={pending}
        {...(errorMessage === undefined ? {} : { message: errorMessage })}
      />
    );
  }

  if (kind === 'empty') {
    return (
      <section className="rounded-xl border bg-card p-8 text-center">
        <EmptyCopy copy={resolveEmpty(emptyProps)} />
      </section>
    );
  }

  return null;
}
