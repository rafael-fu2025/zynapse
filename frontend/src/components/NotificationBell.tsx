/**
 * NotificationBell — header popover with unread badge (Phase 10).
 *
 * shadcn Popover (Radix) for accessible focus management. Notifications
 * are strictly self-scoped (the API filters by the token's user);
 * clicking an unread row marks it read.
 */
import { Bell } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useMarkNotificationRead, useNotifications } from '@/hooks/useNotifications';
import { fmtRelative } from '@/utils/date';

function label(templateCode: string, context: Record<string, unknown> | null): string {
  if (templateCode === 'appointment.assigned') {
    const res = typeof context?.['resource_code'] === 'string' ? (context['resource_code']) : '';
    return `New appointment assigned ${res !== '' ? `(${res})` : ''}`.trim();
  }
  return templateCode;
}

export function NotificationBell() {
  const list = useNotifications(10);
  const markRead = useMarkNotificationRead();

  const items = list.data ?? [];
  const unread = items.filter((n) => n.read_at === null).length;

  return (
    <Popover>
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
        <p className="px-2 py-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Notifications
        </p>
        {items.length === 0 && (
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
                  {label(n.template_code, n.context)}
                </span>
                <span className="mt-0.5 block text-[10px] text-muted-foreground">
                  {fmtRelative(n.created_at)}
                  {n.read_at === null ? ' · unread' : ''}
                </span>
              </button>
            </li>
          ))}
        </ul>
      </PopoverContent>
    </Popover>
  );
}
