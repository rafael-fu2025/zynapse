/**
 * FollowupsTab — the RA 11036 §24 aftercare caseload (Phase C).
 * Open items lead; the loop is closed: addressed/closed require an
 * outcome note. Gated by `counselling.responses.read_any`.
 *
 * 2026-09-23: a **Follow-up appointments** section now sits above the
 * caseload. The aftercare loop is only half the picture — the other half is
 * the follow-up slots themselves, and they arrive from two places: the
 * patient books one through the portal, or the desk books one for them. The
 * section reads `type=follow_up` with no source filter, so both appear, and
 * the "Booked by" column says which. The scope selector narrows the window
 * (Upcoming / Today / Archived / All) and lives in the URL like every other
 * filter in the app.
 */
import { CalendarClock, Inbox, UserCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableStateBlock, TableStateRows } from '@/components/TableStates';
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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import {
  useGuidanceFollowups,
  useTransitionFollowup,
  type GuidanceFollowup,
} from '@/hooks/useGuidanceFollowups';
import { useAppointments } from '@/hooks/useSchedule';
import { useUrlFilter } from '@/hooks/useUrlFilter';
import { titleCase } from '@/lib/utils';
import { SOURCE_LABEL, type AppointmentScope } from '@/schemas/schedule';
import { fmtTimeRange, fmtUtcToApp } from '@/utils/date';
import { STATUS_VARIANT } from '../constants';

const SCOPE_OPTIONS: ReadonlyArray<{ value: AppointmentScope; label: string }> = [
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'today', label: 'Today' },
  { value: 'archived', label: 'Archived' },
  { value: 'all', label: 'All' },
];

/**
 * Follow-up slots, from both booking origins. A compact table rather than a
 * board — this is a supporting list, the caseload below is the worklist.
 */
function FollowUpAppointments() {
  const [scope, setScope] = useUrlFilter('followup_scope', { default: 'upcoming' });
  const appointments = useAppointments({ type: 'follow_up', scope: scope as AppointmentScope });
  const rows = appointments.data?.data ?? [];

  return (
    <section
      aria-labelledby="followup-appointments-heading"
      className="overflow-hidden rounded-xl border bg-card"
    >
      <header className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
        <div className="flex items-center gap-2">
          <CalendarClock className="size-4 text-muted-foreground" aria-hidden />
          <div>
            <h2 id="followup-appointments-heading" className="text-sm font-semibold text-foreground">
              Follow-up appointments
            </h2>
            <p className="text-xs text-muted-foreground">
              Every follow-up slot, whether the student booked it or the desk did.
            </p>
          </div>
        </div>
        <Select value={scope} onValueChange={setScope}>
          <SelectTrigger aria-label="Follow-up appointment window" className="h-8 w-36">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {SCOPE_OPTIONS.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </header>

      <Table ariaLabel="Follow-up appointments">
        <TableHeader className="bg-muted/50">
          <TableRow>
            <TableHead className="px-3">When</TableHead>
            <TableHead className="px-3">Patient</TableHead>
            <TableHead className="px-3">Booked by</TableHead>
            <TableHead className="px-3">Status</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableStateRows
            colSpan={4}
            isLoading={appointments.isLoading}
            isError={appointments.isError}
            isEmpty={rows.length === 0}
            onRetry={() => void appointments.refetch()}
            pending={appointments.isFetching}
            errorMessage="Failed to load follow-up appointments."
            loadingLabel="Loading follow-up appointments"
            empty={{
              title: 'No follow-up appointments in this window.',
              description: 'Follow-up slots booked by students or by the counselling team appear here.',
            }}
          />
          {rows.map((a) => (
            <TableRow key={a.id}>
              <TableCell className="px-3 tabular-nums text-xs text-muted-foreground">
                {a.appointment_date} {fmtTimeRange(a.start_time, a.end_time)}
              </TableCell>
              <TableCell className="px-3">
                <p className="text-xs font-medium text-foreground">
                  {a.patient_display_name ?? a.patient_school_id}
                </p>
                <p className="tabular-nums text-xs text-muted-foreground">{a.patient_school_id}</p>
              </TableCell>
              <TableCell className="px-3 text-xs">
                <Badge variant={a.source === 'patient' ? 'info' : 'secondary'}>
                  {SOURCE_LABEL[a.source]}
                </Badge>
              </TableCell>
              <TableCell className="px-3">
                <Badge variant={STATUS_VARIANT[a.status]}>{titleCase(a.status)}</Badge>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </section>
  );
}

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
  // Memoized so the openCount useMemo below has stable dependencies.
  const rows = useMemo(() => followups.data ?? [], [followups.data]);

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

      <FollowUpAppointments />

      <TableStateBlock
        isLoading={followups.isLoading}
        isError={followups.isError}
        isEmpty={rows.length === 0}
        onRetry={() => void followups.refetch()}
        pending={followups.isFetching}
        errorMessage="Failed to load the follow-up caseload."
        loadingLabel="Loading follow-ups"
        empty={{
          icon: <Inbox className="size-8" />,
          title: 'No follow-ups in this view',
          description:
            'Follow-ups appear when a routine interview scores at or below the well-being threshold, or a student asks for counselor contact.',
        }}
      />

      {!followups.isLoading && !followups.isError && rows.length > 0 && (
        <section aria-labelledby="followup-list-heading" className="overflow-hidden rounded-xl border bg-card">
          <h2 id="followup-list-heading" className="sr-only">Follow-up caseload</h2>
          <div className="overflow-x-auto">
            <Table ariaLabel="Follow-up caseload">
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
