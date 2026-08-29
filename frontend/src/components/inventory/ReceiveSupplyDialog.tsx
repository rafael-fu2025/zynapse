import { Loader2, PackagePlus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useReceiveSupply } from '@/hooks/useInventory';
import { useReceivableReorder } from '@/hooks/useReorders';
import type { InventoryItem } from '@/schemas/inventory';

/**
 * ReceiveSupplyDialog — supply-side twin of the medicine batch
 * receive: quantity is detected from the item's `received` reorder
 * request; receiving is blocked until a delivery has been marked
 * received on the Reorders tab (backend enforces the same 409 gate).
 */
export function ReceiveSupplyDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const receive = useReceiveSupply();
  const receivable = useReceivableReorder('supply', item.id);
  const [note, setNote] = useState('');
  const [qty, setQty] = useState('');
  const [shortage, setShortage] = useState('');

  const order = receivable.data ?? null;
  const ordered = order?.requested_quantity ?? 0;
  const parsed = qty === '' ? Number.NaN : Number(qty);
  const validQty = Number.isInteger(parsed) && parsed >= 1 && parsed <= ordered;
  const isPartial = order !== null && validQty && parsed < ordered;

  // Prefill the quantity once the receivable order is known (partial
  // delivery support — inventory audit fix).
  useEffect(() => {
    if (order !== null && qty === '') setQty(String(order.requested_quantity));
  }, [order, qty]);

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Receive — {item.name}</DialogTitle>
      </DialogHeader>

      {receivable.isLoading && <Loader2 className="mx-auto size-5 animate-spin text-muted-foreground" />}

      {!receivable.isLoading && order === null && (
        <p role="alert" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          No received delivery for this item. Order it on the Reorders tab and mark the
          request as <span className="font-medium">received</span> when the delivery
          arrives — then the stock can be entered here.
        </p>
      )}

      {order !== null && (
        <div className="space-y-3">
          <p className="rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
            Receiving reorder <span className="font-mono">#{order.id}</span> —{' '}
            <span className="font-medium text-foreground">{ordered} {item.unit}</span>{' '}
            ordered. Lower the quantity below for a partial delivery.
          </p>
          <div className="space-y-1.5">
            <Label htmlFor="receive_supply_qty">Quantity received</Label>
            <Input
              id="receive_supply_qty"
              type="number"
              min={1}
              max={ordered}
              value={qty}
              aria-invalid={qty !== '' && !validQty}
              onChange={(e) => setQty(e.target.value)}
            />
            {qty !== '' && !validQty && (
              <p role="alert" className="text-xs text-destructive">Enter 1–{ordered}.</p>
            )}
          </div>
          {isPartial && (
            <div className="space-y-1.5">
              <Label htmlFor="receive_supply_shortage">Shortage reason</Label>
              <Textarea
                id="receive_supply_shortage"
                rows={2}
                maxLength={255}
                value={shortage}
                onChange={(e) => setShortage(e.target.value)}
                placeholder="e.g. supplier short-shipped 5, expected next week."
              />
              <p className="text-xs text-muted-foreground">
                Short by {ordered - parsed} {item.unit}. The reorder will stay open so you can
                chase the supplier or raise a follow-up.
              </p>
            </div>
          )}
          <div className="space-y-1.5">
            <Label htmlFor="receive_supply_note">Note (optional)</Label>
            <Input id="receive_supply_note" value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
          </div>
        </div>
      )}

      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button
          disabled={order === null || !validQty || receive.isPending}
          onClick={() => receive.mutate(
            {
              itemId: item.id,
              ...(note !== '' ? { note } : {}),
              ...(isPartial ? { quantity: parsed, ...(shortage !== '' ? { shortage_note: shortage } : {}) } : {}),
            },
            { onSuccess: onClose },
          )}
        >
          {receive.isPending && <Loader2 className="animate-spin" />}
          <PackagePlus /> Receive
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}
