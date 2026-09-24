import { TableStateRows } from '@/components/TableStates';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { fmtUtcToApp } from '@/utils/date';
import { initialsFromEmail } from './format';

/**
 * LedgerRow — one debit/credit line rendered by both the medicine and
 * supply ledgers (panel revision: in/out tracking with a running
 * balance). `qty_in` and `qty_out` are mutually exclusive.
 */
export function LedgerBody({
  rows,
  isLoading,
  isError,
  onRetry,
  pending = false,
  emptyLabel,
  ariaLabel,
}: {
  rows: Array<{ id: number; label: string; by: string | null; qty_in: number | null; qty_out: number | null; balance_after: number | null; note: string | null; created_at: string }>;
  isLoading: boolean;
  isError: boolean;
  /** Required: a failed ledger read must offer a way to recover. */
  onRetry: () => void;
  pending?: boolean;
  emptyLabel: string;
  ariaLabel: string;
}) {
  return (
    <div className="max-h-96 overflow-auto rounded-md border">
      <Table ariaLabel={ariaLabel}>
        <TableHeader className="sticky top-0 bg-muted/70">
          <TableRow>
            <TableHead className="px-3">Date</TableHead>
            <TableHead className="px-3">Reference</TableHead>
            <TableHead className="px-3 text-right">In</TableHead>
            <TableHead className="px-3 text-right">Out</TableHead>
            <TableHead className="px-3 text-right">Stock after</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableStateRows
            colSpan={5}
            isLoading={isLoading}
            isError={isError}
            isEmpty={rows.length === 0}
            onRetry={onRetry}
            pending={pending}
            errorMessage="Failed to load the transactions."
            loadingLabel="Loading transactions"
            empty={{ title: emptyLabel }}
          />
          {rows.map((r) => (
            <TableRow key={r.id}>
              <TableCell className="px-3 text-xs text-muted-foreground">{fmtUtcToApp(r.created_at)}</TableCell>
              <TableCell className="px-3 text-xs">
                {r.label}
                {r.by !== null && r.by !== '' && (
                  <span className="ml-1 text-muted-foreground">· by {initialsFromEmail(r.by)}</span>
                )}
                {r.note !== null && r.note !== '' ? <span className="ml-1 text-muted-foreground">· {r.note}</span> : ''}
              </TableCell>
              <TableCell className="px-3 text-right tabular-nums text-xs text-emerald-600">{r.qty_in !== null ? `+${r.qty_in}` : ''}</TableCell>
              <TableCell className="px-3 text-right tabular-nums text-xs text-destructive">{r.qty_out !== null ? `-${r.qty_out}` : ''}</TableCell>
              <TableCell className="px-3 text-right tabular-nums text-xs font-semibold">{r.balance_after ?? '—'}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
