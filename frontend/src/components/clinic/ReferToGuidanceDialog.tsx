/**
 * ReferToGuidanceDialog — the clinic-side action on a student registry
 * row: raises a clinic → counselling referral through the existing
 * pipeline. Only reason / notes / priority travel to Guidance; the
 * student's clinic medical record stays confidential to Clinic staff.
 *
 * A 409 `referral.active_duplicate` renders the existing referral
 * instead of the form (one active referral per student + direction).
 */
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
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
import { Textarea } from '@/components/ui/textarea';
import { useCreateGuidanceReferral } from '@/hooks/useReferrals';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  GUIDANCE_REASON_OPTIONS,
  REFERRAL_PRIORITIES,
  referToGuidanceSchema,
  type Referral,
  type ReferToGuidanceInput,
} from '@/schemas/referrals';
import { statusLabel } from '@/utils/status';
import { fmtUtcToApp } from '@/utils/date';

/** The student row a guidance referral is raised for. */
export interface GuidanceReferralSubject {
  /** Student number — the referral pipeline's patient handle. */
  number: string;
  name: string;
}

function reasonLabel(value: string): string {
  return GUIDANCE_REASON_OPTIONS.find((r) => r.value === value)?.label ?? value;
}

export function ReferToGuidanceDialog({
  patient,
  initialDuplicate = null,
  onClose,
}: {
  patient: GuidanceReferralSubject;
  /** Test seam — opens straight into the "already referred" state. */
  initialDuplicate?: Referral | null;
  onClose: () => void;
}) {
  const create = useCreateGuidanceReferral();
  const [duplicate, setDuplicate] = useState<Referral | null>(initialDuplicate);
  const { register, handleSubmit, formState: { errors }, setValue, watch } =
    useForm<ReferToGuidanceInput>({
      resolver: zodResolver(referToGuidanceSchema),
      defaultValues: {
        patient_school_id: patient.number,
        source_module: 'clinic',
        target_module: 'counselling',
        artifact_type: 'intake_pass',
        priority: 'normal',
      },
    });
  const priority = watch('priority');

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: onClose,
      onError: (err: ApiEnvelopeError) => {
        const first = err.errors[0];
        if (err.httpStatus === 409 && first?.code === 'referral.active_duplicate') {
          const existing = (first.details as { referral?: Referral } | undefined)?.referral;
          if (existing !== undefined) {
            setDuplicate(existing);
            return;
          }
        }
        setDuplicate(null);
      },
    });
  });

  return (
    <Dialog open onOpenChange={(open) => ! open && onClose()}>
      <DialogContent lockDismiss className="max-h-[92dvh] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Refer to Guidance</DialogTitle>
          <DialogDescription>
            {patient.name} · <span className="tabular-nums">{patient.number}</span>
          </DialogDescription>
        </DialogHeader>

        {duplicate !== null ? (
          <div className="space-y-3">
            <p className="text-sm text-muted-foreground">
              This student already has an active referral to Guidance.
            </p>
            <div className="space-y-2 rounded-lg border bg-muted/20 p-3.5">
              <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-medium">Referral #{duplicate.id}</p>
                <Badge variant={duplicate.status === 'closed' ? 'success' : 'info'}>
                  {statusLabel(duplicate.status)}
                </Badge>
              </div>
              {duplicate.reason_code !== null && (
                <p className="text-xs text-muted-foreground">Reason: {reasonLabel(duplicate.reason_code)}</p>
              )}
              <p className="text-xs text-muted-foreground">
                Raised {fmtUtcToApp(duplicate.created_at, 'MMM d, yyyy')}
              </p>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={onClose}>Close</Button>
              <Button asChild>
                <Link to="/referrals">Open Referrals</Link>
              </Button>
            </DialogFooter>
          </div>
        ) : (
          <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
            <p className="rounded-lg border border-dashed bg-muted/20 p-3 text-xs text-muted-foreground">
              Only the reason, notes, and priority are shared with the Guidance Center. The student&apos;s clinic
              medical record stays confidential to Clinic staff.
            </p>

            <div className="space-y-1.5">
              <Label htmlFor="guidance-reason">Reason for referral</Label>
              <Select
                value={watch('reason_code') ?? ''}
                onValueChange={(v) => setValue('reason_code', v, { shouldValidate: true })}
              >
                <SelectTrigger id="guidance-reason" aria-invalid={errors.reason_code !== undefined}>
                  <SelectValue placeholder="Select…" />
                </SelectTrigger>
                <SelectContent>
                  {GUIDANCE_REASON_OPTIONS.map((r) => (
                    <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors.reason_code !== undefined && (
                <p role="alert" className="text-xs text-destructive">{errors.reason_code.message}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="guidance-priority">Priority</Label>
              <Select
                value={priority}
                onValueChange={(v) => setValue('priority', v as ReferToGuidanceInput['priority'], { shouldValidate: true })}
              >
                <SelectTrigger id="guidance-priority" aria-invalid={errors.priority !== undefined}>
                  <SelectValue placeholder="Select…" />
                </SelectTrigger>
                <SelectContent>
                  {REFERRAL_PRIORITIES.map((p) => (
                    <SelectItem key={p} value={p}>{p.charAt(0).toUpperCase() + p.slice(1)}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors.priority !== undefined && (
                <p role="alert" className="text-xs text-destructive">{errors.priority.message}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="guidance-notes">Notes (optional)</Label>
              <Textarea
                id="guidance-notes"
                rows={3}
                maxLength={2000}
                placeholder="Brief context for the Guidance counselor…"
                {...register('notes_plaintext')}
              />
              {errors.notes_plaintext !== undefined && (
                <p role="alert" className="text-xs text-destructive">{errors.notes_plaintext.message}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label>Referral date</Label>
              <Input value={new Date().toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })} readOnly disabled />
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
              <Button type="submit" disabled={create.isPending}>
                {create.isPending && <Loader2 className="size-4 animate-spin" aria-hidden />} Refer to Guidance
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}
