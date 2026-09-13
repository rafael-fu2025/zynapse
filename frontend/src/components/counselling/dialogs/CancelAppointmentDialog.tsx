import { useState } from 'react';
import { Loader2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useAppointmentTransition } from '@/hooks/useSchedule';
import type { Appointment } from '@/schemas/schedule';

export function CancelAppointmentDialog({ appointment, onClose }: { appointment: Appointment; onClose: () => void }) {
  const transition = useAppointmentTransition();
  const [reason, setReason] = useState('');

  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle>Cancel appointment #{appointment.id}</DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="cancel-reason">Cancellation reason (optional)</Label>
          <Textarea
            id="cancel-reason"
            rows={3}
            maxLength={255}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Keep</Button>
          <Button
            type="button"
            variant="destructive"
            disabled={transition.isPending}
            onClick={() =>
              transition.mutate(
                { id: appointment.id, action: 'cancel', cancellation_reason: reason },
                { onSuccess: onClose },
              )
            }
          >
            {transition.isPending && <Loader2 className="animate-spin" />}
            <X className="size-4" /> Cancel appointment
          </Button>
        </DialogFooter>
      </div>
    </DialogContent>
  );
}
