import { zodResolver } from '@hookform/resolvers/zod';
import { Loader2 } from 'lucide-react';
import { useEffect } from 'react';
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useInventoryItems } from '@/hooks/useInventory';
import { useMedicines } from '@/hooks/useMedicines';
import { useCreateReorder } from '@/hooks/useReorders';
import {
  createReorderSchema,
  type CreateReorderInput,
} from '@/schemas/reorders';

export function CreateReorderDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateReorder();
  const medicines = useMedicines(null, 25);
  const supplies = useInventoryItems(null, 100);
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<CreateReorderInput>({
      resolver: zodResolver(createReorderSchema),
      defaultValues: { item_type: 'medicine', urgency: 'medium' },
    });

  const itemType = watch('item_type');
  const medicineId = watch('medicine_id');
  const supplyItemId = watch('supply_item_id');
  const urgency = watch('urgency');

  // When an item is picked, prefill the quantity needed to reach its
  // configured target. Legacy records without a target retain the former
  // `2 × threshold` fallback until they are edited.
  useEffect(() => {
    if (itemType === 'medicine' && medicineId !== undefined) {
      const m = medicines.data?.data?.find((x) => x.id === medicineId);
      if (m !== undefined) {
        const suggested = Math.max(1, (m.target_stock ?? 2 * m.reorder_threshold) - m.quantity_on_hand);
        setValue('quantity', suggested, { shouldValidate: true, shouldDirty: true });
      }
      return;
    }
    if (itemType === 'supply' && supplyItemId !== undefined) {
      const it = supplies.data?.data?.find((x) => x.id === supplyItemId);
      if (it !== undefined) {
        const suggested = Math.max(1, (it.target_stock ?? 2 * it.reorder_level) - it.quantity_on_hand);
        setValue('quantity', suggested, { shouldValidate: true, shouldDirty: true });
      }
    }
  }, [itemType, medicineId, supplyItemId, medicines.data, supplies.data, setValue]);

  // Build the hint caption from the currently-selected item so the
  // operator can see the math at a glance.
  let qtyHint: string | null = null;
  if (itemType === 'medicine' && medicineId !== undefined) {
    const m = medicines.data?.data?.find((x) => x.id === medicineId);
    if (m !== undefined) {
      qtyHint = `On hand ${m.quantity_on_hand} · threshold ${m.reorder_threshold} · target ${m.target_stock ?? 'legacy'} · suggested ${Math.max(1, (m.target_stock ?? 2 * m.reorder_threshold) - m.quantity_on_hand)}`;
    }
  } else if (itemType === 'supply' && supplyItemId !== undefined) {
    const it = supplies.data?.data?.find((x) => x.id === supplyItemId);
    if (it !== undefined) {
      qtyHint = `On hand ${it.quantity_on_hand} · reorder level ${it.reorder_level} · target ${it.target_stock ?? 'legacy'} · suggested ${Math.max(1, (it.target_stock ?? 2 * it.reorder_level) - it.quantity_on_hand)}`;
    }
  }

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: () => {
        reset({ item_type: 'medicine', urgency: 'medium' });
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>New reorder request</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="space-y-1.5">
          <Label id="reorder-type-label">Item type</Label>
          <Select
            value={itemType}
            onValueChange={(v) => {
              setValue('item_type', v as CreateReorderInput['item_type']);
              // Switching type invalidates the previous pick.
              setValue('medicine_id', undefined);
              setValue('supply_item_id', undefined);
            }}
          >
            <SelectTrigger aria-labelledby="reorder-type-label"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="medicine">Medicine</SelectItem>
              <SelectItem value="supply">Supply</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label id="reorder-item-label">{itemType === 'supply' ? 'Supply item' : 'Medicine'}</Label>
          {itemType === 'supply' ? (
            <Select
              value={supplyItemId !== undefined ? String(supplyItemId) : ''}
              onValueChange={(v) => setValue('supply_item_id', Number(v), { shouldValidate: true })}
            >
              <SelectTrigger aria-labelledby="reorder-item-label">
                <SelectValue placeholder="Select…" />
              </SelectTrigger>
              <SelectContent>
                {(supplies.data?.data ?? []).map((it) => (
                  <SelectItem key={it.id} value={String(it.id)}>
                    {it.name} ({it.sku}) — {it.quantity_on_hand} {it.unit} on hand
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          ) : (
            <Select
              value={medicineId !== undefined ? String(medicineId) : ''}
              onValueChange={(v) => setValue('medicine_id', Number(v), { shouldValidate: true })}
            >
              <SelectTrigger aria-labelledby="reorder-item-label">
                <SelectValue placeholder="Select…" />
              </SelectTrigger>
              <SelectContent>
                {(medicines.data?.data ?? []).map((m) => (
                  <SelectItem key={m.id} value={String(m.id)}>
                    {m.generic_name} — {m.quantity_on_hand} {m.unit} on hand
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
          {errors.medicine_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.medicine_id.message}</p>
          )}
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="reorder_quantity">Quantity</Label>
            <Input
              id="reorder_quantity"
              type="number"
              min={1}
              aria-invalid={errors.quantity !== undefined}
              {...register('quantity', { valueAsNumber: true })}
            />
            {qtyHint !== null ? (
              <p className="text-xs text-muted-foreground">{qtyHint}</p>
            ) : null}
            {errors.quantity !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.quantity.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label id="reorder-urgency-label">Urgency</Label>
            <Select
              value={urgency}
              onValueChange={(v) => setValue('urgency', v as CreateReorderInput['urgency'])}
            >
              <SelectTrigger aria-labelledby="reorder-urgency-label"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="low">Low</SelectItem>
                <SelectItem value="medium">Medium</SelectItem>
                <SelectItem value="high">High</SelectItem>
                <SelectItem value="critical">Critical</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="reorder_note">Note (optional)</Label>
          <Input id="reorder_note" {...register('note')} />
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Request
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
