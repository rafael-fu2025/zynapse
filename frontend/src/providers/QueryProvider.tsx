/**
 * QueryClient provider — single source of truth for the React Query cache.
 *
 * Defaults chosen for SYNAPSE:
 *   - staleTime 30s / gcTime 5min (avoid hammering the API on tab focus).
 *   - retry up to 2x for transient network errors; never retry 4xx.
 *   - refetchOnWindowFocus only for non-mutation queries.
 */
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

const client = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      gcTime: 5 * 60_000,
      retry: (failureCount, error) => {
        // ApiEnvelopeError has httpStatus; never auto-retry 4xx.
        if (error instanceof Error && 'httpStatus' in error) {
          const status = (error as { httpStatus: number }).httpStatus;
          if (status >= 400 && status < 500) return false;
        }
        return failureCount < 2;
      },
      refetchOnWindowFocus: true,
    },
    mutations: {
      retry: false,
    },
  },
});

export function QueryProvider({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}