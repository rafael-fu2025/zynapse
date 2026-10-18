import { zodResolver } from '@hookform/resolvers/zod';
import { Loader2, Syringe } from 'lucide-react';
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
import { ClinicCatalogPicker } from '@/components/clinic/ClinicCatalogPicker';
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { useDispense } from '@/hooks/useMedicines';
import {
  dispenseSchema,
  type DispenseInput,
  type Medicine,
} from '@/schemas/medicines';

export function DispenseDialog({ medicine, onClose }: { medicine: Medicine; onClose: () => void }) {
  const dispense = useDispense();
  // Panel revision: dispensing is anchored to an OPEN encounter (the
  // actual clinic visit), so the ledger records who received the stock.
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<DispenseInput>({ resolver: zodResolver(dispenseSchema) });
  const encounterId = watch('encounter_id');

  const onSubmit = handleSubmit((values) => {
    dispense.mutate({ medicineId: medicine.id, input: values }, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });


  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle>Dispense — {medicine.generic_name}</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <p className="text-xs text-muted-foreground">
          On hand: <span className="tabular-nums">{medicine.quantity_on_hand} {medicine.unit}</span>.
          Stock is drawn from the earliest-expiring lot first (FEFO).
        </p>
        <div className="space-y-1.5">
          <Label htmlFor="dispense-encounter">Open encounter</Label>
          <ClinicCatalogPicker kind="encounter" id="dispense-encounter" value={encounterId !== undefined ? String(encounterId) : ''} onChange={(v) => setValue('encounter_id', v === '' ? Number.NaN : Number(v), { shouldValidate: true })} />
          {errors.encounter_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.encounter_id.message}</p>
          )}

        </div>
        <div className="space-y-1.5">
          <Label htmlFor="quantity">Quantity</Label>
          <Input
            id="quantity"
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
          <Label htmlFor="note">Note (optional)</Label>
          <Input id="note" {...register('note')} />
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Tooltip>
            <TooltipTrigger asChild>
              {/* Span wrapper so the tooltip fires on the disabled submit. */}
              <span className="inline-flex">
                <Button
                  type="submit"
                  disabled={dispense.isPending || encounterId === undefined || medicine.quantity_on_hand === 0}
                >
                  {dispense.isPending && <Loader2 className="animate-spin" />}
                  <Syringe /> Dispense
                </Button>
              </span>
            </TooltipTrigger>
            {medicine.quantity_on_hand === 0 ? (
              <TooltipContent>No stock — receive a batch first</TooltipContent>
            ) : null}
          </Tooltip>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
