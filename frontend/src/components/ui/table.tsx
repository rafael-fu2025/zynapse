/**
 * Table — shadcn/ui (new-york). Semantic <table> markup preserved for
 * screen readers; styling via wrapper div for horizontal overflow.
 *
 * Two accessibility affordances are built in so callers cannot forget them:
 *  - `ariaLabel` renders a visually hidden <caption>, giving the table an
 *    accessible name (WCAG 1.3.1). Pass the same string as the section
 *    heading. Do NOT also render a <TableCaption> — a table may only have
 *    one caption.
 *  - `TableHead` emits `scope="col"`, so every data cell is programmatically
 *    associated with its column header.
 */
import * as React from 'react';

import { cn } from '@/lib/utils';

interface TableProps extends React.HTMLAttributes<HTMLTableElement> {
  /** Accessible name, rendered as a visually hidden <caption>. */
  ariaLabel?: string;
}

const Table = React.forwardRef<HTMLTableElement, TableProps>(
  ({ className, ariaLabel, children, ...props }, ref) => (
    <div className="relative w-full overflow-auto">
      <table ref={ref} className={cn('w-full caption-bottom text-sm', className)} {...props}>
        {ariaLabel !== undefined && <caption className="sr-only">{ariaLabel}</caption>}
        {children}
      </table>
    </div>
  ),
);
Table.displayName = 'Table';

const TableHeader = React.forwardRef<
  HTMLTableSectionElement,
  React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
  <thead ref={ref} className={cn('[&_tr]:border-b', className)} {...props} />
));
TableHeader.displayName = 'TableHeader';

const TableBody = React.forwardRef<
  HTMLTableSectionElement,
  React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
  <tbody ref={ref} className={cn('[&_tr:last-child]:border-0', className)} {...props} />
));
TableBody.displayName = 'TableBody';

const TableFooter = React.forwardRef<
  HTMLTableSectionElement,
  React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
  <tfoot
    ref={ref}
    className={cn('border-t bg-muted/50 font-medium [&>tr]:last:border-b-0', className)}
    {...props}
  />
));
TableFooter.displayName = 'TableFooter';

const TableRow = React.forwardRef<HTMLTableRowElement, React.HTMLAttributes<HTMLTableRowElement>>(
  ({ className, ...props }, ref) => (
    <tr
      ref={ref}
      className={cn(
        'border-b transition-colors hover:bg-muted/50 hover:outline-1 hover:outline-primary data-[state=selected]:bg-muted',
        className,
      )}
      {...props}
    />
  ),
);
TableRow.displayName = 'TableRow';

const TableHead = React.forwardRef<HTMLTableCellElement, React.ThHTMLAttributes<HTMLTableCellElement>>(
  ({ className, ...props }, ref) => (
    <th
      ref={ref}
      // `scope="col"` is emitted for every header cell: without it a screen
      // reader cannot associate a data cell with its column. Callers may
      // override with scope="row" for a header cell that labels its row.
      scope="col"
      className={cn(
        // Header cells intentionally carry NO hover outline — only the
        // row gets the maroon highlight.
        'h-10 px-2 text-left align-middle font-medium text-muted-foreground [&:has([role=checkbox])]:pr-0',
        className,
      )}
      {...props}
    />
  ),
);
TableHead.displayName = 'TableHead';

const TableCell = React.forwardRef<HTMLTableCellElement, React.TdHTMLAttributes<HTMLTableCellElement>>(
  ({ className, ...props }, ref) => (
    <td
      ref={ref}
      className={cn(
        // Cells carry no hover outline — the ROW provides the maroon
        // highlight (see TableRow).
        'p-2 align-middle [&:has([role=checkbox])]:pr-0',
        className,
      )}
      {...props}
    />
  ),
);
TableCell.displayName = 'TableCell';

const TableCaption = React.forwardRef<
  HTMLTableCaptionElement,
  React.HTMLAttributes<HTMLTableCaptionElement>
>(({ className, ...props }, ref) => (
  <caption ref={ref} className={cn('mt-4 text-sm text-muted-foreground', className)} {...props} />
));
TableCaption.displayName = 'TableCaption';

export { Table, TableHeader, TableBody, TableFooter, TableHead, TableRow, TableCell, TableCaption };
