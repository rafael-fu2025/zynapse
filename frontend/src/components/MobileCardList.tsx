/**
 * MobileCardList — the reusable responsive-table pattern (mobile pass).
 *
 * Wide data tables are unusable on phones: they force horizontal
 * scrolling and shrink touch targets. The convention is:
 *
 *   <section className="hidden md:block">…existing <Table/>…</section>
 *   <MobileCardList>
 *     {rows.map((r) => (
 *       <MobileCard key={r.id} aria-label={`Row ${r.id}`}>
 *         <MobileCardField label="Patient">{r.patient}</MobileCardField>
 *         …
 *         <MobileCardActions>…buttons…</MobileCardActions>
 *       </MobileCard>
 *     ))}
 *   </MobileCardList>
 *
 * Both surfaces map the SAME row data — no duplicated fetching; badge
 * and status components are reused as-is. The list is `md:hidden`, so
 * desktop rendering is untouched.
 *
 * Semantics: `ul/li` for the list, `dl/dt/dd` for label/value pairs —
 * screen readers announce each card as a coherent group instead of a
 * flattened table soup.
 *
 * The loading / error / empty states come from `MobileCardListState`,
 * which shares its copy resolution with the desktop table's
 * `TableStateRows` so the two surfaces cannot drift apart.
 */
import * as React from 'react';

import { cn } from '@/lib/utils';
import { resolveEmpty, resolveTableState, type TableEmptyCopy } from '@/components/TableStates';

/** Stacked card list, rendered only below the `md` breakpoint. */
export function MobileCardList({
  className,
  ...props
}: React.HTMLAttributes<HTMLUListElement>) {
  return <ul className={cn('space-y-3 md:hidden', className)} {...props} />;
}

/** One row-card. Keep the primary action a real link/button inside. */
export function MobileCard({
  className,
  ...props
}: React.LiHTMLAttributes<HTMLLIElement>) {
  return (
    <li
      className={cn('rounded-xl border bg-card p-3 text-card-foreground shadow-sm', className)}
      {...props}
    />
  );
}

/**
 * Label/value pair — `dt` mirrors the table column header, `dd` the
 * cell value. Values keep whatever badges/formatting the table used.
 */
export function MobileCardField({
  label,
  children,
  className,
}: {
  label: React.ReactNode;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <dl className={cn('flex items-start justify-between gap-3 py-1 text-sm', className)}>
      <dt className="shrink-0 text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="min-w-0 text-right">{children}</dd>
    </dl>
  );
}

/** Footer action row — wraps buttons; grows them to comfortable taps. */
export function MobileCardActions({
  className,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        'mt-2 flex flex-wrap items-center justify-end gap-2 border-t border-dashed pt-2',
        className,
      )}
      {...props}
    />
  );
}

/**
 * Loading / error / empty state for a `MobileCardList`, rendered as a
 * single `<li>` so it stays valid inside the `<ul>`. Drop it in as the
 * list's only child when there are no rows to map:
 *
 *   <MobileCardList>
 *     <MobileCardListState isLoading={q.isLoading} … empty={{ title: '…' }} />
 *     {rows.map(…)}
 *   </MobileCardList>
 *
 * Copy resolution is shared with `TableStateRows`, so a page cannot end
 * up telling a phone user "No drums yet" while telling a desktop user
 * something different for the same query.
 */
export function MobileCardListState({
  isLoading,
  isError,
  isEmpty,
  onRetry,
  pending = false,
  errorMessage,
  loadingLabel = 'Loading results',
  empty,
  noResults,
  hasFilters = false,
}: {
  isLoading: boolean;
  isError: boolean;
  isEmpty: boolean;
  onRetry: () => void;
  pending?: boolean;
  errorMessage?: string;
  loadingLabel?: string;
  empty: TableEmptyCopy;
  noResults?: TableEmptyCopy;
  hasFilters?: boolean;
}) {
  const kind = resolveTableState({ isLoading, isError, isEmpty });

  if (kind === 'loading') {
    return (
      <li role="status" aria-label={loadingLabel} className="py-6 text-center text-sm text-muted-foreground">
        {loadingLabel}…
      </li>
    );
  }

  if (kind === 'error') {
    return (
      <li role="alert" className="rounded-xl border border-destructive/40 bg-card p-4 text-center text-sm text-destructive">
        <p>{errorMessage ?? 'Something went wrong.'}</p>
        <button
          type="button"
          onClick={onRetry}
          disabled={pending}
          className="mt-2 inline-flex h-9 items-center rounded-md border px-3 text-xs font-medium disabled:opacity-60"
        >
          {pending ? 'Retrying…' : 'Retry'}
        </button>
      </li>
    );
  }

  if (kind === 'empty') {
    // `exactOptionalPropertyTypes` is on, so the optional keys are only
    // spread in when actually supplied.
    const copy = resolveEmpty({
      empty,
      hasFilters,
      ...(noResults !== undefined ? { noResults } : {}),
    });
    return (
      <li className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
        <p className="font-medium text-foreground">{copy.title}</p>
        {copy.description !== undefined && <p className="mt-1">{copy.description}</p>}
        {copy.action !== undefined && <div className="mt-3">{copy.action}</div>}
      </li>
    );
  }

  return null;
}
