import { Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  useGuidanceCallNext,
  useGuidanceQueueToday,
  useGuidanceQueueTransition,
  useGuidanceRepairSession,
} from '@/hooks/useQueue';
import { hasPermission, useAuthStore } from '@/store/auth';
import { fmtUtcToApp } from '@/utils/date';

interface GuidanceQueueTabProps {
  onOpenSession: (id: number) => void;
}

export function GuidanceQueueTab({ onOpenSession }: GuidanceQueueTabProps) {
  const queue = useGuidanceQueueToday();
  const callNext = useGuidanceCallNext();
  const transition = useGuidanceQueueTransition();
  const repair = useGuidanceRepairSession();
  const active = queue.data?.find((entry) => entry.status === 'called' || entry.status === 'in_session');
  const waiting = queue.data?.filter((entry) => entry.status === 'waiting') ?? [];
  const authState = useAuthStore();
  const canManage = hasPermission(authState, 'counselling.queue.manage');

  return (
    <section className="space-y-4 rounded-xl border bg-card p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="font-semibold text-foreground">Today’s Guidance queue</h2>
          <p className="text-sm text-muted-foreground">
            Independent FIFO queue for Guidance check-ins and accepted handoffs.
          </p>
        </div>
        {canManage && (
          <Button
            onClick={() => callNext.mutate()}
            disabled={callNext.isPending || active !== undefined || waiting.length === 0}
          >
            {callNext.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
            Call next
          </Button>
        )}
      </div>

      {queue.isError ? (
        <div role="alert" className="rounded-lg border border-destructive/40 p-4 text-destructive">
          Failed to load the Guidance queue.{' '}
          <Button size="sm" variant="outline" onClick={() => void queue.refetch()} disabled={queue.isFetching}>
            Retry
          </Button>
        </div>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Queue</TableHead>
              <TableHead>Patient</TableHead>
              <TableHead>Purpose</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Called</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {queue.isLoading && (
              <TableRow>
                <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                  <Loader2 className="mr-2 inline size-4 animate-spin" />
                  Loading queue…
                </TableCell>
              </TableRow>
            )}
            {!queue.isLoading && (queue.data?.length ?? 0) === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="py-12 text-center text-muted-foreground">
                  <p className="font-medium">No Guidance check-ins today.</p>
                  <p className="mt-1 text-xs">Patients who check in at the kiosk or have due appointments will appear here.</p>
                </TableCell>
              </TableRow>
            )}
            {queue.data?.map((entry) => (
              <TableRow key={entry.id}>
                <TableCell className="font-mono font-semibold text-primary">
                  {entry.queue_number ?? `G-${String(entry.position).padStart(3, '0')}`}
                </TableCell>
                <TableCell>
                  <p className="font-medium">{entry.display_name}</p>
                  <p className="font-mono text-xs text-muted-foreground">{entry.patient_school_id}</p>
                </TableCell>
                <TableCell>{entry.purpose}</TableCell>
                <TableCell>
                  <Badge variant={entry.status === 'in_session' ? 'success' : entry.status === 'called' ? 'info' : 'secondary'}>
                    {entry.status.replace('_', ' ')}
                  </Badge>
                </TableCell>
                <TableCell className="text-sm text-muted-foreground">
                  {entry.called_at ? fmtUtcToApp(entry.called_at) : '—'}
                </TableCell>
                <TableCell className="text-right">
                  {canManage && entry.status === 'called' && (
                    <div className="flex justify-end gap-2">
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={transition.isPending && transition.variables?.id === entry.id}
                        onClick={() =>
                          transition.mutate(
                            { id: entry.id, action: 'skip' },
                            { onSuccess: () => toast.success(`${entry.queue_number} skipped.`) },
                          )
                        }
                      >
                        Skip
                      </Button>
                      <Button
                        size="sm"
                        disabled={transition.isPending && transition.variables?.id === entry.id}
                        onClick={() =>
                          transition.mutate(
                            { id: entry.id, action: 'start' },
                            {
                              onSuccess: (started) => {
                                if (started.counselling_session_id === null || started.counselling_session_id === undefined) {
                                  toast.error('The queue started, but its linked session is missing. Refresh and retry opening it.');
                                  return;
                                }
                                toast.success(`${started.queue_number ?? `G-${String(started.position).padStart(3, '0')}`} started — Session #${started.counselling_session_id} is now active.`);
                                onOpenSession(started.counselling_session_id);
                              },
                            },
                          )
                        }
                      >
                        {transition.isPending && transition.variables?.id === entry.id ? <Loader2 className="animate-spin" /> : null}
                        Start Session
                      </Button>
                    </div>
                  )}
                  {entry.status === 'in_session' && (
                    <div className="flex justify-end gap-2">
                      {entry.counselling_session_id !== null && entry.counselling_session_id !== undefined ? (
                        <Button size="sm" variant="outline" onClick={() => onOpenSession(entry.counselling_session_id as number)}>
                          Open Session &amp; Notes
                        </Button>
                      ) : (
                        <div className="flex items-center gap-2">
                          <span className="text-xs text-destructive">Linked session missing.</span>
                          {canManage && (
                            <Button
                              size="sm"
                              variant="outline"
                              disabled={repair.isPending}
                              onClick={() =>
                                repair.mutate(entry.id, {
                                  onSuccess: (repaired) => {
                                    if (repaired.counselling_session_id !== null && repaired.counselling_session_id !== undefined) {
                                      toast.success(`Session #${repaired.counselling_session_id} linked.`);
                                      onOpenSession(repaired.counselling_session_id);
                                    }
                                  },
                                })
                              }
                            >
                              Repair link
                            </Button>
                          )}
                        </div>
                      )}
                      {canManage && (
                        <Button
                          size="sm"
                          disabled={transition.isPending && transition.variables?.id === entry.id}
                          onClick={() =>
                            transition.mutate(
                              { id: entry.id, action: 'complete' },
                              { onSuccess: () => toast.success(`${entry.queue_number} completed.`) },
                            )
                          }
                        >
                          Complete Session
                        </Button>
                      )}
                    </div>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </section>
  );
}
