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
import { Textarea } from '@/components/ui/textarea';
import { useAddEquipmentUnits } from '@/hooks/useEquipment';
import {
  addEquipmentUnitsSchema,
  type AddEquipmentUnitsInput,
  type EquipmentItem,
} from '@/schemas/equipment';

/**
 * Add physical units to an equipment item. New units start `working`
 * and the addition itself is recorded in each unit's status history.
 */
export function AddUnitsDialog({ item, onClose }: { item: EquipmentItem; onClose: () => void }) {
  const add = useAddEquipmentUnits();
  const { register, handleSubmit, formState: { errors } } =
    useForm<AddEquipmentUnitsInput>({
      resolver: zodResolver(addEquipmentUnitsSchema),
      defaultValues: { quantity: 1 },
    });

  const onSubmit = handleSubmit((values) => {
    add.mutate(
      { equipmentId: item.id, input: values },
      { onSuccess: onClose },
    );
  });

  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle>Add units — {item.name}</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <p className="rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
          {item.name} currently has {item.total_units} unit(s). New units are recorded as
          working — change a unit's status afterwards from Manage units.
        </p>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="add_units_quantity">Quantity</Label>
            <Input
              id="add_units_quantity"
              type="number"
              min={1}
              aria-invalid={errors.quantity !== undefined}
              {...register('quantity', { valueAsNumber: true })}
            />
            {errors.quantity !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.quantity.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="add_units_acquired">Acquired date (optional)</Label>
            <Input id="add_units_acquired" type="date" {...register('acquired_date')} />
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="add_units_note">Note (optional)</Label>
          <Textarea
            id="add_units_note"
            rows={2}
            maxLength={255}
            placeholder="e.g. Procured this semester"
            {...register('note')}
          />
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={add.isPending}>
            {add.isPending && <Loader2 className="animate-spin" />} Add units
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
