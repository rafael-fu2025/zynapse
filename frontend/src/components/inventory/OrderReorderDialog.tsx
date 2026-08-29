import { Loader2, Truck } from 'lucide-react';
import { useState } from 'react';
import { DatePicker } from '@/components/ui/date-picker';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useReorderTransition } from '@/hooks/useReorders';

/**
 * OrderReorderDialog — the 'order' transition, capturing an optional
 * expected delivery date (ETA) so the reorder's ETA column can populate.
 */
export function OrderReorderDialog({ reorderId, onClose }: { reorderId: number; onClose: () => void }) {
  const transition = useReorderTransition();
  const [eta, setEta] = useState('');

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Mark reorder #{reorderId} as ordered</DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="reorder-eta">Expected delivery date (optional)</Label>
          <DatePicker id="reorder-eta" value={eta} onChange={setEta} className="w-full" placeholder="Pick an ETA" />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button
          disabled={transition.isPending}
          onClick={() =>
            transition.mutate(
              { id: reorderId, action: 'order', ...(eta !== '' ? { expected_delivery_date: eta } : {}) },
              { onSuccess: onClose },
            )
          }
        >
          {transition.isPending && <Loader2 className="animate-spin" />}
          <Truck /> Mark ordered
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}
