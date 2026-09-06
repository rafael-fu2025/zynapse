import { Loader2, Lock } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { fmtUtcToApp } from '@/utils/date';
import type { Note, SessionDetail } from '@/schemas/counselling';

interface SessionNotesListProps {
  session: SessionDetail;
  notes: Note[] | undefined;
  isLoading: boolean;
  revealed: boolean;
  onReveal: () => void;
  onAmend: (noteId: number) => void;
}

export function SessionNotesList({
  session,
  notes,
  isLoading,
  revealed,
  onReveal,
  onAmend,
}: SessionNotesListProps) {
  if (!revealed) {
    return (
      <div className="rounded-lg border border-dashed p-4 text-center">
        <p className="text-sm text-muted-foreground">
          {session.note_count > 0
            ? `${session.note_count} encrypted note${session.note_count === 1 ? '' : 's'} on record.`
            : 'No notes on record.'}
        </p>
        {session.note_count > 0 && (
          <>
            <p className="mt-1 text-xs text-muted-foreground">
              Decrypting is recorded in the audit log (AES-256-GCM).
            </p>
            <Button className="mt-3" size="sm" variant="outline" onClick={onReveal}>
              <Lock className="size-3.5" /> Reveal notes
            </Button>
          </>
        )}
      </div>
    );
  }

  if (isLoading) {
    return (
      <div className="py-6 text-center">
        <Loader2 className="mx-auto size-5 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (!notes || notes.length === 0) {
    return <p className="py-4 text-center text-sm text-muted-foreground">No notes yet.</p>;
  }

  return (
    <div className="space-y-3">
      {notes.map((n) => (
        <section key={n.id ?? n.created_at} className="rounded-md border bg-card p-3 shadow-xs">
          <header className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <p className="text-[10px] text-muted-foreground">{fmtUtcToApp(n.created_at)}</p>
              {n.supersedes_note_id !== null && n.supersedes_note_id !== undefined && (
                <Badge variant="warning">Amends #{n.supersedes_note_id}</Badge>
              )}
            </div>
            <div className="flex items-center gap-2">
              <Badge variant="info">kv={n.key_version}</Badge>
              {session.ended_at === null && n.id !== undefined && (
                <Button
                  size="sm"
                  variant="ghost"
                  className="h-6 px-2 text-xs"
                  onClick={() => onAmend(n.id!)}
                >
                  Amend
                </Button>
              )}
            </div>
          </header>
          <p className="mt-2 whitespace-pre-wrap text-sm text-foreground">{n.plaintext}</p>
        </section>
      ))}
    </div>
  );
}
