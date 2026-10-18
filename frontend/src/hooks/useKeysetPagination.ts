/**
 * useKeysetPagination — cursor + history state for the keyset-paginated
 * inventory lists (Medicines, Reorders, Supplies tabs). The backend
 * returns an opaque `next` cursor per page; walking forward pushes it
 * onto a history stack so Prev can pop back to the previous cursor.
 *
 * Whenever `query` (a stable primitive search/filter key) changes,
 * pagination snaps back to page 1 before any old cursor can be sent.
 * Combine active URL filters in this key, e.g. JSON.stringify([q, archived]).
 * `reset()` also supports explicit action-driven resets.
 */
import { useCallback, useEffect, useRef, useState } from 'react';

export function useKeysetPagination(query: string): {
  cursor: string | null;
  history: Array<string | null>;
  nextPage: (next: string | null | undefined) => void;
  prevPage: () => void;
  reset: () => void;
} {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);

  // Reset to the first page whenever the debounced search changes.
  const lastQueryRef = useRef(query);
  // Do not send a cursor from the old slice during the render before the
  // reset effect runs (including URL Back/Forward navigation).
  const filtersChanged = query !== lastQueryRef.current;
  useEffect(() => {
    if (query !== lastQueryRef.current) {
      lastQueryRef.current = query;
      setCursor(null);
      setHistory([null]);
    }
  }, [query]);

  function nextPage(next: string | null | undefined) {
    if (next !== null && next !== undefined) {
      setHistory((h) => [...h, next]);
      setCursor(next);
    }
  }
  function prevPage() {
    if (history.length < 2) return;
    const next = history.slice(0, -1);
    setHistory(next);
    setCursor(next[next.length - 1] ?? null);
  }

  // Stable identity (useState setters are stable) so effects that fire
  // on filter changes can depend on it without re-running every render.
  const reset = useCallback(() => {
    setCursor(null);
    setHistory([null]);
  }, []);

  return {
    cursor: filtersChanged ? null : cursor,
    history: filtersChanged ? [null] : history,
    nextPage, prevPage, reset,
  };
}
