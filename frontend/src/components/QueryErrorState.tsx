/**
 * QueryErrorState / QueryErrorRow — consistent error + retry surfaces
 * for failed TanStack Query reads.
 *
 * Most list queries in the app previously rendered a failed fetch as a
 * misleading "empty" state with no way to recover. These helpers give
 * the user an explicit error message and a Retry button wired to the
 * query's `refetch`.
 *
 * - QueryErrorState: block-level (cards, panels, popovers).
 * - QueryErrorRow: a full-width <TableRow> for use inside a <TableBody>.
 */
import { AlertTriangle, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { TableCell, TableRow } from '@/components/ui/table';

interface BaseProps {
  message?: string;
  onRetry: () => void;
  /** True while a refetch is in flight — spins the icon + disables the button. */
  pending?: boolean;
}

export function QueryErrorState({
  message = 'Something went wrong loading this data.',
  onRetry,
  pending = false,
}: BaseProps) {
  return (
    <div
      role="alert"
      className="flex flex-col items-center gap-2 rounded-md border border-destructive/30 bg-destructive/5 p-6 text-center"
    >
      <AlertTriangle className="size-5 text-destructive" aria-hidden />
      <p className="text-sm text-destructive">{message}</p>
      <Button size="sm" variant="outline" onClick={onRetry} disabled={pending}>
        <RefreshCw className={pending ? 'animate-spin' : undefined} aria-hidden /> Retry
      </Button>
    </div>
  );
}

export function QueryErrorRow({
  colSpan,
  message = 'Failed to load. Please retry.',
  onRetry,
  pending = false,
}: BaseProps & { colSpan: number }) {
  return (
    <TableRow>
      <TableCell colSpan={colSpan} className="px-3 py-6 text-center">
        <div role="alert" className="flex flex-col items-center gap-2 text-destructive">
          <p className="text-sm">{message}</p>
          <Button size="sm" variant="outline" onClick={onRetry} disabled={pending}>
            <RefreshCw className={pending ? 'animate-spin' : undefined} aria-hidden /> Retry
          </Button>
        </div>
      </TableCell>
    </TableRow>
  );
}
