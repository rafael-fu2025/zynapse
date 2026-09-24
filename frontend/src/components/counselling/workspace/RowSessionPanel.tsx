/**
 * RowSessionPanel — the inline, expandable session drawer for board rows
 * (2026-09-23).
 *
 * The Sessions & Notes tab is gone. Its content now lives inside each patient's
 * booking: a row on the **Queue** board expands in place and reveals the session
 * and its notes, with Complete Session at the end of the flow. `SessionWorkspace`
 * already owns that whole lifecycle — write note, refer to Clinic, complete,
 * archive — so this component is only the frame around it plus the "nothing here
 * yet" state.
 *
 * **Queue-only** (2026-09-23, third revision). The Appointments table used to
 * host this too, and carried an "Open full view" escape hatch that navigated to
 * `?session=N`. Both are gone: sessions are read in exactly one place now, and
 * since `?session=N` resolves *back* to the Queue, an escape hatch here would
 * only reload the page onto the panel you are already looking at.
 *
 * Deliberately a dumb child: it takes a session id and renders. It does not
 * fetch, resolve, or guess which session a row owns — the caller knows that
 * from the queue entry's `counselling_session_id`, and inventing a fallback
 * here would only hide a broken link.
 */
import type { ReactNode } from 'react';
import { Loader2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { SessionWorkspace } from './SessionWorkspace';

interface RowSessionPanelProps {
  /** The session this row owns, or null when none has started. */
  sessionId: number | null;
  /** Collapses the row. Wired to the workspace's own Close button. */
  onClose: () => void;
  /**
   * True while the owning session is still being resolved. Without this the
   * panel would flash its "no session" empty state on every expand.
   */
  isLoading?: boolean;
  /** Copy for the empty state — say *why* there is no session. */
  emptyTitle?: string;
  emptyHint?: string;
  /** Optional action for the empty state (e.g. Start Session). */
  action?: ReactNode;
}

export function RowSessionPanel({
  sessionId,
  onClose,
  isLoading = false,
  emptyTitle = 'No session yet',
  emptyHint,
  action,
}: RowSessionPanelProps) {
  if (isLoading && sessionId === null) {
    return (
      <div
        role="status"
        className="flex items-center gap-2 border-t bg-muted/20 p-6 text-sm text-muted-foreground"
      >
        <Loader2 className="size-4 animate-spin" aria-hidden />
        Loading this booking&rsquo;s session and notes…
      </div>
    );
  }

  if (sessionId !== null) {
    return (
      <section aria-label="Session and notes" className="border-t bg-muted/20 p-3">
        <SessionWorkspace sessionId={sessionId} onCloseWorkspace={onClose} />
      </section>
    );
  }

  return (
    <section aria-label="Session and notes" className="border-t bg-muted/20 p-3">
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-4">
        <div>
          <p className="text-sm font-medium text-foreground">{emptyTitle}</p>
          {emptyHint !== undefined && (
            <p className="mt-0.5 text-xs text-muted-foreground">{emptyHint}</p>
          )}
        </div>
        <div className="flex items-center gap-2">
          {action}
          <Button size="sm" variant="ghost" onClick={onClose}>
            <X className="size-3.5" /> Close
          </Button>
        </div>
      </div>
    </section>
  );
}
