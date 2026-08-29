import { CalendarX2, Loader2, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useExpireBatch, useRecallBatch } from '@/hooks/useMedicines';
import type { Medicine, MedicineBatch } from '@/schemas/medicines';

/**
 * WriteOffBatchDialog — confirm + note for writing an active batch off
 * as expired / recalled (inventory audit fix). The remaining stock is
 * zeroed and a typed ledger transaction is recorded server-side.
 */
export function WriteOffBatchDialog({
  medicine,
  batch,
  reason,
  onClose,
}: {
  medicine: Medicine;
  batch: MedicineBatch;
  reason: 'expire' | 'recall';
  onClose: () => void;
}) {
  const expire = useExpireBatch();
  const recall = useRecallBatch();
  const mutation = reason === 'expire' ? expire : recall;
  const [note, setNote] = useState('');

  return (
    <DialogContent className="max-w-md">
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          {reason === 'expire' ? <CalendarX2 className="size-4" /> : <ShieldAlert className="size-4" />}
          {reason === 'expire' ? 'Write off as expired' : 'Recall batch'} — {batch.batch_number}
        </DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <p className="rounded-md bg-muted/50 p-3 text-xs text-muted-foreground">
          {medicine.generic_name} · {batch.quantity_remaining}/{batch.quantity_received} {medicine.unit} remaining ·
          expires {batch.expiration_date}.
          {reason === 'expire'
            ? ' The remaining stock will be zeroed and recorded as an expired write-off.'
            : ' The remaining stock will be zeroed and recorded as a recalled write-off.'}
        </p>
        <div className="space-y-1.5">
          <Label htmlFor="writeoff_note">Note (optional)</Label>
          <Input
            id="writeoff_note"
            value={note}
            onChange={(e) => setNote(e.target.value)}
            maxLength={255}
            placeholder={reason === 'expire' ? 'e.g. expired on shelf' : 'e.g. manufacturer recall'}
          />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button
          variant={reason === 'expire' ? 'destructive' : 'secondary'}
          disabled={mutation.isPending}
          onClick={() => mutation.mutate(
            { medicineId: medicine.id, batchId: batch.id, ...(note !== '' ? { note } : {}) },
            { onSuccess: onClose },
          )}
        >
          {mutation.isPending && <Loader2 className="animate-spin" />}
          {reason === 'expire' ? 'Expire batch' : 'Recall batch'}
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}
