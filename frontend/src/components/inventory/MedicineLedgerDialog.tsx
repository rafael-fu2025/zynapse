import { ScrollText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { titleCase } from '@/lib/utils';
import { useMedicineTransactions } from '@/hooks/useMedicines';
import type { Medicine } from '@/schemas/medicines';
import { LedgerBody } from './LedgerBody';

export function MedicineLedgerDialog({ medicine, onClose }: { medicine: Medicine; onClose: () => void }) {
  const txns = useMedicineTransactions(medicine.id);
  const rows = (txns.data ?? []).map((t) => ({
    id: t.id,
    label: t.reference_type !== null ? `${titleCase(t.type)} · ${titleCase(t.reference_type)}#${t.reference_id ?? '?'}` : titleCase(t.type),
    by: t.user_email ?? null,
    qty_in: t.qty_in,
    qty_out: t.qty_out,
    balance_after: t.balance_after,
    note: t.note,
    created_at: t.created_at,
  }));
  return (
    <DialogContent className="max-w-2xl">
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2"><ScrollText className="size-4" /> Transactions — {medicine.generic_name}</DialogTitle>
      </DialogHeader>
      <p className="text-xs text-muted-foreground">Every stock movement, oldest first. Stock after is the on-hand quantity following each transaction.</p>
      <LedgerBody rows={rows} isLoading={txns.isLoading} isError={txns.isError} emptyLabel="No transactions yet." />
      <DialogFooter><Button variant="outline" onClick={onClose}>Close</Button></DialogFooter>
    </DialogContent>
  );
}
