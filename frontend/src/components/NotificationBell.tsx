/**
 * NotificationBell — header popover with unread badge (Phase 10).
 *
 * shadcn Popover (Radix) for accessible focus management. Notifications
 * are strictly self-scoped (the API filters by the token's user);
 * clicking an unread row marks it read. A "View all" link opens the
 * full history page.
 */
import { useState } from 'react';
import { Bell, ChevronRight, Loader2 } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useMarkAllNotificationsRead, useMarkNotificationRead, useNotifications } from '@/hooks/useNotifications';
import { notificationLabel } from '@/utils/notifications';
import { fmtRelative } from '@/utils/date';
import { hasPermission, useAuthStore } from '@/store/auth';

export function NotificationBell() {
  // Hide the bell entirely when the user can't read notifications —
  // e.g. a custom account created via /admin/users that was never
  // assigned to a group containing `notifications.read`. Without this
  // guard the bell polls `/api/v1/notifications` every 60 s and the
  // browser fills up with 403 noise.
  const authState = useAuthStore();
  const canRead = hasPermission(authState, 'notifications.read');
  const list = useNotifications(10);
  const markRead = useMarkNotificationRead();
  const markAll = useMarkAllNotificationsRead();
  const [open, setOpen] = useState(false);

  if (!canRead) {
    return null;
  }

  const items = list.data ?? [];
  const unread = items.filter((n) => n.read_at === null).length;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          aria-label={`Notifications (${unread} unread)`}
          className="relative rounded-full"
        >
          <Bell aria-hidden />
          {unread > 0 && (
            <span
              aria-hidden
              className="absolute -right-0.5 -top-0.5 grid size-4 place-items-center rounded-full bg-destructive text-[10px] font-semibold text-destructive-foreground"
            >
              {unread > 9 ? '9+' : unread}
            </span>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" sideOffset={8} className="w-80 p-2">
        <div className="flex items-center justify-between px-2 py-1">
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            Notifications
          </p>
          {unread > 0 && (
            <Button
              size="sm"
              variant="ghost"
              className="h-6 px-2 text-[11px]"
              disabled={markAll.isPending}
              onClick={() => markAll.mutate()}
            >
              Mark all read
            </Button>
          )}
        </div>
        {list.isLoading && (
          <p className="flex items-center justify-center gap-2 px-2 py-4 text-center text-xs text-muted-foreground">
            <Loader2 className="size-3.5 animate-spin" aria-hidden /> Loading…
          </p>
        )}
        {list.isError && !list.isLoading && (
          <div role="alert" className="px-2 py-4 text-center text-xs text-destructive">
            <p>Couldn’t load notifications.</p>
            <Button size="sm" variant="outline" className="mt-2" onClick={() => void list.refetch()} disabled={list.isFetching}>
              Retry
            </Button>
          </div>
        )}
        {!list.isLoading && !list.isError && items.length === 0 && (
          <p className="px-2 py-4 text-center text-xs text-muted-foreground">Nothing yet.</p>
        )}
        <ul className="max-h-72 space-y-1 overflow-y-auto">
          {items.map((n) => (
            <li key={n.id}>
              <button
                type="button"
                onClick={() => n.read_at === null && markRead.mutate(n.id)}
                className={`w-full rounded-md px-2 py-2 text-left text-xs transition-colors hover:bg-accent hover:text-accent-foreground ${
                  n.read_at === null ? 'bg-accent/60' : ''
                }`}
              >
                <span className="block font-medium text-foreground">
                  {notificationLabel(n.template_code, n.context)}
                </span>
                <span className="mt-0.5 block text-[10px] text-muted-foreground">
                  {fmtRelative(n.created_at)}
                  {n.read_at === null ? ' · unread' : ''}
                </span>
              </button>
            </li>
          ))}
        </ul>
        <div className="mt-1 border-t pt-1">
          <Button asChild size="sm" variant="ghost" className="h-8 w-full justify-between px-2 text-[11px]">
            <Link to="/notifications" onClick={() => setOpen(false)}>
              View all notifications <ChevronRight className="size-3.5" aria-hidden />
            </Link>
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  );
}
