/**
 * useDebouncedValue — trailing-edge debounce for fast-changing inputs.
 *
 * Used by live-search fields so a request fires only after the user
 * pauses typing, instead of one query per keystroke (each keystroke
 * previously created a fresh TanStack query key + network call).
 */
import { useEffect, useState } from 'react';

export function useDebouncedValue<T>(value: T, delayMs = 300): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delayMs);
    return () => window.clearTimeout(timer);
  }, [value, delayMs]);

  return debounced;
}
