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
import { useCreateItem } from '@/hooks/useInventory';
import {
  createItemSchema,
  type CreateItemInput,
} from '@/schemas/inventory';
import { INVENTORY_UNITS } from '@/data/taxonomy';

export function CreateItemDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateItem();
  const {
    register,
    handleSubmit,
    formState: { errors },
    reset,
    setValue,
    watch,
  } = useForm<CreateItemInput>({
    resolver: zodResolver(createItemSchema),
    defaultValues: { unit: 'pc', reorder_level: 0, target_stock: 1 },
  });

  const unit = watch('unit') ?? 'pc';

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>New inventory item</DialogTitle>
      </DialogHeader>
      <form onSubmit={(e) => void onSubmit(e)} className="space-y-3" noValidate>
        <div className="space-y-1.5">
          <Label htmlFor="sku">SKU</Label>
          <Input id="sku" aria-invalid={errors.sku !== undefined} {...register('sku')} />
          {errors.sku !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.sku.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="name">Name</Label>
          <Input id="name" aria-invalid={errors.name !== undefined} {...register('name')} />
          {errors.name !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.name.message}</p>
          )}
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="unit">Unit</Label>
            <ComboboxField
              id="unit"
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
            <Label htmlFor="reorder_level">Reorder level</Label>
            <Input id="reorder_level" type="number" {...register('reorder_level', { valueAsNumber: true })} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="supply_target_stock">Target stock</Label>
            <Input
              id="supply_target_stock"
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
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Create
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
