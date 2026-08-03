/**
 * lazyWithRetry — `React.lazy` wrapper that survives the Vite dev
 * "Failed to fetch dynamically imported module" race.
 *
 * The Vite dev server discovers new npm deps on first transform of a
 * lazy chunk, re-runs the dependency optimizer, and any in-flight
 * dynamic-import request for the same chunk is dropped. Browser-side
 * the dynamic import then rejects with a "Failed to fetch" / "Loading
 * chunk N failed" / "Importing a module script failed" error and the
 * route's error boundary renders instead of the page.
 *
 * We can't fix Vite from the SPA side, but we CAN catch the rejection,
 * wait a tick for the optimizer to settle, and retry the import once.
 * The second try hits Vite's pre-bundled cache and resolves cleanly —
 * no F5 / page reload required (and no loss of in-memory access token).
 *
 * In production this is a no-op: a real build emits hashed assets, so
 * a failed chunk really is gone and the retry would also fail. We
 * detect "vite dev" via `import.meta.env.DEV` and only retry there.
 */
import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

type Importer<T> = () => Promise<{ default: T }>;

// Vite's re-optimize on a cold first load can take 2–4s with our dep
// list. 250ms × 2 attempts (750ms total) was too short — the third
// attempt still landed before the optimizer finished and the error
// boundary won. Give the budget a real backoff so the first retry
// catches cheap transient blips and the later ones give Vite time.
const BASE_RETRY_DELAY_MS = 400;
const MAX_RETRY_DELAY_MS = 2000;
const MAX_RETRIES = 6;

function isTransientDevFetchError(err: unknown): boolean {
  if (err === null || typeof err !== 'object') return false;
  const message = (err as { message?: unknown }).message;
  if (typeof message !== 'string') return false;
  // Vite's dev server drops in-flight imports during dep re-optimization.
  // Browsers surface this as one of:
  //   - "Failed to fetch dynamically imported module"
  //   - "Importing a module script failed"
  //   - "error loading dynamically imported module"
  //   - ChunkLoadError (older bundlers)
  return (
    message.includes('Failed to fetch dynamically imported module') ||
    message.includes('Importing a module script failed') ||
    message.includes('error loading dynamically imported module') ||
    (err as { name?: string }).name === 'ChunkLoadError'
  );
}

export function lazyWithRetry<T extends ComponentType<unknown>>(
  importer: Importer<T>,
): LazyExoticComponent<T> {
  const wrappedImporter: Importer<T> = import.meta.env.DEV
    ? () => {
        let attempt = 0;
        let settled = false;
        const run = (): Promise<{ default: T }> =>
          importer().catch((err: unknown) => {
            if (settled) throw err instanceof Error ? err : new Error(String(err));
            if (attempt < MAX_RETRIES && isTransientDevFetchError(err)) {
              attempt += 1;
              // Exponential backoff capped at MAX_RETRY_DELAY_MS. First
              // retry is fast (400ms) so cheap blips disappear; later
              // retries stretch up to 2s to outlast Vite re-optimize.
              const delay = Math.min(
                MAX_RETRY_DELAY_MS,
                BASE_RETRY_DELAY_MS * 2 ** (attempt - 1),
              );
              return new Promise<{ default: T }>((resolve, reject) => {
                setTimeout(() => {
                  run().then(
                    (mod) => {
                      settled = true;
                      resolve(mod);
                    },
                    (retryErr: unknown) => {
                      if (attempt >= MAX_RETRIES || !isTransientDevFetchError(retryErr)) {
                        settled = true;
                      }
                      reject(retryErr instanceof Error ? retryErr : new Error(String(retryErr)));
                    },
                  );
                }, delay);
              });
            }
            throw err instanceof Error ? err : new Error(String(err));
          });
        return run();
      }
    : importer;

  // Production: just defer to React.lazy. A genuine chunk failure
  // (mismatched hash, CDN miss) is unrecoverable and should surface
  // to the route's error boundary so the user sees the message.
  return lazy(wrappedImporter);
}
