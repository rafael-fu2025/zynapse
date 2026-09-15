import { zodResolver } from '@hookform/resolvers/zod';
import { Loader2 } from 'lucide-react';
import { useEffect } from 'react';
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
import { Textarea } from '@/components/ui/textarea';
import { useUpdateEquipment } from '@/hooks/useEquipment';
import { EQUIPMENT_CATEGORIES } from '@/data/taxonomy';
import {
  updateEquipmentSchema,
  type EquipmentItem,
  type UpdateEquipmentInput,
} from '@/schemas/equipment';

/**
 * Edit the equipment catalog row only. Unit statuses are managed in the
 * units dialog — every status change must go through the logged path.
 */
export function EditEquipmentDialog({ item, onClose }: { item: EquipmentItem; onClose: () => void }) {
  const update = useUpdateEquipment();
  const { register, handleSubmit, setValue, watch, formState: { errors }, reset } =
    useForm<UpdateEquipmentInput>({
      resolver: zodResolver(updateEquipmentSchema),
      defaultValues: {
        name: item.name,
        category: item.category ?? '',
        location: item.location ?? '',
        notes: item.notes ?? '',
      },
    });

  const category = watch('category') ?? '';

  // Re-seed the form if the row changes while the dialog is open
  // (background refetch after a status change elsewhere).
  useEffect(() => {
    reset({
      name: item.name,
      category: item.category ?? '',
      location: item.location ?? '',
      notes: item.notes ?? '',
    });
  }, [item, reset]);

  const onSubmit = handleSubmit((values) => {
    update.mutate({ equipmentId: item.id, input: values }, { onSuccess: onClose });
  });

  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle>Edit equipment — {item.name}</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="edit_equipment_name">Name</Label>
          <Input id="edit_equipment_name" aria-invalid={errors.name !== undefined} {...register('name')} />
          {errors.name !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.name.message}</p>
          )}
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="edit_equipment_category">Category</Label>
            <ComboboxField
              id="edit_equipment_category"
              sourceKey="clinic_equipment.category"
              options={EQUIPMENT_CATEGORIES}
              value={category}
              onChange={(v) => setValue('category', v, { shouldValidate: true, shouldDirty: true })}
              allowCreate
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="edit_equipment_location">Location</Label>
            <Input id="edit_equipment_location" aria-invalid={errors.location !== undefined} {...register('location')} />
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit_equipment_notes">Notes</Label>
          <Textarea id="edit_equipment_notes" rows={2} maxLength={500} {...register('notes')} />
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
