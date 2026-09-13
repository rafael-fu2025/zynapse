/**
 * FollowupsTab — the RA 11036 §24 aftercare caseload (Phase C).
 * Open items lead; the loop is closed: addressed/closed require an
 * outcome note. Gated by `counselling.responses.read_any`.
 */
import { Inbox, UserCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { QueryErrorState } from '@/components/QueryErrorState';
import { Label } from '@/components/ui/label';
import { PageToolbar } from '@/components/PageHeader';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import {
  useGuidanceFollowups,
  useTransitionFollowup,
  type GuidanceFollowup,
} from '@/hooks/useGuidanceFollowups';
import { fmtUtcToApp } from '@/utils/date';

const STATUS_VARIANTS: Record<string, 'destructive' | 'info' | 'success' | 'secondary'> = {
  new: 'destructive',
  in_review: 'info',
  addressed: 'success',
  closed: 'secondary',
};

const ACTION_LABELS: Record<string, string> = {
  start_review: 'Start review',
  mark_addressed: 'Mark addressed',
  close: 'Close loop',
};

function TransitionDialog({
  followup,
  action,
  onClose,
}: {
  followup: GuidanceFollowup;
  action: string;
  onClose: () => void;
}) {
  const transition = useTransitionFollowup();
  const needsNote = action !== 'start_review';
  const [note, setNote] = useState('');

  return (
    <Dialog open onOpenChange={(open) => ! open && ! transition.isPending && onClose()}>
      <DialogContent lockDismiss className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{ACTION_LABELS[action]} — {followup.student_name}</DialogTitle>
          <DialogDescription>
            {followup.risk_reason}
            {followup.who5_score !== null && ` (WHO-5 ${followup.who5_score}/100)`}
          </DialogDescription>
        </DialogHeader>
        {needsNote && (
          <div className="space-y-1.5">
            <Label htmlFor="outcome-note">Outcome note <span className="text-muted-foreground">(required — closes the loop)</span></Label>
            <Textarea
              id="outcome-note"
              rows={3}
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder="What was done for the student (e.g. outreach call, session booked, referral made)?"
            />
          </div>
        )}
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose} disabled={transition.isPending}>Cancel</Button>
          <Button
            type="button"
            onClick={() => transition.mutate(
              { id: followup.id, action, ...(needsNote ? { outcomeNote: note } : {}) },
              { onSuccess: onClose },
            )}
            disabled={transition.isPending || (needsNote && note.trim() === '')}
          >
            <UserCheck aria-hidden /> {transition.isPending ? 'Saving…' : ACTION_LABELS[action]}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function FollowupsTab() {
  const [status, setStatus] = useState('all');
  const [mineOnly, setMineOnly] = useState(false);
  const followups = useGuidanceFollowups(status, mineOnly);
  const [transitionFor, setTransitionFor] = useState<{ followup: GuidanceFollowup; action: string } | null>(null);
  const rows = followups.data ?? [];

  const openCount = useMemo(
    () => rows.filter((r) => r.status === 'new' || r.status === 'in_review').length,
    [rows],
  );

  function requestTransition(f: GuidanceFollowup, action: string) {
    setTransitionFor({ followup: f, action });
  }

  return (
    <div className="space-y-4">
      <PageToolbar>
        <div className="flex flex-wrap items-end gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="followup-status" className="text-xs text-muted-foreground">Status</Label>
            <Select value={status} onValueChange={setStatus}>
              <SelectTrigger id="followup-status" className="min-w-40"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All</SelectItem>
                <SelectItem value="new">New</SelectItem>
                <SelectItem value="in_review">In review</SelectItem>
                <SelectItem value="addressed">Addressed</SelectItem>
                <SelectItem value="closed">Closed</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="flex items-center gap-2 pb-2">
            <input
              id="followup-mine"
              type="checkbox"
              checked={mineOnly}
              onChange={(e) => setMineOnly(e.target.checked)}
              className="size-4 accent-[var(--primary)]"
            />
            <Label htmlFor="followup-mine" className="cursor-pointer text-sm font-normal">Assigned to me</Label>
          </div>
          <p className="pb-2 text-xs text-muted-foreground">
            {openCount} open follow-up{openCount === 1 ? '' : 's'}
          </p>
        </div>
      </PageToolbar>

      {followups.isError && (
        <QueryErrorState message="Failed to load the follow-up caseload." onRetry={() => void followups.refetch()} pending={followups.isFetching} />
      )}

      {followups.isLoading && (
        <div role="status" aria-label="Loading follow-ups" className="space-y-3">
          <Skeleton className="h-14 w-full" />
          <Skeleton className="h-14 w-full" />
        </div>
      )}

      {followups.data !== undefined && rows.length === 0 && (
        <section className="rounded-xl border bg-card p-8 text-center">
          <Inbox className="mx-auto mb-3 size-8 text-muted-foreground" aria-hidden />
          <p className="font-medium text-foreground">No follow-ups in this view</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Follow-ups appear when a routine interview scores at or below the well-being threshold, or a student asks
            for counselor contact.
          </p>
        </section>
      )}

      {followups.data !== undefined && rows.length > 0 && (
        <section aria-labelledby="followup-list-heading" className="overflow-hidden rounded-xl border bg-card">
          <h2 id="followup-list-heading" className="sr-only">Follow-up caseload</h2>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader className="bg-muted/50">
                <TableRow>
                  <TableHead className="px-3">Student</TableHead>
                  <TableHead className="px-3">Why flagged</TableHead>
                  <TableHead className="px-3">Status</TableHead>
                  <TableHead className="px-3">Owner</TableHead>
                  <TableHead className="px-3">Due</TableHead>
                  <TableHead className="px-3 text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((f) => (
                  <TableRow key={f.id}>
                    <TableCell className="px-3 text-sm font-medium">{f.student_name}</TableCell>
                    <TableCell className="max-w-64 px-3">
                      <p className="truncate text-sm" title={f.risk_reason}>
                        {f.who5_score !== null && <Badge variant="destructive" className="mr-1.5">WHO-5 {f.who5_score}</Badge>}
                        {f.risk_reason}
                      </p>
                      {f.outcome_note !== null && (
                        <p className="truncate text-xs text-muted-foreground" title={f.outcome_note}>Outcome: {f.outcome_note}</p>
                      )}
                    </TableCell>
                    <TableCell className="px-3">
                      <Badge variant={STATUS_VARIANTS[f.status]}>{f.status.replace('_', ' ')}</Badge>
                    </TableCell>
                    <TableCell className="px-3 text-xs">
                      {f.assigned_counsellor ?? <span className="text-muted-foreground">Unassigned</span>}
                    </TableCell>
                    <TableCell className="px-3 text-xs">
                      {f.status === 'closed' || f.due_at === null ? '—' : fmtUtcToApp(f.due_at, 'MMM d')}
                    </TableCell>
                    <TableCell className="px-3 text-right">
                      {f.status === 'new' && (
                        <Button size="sm" onClick={() => requestTransition(f, 'start_review')}>
                          <UserCheck aria-hidden /> Review
                        </Button>
                      )}
                      {f.status === 'in_review' && (
                        <Button size="sm" onClick={() => requestTransition(f, 'mark_addressed')}>
                          Mark addressed
                        </Button>
                      )}
                      {f.status === 'addressed' && (
                        <Button size="sm" variant="outline" onClick={() => requestTransition(f, 'close')}>
                          Close loop
                        </Button>
                      )}
                      {f.status === 'closed' && <span className="text-xs text-muted-foreground">Resolved</span>}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </section>
      )}

      {transitionFor !== null && (
        <TransitionDialog
          followup={transitionFor.followup}
          action={transitionFor.action}
          onClose={() => setTransitionFor(null)}
        />
      )}
    </div>
  );
}
