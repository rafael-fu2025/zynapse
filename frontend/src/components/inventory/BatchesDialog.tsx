import { CalendarX2, Loader2, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { titleCase } from '@/lib/utils';
import { useMedicine } from '@/hooks/useMedicines';
import type { MedicineBatch } from '@/schemas/medicines';
import { BATCH_STATUS_VARIANT } from './constants';
import { daysUntil } from './format';
import { WriteOffBatchDialog } from './WriteOffBatchDialog';

export function BatchesDialog({ medicineId, onClose }: { medicineId: number; onClose: () => void }) {
  const detail = useMedicine(medicineId);
  const m = detail.data;
  const [writeOff, setWriteOff] = useState<{ batch: MedicineBatch; reason: 'expire' | 'recall' } | null>(null);

  return (
    <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>
          Batches {m !== undefined ? `— ${m.generic_name} (${m.quantity_on_hand} ${m.unit} on hand)` : ''}
        </DialogTitle>
      </DialogHeader>

      {detail.isLoading && <Loader2 className="mx-auto size-5 animate-spin text-muted-foreground" />}

      {m !== undefined && (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Lot</TableHead>
              <TableHead>Remaining</TableHead>
              <TableHead>Expires</TableHead>
              <TableHead>Supplier</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {(m.batches ?? []).length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="py-6 text-center text-muted-foreground">
                  No batches received yet.
                </TableCell>
              </TableRow>
            )}
            {(m.batches ?? []).map((b) => {
              const days = daysUntil(b.expiration_date);
              const writable = b.status === 'active' && b.quantity_remaining > 0;
              return (
                <TableRow key={b.id}>
                  <TableCell className="font-mono text-xs">{b.batch_number}</TableCell>
                  <TableCell className="font-mono text-xs">
                    {b.quantity_remaining}/{b.quantity_received}
                  </TableCell>
                  <TableCell className="text-xs">
                    {b.expiration_date}
                    {b.status === 'active' && days <= 30 && (
                      <Badge variant={days <= 7 ? 'destructive' : 'warning'} className="ml-1.5">
                        {days <= 0 ? 'expired' : `${days}d`}
                      </Badge>
                    )}
                  </TableCell>
                  <TableCell className="text-xs">{b.supplier ?? '—'}</TableCell>
                  <TableCell>
                    <Badge variant={BATCH_STATUS_VARIANT[b.status]}>{titleCase(b.status)}</Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    {writable && (
                      <div className="flex justify-end gap-1">
                        <Button size="sm" variant="outline" aria-label={`Expire batch ${b.batch_number}`} onClick={() => setWriteOff({ batch: b, reason: 'expire' })}>
                          <CalendarX2 /> Expire
                        </Button>
                        <Button size="sm" variant="outline" aria-label={`Recall batch ${b.batch_number}`} onClick={() => setWriteOff({ batch: b, reason: 'recall' })}>
                          <ShieldAlert /> Recall
                        </Button>
                      </div>
                    )}
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      )}

      {writeOff !== null && (
        <WriteOffBatchDialog medicine={m!} batch={writeOff.batch} reason={writeOff.reason} onClose={() => setWriteOff(null)} />
      )}

      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
      </DialogFooter>
    </DialogContent>
  );
}
