/**
 * CommandPalette — global ⌘K / Ctrl-K launcher.
 *
 * Opens a centered dialog with a search field + grouped command list.
 * Triggers anywhere in the app via the global keydown listener below;
 * the search input stays focused as soon as the palette opens so the
 * user can start typing immediately.
 *
 * `useAuthStore` gates each command on the caller's effective
 * permission set, so a clerk without `reports.read` won't even see
 * "Reports" in the palette. Page names also double as keywords
 * (e.g. typing "med" surfaces Inventory because the medicine list
 * lives there), so a single search box covers navigation + lookup.
 */
import {
  ArrowRightLeft,
  BarChart3,
  CalendarClock,
  Factory,
  HeartHandshake,
  Home,
  KeyRound,
  LayoutDashboard,
  LogOut,
  Pill,
  Search,
  Shield,
  Stethoscope,
  UserCog,
  UserCircle,
  Users,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/store/auth';
import {
  Dialog,
  DialogContent,
  DialogTitle,
} from '@/components/ui/dialog';
import { prefetchRoute } from '@/lib/routeChunks';
import { cn } from '@/lib/utils';

type CommandCategory = 'Navigate' | 'Account';

interface CommandDef {
  id: string;
  label: string;
  category: CommandCategory;
  /** Search-time aliases — typing any of these matches the command. */
  keywords: string[];
  icon: ReactNode;
  /** Permission code required to see this command. Omit = visible to all. */
  permission?: string;
  /**
   * Optional route to prefetch on intent (hover / arrow-key
   * highlight). When set, the chunk is warmed before the user
   * confirms the command, so `run()` navigates to a page that is
   * already in the browser cache — no "first navigation
   * requires reload" race against the dev-server dep optimizer.
   */
  prefetch?: string;
  /** Executed when the operator picks the row. */
  run: (helpers: CommandHelpers) => void;
}

interface CommandHelpers {
  navigate: ReturnType<typeof useNavigate>;
  signOut: () => void;
}

// Single source of truth — keep alphabetical within each category so
// the rendered order is predictable and grep-friendly.
const COMMANDS: ReadonlyArray<CommandDef> = [
  // -------- Navigate --------
  {
    id: 'go-appointments',
    label: 'Appointments',
    category: 'Navigate',
    keywords: ['book', 'schedule', 'visit', 'clinic', 'calendar'],
    icon: <CalendarClock className="size-4" />,
    permission: 'clinic.appointments.read',
    prefetch: '/appointments',
    run: ({ navigate }) => void navigate('/appointments'),
  },
  {
    id: 'go-audit',
    label: 'Audit log',
    category: 'Navigate',
    keywords: ['chain', 'tamper', 'integrity', 'verify'],
    icon: <Shield className="size-4" />,
    permission: 'audit.read',
    prefetch: '/audit',
    run: ({ navigate }) => void navigate('/audit'),
  },
  {
    id: 'go-clinic',
    label: 'Clinic (encounters)',
    category: 'Navigate',
    keywords: ['patient', 'visit', 'vitals', 'triage'],
    icon: <Stethoscope className="size-4" />,
    permission: 'clinic.encounters.read',
    prefetch: '/clinic',
    run: ({ navigate }) => void navigate('/clinic'),
  },
  {
    id: 'go-counselling',
    label: 'Counselling',
    category: 'Navigate',
    keywords: ['mental', 'session', 'mh'],
    icon: <HeartHandshake className="size-4" />,
    permission: 'counselling.records.read',
    prefetch: '/counselling',
    run: ({ navigate }) => void navigate('/counselling'),
  },
  {
    id: 'go-dashboard',
    label: 'Dashboard',
    category: 'Navigate',
    keywords: ['home', 'overview'],
    icon: <Home className="size-4" />,
    prefetch: '/',
    run: ({ navigate }) => void navigate('/'),
  },
  {
    id: 'go-facilities',
    label: 'Facilities (BMG)',
    category: 'Navigate',
    keywords: ['composter', 'drum', 'waste'],
    icon: <Factory className="size-4" />,
    permission: 'facilities.units.read',
    prefetch: '/facilities',
    run: ({ navigate }) => void navigate('/facilities'),
  },
  {
    id: 'go-inventory',
    label: 'Inventory',
    category: 'Navigate',
    keywords: ['medicine', 'med', 'stock', 'supply', 'reorder', 'drug', 'pill'],
    icon: <Pill className="size-4" />,
    permission: 'clinic.inventory.read',
    prefetch: '/inventory',
    run: ({ navigate }) => void navigate('/inventory'),
  },
  {
    id: 'go-portal',
    label: 'My portal',
    category: 'Navigate',
    keywords: ['profile', 'employee', 'student', 'me'],
    icon: <UserCircle className="size-4" />,
    permission: 'employee.portal.read',
    prefetch: '/me',
    run: ({ navigate }) => void navigate('/me'),
  },
  {
    id: 'go-admin-users',
    label: 'Manage users',
    category: 'Navigate',
    keywords: ['rbac', 'permissions', 'roles', 'admin'],
    icon: <UserCog className="size-4" />,
    permission: 'rbac.manage',
    prefetch: '/admin/users',
    run: ({ navigate }) => void navigate('/admin/users'),
  },
  {
    id: 'go-patients',
    label: 'Patients',
    category: 'Navigate',
    keywords: ['students', 'employees', 'registry'],
    icon: <Users className="size-4" />,
    permission: 'clinic.patients.read',
    prefetch: '/patients',
    run: ({ navigate }) => void navigate('/patients'),
  },
  {
    id: 'go-referrals',
    label: 'Referrals',
    category: 'Navigate',
    keywords: ['qr', 'issue', 'acknowledge'],
    icon: <ArrowRightLeft className="size-4" />,
    permission: 'referrals.read',
    prefetch: '/referrals',
    run: ({ navigate }) => void navigate('/referrals'),
  },
  {
    id: 'go-reports',
    label: 'Reports',
    category: 'Navigate',
    keywords: ['export', 'csv', 'analytics'],
    icon: <BarChart3 className="size-4" />,
    permission: 'reports.read',
    prefetch: '/reports',
    run: ({ navigate }) => void navigate('/reports'),
  },
  // -------- Account --------
  {
    id: 'go-overview',
    label: 'Overview (staff portal)',
    category: 'Navigate',
    keywords: ['home', 'landing', 'staff'],
    icon: <LayoutDashboard className="size-4" />,
    prefetch: '/',
    run: ({ navigate }) => void navigate('/'),
  },
  {
    id: 'acct-change-password',
    label: 'Change password',
    category: 'Account',
    keywords: ['rotate', 'credentials', 'reset'],
    icon: <KeyRound className="size-4" />,
    prefetch: '/change-password',
    run: ({ navigate }) => void navigate('/change-password'),
  },
  {
    id: 'acct-sign-out',
    label: 'Sign out',
    category: 'Account',
    keywords: ['logout', 'exit'],
    icon: <LogOut className="size-4" />,
    run: ({ signOut }) => signOut(),
  },
];

const CATEGORY_ORDER: ReadonlyArray<CommandCategory> = ['Navigate', 'Account'];

function matches(command: CommandDef, query: string): boolean {
  if (query === '') return true;
  const haystack = [command.label.toLowerCase(), ...command.keywords.map((k) => k.toLowerCase())];
  return haystack.some((h) => h.includes(query));
}

export function CommandPalette() {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [highlight, setHighlight] = useState(0);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const navigate = useNavigate();
  const signOut = useAuthStore((s) => s.clear);

  // Global Cmd/Ctrl-K toggles the palette. Bound at window level so any
  // focused input loses focus cleanly when the dialog opens — Radix
  // Dialog then focuses the input below.
  useEffect(() => {
    const onKey = (e: globalThis.KeyboardEvent): void => {
      // ⌘K on macOS, Ctrl-K elsewhere. Skip when modifier includes Shift
      // so we don't shadow other shortcuts.
      const mod = e.metaKey || e.ctrlKey;
      if (mod && !e.shiftKey && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        setOpen((o) => !o);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  // Imperative open trigger — the topbar button dispatches this event
  // so users who don't know the shortcut can still discover the palette.
  useEffect(() => {
    const onOpenEvent = (): void => setOpen(true);
    window.addEventListener('synapse:command-palette:open', onOpenEvent);
    return () => window.removeEventListener('synapse:command-palette:open', onOpenEvent);
  }, []);

  // Reset transient state on close — next open starts clean.
  useEffect(() => {
    if (!open) {
      setQuery('');
      setHighlight(0);
    }
  }, [open]);

  // Subscribe to the effective permission set so the filtered list
  // updates when the session is loaded / changed.
  const perms = useAuthStore((s) => s.permissions ?? []);

  // Build the grouped, filtered, query-matched view. Order: declared
  // order within each category, categories in CATEGORY_ORDER.
  const groups = useMemo(() => {
    const q = query.trim().toLowerCase();
    const visible = COMMANDS.filter((c) => {
      if (c.permission !== undefined && !perms.includes(c.permission) && !perms.includes('*')) {
        return false;
      }
      return matches(c, q);
    });
    const out: Record<CommandCategory, CommandDef[]> = { Navigate: [], Account: [] };
    for (const c of visible) out[c.category].push(c);
    return out;
  }, [query, perms]);

  const flat = CATEGORY_ORDER.flatMap((cat) => groups[cat]);

  // Clamp the highlight when the result list shrinks.
  useEffect(() => {
    if (highlight >= flat.length) setHighlight(Math.max(0, flat.length - 1));
  }, [flat.length, highlight]);

  // Intent-based prefetch: as soon as the highlight lands on a
  // navigation command, warm its chunk. By the time the operator
  // presses Enter the lazy import is already resolved, so the
  // destination page renders without a dep-optimizer race.
  useEffect(() => {
    const cmd = flat[highlight];
    if (cmd?.prefetch !== undefined) void prefetchRoute(cmd.prefetch);
  }, [flat, highlight]);

  function execute(index: number): void {
    const cmd = flat[index];
    if (cmd === undefined) return;
    setOpen(false);
    cmd.run({ navigate, signOut });
  }

  function onKeyDown(e: KeyboardEvent<HTMLDivElement>): void {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setHighlight((h) => (flat.length === 0 ? 0 : Math.min(h + 1, flat.length - 1)));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setHighlight((h) => Math.max(0, h - 1));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      execute(highlight);
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent
        // Override the canned padding/sizing + animation. The default
        // DialogContent is a centered modal (top:50% + zoom-in-95); for
        // a command palette the conventional "launcher" feel is a
        // top-anchored panel that drops in from above the viewport and
        // retracts back up on close — so we:
        //   1. pin it near the top (`sm:top-[10vh]`) and drop the
        //      vertical-centering `translate-y-[-50%]`
        //   2. use a much larger slide distance (40vh) so the motion
        //      reads as "drop down" / "retract up", not "fade and
        //      zoom 48%". `duration-200` keeps it snappy.
        className="max-w-xl gap-0 overflow-hidden border bg-popover p-0 text-popover-foreground shadow-lg duration-200 sm:top-[10vh] sm:translate-y-0 sm:data-[state=open]:slide-in-from-top-[-40vh] sm:data-[state=closed]:slide-out-to-top-[-40vh]"
        onOpenAutoFocus={(e) => {
          // We drive focus to the search field ourselves, so the title
          // doesn't steal it on every render.
          e.preventDefault();
          inputRef.current?.focus();
        }}
      >
        <DialogTitle className="sr-only">Command palette</DialogTitle>

        {/* Search row. The ⌘K/Ctrl-K shortcut hint used to live here on
            the right, but it overlapped the Radix close (×) button in the
            top-right corner — keep the row clean so the close hit area
            stays clear. */}
        <div className="flex items-center gap-2 border-b px-3">
          <Search className="size-4 text-muted-foreground" aria-hidden />
          <input
            ref={inputRef}
            value={query}
            onChange={(e) => { setQuery(e.target.value); setHighlight(0); }}
            onKeyDown={onKeyDown}
            placeholder="Type a command or search…"
            aria-label="Search commands"
            className="h-11 w-full bg-transparent text-sm text-foreground placeholder:text-muted-foreground focus:outline-none"
          />
        </div>

        {/* Results — flat list with category headers. Empty state
            differentiates "no match" from "no permissions" so the user
            knows whether to widen the query or escalate. */}
        <div className="max-h-80 overflow-y-auto" role="listbox" aria-label="Commands">
          {flat.length === 0 && (
            <p className="px-3 py-6 text-center text-sm text-muted-foreground">
              {query.trim() === ''
                ? 'No commands available for your role.'
                : `No matches for "${query.trim()}".`}
            </p>
          )}
          {CATEGORY_ORDER.map((category) => {
            const items = groups[category];
            if (items.length === 0) return null;
            return (
              <div key={category} className="py-1">
                <div className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                  {category}
                </div>
                <ul>
                  {items.map((cmd) => {
                    const index = flat.indexOf(cmd);
                    const isActive = index === highlight;
                    return (
                      <li key={cmd.id}>
                        <button
                          type="button"
                          role="option"
                          aria-selected={isActive}
                          // Use onMouseDown so the click doesn't blur the
                          // input (and reset the typed query) before run().
                          onMouseDown={(e) => { e.preventDefault(); }}
                          onClick={() => execute(index)}
                          onMouseEnter={() => setHighlight(index)}
                          onFocus={() => {
                            setHighlight(index);
                            if (cmd.prefetch !== undefined) void prefetchRoute(cmd.prefetch);
                          }}
                          className={cn(
                            'flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors',
                            isActive ? 'bg-accent text-accent-foreground' : 'text-foreground',
                          )}
                        >
                          <span className={cn('shrink-0', isActive ? 'text-accent-foreground' : 'text-muted-foreground')}>
                            {cmd.icon}
                          </span>
                          <span className="flex-1 truncate">{cmd.label}</span>
                        </button>
                      </li>
                    );
                  })}
                </ul>
              </div>
            );
          })}
        </div>

        {/* Footer — keyboard hint so first-time users discover ↑↓⏎. */}
        <div className="flex items-center justify-between border-t bg-muted/40 px-3 py-2 text-[10px] text-muted-foreground">
          <span>{flat.length} command{flat.length === 1 ? '' : 's'}</span>
          <span className="flex items-center gap-2">
            <Kbd>↑</Kbd>
            <Kbd>↓</Kbd>
            navigate
            <Kbd>⏎</Kbd>
            open
            <Kbd>Esc</Kbd>
            close
          </span>
        </div>
      </DialogContent>
    </Dialog>
  );
}

function Kbd({ children }: { children: ReactNode }): JSX.Element {
  return (
    <kbd className="inline-flex min-w-[1.25rem] items-center justify-center rounded border bg-background px-1 font-mono text-[10px]">
      {children}
    </kbd>
  );
}