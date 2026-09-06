import { useState } from 'react';
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
import { Label } from '@/components/ui/label';
import { CounsellingPatientPicker } from '@/components/CounsellingPatientPicker';
import { useOpenSession } from '@/hooks/useCounselling';
import { openSessionSchema, type OpenSessionInput } from '@/schemas/counselling';

export function OpenSessionDialog({ onClose }: { onClose: () => void }) {
  const open = useOpenSession();
  const { handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<OpenSessionInput>({ resolver: zodResolver(openSessionSchema) });
  const [pickedPatient, setPickedPatient] = useState<string | null>(null);

  const onSubmit = handleSubmit((values) => {
    open.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Open session</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="patient_school_id">Patient school ID</Label>
          <CounsellingPatientPicker
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
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={open.isPending}>
            {open.isPending && <Loader2 className="animate-spin" />} Open
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
