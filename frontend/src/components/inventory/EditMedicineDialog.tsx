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
import { useUpdateMedicine } from '@/hooks/useMedicines';
import {
  updateMedicineSchema,
  type Medicine,
  type UpdateMedicineInput,
} from '@/schemas/medicines';

/**
 * EditMedicineDialog — the catalog identity (names, category, form,
 * strength, unit) is read-only after creation so the batch ledger and
 * forecasts keep describing the same product. Only the reorder
 * threshold is editable; the backend enforces the same restriction.
 */
export function EditMedicineDialog({ medicine, onClose }: { medicine: Medicine; onClose: () => void }) {
  const update = useUpdateMedicine();
  const { register, handleSubmit, formState: { errors }, reset } =
    useForm<UpdateMedicineInput>({
      resolver: zodResolver(updateMedicineSchema),
      defaultValues: {
        reorder_threshold: medicine.reorder_threshold,
        target_stock: medicine.target_stock ?? Math.max(1, medicine.reorder_threshold * 2),
      },
    });

  const onSubmit = handleSubmit((values) => {
    update.mutate(
      { medicineId: medicine.id, input: values },
      { onSuccess: () => { reset(); onClose(); } },
    );
  });

  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle>Edit — {medicine.generic_name}</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <p className="col-span-2 text-xs text-muted-foreground">
          Catalog details are locked after creation; stock threshold and target can still change.
        </p>
        <div className="space-y-1.5">
          <Label htmlFor="edit_brand_name">Brand name</Label>
          <Input id="edit_brand_name" value={medicine.brand_name ?? '—'} readOnly disabled />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit_category">Category</Label>
          <Input id="edit_category" value={medicine.category ?? '—'} readOnly disabled />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit_dosage_form">Form</Label>
          <Input id="edit_dosage_form" value={medicine.dosage_form ?? '—'} readOnly disabled />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit_dosage_strength">Strength</Label>
          <Input id="edit_dosage_strength" value={medicine.dosage_strength ?? '—'} readOnly disabled />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit_med_unit">Unit</Label>
          <Input id="edit_med_unit" value={medicine.unit} readOnly disabled />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit_reorder_threshold">Reorder threshold</Label>
          <Input
            id="edit_reorder_threshold"
            type="number"
            min={0}
            aria-invalid={errors.reorder_threshold !== undefined}
            {...register('reorder_threshold', { valueAsNumber: true })}
          />
          {errors.reorder_threshold !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.reorder_threshold.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit_medicine_target_stock">Target stock</Label>
          <Input
            id="edit_medicine_target_stock"
            type="number"
            min={1}
            aria-invalid={errors.target_stock !== undefined}
            {...register('target_stock', { valueAsNumber: true })}
          />
          {errors.target_stock !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.target_stock.message}</p>
          )}
        </div>
        <DialogFooter className="col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={update.isPending}>
            {update.isPending && <Loader2 className="animate-spin" />} Save
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
