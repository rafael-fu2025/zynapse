import { zodResolver } from '@hookform/resolvers/zod';
import { Loader2 } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { ComboboxField } from '@/components/ComboboxField';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useUpdateItem } from '@/hooks/useInventory';
import {
  updateItemSchema,
  type InventoryItem,
  type UpdateItemInput,
} from '@/schemas/inventory';
import { INVENTORY_UNITS } from '@/data/taxonomy';

/**
 * EditItemDialog — update a supply item (Supplies tab). SKU is
 * intentionally NOT editable; it backs the movement ledger.
 */
export function EditItemDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const update = useUpdateItem();
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<UpdateItemInput>({
      resolver: zodResolver(updateItemSchema),
      defaultValues: {
        name: item.name,
        unit: item.unit,
        reorder_level: item.reorder_level,
        target_stock: item.target_stock ?? Math.max(1, item.reorder_level * 2),
      },
    });

  const unit = watch('unit') ?? item.unit;

  const onSubmit = handleSubmit((values) => {
    update.mutate(
      { itemId: item.id, input: values },
      { onSuccess: () => { reset(); onClose(); } },
    );
  });

  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle>Edit — {item.sku}</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <p className="text-xs text-muted-foreground">
          SKU is immutable because it identifies stock transactions. To rename it, archive and recreate.
        </p>
        <div className="space-y-1.5">
          <Label htmlFor="edit_item_name">Name</Label>
          <Input id="edit_item_name" aria-invalid={errors.name !== undefined} {...register('name')} />
          {errors.name !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.name.message}</p>
          )}
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="edit_item_unit">Unit</Label>
            <ComboboxField
              id="edit_item_unit"
              sourceKey="clinic.inventory_items.unit"
              options={INVENTORY_UNITS}
              value={unit}
              onChange={(v) => setValue('unit', v, { shouldValidate: true, shouldDirty: true })}
              placeholder="pc, box, mL …"
              allowCreate
            />
            {errors.unit !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.unit.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="edit_item_reorder_level">Reorder level</Label>
            <Input
              id="edit_item_reorder_level"
              type="number"
              min={0}
              {...register('reorder_level', { valueAsNumber: true })}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="edit_item_target_stock">Target stock</Label>
            <Input
              id="edit_item_target_stock"
              type="number"
              min={1}
              aria-invalid={errors.target_stock !== undefined}
              {...register('target_stock', { valueAsNumber: true })}
            />
            {errors.target_stock !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.target_stock.message}</p>
            )}
          </div>
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={update.isPending}>
            {update.isPending && <Loader2 className="animate-spin" />} Save
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
