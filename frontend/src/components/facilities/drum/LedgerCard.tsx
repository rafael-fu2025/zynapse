/**
 * LedgerCard — presentation shared by "Waste loaded" and "Compost
 * harvested". Both are append-only ledgers: rows are voided with a
 * reason, never edited or deleted, so the pair of rows is the audit
 * trail. The card owns the void flow; the caller owns the record form.
 */
import { Loader2, Plus, Trash2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { fmtUtcToApp } from '@/utils/date';

export interface LedgerRow {
  id: number;
  weightLabel: string;
  recordedAt: string;
  /** Optional secondary text (note, grade…). */
  detail: string | null;
  voided: boolean;
  voidReason: string | null;
}

interface LedgerCardProps {
  title: string;
  icon: ReactNode;
  totalLabel: string;
  rows: LedgerRow[];
  loading: boolean;
  error: boolean;
  emptyText: string;
  canRecord: boolean;
  recordLabel: string;
  onRecord: () => void;
  voidTitle: string;
  voidConfirmLabel: string;
  voidPending: boolean;
  /** Call `done` once the void succeeded so the dialog can close. */
  onVoid: (row: LedgerRow, reason: string, done: () => void) => void;
}

export function LedgerCard({
  title,
  icon,
  totalLabel,
  rows,
  loading,
  error,
  emptyText,
  canRecord,
  recordLabel,
  onRecord,
  voidTitle,
  voidConfirmLabel,
  voidPending,
  onVoid,
}: LedgerCardProps) {
  const [pendingVoid, setPendingVoid] = useState<LedgerRow | null>(null);
  const [reason, setReason] = useState('');

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0 pb-3">
        <CardTitle className="flex items-center gap-2 whitespace-nowrap text-base">
          {icon} {title}
          <Badge variant="secondary" className="whitespace-nowrap tabular-nums">{totalLabel}</Badge>
        </CardTitle>
        {canRecord && (
          <Button size="sm" variant="outline" onClick={onRecord}>
            <Plus /> {recordLabel}
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {loading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
        {error && (
          <p className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
            Failed to load this ledger.
          </p>
        )}
        {!loading && !error && rows.length === 0 && (
          <p className="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">
            {emptyText}
          </p>
        )}
        {rows.length > 0 && (
          <ul className="max-h-64 divide-y overflow-auto rounded-md border">
            {rows.map((row) => (
              <li key={row.id} className="flex items-center justify-between gap-2 px-3 py-2">
                <div className="min-w-0">
                  <p
                    className={cn(
                      'text-sm font-medium tabular-nums',
                      row.voided && 'text-muted-foreground line-through',
                    )}
                  >
                    {row.weightLabel}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {fmtUtcToApp(row.recordedAt)}
                    {row.detail !== null && row.detail !== '' ? ` · ${row.detail}` : ''}
                  </p>
                  {row.voided && (
                    <p className="text-xs text-muted-foreground">Voided: {row.voidReason}</p>
                  )}
                </div>
                {!row.voided && canRecord && (
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                      setPendingVoid(row);
                      setReason('');
                    }}
                  >
                    <Trash2 /> Void
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </CardContent>

      <Dialog open={pendingVoid !== null} onOpenChange={(next) => !next && setPendingVoid(null)}>
        <DialogContent size="md">
          <DialogHeader>
            <DialogTitle>{voidTitle}</DialogTitle>
            <DialogDescription>
              {pendingVoid?.weightLabel ?? ''} will be excluded from every total. The row stays in the
              ledger with your reason.
            </DialogDescription>
          </DialogHeader>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault();
              if (pendingVoid === null || reason.trim() === '') return;
              onVoid(pendingVoid, reason, () => setPendingVoid(null));
            }}
          >
            <div className="space-y-1.5">
              <Label htmlFor="ledger-void-reason">Reason</Label>
              <Input
                id="ledger-void-reason"
                maxLength={255}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
              />
            </div>
            <DialogFooter className="gap-2 sm:gap-0">
              <Button type="button" variant="outline" onClick={() => setPendingVoid(null)}>
                Cancel
              </Button>
              <Button type="submit" variant="destructive" disabled={voidPending || reason.trim() === ''}>
                {voidPending && <Loader2 className="animate-spin" />}
                {voidConfirmLabel}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
