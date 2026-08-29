import { zodResolver } from '@hookform/resolvers/zod';
import { Loader2 } from 'lucide-react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { DatePicker } from '@/components/ui/date-picker';
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
import { useAddBatch } from '@/hooks/useMedicines';
import { useReceivableReorder } from '@/hooks/useReorders';
import {
  addBatchSchema,
  type AddBatchInput,
  type Medicine,
} from '@/schemas/medicines';

/**
 * AddBatchDialog — receive a delivered lot. The quantity defaults to
 * the medicine's `received` reorder request (procurement loop), but
 * the operator can lower it for partial deliveries (Gap 8) and add a
 * shortage reason that lands in the ledger. When no delivery has
 * been marked received on the Reorders tab, receiving is blocked —
 * mirroring the backend's 409 gate.
 */
export function AddBatchDialog({ medicine, onClose }: { medicine: Medicine; onClose: () => void }) {
  const add = useAddBatch();
  const receivable = useReceivableReorder('medicine', medicine.id);
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<AddBatchInput>({ resolver: zodResolver(addBatchSchema) });

  const onSubmit = handleSubmit((values) => {
    add.mutate({ medicineId: medicine.id, input: values }, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  const order = receivable.data ?? null;

  // Prefill the quantity field once the receivable order is known, so
  // the operator sees the ordered amount by default — and the shortage
  // block (below) only appears when they intentionally lower it.
  useEffect(() => {
    if (order !== null && watch('quantity') === undefined) {
      setValue('quantity', order.requested_quantity, { shouldDirty: false });
    }
  }, [order, setValue, watch]);

  const quantity = watch('quantity');

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Receive batch — {medicine.generic_name}</DialogTitle>
      </DialogHeader>

      {receivable.isLoading && <Loader2 className="mx-auto size-5 animate-spin text-muted-foreground" />}

      {!receivable.isLoading && order === null && (
        <p role="alert" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          No received delivery for this medicine. Order it on the Reorders tab and mark
          the request as <span className="font-medium">received</span> when the delivery
          arrives — then the batch can be entered here.
        </p>
      )}

      {order !== null && (
        <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <p className="col-span-2 rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
            Receiving reorder <span className="font-mono">#{order.id}</span> —{' '}
            <span className="font-medium text-foreground">{order.requested_quantity} {medicine.unit}</span>{' '}
            ordered. Lower the quantity below for a partial delivery and explain the shortfall.
          </p>
          <div className="space-y-1.5">
            <Label htmlFor="batch_number">Batch / lot number</Label>
            <Input id="batch_number" aria-invalid={errors.batch_number !== undefined} {...register('batch_number')} />
            {errors.batch_number !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.batch_number.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="batch_quantity">Quantity received</Label>
            <Input
              id="batch_quantity"
              type="number"
              min={1}
              max={order.requested_quantity}
              aria-invalid={errors.quantity !== undefined}
              {...register('quantity', { valueAsNumber: true })}
            />
            {errors.quantity !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.quantity.message}</p>
            )}
          </div>
          {/* Shortage capture — only shown when the operator lowered the
              quantity below what was ordered. The note lands in the
              transaction ledger so the discrepancy is auditable. */}
          {(quantity ?? order.requested_quantity) < order.requested_quantity && (
            <div className="col-span-2 space-y-1.5">
              <Label htmlFor="shortage_note">Shortage reason</Label>
              <Textarea
                id="shortage_note"
                rows={2}
                maxLength={255}
                placeholder="e.g. supplier back-ordered 30, expected next week."
                aria-invalid={errors.shortage_note !== undefined}
                {...register('shortage_note')}
              />
              {errors.shortage_note !== undefined && (
                <p role="alert" className="text-xs text-destructive">{errors.shortage_note.message}</p>
              )}
              <p className="text-xs text-muted-foreground">
                Short by {order.requested_quantity - (quantity ?? order.requested_quantity)} {medicine.unit}.
                The reorder will stay open so you can chase the supplier or raise a follow-up.
              </p>
            </div>
          )}
          <div className="space-y-1.5">
            <Label htmlFor="expiration_date">Expiration date</Label>
            <DatePicker id="expiration_date" aria-invalid={errors.expiration_date !== undefined} value={watch('expiration_date') ?? ''} onChange={(v) => setValue('expiration_date', v, { shouldValidate: true, shouldDirty: true })} />
            {errors.expiration_date !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.expiration_date.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="supplier">Supplier</Label>
            <Input id="supplier" {...register('supplier')} />
          </div>
          <DialogFooter className="col-span-2">
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={add.isPending}>
              {add.isPending && <Loader2 className="animate-spin" />} Receive
            </Button>
          </DialogFooter>
        </form>
      )}

      {!receivable.isLoading && order === null && (
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Close</Button>
        </DialogFooter>
      )}
    </DialogContent>
  );
}
