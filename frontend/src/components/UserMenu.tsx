/**
 * Phase 3.1: UserMenu now shows the linked patient's full name
 * (from the unified PersonDto) and a small kind badge in the
 * popover. Falls back to email/username when no patient record
 * is linked.
 */
import { CirclePower, Moon, Sun } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useLogout, useMe } from '@/hooks/useAuth';
import { useTheme } from '@/hooks/useTheme';

function initialsFor(identity: string | null | undefined): string {
  const raw = (identity ?? '').trim();
  if (raw === '') return '··';
  const source = raw.includes('@') ? raw.split('@')[0] ?? raw : raw;
  const parts = source.split(/[._\-\s\d]+/).filter((p) => p.length > 0);
  if (parts.length >= 2) {
    return (parts[0]![0]! + parts[1]![0]!).toUpperCase();
  }
  const single = parts[0] ?? source;
  return (single.length >= 2 ? single.slice(0, 2) : single).toUpperCase();
}

function kindLabel(kind: 'student' | 'employee' | 'contractor' | 'alumni' | null | undefined): string {
  if (kind === 'student') return 'Student';
  if (kind === 'employee') return 'Employee';
  if (kind === 'contractor') return 'Contractor';
  if (kind === 'alumni') return 'Alumni';
  return 'No patient link';
}

export function UserMenu() {
  const me = useMe();
  const logout = useLogout();
  const { theme, toggleTheme } = useTheme();
  const [confirmSignOut, setConfirmSignOut] = useState(false);

  const linkedName = me.data?.person_name ?? me.data?.email ?? me.data?.username ?? null;
  const identity = linkedName ?? me.data?.email ?? me.data?.username ?? null;
  const initials = initialsFor(identity);
  const personKind = me.data?.person_kind ?? null;

  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          aria-label="Open user menu"
          className="flex items-center justify-center rounded-full bg-primary text-primary-foreground transition-colors hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50
            size-9 shrink-0 p-0
            sm:size-auto sm:h-9 sm:max-w-64 sm:shrink sm:gap-2 sm:border sm:border-primary/60 sm:bg-background/60 sm:px-1 sm:py-0 sm:pr-3 sm:text-left sm:text-xs sm:text-foreground sm:justify-start sm:hover:border-primary sm:hover:bg-accent sm:hover:opacity-100"
        >
          <span
            aria-hidden
            className="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-[11px] font-semibold tracking-wide text-primary-foreground"
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
          {me.data?.person_name !== undefined && me.data.person_name !== null && (
            <p className="truncate text-sm font-medium text-foreground">
              {me.data.person_name}
            </p>
          )}
          <p className="truncate text-sm font-medium text-foreground">
            {me.data?.person_name ? '' : (me.data?.email ?? 'Signed in')}
          </p>
          {me.data?.username !== undefined && me.data.username !== '' && (
            <p className="truncate text-[11px] text-muted-foreground">
              @{me.data.username}
            </p>
          )}
          <p className="mt-1 truncate text-[11px] text-muted-foreground">
            {kindLabel(personKind)}
          </p>
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
          onClick={() => setConfirmSignOut(true)}
          disabled={logout.isPending}
          className="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-sm text-foreground transition-colors hover:bg-destructive/10 hover:text-destructive focus-visible:outline-none focus-visible:bg-destructive/10 disabled:pointer-events-none disabled:opacity-50"
        >
          <CirclePower aria-hidden className="size-4" />
          <span>{logout.isPending ? 'Signing out…' : 'Sign out'}</span>
        </button>
      </PopoverContent>
      <ConfirmDialog
        open={confirmSignOut}
        title="Sign out?"
        description="You will need to sign in again to access SYNAPSE."
        confirmLabel="Sign out"
        pending={logout.isPending}
        onConfirm={() => logout.mutate()}
        onCancel={() => setConfirmSignOut(false)}
      />
    </Popover>
  );
}
