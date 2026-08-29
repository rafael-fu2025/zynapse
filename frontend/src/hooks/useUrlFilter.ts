/**
 * useUrlFilter — a string filter mirrored into the URL query string,
 * so filters and entity context survive a refresh and can be shared
 * (PRODUCT principle 5, "URL-addressable investigations").
 *
 * Pushes use `replace` navigation and the param is DELETED while the
 * value sits at its default, keeping URLs canonical (same rules as
 * useTabParam). Text inputs should pass `debounceMs` so keystrokes
 * don't rewrite the URL per character: the draft updates instantly,
 * the URL — and therefore the query — follows the debounce.
 *
 * Returns [value, setValue, draft]:
 *  - `value`  — the settled URL value; feed this to queries/hooks.
 *  - `setValue` — write handler; pass it straight to onChange/onValueChange.
 *  - `draft`  — what the input should display (leads `value` while
 *               debouncing); for selects draft === value.
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

export interface UrlFilterOptions {
  /** Value that removes the param from the URL entirely. */
  default?: string;
  /** Debounce the URL push (text inputs). Omit for selects/toggles. */
  debounceMs?: number;
}

export function useUrlFilter(
  key: string,
  options: UrlFilterOptions = {},
): [string, (next: string) => void, string] {
  const defaultValue = options.default ?? '';
  const debounceMs = options.debounceMs ?? 0;
  const [params, setParams] = useSearchParams();
  const urlValue = params.get(key) ?? defaultValue;
  const [draft, setDraft] = useState(urlValue);
  const timer = useRef<ReturnType<typeof setTimeout>>();

  // External URL changes (back/forward navigation, shared links,
  // cleared filters) win over whatever draft is pending.
  useEffect(() => setDraft(urlValue), [urlValue]);
  useEffect(() => () => clearTimeout(timer.current), []);

  const push = useCallback(
    (next: string) => {
      setParams((prev) => {
        const nextParams = new URLSearchParams(prev);
        if (next === defaultValue) nextParams.delete(key);
        else nextParams.set(key, next);
        if (nextParams.toString() === prev.toString()) return prev;
        return nextParams;
      }, { replace: true });
    },
    [defaultValue, key, setParams],
  );

  const setValue = useCallback(
    (next: string) => {
      setDraft(next);
      clearTimeout(timer.current);
      if (debounceMs > 0) {
        timer.current = setTimeout(() => push(next), debounceMs);
      } else {
        push(next);
      }
    },
    [debounceMs, push],
  );

  return [urlValue, setValue, draft];
}
