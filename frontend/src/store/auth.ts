/**
 * Auth store — IN-MEMORY ONLY.
 *
 * The access token is held here, never in localStorage (per directive).
 * Refresh tokens are HttpOnly cookies set by the backend. UI prefs
 * (sidebar collapsed, timezone) live here too because they survive route
 * changes but are not server state.
 */
import { create } from 'zustand';

export type AppTimezone = 'Asia/Manila' | 'UTC';

interface AuthState {
  accessToken: string | null;
  userId: number | null;
  email: string | null;
  permissions: ReadonlyArray<string>;

  /** UI prefs (NOT server state). */
  sidebarCollapsed: boolean;
  timezone: AppTimezone;

  /** Mutations. */
  setAccessToken: (token: string) => void;
  setSession: (input: { userId: number; email: string; permissions: ReadonlyArray<string> }) => void;
  setPermissions: (perms: ReadonlyArray<string>) => void;
  toggleSidebar: () => void;
  setTimezone: (tz: AppTimezone) => void;
  clear: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
  accessToken: null,
  userId: null,
  email: null,
  permissions: [],

  sidebarCollapsed: false,
  timezone: 'Asia/Manila',

  setAccessToken: (token) => set({ accessToken: token }),
  setSession: ({ userId, email, permissions }) =>
    set({ userId, email, permissions }),
  setPermissions: (perms) => set({ permissions: perms }),
  toggleSidebar: () => set((s) => ({ sidebarCollapsed: !s.sidebarCollapsed })),
  setTimezone: (tz) => set({ timezone: tz }),

  clear: () =>
    set({
      accessToken: null,
      userId: null,
      email: null,
      permissions: [],
    }),
}));

export function hasPermission(state: AuthState, code: string): boolean {
  return state.permissions.includes(code) || state.permissions.includes('*');
}