/**
 * NotificationsPage — full in-app notification history (Phase 10/19).
 *
 * Self-scoped: the API only ever returns the signed-in user's rows.
 * Keyset pagination, mark-read on click, "Mark all read". Friendly
 * copy lives in `utils/notifications.ts` (shared with the bell).
 */
import { ChevronLeft, ChevronRight, Inbox, Loader2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { QueryErrorState } from '@/components/QueryErrorState';
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotificationsPage,
} from '@/hooks/useNotifications';
import { notificationDetail, notificationLabel } from '@/utils/notifications';
import { fmtRelative, fmtUtcToApp } from '@/utils/date';

export default function NotificationsPage() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [onlyUnread, setOnlyUnread] = useState(false);

  const list = useNotificationsPage(cursor, 25);
  const markRead = useMarkNotificationRead();
  const markAll = useMarkAllNotificationsRead();

  const rows = useMemo(() => list.data?.data ?? [], [list.data]);
  const pageHasUnread = rows.some((n) => n.read_at === null);
  const visible = onlyUnread ? rows.filter((n) => n.read_at === null) : rows;

  function nextPage() {
    if (list.data?.next !== null && list.data?.next !== undefined) {
      const n = list.data.next;
      setHistory((h) => [...h, n]);
      setCursor(n);
    }
  }
  function prevPage() {
    if (history.length < 2) return;
    const next = history.slice(0, -1);
    setHistory(next);
    setCursor(next[next.length - 1] ?? null);
  }

  return (
    <main className="mx-auto max-w-3xl space-y-4 p-6">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Notifications</h1>
          <p className="text-sm text-muted-foreground">Your in-app notification history.</p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant={onlyUnread ? 'secondary' : 'outline'}
            size="sm"
            onClick={() => setOnlyUnread((v) => !v)}
          >
            {onlyUnread ? 'Showing unread' : 'All'}
          </Button>
          <Button size="sm" variant="outline" disabled={markAll.isPending || !pageHasUnread} onClick={() => markAll.mutate()}>
            {markAll.isPending && <Loader2 className="size-3.5 animate-spin" aria-hidden />}
            Mark all read
          </Button>
        </div>
      </header>

      <section className="overflow-hidden rounded-xl border bg-card">
        {list.isLoading && (
          <p className="flex items-center justify-center gap-2 px-4 py-12 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" aria-hidden /> Loading…
          </p>
        )}
        {list.isError && !list.isLoading && (
          <QueryErrorState message="Failed to load notifications." onRetry={() => void list.refetch()} pending={list.isFetching} />
        )}
        {!list.isLoading && !list.isError && visible.length === 0 && (
          <div className="px-4 py-14 text-center">
            <Inbox className="mx-auto size-8 text-muted-foreground/60" aria-hidden />
            <p className="mt-2 text-sm text-muted-foreground">
              {onlyUnread ? 'No unread notifications.' : 'Nothing yet.'}
            </p>
          </div>
        )}
        {visible.length > 0 && (
          <ul className="divide-y">
            {visible.map((n) => {
              const unread = n.read_at === null;
              const detail = notificationDetail(n.template_code, n.context);
              return (
                <li key={n.id}>
                  <button
                    type="button"
                    onClick={() => unread && markRead.mutate(n.id)}
                    className={`flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-accent/60 ${
                      unread ? 'bg-accent/40' : ''
                    }`}
                  >
                    <span
                      aria-hidden
                      className={`mt-1.5 size-2 shrink-0 rounded-full ${unread ? 'bg-destructive' : 'bg-transparent'}`}
                    />
                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-medium text-foreground">
                        {notificationLabel(n.template_code, n.context)}
                      </span>
                      {detail !== null && (
                        <span className="mt-0.5 block font-mono text-xs text-muted-foreground">
                          {detail}
                        </span>
                      )}
                      <span className="mt-0.5 block text-xs text-muted-foreground">
                        {fmtUtcToApp(n.created_at)} · {fmtRelative(n.created_at)}
                        {unread ? ' · unread' : ''}
                      </span>
                    </span>
                    {n.template_code.startsWith('referral.') && (
                      <Badge variant="secondary" className="shrink-0">Referral</Badge>
                    )}
                    {n.template_code.startsWith('reorder.') && (
                      <Badge variant="warning" className="shrink-0">Inventory</Badge>
                    )}
                  </button>
                </li>
              );
            })}
          </ul>
        )}
      </section>

      <nav className="flex items-center justify-between" aria-label="pagination">
        <p className="text-xs text-muted-foreground">Page {history.length}</p>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={prevPage} disabled={history.length < 2}>
            <ChevronLeft /> Prev
          </Button>
          <Button variant="outline" size="sm" onClick={nextPage} disabled={list.data?.next === null || list.data?.next === undefined}>
            Next <ChevronRight />
          </Button>
        </div>
      </nav>
    </main>
  );
}
