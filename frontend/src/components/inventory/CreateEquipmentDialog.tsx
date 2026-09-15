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
import { Textarea } from '@/components/ui/textarea';
import { useCreateEquipment } from '@/hooks/useEquipment';
import { EQUIPMENT_CATEGORIES } from '@/data/taxonomy';
import {
  createEquipmentSchema,
  type CreateEquipmentInput,
} from '@/schemas/equipment';

export function CreateEquipmentDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateEquipment();
  const { register, handleSubmit, setValue, watch, formState: { errors } } =
    useForm<CreateEquipmentInput>({ resolver: zodResolver(createEquipmentSchema) });

  const category = watch('category') ?? '';

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, { onSuccess: onClose });
  });

  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle>New equipment</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="equipment_name">Name</Label>
          <Input
            id="equipment_name"
            aria-invalid={errors.name !== undefined}
            placeholder="e.g. BP apparatus (aneroid)"
            {...register('name')}
          />
          {errors.name !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.name.message}</p>
          )}
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="equipment_category">Category</Label>
            <ComboboxField
              id="equipment_category"
              sourceKey="clinic_equipment.category"
              options={EQUIPMENT_CATEGORIES}
              value={category}
              onChange={(v) => setValue('category', v, { shouldValidate: true, shouldDirty: true })}
              placeholder="e.g. Diagnostic"
              allowCreate
            />
            {errors.category !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.category.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="equipment_location">Location</Label>
            <Input
              id="equipment_location"
              placeholder="e.g. Consultation Room 1"
              aria-invalid={errors.location !== undefined}
              {...register('location')}
            />
            {errors.location !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.location.message}</p>
            )}
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="equipment_notes">Notes (optional)</Label>
          <Textarea id="equipment_notes" rows={2} maxLength={500} {...register('notes')} />
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
