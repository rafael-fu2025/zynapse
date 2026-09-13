/**
 * SessionInterviewsPanel — the counsellor's session view of the
 * student's latest routine/exit interview submissions (Phase C session
 * linkage). Silently renders nothing when the caller lacks access.
 */
import { ClipboardList } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useSessionInterviews } from '@/hooks/useGuidanceFollowups';
import { fmtUtcToApp } from '@/utils/date';

export function SessionInterviewsPanel({ sessionId }: { sessionId: number }) {
  const interviews = useSessionInterviews(sessionId);

  // Permission-denied (plain responses.read on someone else's session):
  // render nothing rather than an error state.
  if (interviews.isError) return null;

  const rows = interviews.data ?? [];
  if (rows.length === 0) return null;

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-sm">
          <ClipboardList className="size-4" aria-hidden /> Latest interviews
        </CardTitle>
        <CardDescription>
          Routine/exit interview submissions from this student, newest first.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {interviews.isLoading && <Skeleton className="h-16" />}
        {rows.map((entry) => (
          <details key={entry.response_id} className="rounded-lg border bg-background p-3">
            <summary className="cursor-pointer text-xs font-medium text-foreground">
              {entry.survey_title}
              <span className="ml-2 font-normal text-muted-foreground">{fmtUtcToApp(entry.submitted_at)}</span>
            </summary>
            <dl className="mt-2 space-y-1.5">
              {entry.answers.map((a, i) => (
                <div key={i} className="text-xs">
                  <dt className="font-medium text-foreground">{a.question}</dt>
                  <dd className="text-muted-foreground">{a.value === '' ? '—' : a.value}</dd>
                </div>
              ))}
            </dl>
          </details>
        ))}
      </CardContent>
    </Card>
  );
}
