/**
 * App shell — shadcn Sidebar + topbar management layout.
 *
 * SidebarProvider drives the shadcn Sidebar: cookie-persisted collapse
 * (Ctrl/Cmd+B), icon rail on desktop, Sheet on mobile. Topbar hosts
 * the sidebar trigger, theme toggle, notification bell, identity and
 * sign-out. Enforces the `force_reset` gate: an admin-issued temporary
 * password locks every route to /change-password until rotated.
 */
import { Suspense } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { Search } from 'lucide-react';
import { AppSidebar } from '@/components/AppSidebar';
import { CommandPalette } from '@/components/CommandPalette';
import { NotificationBell } from '@/components/NotificationBell';
import { UserMenu } from '@/components/UserMenu';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { Toaster } from '@/components/ui/sonner';
import { useMe } from '@/hooks/useAuth';
import { useScrollDirection } from '@/hooks/useScrollDirection';
import { useTheme } from '@/hooks/useTheme';
import { resolvePageMeta } from '@/lib/pageMeta';

export default function Layout() {
  const me = useMe();
  const location = useLocation();
  const { theme } = useTheme();
  const meta = resolvePageMeta(location.pathname);
  // Auto-hide the topbar on mobile when the user scrolls down, and
  // re-show it on scroll-up. Disabled on desktop via the md: classes
  // so the desktop chrome stays static.
  const scrollDir = useScrollDirection();
  const hideOnMobile = scrollDir === 'down';

  if (me.data?.force_reset === true && location.pathname !== '/change-password') {
    return <Navigate to="/change-password" replace />;
  }

  // Identity-consolidated: every patient IS a `users` row, so the legacy
  // "patient_identifier missing" banner (re-link your account) no longer
  // applies.
  const showInactivePatientBanner = false;

  return (
    <SidebarProvider>
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-50 focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-sm focus:shadow"
      >
        Skip to content
      </a>

      <AppSidebar />

      <SidebarInset>
        {showInactivePatientBanner && (
          <div
            role="status"
            className="flex items-center gap-2 border-b bg-amber-50 px-4 py-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
          >
            <AlertTriangle aria-hidden className="size-3.5 shrink-0" />
            <span>
              Your patient record is inactive. Contact the registrar to re-link your account.
            </span>
          </div>
        )}
        <header
          className={
            'sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 overflow-hidden border-b bg-background px-4 shadow-sm transition-transform duration-200 ease-out lg:px-6 ' +
            // Translate off-screen on mobile when the user is scrolling
            // down. `-translate-y-full` clears the bar entirely; the
            // `md:translate-y-0` keeps the desktop topbar always shown.
            (hideOnMobile
              ? '-translate-y-full md:translate-y-0'
              : 'translate-y-0')
          }
        >
          <SidebarTrigger className="-ml-1" />
          {/* Palette launcher — sits right after the collapse button so
              it stays anchored to the left edge of the topbar instead
              of floating in the middle. Hidden on mobile so the page
              title gets the full row. */}
          <button
            type="button"
            onClick={() => window.dispatchEvent(new Event('synapse:command-palette:open'))}
            aria-label="Open command palette"
            className="hidden h-9 w-72 shrink-0 items-center gap-2 rounded-md border bg-background/60 px-3 py-0 text-xs text-muted-foreground transition-colors hover:bg-accent/40 hover:text-foreground sm:inline-flex sm:w-80"
          >
            <span className="flex items-center gap-2">
              <Search className="size-3.5" />
              <span>Search…</span>
            </span>
            <kbd className="ml-auto rounded border bg-muted px-1 font-mono text-[10px]">⌘K</kbd>
          </button>
          {/*
            Page title — mirrors the page H1 as a location cue. On web
            (desktop) the in-page H1 already provides this context, so
            the topbar would be redundant; on mobile the topbar is the
            only place a title appears because the in-page H1 is hidden
            by the global mobile rule.
          */}
          {meta.title !== '' && (
            <div className="min-w-0 flex-1 truncate md:hidden">
              <p className="truncate text-sm font-semibold leading-none text-foreground">
                {meta.title}
              </p>
            </div>
          )}
          <div className="ml-auto flex items-center gap-2 sm:gap-3">
            <NotificationBell />
            <UserMenu />
          </div>
        </header>

        <main id="main">
          {/* Nested boundary: if a lazy page ever suspends outside a
              transition, only the content area falls back — the shell
              (sidebar, topbar) stays mounted. */}
          <Suspense
            fallback={
              <p className="grid min-h-40 place-items-center text-sm text-muted-foreground" role="status">
                Loading…
              </p>
            }
          >
            <Outlet />
          </Suspense>
        </main>
      </SidebarInset>

      <Toaster theme={theme} position="top-right" richColors closeButton />
      {/* Global ⌘K / Ctrl-K launcher. Lives outside the page tree so it
          overlays every route; the keyboard listener is mounted inside
          the component (one place to maintain). */}
      <CommandPalette />
    </SidebarProvider>
  );
}
