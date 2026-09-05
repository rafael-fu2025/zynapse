import { useState } from 'react';
import {
  Archive,
  ArrowRight,
  Loader2,
  Plus,
  X,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Dialog } from '@/components/ui/dialog';
import { SessionProgressTracker, type SessionProgressStep } from '@/components/SessionProgressTracker';
import {
  useArchiveSession,
  useCloseSession,
  useNotes,
  useSession,
} from '@/hooks/useCounselling';
import { hasPermission, useAuthStore } from '@/store/auth';
import { fmtUtcToApp } from '@/utils/date';
import { WriteNotesDialog, GuidanceClinicReferralDialog } from '../dialogs';
import { SessionNotesList } from './SessionNotesList';

interface SessionWorkspaceProps {
  sessionId: number;
  onCloseWorkspace?: () => void;
}

export function SessionWorkspace({ sessionId, onCloseWorkspace }: SessionWorkspaceProps) {
  const authState = useAuthStore();
  const canRefer = hasPermission(authState, 'referrals.create');
  const canSoftDelete = hasPermission(authState, 'counselling.records.soft_delete');

  const detail = useSession(sessionId);
  const selected = detail.data ?? null;

  const [progressStep, setProgressStep] = useState('notes');
  const [revealedId, setRevealedId] = useState<number | null>(null);
  const [writeOpen, setWriteOpen] = useState(false);
  const [amendNoteId, setAmendNoteId] = useState<number | null>(null);
  const [referOpen, setReferOpen] = useState(false);
  const [closingId, setClosingId] = useState<number | null>(null);
  const [archivingId, setArchivingId] = useState<number | null>(null);

  const notes = useNotes(revealedId === sessionId ? sessionId : 0);
  const close = useCloseSession();
  const archive = useArchiveSession();

  if (detail.isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (detail.isError || !selected) {
    return (
      <div role="alert" className="m-4 rounded-lg border border-destructive/40 p-4 text-sm">
        <p>{detail.error?.errors[0]?.message ?? 'This session could not be opened.'}</p>
        <div className="mt-3 flex gap-2">
          <Button size="sm" variant="outline" onClick={() => void detail.refetch()}>Retry</Button>
          {onCloseWorkspace && (
            <Button size="sm" variant="ghost" onClick={onCloseWorkspace}>Close</Button>
          )}
        </div>
      </div>
    );
  }

  const progressSteps: SessionProgressStep[] = [
    { id: 'started', label: 'Session Started', state: 'complete', summary: selected.queue_number ?? `Session #${selected.id}` },
    { id: 'notes', label: 'Session Notes', state: selected.note_count > 0 ? 'complete' : selected.ended_at === null ? 'current' : 'available', summary: selected.note_count > 0 ? `${selected.note_count} note${selected.note_count === 1 ? '' : 's'}` : 'No notes yet' },
    { id: 'referral', label: 'Referral', state: !canRefer ? 'unavailable' : selected.outgoing_referral !== null ? 'complete' : 'optional', summary: selected.outgoing_referral !== null ? `#${selected.outgoing_referral.id} · ${selected.outgoing_referral.status.replace('_', ' ')}` : canRefer ? 'When Clinic care is needed' : 'Permission required' },
    { id: 'complete', label: 'Complete', state: selected.ended_at !== null ? 'complete' : 'available', summary: selected.ended_at !== null ? fmtUtcToApp(selected.ended_at) : 'Finish session' },
  ];

  return (
    <article className="flex flex-col overflow-hidden rounded-xl border bg-card">
      <header className="flex items-center justify-between border-b px-4 py-3">
        <div className="flex items-center gap-2">
          <p className="font-semibold text-foreground">Session #{selected.id}</p>
          <Badge variant={selected.ended_at === null ? 'success' : 'secondary'}>
            {selected.ended_at === null ? 'Active' : 'Closed'}
          </Badge>
        </div>
        <div className="flex items-center gap-1.5">
          {canSoftDelete && (
            <Button
              size="sm"
              variant="ghost"
              className="text-muted-foreground hover:text-destructive"
              onClick={() => setArchivingId(selected.id)}
              title="Archive session (supervisor only)"
            >
              <Archive className="size-4" /> Archive
            </Button>
          )}
          {onCloseWorkspace && (
            <Button size="sm" variant="ghost" onClick={onCloseWorkspace}>
              <X className="size-4" /> Close
            </Button>
          )}
        </div>
      </header>

      <div className="space-y-4 p-4">
        <SessionProgressTracker
          steps={progressSteps}
          selected={progressStep}
          onSelect={setProgressStep}
        />

        <div className="rounded-lg border bg-muted/20 p-3">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="font-medium">{selected.patient_display_name}</p>
              <p className="font-mono text-xs text-muted-foreground">{selected.patient_school_id}</p>
            </div>
            {selected.queue_number && (
              <Badge variant="outline" className="font-mono">
                {selected.queue_number}
              </Badge>
            )}
          </div>
          <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs sm:grid-cols-3">
            <div><dt className="text-muted-foreground">Queue entry</dt><dd>{selected.queue_number ?? 'Direct'}</dd></div>
            <div><dt className="text-muted-foreground">Started</dt><dd>{fmtUtcToApp(selected.started_at)}</dd></div>
            <div><dt className="text-muted-foreground">Purpose</dt><dd>{selected.purpose ?? '—'}</dd></div>
            <div><dt className="text-muted-foreground">Appointment</dt><dd>{selected.appointment_id ? `#${selected.appointment_id}` : '—'}</dd></div>
            <div><dt className="text-muted-foreground">Incoming referral</dt><dd>{selected.incoming_referral_id ? `#${selected.incoming_referral_id}` : '—'}</dd></div>
            <div><dt className="text-muted-foreground">Ended</dt><dd>{selected.ended_at ? fmtUtcToApp(selected.ended_at) : 'In progress'}</dd></div>
          </dl>
        </div>

        {selected.ended_at === null && progressStep === 'notes' && (
          <div className="flex flex-wrap gap-2">
            <Button
              size="sm"
              onClick={() => {
                setAmendNoteId(null);
                setWriteOpen(true);
              }}
            >
              <Plus className="size-4" /> Write Note
            </Button>
          </div>
        )}

        {progressStep === 'referral' && (
          <div className="rounded-lg border p-4">
            <p className="text-sm font-medium">Clinic referral</p>
            {selected.outgoing_referral !== null ? (
              <p className="mt-1 text-sm text-muted-foreground">
                Referral #{selected.outgoing_referral.id} is {selected.outgoing_referral.status.replace('_', ' ')}.
                The session remains independent from its handoff lifecycle.
              </p>
            ) : (
              <p className="mt-1 text-sm text-muted-foreground">
                Optional. Refer only when Clinic follow-up or medical care is needed.
              </p>
            )}
            {selected.ended_at === null && canRefer && selected.outgoing_referral === null && (
              <Button className="mt-3" size="sm" variant="outline" onClick={() => setReferOpen(true)}>
                <ArrowRight className="size-4" /> Refer to Clinic
              </Button>
            )}
          </div>
        )}

        {progressStep === 'complete' && (
          <div className="rounded-lg border p-4">
            <p className="text-sm font-medium">Finish this session</p>
            <p className="mt-1 text-sm text-muted-foreground">
              Review the notes and records before completing. Completing is permanent.
            </p>
            {selected.ended_at === null && (
              <Button
                className="mt-3"
                size="sm"
                variant="destructive"
                onClick={() => setClosingId(selected.id)}
              >
                {selected.queue_entry_id !== null ? 'Complete Session' : 'Close Session'}
              </Button>
            )}
          </div>
        )}

        {progressStep === 'notes' && (
          <SessionNotesList
            session={selected}
            notes={notes.data}
            isLoading={notes.isLoading}
            revealed={revealedId === selected.id}
            onReveal={() => setRevealedId(selected.id)}
            onAmend={(noteId) => {
              setAmendNoteId(noteId);
              setWriteOpen(true);
            }}
          />
        )}
      </div>

      <Dialog open={writeOpen} onOpenChange={(open) => { setWriteOpen(open); if (!open) setAmendNoteId(null); }}>
        {writeOpen && (
          <WriteNotesDialog
            session={selected}
            amendNoteId={amendNoteId}
            onClose={() => { setWriteOpen(false); setAmendNoteId(null); }}
          />
        )}
      </Dialog>

      <Dialog open={referOpen} onOpenChange={setReferOpen}>
        {referOpen && (
          <GuidanceClinicReferralDialog
            session={selected}
            onClose={() => setReferOpen(false)}
          />
        )}
      </Dialog>

      <ConfirmDialog
        open={closingId !== null}
        title={closingId !== null ? `${selected.queue_entry_id !== null ? 'Complete' : 'Close'} session #${closingId}?` : ''}
        description={selected.queue_entry_id !== null
          ? 'This completes the Guidance queue entry, closes the session, and completes its linked confirmed appointment.'
          : 'Closing a session is final and cannot be reopened.'}
        confirmLabel={selected.queue_entry_id !== null ? 'Complete session' : 'Close session'}
        pending={close.isPending}
        onConfirm={() => {
          if (closingId !== null) {
            close.mutate(closingId, {
              onSuccess: () => {
                setClosingId(null);
                onCloseWorkspace?.();
              },
            });
          }
        }}
        onCancel={() => setClosingId(null)}
      />

      <ConfirmDialog
        open={archivingId !== null}
        title={archivingId !== null ? `Archive session #${archivingId}?` : ''}
        description="Soft-deleting removes this session from operational views without destroying the encrypted note history. Use for wrong-patient errors. This action is audited."
        confirmLabel="Archive session"
        pending={archive.isPending}
        onConfirm={() => {
          if (archivingId !== null) {
            archive.mutate(archivingId, {
              onSuccess: () => {
                setArchivingId(null);
                onCloseWorkspace?.();
              },
            });
          }
        }}
        onCancel={() => setArchivingId(null)}
      />
    </article>
  );
}
