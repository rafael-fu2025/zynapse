/**
 * useTabParam — sync a Tabs value with a URL query param so the active
 * tab is deep-linkable and survives refresh / back / share.
 *
 * The default tab is kept OUT of the URL (the param is deleted when the
 * value equals the default) to keep canonical URLs clean, and updates
 * use `replace` so tab switching does not spam the history stack.
 *
 *   const [tab, setTab] = useTabParam('medicines');
 *   <Tabs value={tab} onValueChange={setTab}>…</Tabs>
 */
import { useSearchParams } from 'react-router-dom';

export function useTabParam(
  defaultValue: string,
  key = 'tab',
): [string, (value: string) => void] {
  const [params, setParams] = useSearchParams();
  const value = params.get(key) ?? defaultValue;

  const setValue = (next: string): void => {
    const nextParams = new URLSearchParams(params);
    if (next === defaultValue) {
      nextParams.delete(key);
    } else {
      nextParams.set(key, next);
    }
    setParams(nextParams, { replace: true });
  };

  return [value, setValue];
}
