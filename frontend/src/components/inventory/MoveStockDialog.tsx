import { zodResolver } from '@hookform/resolvers/zod';
import { Loader2 } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useMoveStock } from '@/hooks/useInventory';
import {
  moveStockSchema,
  type InventoryItem,
  type MoveStockInput,
} from '@/schemas/inventory';

/**
 * MoveStockDialog — free-form ledger correction (Adjust). Receipts are
 * NOT available here any more: delivered stock enters via the gated
 * Receive flow so every receipt traces back to a reorder request.
 */
export function MoveStockDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const move = useMoveStock();
  const {
    register,
    handleSubmit,
    formState: { errors },
    reset,
  } = useForm<MoveStockInput>({
    resolver: zodResolver(moveStockSchema),
    defaultValues: { reason_code: 'adjustment' },
  });

  const onSubmit = handleSubmit((values) => {
    move.mutate(
      { itemId: item.id, input: values },
      {
        onSuccess: () => {
          reset();
          onClose();
        },
      },
    );
  });

  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle>Adjust stock — {item.sku}</DialogTitle>
      </DialogHeader>
      <form onSubmit={(e) => void onSubmit(e)} className="space-y-3" noValidate>
        <p className="text-xs text-muted-foreground">
          On hand: <span className="font-mono">{item.quantity_on_hand} {item.unit}</span>.
          Adjustments may go either way. Dispensing is a separate action
          (tied to an open visit) and deliveries use the Receive button.
        </p>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="qty_delta">Quantity delta</Label>
            <Input id="qty_delta" type="number" aria-invalid={errors.qty_delta !== undefined} {...register('qty_delta', { valueAsNumber: true })} />
            {errors.qty_delta !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.qty_delta.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="reason_code">Reason</Label>
            <Input id="reason_code" value="Adjustment" readOnly disabled aria-label="Reason" />
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="note">Note (optional)</Label>
          <Input id="note" {...register('note')} />
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={move.isPending}>
            {move.isPending && <Loader2 className="animate-spin" />} Apply
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
