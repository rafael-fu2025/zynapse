/**
 * Phase 3.1: UserMenu now shows the linked patient's full name
 * (from the unified PersonDto) and a small kind badge in the
 * popover. Falls back to email/username when no patient record
 * is linked.
 */
import { CirclePower, Moon, Sun } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
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

/** Cumulative wheel delta (px) that counts as a real scroll, not trackpad jitter. */
const SCROLL_DISMISS_THRESHOLD_PX = 4;

/**
 * Grace window (ms) after an in-menu wheel/touch gesture. The menu has no
 * internal scroll area yet, so a wheel over it scrolls the PAGE — without
 * this, the resulting scroll event would dismiss the very menu the user is
 * pointing at (and would self-close the moment the menu gains a scroller).
 */
const INSIDE_MENU_SCROLL_GRACE_MS = 200;

export function UserMenu() {
  const me = useMe();
  const logout = useLogout();
  const { theme, toggleTheme } = useTheme();
  const [confirmSignOut, setConfirmSignOut] = useState(false);
  const [open, setOpen] = useState(false);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const wheelDeltaRef = useRef(0);
  const inMenuGestureAtRef = useRef(0);

  /**
   * The ONE dismissal path. Radix routes outside-click and Escape through
   * `onOpenChange`, and the scroll listeners below call this directly — so
   * every dismiss flips the state, closes `aria-expanded` (Radix derives it
   * from `open`) and returns focus to the trigger when the menu held it.
   */
  const closeMenu = useCallback((): void => {
    setOpen(false);
    const active = document.activeElement;
    if (active instanceof HTMLElement && menuRef.current?.contains(active)) {
      triggerRef.current?.focus();
    }
  }, []);

  useEffect(() => {
    if (!open) return;
    wheelDeltaRef.current = 0;
    inMenuGestureAtRef.current = 0;

    const isInsideMenu = (target: EventTarget | null): boolean =>
      target instanceof Node && (menuRef.current?.contains(target) ?? false);

    // `scroll` does NOT bubble, so the listener is registered on window in
    // the CAPTURE phase — that catches nested scrollers (tables, chart
    // panes) that a bubble-phase window listener would miss. A scroll event
    // means the offset already moved, so it dismisses on the first event.
    const onScroll = (event: Event): void => {
      if (isInsideMenu(event.target)) return;
      if (performance.now() - inMenuGestureAtRef.current < INSIDE_MENU_SCROLL_GRACE_MS) return;
      closeMenu();
    };
    // wheel/touchmove fire BEFORE any scroll event; accumulate the wheel
    // delta so trackpad momentum jitter (a few px) doesn't dismiss, while a
    // deliberate nudge (>= SCROLL_DISMISS_THRESHOLD_PX) does.
    const onWheel = (event: WheelEvent): void => {
      if (isInsideMenu(event.target)) {
        inMenuGestureAtRef.current = performance.now();
        return;
      }
      wheelDeltaRef.current += Math.abs(event.deltaX) + Math.abs(event.deltaY);
      if (wheelDeltaRef.current >= SCROLL_DISMISS_THRESHOLD_PX) closeMenu();
    };
    // A touch drag is an intentional gesture — no threshold.
    const onTouchMove = (event: TouchEvent): void => {
      if (isInsideMenu(event.target)) {
        inMenuGestureAtRef.current = performance.now();
        return;
      }
      closeMenu();
    };

    window.addEventListener('scroll', onScroll, { capture: true, passive: true });
    window.addEventListener('wheel', onWheel, { passive: true });
    window.addEventListener('touchmove', onTouchMove, { passive: true });

    return () => {
      window.removeEventListener('scroll', onScroll, { capture: true });
      window.removeEventListener('wheel', onWheel);
      window.removeEventListener('touchmove', onTouchMove);
    };
  }, [closeMenu, open]);

  const linkedName = me.data?.person_name ?? me.data?.email ?? me.data?.username ?? null;
  const identity = linkedName ?? me.data?.email ?? me.data?.username ?? null;
  const initials = initialsFor(identity);
  const personKind = me.data?.person_kind ?? null;
  // Priority: person's real name > institutional email > university ID number.
  const displayHandle = me.data?.person_name ?? me.data?.email ?? me.data?.identifier ?? '';

  return (
    <Popover open={open} onOpenChange={(next) => (next ? setOpen(true) : closeMenu())}>
      <PopoverTrigger asChild>
        <button
          ref={triggerRef}
          type="button"
          aria-label="Open user menu"
          className="flex items-center justify-center rounded-full bg-primary text-primary-foreground transition-colors hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50
            size-9 shrink-0 p-0
            sm:size-auto sm:h-9 sm:max-w-64 sm:shrink sm:gap-2 sm:border sm:border-primary/60 sm:bg-background/60 sm:px-1 sm:py-0 sm:pr-3 sm:text-left sm:text-xs sm:text-foreground sm:justify-start sm:hover:border-primary sm:hover:bg-accent sm:hover:opacity-100"
        >
          <span
            aria-hidden
            className="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-[0.6875rem] font-semibold tracking-wide text-primary-foreground"
          >
            {initials}
          </span>
          <span className="hidden truncate text-muted-foreground sm:block">
            {displayHandle}
          </span>
        </button>
      </PopoverTrigger>
      <PopoverContent ref={menuRef} align="end" sideOffset={8} className="w-56 p-1">
        <div className="px-2 py-1.5">
          {me.data?.person_name ? (
            <>
              <p className="truncate text-sm font-medium text-foreground">
                {me.data.person_name}
              </p>
              {me.data?.email && (
                <p className="truncate text-xs text-muted-foreground">
                  {me.data.email}
                </p>
              )}
            </>
          ) : (
            <p className="truncate text-sm font-medium text-foreground">
              {me.data?.email ?? me.data?.username ?? 'Signed in'}
            </p>
          )}
          {me.data?.username &&
            !me.data.username.startsWith('stu-') &&
            !me.data.username.startsWith('emp-') &&
            me.data.username !== me.data.identifier && (
              <p className="truncate text-[0.6875rem] text-muted-foreground">
                @{me.data.username}
              </p>
            )}
          <div className="mt-1 flex items-center gap-1.5 text-[0.6875rem] text-muted-foreground">
            <span>{kindLabel(personKind)}</span>
            {me.data?.identifier && (
              <>
                <span>·</span>
                <span className="tabular-nums">{me.data.identifier}</span>
              </>
            )}
          </div>
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
