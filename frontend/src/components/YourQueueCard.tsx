import { Clock3, Hourglass, Stethoscope, UserRoundCheck } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useMyQueueStatus, type MyQueueStatus } from '@/hooks/useMyQueue';

export function YourQueueCard({ kind }: { kind: 'employee' | 'student' }) {
  const queues = useMyQueueStatus(kind).data ?? [];
  if (queues.length === 0) return null;
  return <div className="grid gap-3 md:grid-cols-2">{queues.map((q) => <QueueCard key={`${q.destination}-${q.queue_entry_id}`} q={q} />)}</div>;
}
function QueueCard({ q }: { q: MyQueueStatus }) {
  const destination = q.destination === 'clinic' ? 'Clinic' : 'Guidance';
  if (q.status === 'called') return <Card className="border-destructive/40 bg-destructive/5"><CardHeader className="pb-2"><CardTitle className="flex items-center gap-2 text-base"><UserRoundCheck className="size-4 text-destructive" />You're up — {q.queue_number}</CardTitle><CardDescription>Proceed to {destination}.</CardDescription></CardHeader></Card>;
  if (q.status === 'in_session') return <Card><CardHeader className="pb-2"><CardTitle className="flex items-center gap-2 text-base"><Stethoscope className="size-4 text-primary" />In session with {destination}</CardTitle><CardDescription>Your number {q.queue_number} is being served now.</CardDescription></CardHeader></Card>;
  return <Card><CardHeader className="pb-2"><CardTitle className="flex items-center gap-2 text-base"><Clock3 className="size-4 text-primary" />{destination} queue</CardTitle><CardDescription>Updates automatically every 10 seconds.</CardDescription></CardHeader><CardContent className="flex flex-wrap items-center gap-4"><div><p className="text-2xl font-semibold tabular-nums">{q.queue_number}</p><p className="text-xs text-muted-foreground">queue number</p></div><Badge variant="info" className="gap-1.5"><Hourglass className="size-3.5" />~{q.estimated_wait_minutes ?? '—'} min wait</Badge><p className="text-xs text-muted-foreground">{q.people_ahead} person{q.people_ahead === 1 ? '' : 's'} ahead</p></CardContent></Card>;
}
