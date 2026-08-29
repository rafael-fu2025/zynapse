import { zodResolver } from '@hookform/resolvers/zod';
import { Loader2 } from 'lucide-react';
import { useCallback } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
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
import { apiClient } from '@/api/client';
import { useCreateMedicine } from '@/hooks/useMedicines';
import {
  createMedicineSchema,
  medicineSchema,
  type CreateMedicineInput,
} from '@/schemas/medicines';
import {
  MEDICINE_CATEGORIES,
  MEDICINE_DOSAGE_FORMS,
  MEDICINE_STRENGTHS,
  MEDICINE_STRENGTH_PATTERN,
  MEDICINE_UNITS,
  type TaxonomyEntry,
} from '@/data/taxonomy';

export function CreateMedicineDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateMedicine();
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<CreateMedicineInput>({
      resolver: zodResolver(createMedicineSchema),
      defaultValues: { unit: 'pc', reorder_threshold: 10, target_stock: 20 },
    });

  const genericName = watch('generic_name') ?? '';
  const category = watch('category') ?? '';
  const dosageForm = watch('dosage_form') ?? '';
  const dosageStrength = watch('dosage_strength') ?? '';
  const unit = watch('unit') ?? 'pc';

  // Gap 2: live-search the catalogue as the operator types the
  // generic name, so duplicate Paracetamol / Paracetemol / etc. are
  // flagged before they hit POST. Backend already supports ?q= on
  // /clinic/medicines — this just wires the existing search to the
  // autocomplete. AbortController cancels in-flight requests when a
  // newer keystroke supersedes them.
  const fetchMedicineOptions = useCallback(
    async (q: string, signal: AbortSignal): Promise<ReadonlyArray<TaxonomyEntry>> => {
      if (q.trim().length < 2) return [];
      const params = new URLSearchParams();
      params.set('q', q.trim());
      params.set('limit', '10');
      const res = await apiClient.get<{ data: unknown[] }>(
        `/clinic/medicines?${params.toString()}`,
        { signal },
      );
      const medicines = z.array(medicineSchema).parse(res.data);
      return medicines.map((m): TaxonomyEntry => ({
        value: m.generic_name,
        label: m.generic_name,
        hint: [
          m.dosage_strength,
          m.dosage_form,
          m.brand_name !== null && m.brand_name !== '' ? `(${m.brand_name})` : null,
          `${m.quantity_on_hand} ${m.unit} on hand`,
        ]
          .filter((s): s is string => s !== null && s !== '')
          .join(' · '),
      }));
    },
    [],
  );

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
        <DialogTitle>New medicine</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="generic_name">Generic name</Label>
          <ComboboxField
            id="generic_name"
            sourceKey="clinic.medicines.generic_name"
            options={[]}
            value={genericName}
            onChange={(v) => setValue('generic_name', v, { shouldValidate: true, shouldDirty: true })}
            placeholder="Type at least 2 letters…"
            allowCreate
            fetchOptions={fetchMedicineOptions}
            loadingLabel="Searching catalog…"
            emptyHintLabel="Type to search the existing catalog — matches will appear here."
          />
          {errors.generic_name !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.generic_name.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="brand_name">Brand name</Label>
          <Input id="brand_name" {...register('brand_name')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="category">Category</Label>
          <ComboboxField
            id="category"
            sourceKey="clinic.medicines.category"
            options={MEDICINE_CATEGORIES}
            value={category}
            onChange={(v) => setValue('category', v, { shouldValidate: true, shouldDirty: true })}
            placeholder="analgesic, antibiotic, vitamin …"
            allowCreate
          />
          {errors.category !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.category.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="dosage_form">Form</Label>
          <ComboboxField
            id="dosage_form"
            sourceKey="clinic.medicines.dosage_form"
            options={MEDICINE_DOSAGE_FORMS}
            value={dosageForm}
            onChange={(v) => setValue('dosage_form', v, { shouldValidate: true, shouldDirty: true })}
            placeholder="tablet, capsule, syrup …"
            allowCreate
          />
          {errors.dosage_form !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.dosage_form.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="dosage_strength">Strength</Label>
          <ComboboxField
            id="dosage_strength"
            sourceKey="clinic.medicines.dosage_strength"
            options={MEDICINE_STRENGTHS}
            value={dosageStrength}
            onChange={(v) => setValue('dosage_strength', v, { shouldValidate: true, shouldDirty: true })}
            placeholder="500mg, 5mg/mL, 0.5%, 100units/mL…"
            allowCreate
            pattern={MEDICINE_STRENGTH_PATTERN}
            patternTitle="Use a recognized FDA shape, e.g. 500mg, 5mg/mL, 100mg/5mL, 0.5%, or 100units/mL."
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="med_unit">Unit</Label>
          <ComboboxField
            id="med_unit"
            sourceKey="clinic.medicines.unit"
            options={MEDICINE_UNITS}
            value={unit}
            onChange={(v) => setValue('unit', v, { shouldValidate: true, shouldDirty: true })}
            placeholder="tab, mL, vial …"
            allowCreate
          />
          {errors.unit !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.unit.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="reorder_threshold">Reorder threshold</Label>
          <Input
            id="reorder_threshold"
            type="number"
            min={0}
            aria-invalid={errors.reorder_threshold !== undefined}
            {...register('reorder_threshold', { valueAsNumber: true })}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="medicine_target_stock">Target stock</Label>
          <Input
            id="medicine_target_stock"
            type="number"
            min={1}
            aria-invalid={errors.target_stock !== undefined}
            {...register('target_stock', { valueAsNumber: true })}
          />
          {errors.target_stock !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.target_stock.message}</p>
          )}
        </div>
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="description">Notes / indications</Label>
          <Textarea
            id="description"
            rows={3}
            maxLength={2000}
            placeholder="Common uses, storage instructions, supply notes…"
            aria-invalid={errors.description !== undefined}
            {...register('description')}
          />
          {errors.description !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.description.message}</p>
          )}
        </div>
        <DialogFooter className="col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Create
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
