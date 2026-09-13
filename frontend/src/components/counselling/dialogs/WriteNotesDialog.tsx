import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Loader2, Lock, ShieldCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { useWriteNotes } from '@/hooks/useCounselling';
import { writeNotesSchema, type Session, type WriteNotesInput } from '@/schemas/counselling';

export function WriteNotesDialog({
  session,
  amendNoteId,
  onClose,
}: {
  session: Session;
  amendNoteId?: number | null;
  onClose: () => void;
}) {
  const write = useWriteNotes();
  const [confirmDiscard, setConfirmDiscard] = useState(false);
  const { register, handleSubmit, formState: { errors, isDirty }, reset } =
    useForm<WriteNotesInput>({
      resolver: zodResolver(writeNotesSchema),
      defaultValues: amendNoteId !== null && amendNoteId !== undefined ? { supersedes_note_id: amendNoteId } : {},
    });

  const onSubmit = handleSubmit((values) => {
    write.mutate(
      { sessionId: session.id, input: values },
      {
        onSuccess: () => {
          reset();
          onClose();
        },
      },
    );
  });

  function handleCancel() {
    if (isDirty) {
      setConfirmDiscard(true);
    } else {
      onClose();
    }
  }

  return (
    <>
      <DialogContent lockDismiss onPointerDownOutside={(e) => { if (isDirty) e.preventDefault(); }}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Lock className="size-4" />{' '}
            {amendNoteId !== null && amendNoteId !== undefined
              ? `Amend note #${amendNoteId} — session #${session.id}`
              : `Encrypted notes — session #${session.id}`}
          </DialogTitle>
        </DialogHeader>
        <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
          {amendNoteId !== null && amendNoteId !== undefined && (
            <p className="text-xs text-muted-foreground">
              Notes are immutable. This amendment will be appended to the session record alongside the note it supersedes.
            </p>
          )}
          <div className="space-y-1.5">
            <Label htmlFor="plaintext">
              Notes (encrypted at rest on the server)
            </Label>
            <Textarea
              id="plaintext"
              rows={8}
              className="min-h-40"
              aria-invalid={errors.plaintext !== undefined}
              {...register('plaintext')}
            />
            {errors.plaintext !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.plaintext.message}</p>
            )}
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={handleCancel}>Cancel</Button>
            <Button type="submit" disabled={write.isPending}>
              {write.isPending && <Loader2 className="animate-spin" />}
              <ShieldCheck className="size-4" /> Encrypt & save
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>

      <ConfirmDialog
        open={confirmDiscard}
        title="Discard unsaved note?"
        description="You have entered text in this note. Closing now will lose your draft."
        confirmLabel="Discard draft"
        onConfirm={() => {
          setConfirmDiscard(false);
          reset();
          onClose();
        }}
        onCancel={() => setConfirmDiscard(false)}
      />
    </>
  );
}
