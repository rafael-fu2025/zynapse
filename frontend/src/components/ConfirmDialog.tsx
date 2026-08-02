/**
 * ConfirmDialog — shared confirmation for destructive / irreversible
 * actions. Modeled on the counselling CancelAppointmentDialog pattern
 * (explicit "keep" escape hatch + destructive confirm with pending
 * spinner). Pages hold a small `confirm` state describing the action
 * and render a single instance of this dialog.
 */
import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

export interface ConfirmDialogProps {
  open: boolean;
  title: string;
  description?: string | undefined;
  confirmLabel?: string | undefined;
  cancelLabel?: string | undefined;
  /** Renders the confirm button in the destructive variant (default true). */
  destructive?: boolean;
  pending?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

export function ConfirmDialog({
  open,
  title,
  description,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  destructive = true,
  pending = false,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  // Pages null their `confirm` state in the same render that closes the
  // dialog, which would blank the title/labels during the exit
  // animation. Freeze the last-open content so the dialog closes with
  // its text intact.
  const [frozen, setFrozen] = useState({ title, description, confirmLabel });
  useEffect(() => {
    if (open) setFrozen({ title, description, confirmLabel });
  }, [open, title, description, confirmLabel]);
  const shownTitle = open ? title : frozen.title;
  const shownDescription = open ? description : frozen.description;
  const shownConfirmLabel = open ? confirmLabel : frozen.confirmLabel;

  return (
    <Dialog open={open} onOpenChange={(o) => !o && !pending && onCancel()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{shownTitle}</DialogTitle>
          {shownDescription !== undefined && <DialogDescription>{shownDescription}</DialogDescription>}
        </DialogHeader>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onCancel} disabled={pending}>
            {cancelLabel}
          </Button>
          <Button
            type="button"
            variant={destructive ? 'destructive' : 'default'}
            disabled={pending}
            onClick={onConfirm}
          >
            {pending && <Loader2 className="animate-spin" />}
            {shownConfirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Minimal action descriptor for the shared page-level `confirm` state:
 *   const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
 * The dialog fires `run()` and the page closes it in the same handler;
 * outcome feedback arrives via the mutation's toasts.
 */
export interface ConfirmAction {
  title: string;
  description?: string | undefined;
  confirmLabel?: string | undefined;
  run: () => void;
}
