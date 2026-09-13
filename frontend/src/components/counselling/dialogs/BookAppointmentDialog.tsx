import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { CalendarPlus, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { DatePicker } from '@/components/ui/date-picker';
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
import { CounsellingPatientPicker } from '@/components/CounsellingPatientPicker';
import { useAvailability, useBookAppointment, useCounsellors } from '@/hooks/useSchedule';
import {
  APPOINTMENT_TYPES,
  bookAppointmentSchema,
  type BookAppointmentInput,
} from '@/schemas/schedule';
import { TYPE_LABEL } from '../constants';

export function BookAppointmentDialog({ onClose }: { onClose: () => void }) {
  const book = useBookAppointment();
  const availability = useAvailability();
  const counsellors = useCounsellors();
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<BookAppointmentInput>({
      resolver: zodResolver(bookAppointmentSchema),
      defaultValues: { type: 'initial', reason: '' },
    });
  const [pickedPatient, setPickedPatient] = useState<string | null>(null);

  const type = watch('type');
  const counsellorId = watch('counsellor_user_id');

  const counsellorIds = [...new Set((availability.data ?? []).map((w) => w.counsellor_user_id))]
    .sort((a, b) => a - b);

  const onSubmit = handleSubmit((values) => {
    book.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle>Book appointment</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="appt-patient">Patient school ID</Label>
          <CounsellingPatientPicker
            id="appt-patient"
            value={watch('patient_school_id') ?? ''}
            onValue={(v) => { setValue('patient_school_id', v, { shouldValidate: true }); setPickedPatient(null); }}
            onPick={(p) => setPickedPatient(`${p.name} (${p.kind === 'student' ? 'Student' : 'Employee'})`)}
            error={errors.patient_school_id?.message}
          />
          {pickedPatient !== null && (
            <p className="text-xs text-emerald-600 dark:text-emerald-400">
              Selected: {pickedPatient}
            </p>
          )}
          {errors.patient_school_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.patient_school_id.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label id="appt-counsellor-label">Counsellor</Label>
          <Select
            value={counsellorId !== undefined ? String(counsellorId) : ''}
            onValueChange={(v) => setValue('counsellor_user_id', Number(v), { shouldValidate: true, shouldDirty: true })}
          >
            <SelectTrigger aria-labelledby="appt-counsellor-label" aria-invalid={errors.counsellor_user_id !== undefined}>
              <SelectValue placeholder={counsellorIds.length === 0 ? 'No counsellors with availability' : 'Select counsellor'} />
            </SelectTrigger>
            <SelectContent>
              {counsellorIds.map((id) => (
                <SelectItem key={id} value={String(id)}>{counsellors.data?.find((c) => c.id === id)?.name ?? `Counsellor #${id}`}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors.counsellor_user_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.counsellor_user_id.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="appt-date">Date</Label>
          <DatePicker id="appt-date" aria-invalid={errors.appointment_date !== undefined} value={watch('appointment_date') ?? ''} onChange={(v) => setValue('appointment_date', v, { shouldValidate: true, shouldDirty: true })} />
          {errors.appointment_date !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.appointment_date.message}</p>
          )}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="appt-start">Start time</Label>
            <TimePicker id="appt-start" aria-invalid={errors.start_time !== undefined} value={watch('start_time') ?? ''} onChange={(v) => setValue('start_time', v, { shouldValidate: true, shouldDirty: true })} />
            {errors.start_time !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.start_time.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="appt-end">End time</Label>
            <TimePicker id="appt-end" aria-invalid={errors.end_time !== undefined} value={watch('end_time') ?? ''} onChange={(v) => setValue('end_time', v, { shouldValidate: true, shouldDirty: true })} />
            {errors.end_time !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.end_time.message}</p>
            )}
          </div>
        </div>
        <div className="space-y-1.5">
          <Label id="appt-type-label">Type</Label>
          <Select
            value={type}
            onValueChange={(v) => setValue('type', v as BookAppointmentInput['type'], { shouldValidate: true })}
          >
            <SelectTrigger aria-labelledby="appt-type-label"><SelectValue /></SelectTrigger>
            <SelectContent>
              {APPOINTMENT_TYPES.map((t) => (
                <SelectItem key={t} value={t}>{TYPE_LABEL[t]}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="appt-reason">Reason (optional)</Label>
          <Input id="appt-reason" aria-invalid={errors.reason !== undefined} {...register('reason')} />
          {errors.reason !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.reason.message}</p>
          )}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={book.isPending}>
            {book.isPending && <Loader2 className="animate-spin" />}
            <CalendarPlus className="size-4" /> Book
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
