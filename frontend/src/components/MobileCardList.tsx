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
 */
import * as React from 'react';

import { cn } from '@/lib/utils';

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
