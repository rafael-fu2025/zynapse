import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link } from 'react-router-dom';
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
import { Textarea } from '@/components/ui/textarea';
import { useCreateContextualReferral } from '@/hooks/useReferrals';
import { referralSchema, type Referral } from '@/schemas/referrals';
import type { SessionDetail } from '@/schemas/counselling';

export function GuidanceClinicReferralDialog({ session, onClose }: { session: SessionDetail; onClose: () => void }) {
  const create = useCreateContextualReferral({ module: 'counselling', sessionId: session.id });
  const [result, setResult] = useState<Referral | null>(null);
  const [duplicate, setDuplicate] = useState<Referral | null>(null);
  const { register, handleSubmit, formState: { errors } } = useForm<{ reason_code?: string; notes_plaintext?: string }>({
    defaultValues: {
      reason_code: '',
      notes_plaintext: '',
    },
  });

  const submit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: (created) => setResult(created),
      onError: (error) => {
        const candidate = error.errors[0]?.details?.referral;
        const parsed = referralSchema.safeParse(candidate);
        if (parsed.success) setDuplicate(parsed.data);
      },
    });
  });
  const shown = result ?? duplicate;

  return (
    <DialogContent>
      <DialogHeader><DialogTitle>Refer patient to Clinic</DialogTitle></DialogHeader>
      {shown !== null ? (
        <div className="space-y-4">
          <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-4">
            <p className="font-medium">Referral #{shown.id}</p>
            <p className="text-sm text-muted-foreground">Status: {shown.status.replace('_', ' ')}</p>
            <p className="mt-2 text-sm">The Guidance session remains active. Clinic acknowledgement, review, and queue handoff remain separate actions.</p>
          </div>
          <DialogFooter>
            <Button asChild variant="outline"><Link to="/referrals">Open Referrals</Link></Button>
            <Button onClick={onClose}>Continue session</Button>
          </DialogFooter>
        </div>
      ) : (
        <form noValidate onSubmit={(event) => void submit(event)} className="space-y-4">
          <div className="rounded-lg border bg-muted/30 p-3 text-sm">
            <p className="font-medium">{session.patient_display_name}</p>
            <p className="font-mono text-xs text-muted-foreground">{session.patient_school_id}</p>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5"><Label htmlFor="guidance-referral-from">From</Label><Input id="guidance-referral-from" value="Guidance" readOnly disabled /></div>
            <div className="space-y-1.5"><Label htmlFor="guidance-referral-to">To</Label><Input id="guidance-referral-to" value="Clinic" readOnly disabled /></div>
          </div>
          <div className="space-y-1.5"><Label htmlFor="guidance-referral-artifact">Artifact</Label><Input id="guidance-referral-artifact" value="Referral letter" readOnly disabled /></div>
          <div className="space-y-1.5">
            <Label htmlFor="guidance-referral-reason">Reason (optional)</Label>
            <Input id="guidance-referral-reason" {...register('reason_code', { maxLength: 64 })} aria-invalid={errors.reason_code !== undefined} />
            {errors.reason_code !== undefined && <p role="alert" className="text-xs text-destructive">Reason is too long.</p>}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="guidance-referral-notes">Referral notes (optional)</Label>
            <Textarea id="guidance-referral-notes" {...register('notes_plaintext', { maxLength: 8192 })} placeholder="Share only information needed by Clinic. Session notes are not copied." />
            {errors.notes_plaintext !== undefined && <p role="alert" className="text-xs text-destructive">Referral notes are too long.</p>}
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={create.isPending}>
              {create.isPending && <Loader2 className="animate-spin" />}
              Submit referral
            </Button>
          </DialogFooter>
        </form>
      )}
    </DialogContent>
  );
}
