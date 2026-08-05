/**
 * YourQueueCard — live "your place in line" for the student/employee
 * portal. Polls the self-scoped queue-status endpoint every 10s and
 * renders nothing when the caller has no active queue entry today (keeps
 * the portal clean when not queued).
 *
 * States:
 *   - waiting    → queue number + ~min wait + people ahead
 *   - called     → highlighted "you're up — please proceed"
 *   - in_session → in the room with the clinic staff
 */
import { Clock3, Hourglass, Stethoscope, UserRoundCheck } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useMyQueueStatus } from '@/hooks/useMyQueue';

export function YourQueueCard({ kind }: { kind: 'employee' | 'student' }) {
  const status = useMyQueueStatus(kind);
  const q = status.data;

  // Not queued today → no card. (Loading shows nothing too, so the page
  // doesn't flash a skeleton for a card that usually won't appear.)
  if (q === null || q === undefined) return null;

  if (q.status === 'called') {
    return (
      <Card className="border-destructive/40 bg-destructive/5">
        <CardHeader className="pb-2">
          <CardTitle className="flex items-center gap-2 text-base">
            <UserRoundCheck className="size-4 text-destructive" aria-hidden />
            You're up — {q.queue_number}
          </CardTitle>
          <CardDescription>Please proceed to the clinic.</CardDescription>
        </CardHeader>
      </Card>
    );
  }

  if (q.status === 'in_session') {
    return (
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="flex items-center gap-2 text-base">
            <Stethoscope className="size-4 text-primary" aria-hidden />
            In session with the clinic
          </CardTitle>
          <CardDescription>Your number {q.queue_number} is being seen now.</CardDescription>
        </CardHeader>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center gap-2 text-base">
          <Clock3 className="size-4 text-primary" aria-hidden />
          You're in the queue
        </CardTitle>
        <CardDescription>This updates automatically.</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-wrap items-center gap-4">
        <div>
          <p className="text-2xl font-semibold tabular-nums text-foreground">{q.queue_number}</p>
          <p className="text-xs text-muted-foreground">queue number</p>
        </div>
        <Badge variant="info" className="gap-1.5">
          <Hourglass className="size-3.5" aria-hidden />
          ~{q.estimated_wait_minutes ?? '—'} min wait
        </Badge>
        <p className="text-xs text-muted-foreground">
          {q.people_ahead} person{q.people_ahead === 1 ? '' : 's'} ahead of you
        </p>
      </CardContent>
    </Card>
  );
}
