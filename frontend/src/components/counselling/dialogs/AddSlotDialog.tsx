import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { TimePicker } from '@/components/ui/time-picker';
import { useAddSlot, useCounsellors } from '@/hooks/useSchedule';
import { hasPermission, useAuthStore } from '@/store/auth';
import { addSlotSchema, DAY_NAMES, type AddSlotInput } from '@/schemas/schedule';

export function AddSlotDialog({ onClose }: { onClose: () => void }) {
  const add = useAddSlot();
  const counsellors = useCounsellors();
  const auth = useAuthStore();
  const team = hasPermission(auth, 'counselling.schedule.team_manage');
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<AddSlotInput>({
      resolver: zodResolver(addSlotSchema),
      defaultValues: { day_of_week: 1, max_slots: 1 },
    });

  const dow = watch('day_of_week');

  const onSubmit = handleSubmit((values) => {
    add.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Add availability window</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        {team && (
          <div className="space-y-1.5">
            <Label>Counsellor</Label>
            <Select
              value={watch('counsellor_user_id') ? String(watch('counsellor_user_id')) : ''}
              onValueChange={(v) => setValue('counsellor_user_id', Number(v), { shouldValidate: true })}
            >
              <SelectTrigger><SelectValue placeholder="Select counsellor" /></SelectTrigger>
              <SelectContent>
                {(counsellors.data ?? []).map((c) => (
                  <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}
        <div className="space-y-1.5">
          <Label id="slot-dow-label">Day of week</Label>
          <Select
            value={String(dow)}
            onValueChange={(v) => setValue('day_of_week', Number(v), { shouldValidate: true })}
          >
            <SelectTrigger aria-labelledby="slot-dow-label"><SelectValue /></SelectTrigger>
            <SelectContent>
              {DAY_NAMES.map((name, i) => (
                <SelectItem key={name} value={String(i)}>{name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="slot-start">Start time</Label>
            <TimePicker
              id="slot-start"
              aria-invalid={errors.start_time !== undefined}
              value={watch('start_time') ?? ''}
              onChange={(v) => setValue('start_time', v, { shouldValidate: true, shouldDirty: true })}
            />
            {errors.start_time !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.start_time.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="slot-end">End time</Label>
            <TimePicker
              id="slot-end"
              aria-invalid={errors.end_time !== undefined}
              value={watch('end_time') ?? ''}
              onChange={(v) => setValue('end_time', v, { shouldValidate: true, shouldDirty: true })}
            />
            {errors.end_time !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.end_time.message}</p>
            )}
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="slot-max">Concurrent capacity (max slots)</Label>
          <Input
            id="slot-max"
            type="number"
            min={1}
            aria-invalid={errors.max_slots !== undefined}
            {...register('max_slots', { valueAsNumber: true })}
          />
          {errors.max_slots !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.max_slots.message}</p>
          )}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={add.isPending}>
            {add.isPending && <Loader2 className="animate-spin" />} Add window
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
