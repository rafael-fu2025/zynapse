/**
 * UserMenu — topbar identity capsule + popover.
 *
 * Capsule trigger: round avatar (initials from `me.email`/`me.username`)
 * on the left, truncated email on the right. The popover mirrors the
 * NotificationBell layout: theme toggle and sign-out stacked vertically
 * with dividers, all keyboard-accessible via Radix Popover.
 *
 * Identity is read from the cached `me` query (the same source the
 * sidebar and force-reset gate use) so the avatar and email are always
 * in sync with the server.
 */
import { CirclePower, Moon, Sun } from 'lucide-react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useLogout, useMe } from '@/hooks/useAuth';
import { useTheme } from '@/hooks/useTheme';

/**
 * Derive two-letter initials from a free-form identifier.
 *
 *   "admin@synapse.dev"   -> "AD"
 *   "jose.rizal"          -> "JR"
 *   "maria"               -> "MA"
 *   ""                    -> "··"   (neutral placeholder)
 */
function initialsFor(identity: string | null | undefined): string {
  const raw = (identity ?? '').trim();
  if (raw === '') return '··';

  // Prefer the local-part of an email; fall back to the raw string.
  const source = raw.includes('@') ? raw.split('@')[0] ?? raw : raw;

  // Split on common separators (`.`, `_`, `-`, whitespace, digits) and
  // pull the first letter of the first two non-empty parts. This gives
  // "jose.rizal" -> JR and "maria" -> MA without needing a name field.
  const parts = source.split(/[._\-\s\d]+/).filter((p) => p.length > 0);
  if (parts.length >= 2) {
    return (parts[0]![0]! + parts[1]![0]!).toUpperCase();
  }
  const single = parts[0] ?? source;
  return (single.length >= 2 ? single.slice(0, 2) : single).toUpperCase();
}

export function UserMenu() {
  const me = useMe();
  const logout = useLogout();
  const { theme, toggleTheme } = useTheme();

  const identity = me.data?.email ?? me.data?.username ?? null;
  const initials = initialsFor(identity);

  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          aria-label="Open user menu"
          className="flex items-center justify-center gap-0 rounded-full bg-primary text-[11px] font-semibold text-primary-foreground transition-colors hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 size-9 p-0 sm:h-9 sm:max-w-64 sm:gap-2 sm:border sm:border-primary/60 sm:bg-background/60 sm:pl-1 sm:pr-3 sm:text-left sm:text-xs sm:hover:border-primary sm:hover:bg-accent sm:hover:text-foreground"
        >
          <span
            aria-hidden
            className="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-[11px] font-semibold tracking-wide text-primary-foreground sm:bg-primary sm:text-primary-foreground"
          >
            {initials}
          </span>
          <span className="hidden truncate text-muted-foreground sm:block">
            {me.data?.email ?? ''}
          </span>
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" sideOffset={8} className="w-56 p-1">
        <div className="px-2 py-1.5">
          <p className="truncate text-sm font-medium text-foreground">
            {me.data?.email ?? 'Signed in'}
          </p>
          {me.data?.username !== undefined && me.data.username !== '' && (
            <p className="truncate text-[11px] text-muted-foreground">
              @{me.data.username}
            </p>
          )}
        </div>
        <div className="my-1 h-px bg-border" />
        <button
          type="button"
          onClick={toggleTheme}
          className="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-sm text-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:bg-accent"
        >
          {theme === 'dark' ? <Sun aria-hidden className="size-4" /> : <Moon aria-hidden className="size-4" />}
          <span>{theme === 'dark' ? 'Light mode' : 'Dark mode'}</span>
        </button>
        <div className="my-1 h-px bg-border" />
        <button
          type="button"
          onClick={() => logout.mutate()}
          disabled={logout.isPending}
          className="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-sm text-foreground transition-colors hover:bg-destructive/10 hover:text-destructive focus-visible:outline-none focus-visible:bg-destructive/10 disabled:pointer-events-none disabled:opacity-50"
        >
          <CirclePower aria-hidden className="size-4" />
          <span>{logout.isPending ? 'Signing out…' : 'Sign out'}</span>
        </button>
      </PopoverContent>
    </Popover>
  );
}
