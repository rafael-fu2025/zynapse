import { ScrollText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useInventoryMovements } from '@/hooks/useInventory';
import type { InventoryItem } from '@/schemas/inventory';
import { LedgerBody } from './LedgerBody';

export function SupplyLedgerDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const moves = useInventoryMovements(item.id);
  const rows = (moves.data ?? []).map((m) => ({
    id: m.id,
    label: m.reference_type !== null ? `${m.reason_code} · ${m.reference_type}#${m.reference_id ?? '?'}` : m.reason_code,
    by: m.user_email ?? null,
    qty_in: m.qty_in,
    qty_out: m.qty_out,
    balance_after: m.balance_after,
    note: m.note,
    created_at: m.created_at,
  }));
  return (
    <DialogContent className="max-w-2xl">
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2"><ScrollText className="size-4" /> Transactions — {item.name}</DialogTitle>
      </DialogHeader>
      <p className="text-xs text-muted-foreground">Every stock movement, oldest first. Stock after is the on-hand quantity following each transaction.</p>
      <LedgerBody rows={rows} isLoading={moves.isLoading} isError={moves.isError} emptyLabel="No movements yet." />
      <DialogFooter><Button variant="outline" onClick={onClose}>Close</Button></DialogFooter>
    </DialogContent>
  );
}
