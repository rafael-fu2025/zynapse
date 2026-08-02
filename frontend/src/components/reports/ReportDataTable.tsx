import { useId } from 'react';
import { QueryErrorState } from '@/components/QueryErrorState';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

export interface ReportTableRow {
  id: string;
  cells: Array<string | number>;
}

interface Props {
  title: string;
  columns: string[];
  rows: ReportTableRow[];
  loading: boolean;
  error?: boolean;
  onRetry?: () => void;
  fetching?: boolean;
  emptyMessage?: string;
}

export function ReportDataTable({
  title,
  columns,
  rows,
  loading,
  error = false,
  onRetry,
  fetching = false,
  emptyMessage = 'No data was recorded in this range.',
}: Props) {
  const headingId = useId();

  return (
    <section className="min-w-0 overflow-hidden rounded-xl border bg-card" aria-labelledby={headingId}>
      <div className="border-b px-4 py-3">
        <h3 id={headingId} className="text-sm font-semibold text-foreground">{title}</h3>
      </div>
      {error && onRetry !== undefined ? (
        <div className="p-3">
          <QueryErrorState message={'Failed to load ' + title.toLowerCase() + '.'} onRetry={onRetry} pending={fetching} />
        </div>
      ) : loading ? (
        <div className="space-y-2 p-4" role="status" aria-label={'Loading ' + title.toLowerCase()}>
          <Skeleton className="h-5 w-3/4" />
          <Skeleton className="h-5 w-full" />
          <Skeleton className="h-5 w-2/3" />
          <span className="sr-only">Loading {title.toLowerCase()}.</span>
        </div>
      ) : rows.length === 0 ? (
        <p className="px-4 py-8 text-center text-sm text-muted-foreground">{emptyMessage}</p>
      ) : (
        <>
          <div className="hidden md:block">
            <Table aria-labelledby={headingId}>
              <caption className="sr-only">{title}</caption>
              <TableHeader className="bg-muted/50">
                <TableRow>
                  {columns.map((column) => <TableHead key={column} className="px-3">{column}</TableHead>)}
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.id}>
                    {row.cells.map((value, index) => (
                      <TableCell key={columns[index]} className={index === row.cells.length - 1 ? 'px-3 text-xs tabular-nums' : 'px-3 text-xs'}>
                        {value}
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
          <ul className="divide-y md:hidden">
            {rows.map((row) => (
              <li key={row.id} className="space-y-2 px-4 py-3">
                {row.cells.map((value, index) => (
                  <div key={columns[index]} className="grid grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] gap-3 text-sm">
                    <span className="text-muted-foreground">{columns[index]}</span>
                    <span className="min-w-0 break-words text-right text-foreground">{value}</span>
                  </div>
                ))}
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  );
}
