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

const RETRY_DELAY_MS = 250;
const MAX_RETRIES = 2;

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
        const run = (): Promise<{ default: T }> =>
          importer().catch((err: unknown) => {
            if (attempt < MAX_RETRIES && isTransientDevFetchError(err)) {
              attempt += 1;
              // Give Vite a moment to finish re-bundling deps, then retry.
              return new Promise<{ default: T }>((resolve, reject) => {
                setTimeout(() => {
                  run().then(resolve, reject);
                }, RETRY_DELAY_MS * attempt);
              });
            }
            throw err;
          });
        return run();
      }
    : importer;

  // Production: just defer to React.lazy. A genuine chunk failure
  // (mismatched hash, CDN miss) is unrecoverable and should surface
  // to the route's error boundary so the user sees the message.
  return lazy(wrappedImporter);
}
