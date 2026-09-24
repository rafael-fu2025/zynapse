import { useState } from 'react';
import { Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { TimePicker } from '@/components/ui/time-picker';
import { useUpdateSlot } from '@/hooks/useSchedule';
import { DAY_NAMES, updateSlotSchema } from '@/schemas/schedule';
import { fmtTimeRange } from '@/utils/date';
import type { AvailabilitySlot } from '../scheduling/availabilitySlots';

/**
 * EditSlotDialog — change an availability window without re-creating it
 * (2026-09-24).
 *
 * Before this, the only way to move a window was to remove it and add
 * another, which left the original row active and produced the duplicates
 * `synapse:counselling-dedupe-availability` exists to clean up. Saving here
 * writes to the rows the desk is already looking at.
 *
 * A row can group several windows — one per counsellor sharing those hours —
 * so the dialog names everyone it will move. There is no weekday *set*: a
 * window belongs to one weekday, and changing it moves that window rather
 * than spreading it across the week the way adding does.
 */
export function EditSlotDialog({
  slot,
  onClose,
}: {
  slot: AvailabilitySlot;
  onClose: () => void;
}) {
  const update = useUpdateSlot();
  const [dow, setDow] = useState(String(slot.day_of_week));
  const [start, setStart] = useState(slot.start_time.slice(0, 5));
  const [end, setEnd] = useState(slot.end_time.slice(0, 5));

  const assigned = slot.members.map((m) => m.name ?? `Staff #${m.id}`).join(', ');

  function save() {
    const parsed = updateSlotSchema.safeParse({
      day_of_week: dow,
      start_time: start,
      end_time: end,
    });
    if (!parsed.success) {
      toast.error(parsed.error.issues[0]?.message ?? 'Invalid input.');
      return;
    }
    update.mutate({ ids: slot.windowIds, input: parsed.data }, { onSuccess: onClose });
  }

  return (
    <DialogContent lockDismiss className="max-w-md">
      <DialogHeader>
        <DialogTitle>Edit availability window</DialogTitle>
      </DialogHeader>

      <p className="text-xs text-muted-foreground">
        {DAY_NAMES[slot.day_of_week]} {fmtTimeRange(slot.start_time, slot.end_time)} — assigned to {assigned}.
        {slot.windowIds.length > 1 && (
          <span> Saving moves all {slot.windowIds.length} assigned staff.</span>
        )}
      </p>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div className="space-y-1.5">
          <Label id="edit-slot-dow-label" className="text-xs">Day</Label>
          <Select value={dow} onValueChange={setDow}>
            <SelectTrigger aria-labelledby="edit-slot-dow-label" className="h-8 w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {DAY_NAMES.map((name, i) => (
                <SelectItem key={name} value={String(i)}>{name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit-slot-start" className="text-xs">Start</Label>
          <TimePicker id="edit-slot-start" value={start} onChange={setStart} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit-slot-end" className="text-xs">End</Label>
          <TimePicker id="edit-slot-end" value={end} onChange={setEnd} />
        </div>
      </div>

      <DialogFooter>
        <Button variant="outline" onClick={onClose} disabled={update.isPending}>
          Cancel
        </Button>
        <Button onClick={save} disabled={update.isPending}>
          {update.isPending && <Loader2 className="animate-spin" />} Save changes
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}
