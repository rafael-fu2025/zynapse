/**
 * GuidancePortalTab — student-facing guidance feed (parity plan Phase
 * A/B): the clearance requirements checklist, targeted announcements
 * with action links, open surveys/interviews, and the CMO service
 * catalogue. Bookable services deep-link into the existing portal
 * appointment/queue flows.
 */
import { AlertCircle, CalendarPlus, ClipboardList, ExternalLink, Megaphone } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { QueryErrorState } from '@/components/QueryErrorState';
import { Skeleton } from '@/components/ui/skeleton';
import { TakeSurveyDialog } from '@/components/TakeSurveyDialog';
import { useMyGuidanceAnnouncements, useMyGuidanceServices } from '@/hooks/useGuidanceContent';
import { useMySurveys } from '@/hooks/useSurveys';
import { fmtUtcToApp } from '@/utils/date';

export function GuidancePortalTab() {
  const announcements = useMyGuidanceAnnouncements();
  const services = useMyGuidanceServices();
  const surveys = useMySurveys();
  const [takingSurveyId, setTakingSurveyId] = useState<number | null>(null);

  const openSurveys = surveys.data ?? [];

  return (
    <div className="space-y-6">
      {/* Clearance gate — pending required items (server-enforced list). */}
      <Card className={openSurveys.some((s) => s.is_required) ? 'border-amber-300/70 dark:border-amber-800' : undefined}>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <ClipboardList className="size-4" aria-hidden /> Requirements for clearance signing
          </CardTitle>
          <CardDescription>Required Guidance Office items you have not completed yet.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {surveys.isLoading && <Skeleton className="h-16" />}
          {surveys.isError && (
            <QueryErrorState message="Failed to load your requirements." onRetry={() => void surveys.refetch()} pending={surveys.isFetching} />
          )}
          {surveys.data !== undefined && openSurveys.filter((s) => s.is_required).length === 0 && (
            <p className="flex items-center gap-2 py-1 text-sm text-emerald-600 dark:text-emerald-400">
              <AlertCircle className="size-4" aria-hidden /> You're all caught up — nothing pending.
            </p>
          )}
          {openSurveys.filter((s) => s.is_required).map((s) => (
            <div key={s.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300/70 bg-amber-50/60 p-3.5 dark:border-amber-800 dark:bg-amber-950/30">
              <div className="min-w-0">
                <p className="text-sm font-medium text-foreground">{s.title}</p>
                <p className="text-xs text-muted-foreground">
                  {s.close_at !== null ? `Closes ${fmtUtcToApp(s.close_at, 'MMM d, yyyy')}` : 'No deadline set'}
                </p>
              </div>
              <Button size="sm" onClick={() => setTakingSurveyId(s.id)}>Take survey</Button>
            </div>
          ))}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Megaphone className="size-4" aria-hidden /> Announcements
          </CardTitle>
          <CardDescription>Guidance office posts for you.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {announcements.isLoading && <Skeleton className="h-20" />}
          {announcements.isError && (
            <QueryErrorState message="Failed to load announcements." onRetry={() => void announcements.refetch()} pending={announcements.isFetching} />
          )}
          {announcements.data !== undefined && announcements.data.length === 0 && (
            <p className="py-3 text-sm text-muted-foreground">No announcements for you right now.</p>
          )}
          {announcements.data !== undefined && announcements.data.map((a) => (
            <article key={a.id} className="rounded-lg border bg-background p-4">
              <div className="flex flex-wrap items-center gap-2">
                {a.is_required && <Badge variant="warning">Required</Badge>}
                <h3 className="text-sm font-medium text-foreground">{a.title}</h3>
              </div>
              <p className="mt-1.5 text-sm text-muted-foreground">{a.body}</p>
              <div className="mt-2.5 flex flex-wrap items-center gap-3">
                {a.publish_at !== null && (
                  <p className="text-xs text-muted-foreground">{fmtUtcToApp(a.publish_at, 'MMM d, yyyy')}</p>
                )}
                {a.action_url !== null && (
                  <a
                    href={a.action_url}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="inline-flex items-center gap-1 text-xs font-medium text-primary underline underline-offset-2"
                  >
                    {a.action_label ?? 'Open link'} <ExternalLink className="size-3" aria-hidden />
                  </a>
                )}
              </div>
            </article>
          ))}
        </CardContent>
      </Card>

      {openSurveys.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <ClipboardList className="size-4" aria-hidden /> Open surveys &amp; interviews
            </CardTitle>
            <CardDescription>Optional forms currently open for you.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {openSurveys.filter((s) => !s.is_required).map((s) => (
              <div key={s.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-background p-3.5">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-foreground">{s.title}</p>
                  {s.description !== null && <p className="truncate text-xs text-muted-foreground">{s.description}</p>}
                </div>
                <Button size="sm" variant="outline" onClick={() => setTakingSurveyId(s.id)}>
                  Take survey
                </Button>
              </div>
            ))}
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>Guidance services</CardTitle>
          <CardDescription>
            What the Guidance Office offers — CHED CMO 9 s.2013 catalogue. Bookable services link straight into scheduling.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {services.isLoading && <Skeleton className="h-32" />}
          {services.isError && (
            <QueryErrorState message="Failed to load the service catalogue." onRetry={() => void services.refetch()} pending={services.isFetching} />
          )}
          {services.data !== undefined && (
            <ul className="grid gap-3 sm:grid-cols-2">
              {services.data.map((s) => (
                <li key={s.id} className="rounded-lg border bg-background p-4">
                  <p className="text-sm font-medium text-foreground">{s.name}</p>
                  {s.description !== null && <p className="mt-1 text-xs text-muted-foreground">{s.description}</p>}
                  {s.queue_destination === 'counselling' && (
                    <Button asChild size="sm" variant="outline" className="mt-2.5">
                      <Link to="/appointments">
                        <CalendarPlus className="size-3.5" aria-hidden /> Book appointment
                      </Link>
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>

      {takingSurveyId !== null && <TakeSurveyDialog surveyId={takingSurveyId} onClose={() => setTakingSurveyId(null)} />}
    </div>
  );
}
