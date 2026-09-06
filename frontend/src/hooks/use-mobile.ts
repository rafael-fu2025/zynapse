/**
 * useIsMobile — shadcn/ui viewport hook used by the Sidebar to switch
 * between the fixed desktop rail and the mobile Sheet.
 *
 * The breakpoint is 1024 (not the shadcn default 768): the docked
 * sidebar occupies a fixed 256px, and between 768–1023 that left only
 * ~460–760px of content — dense tables overflowed the viewport on
 * every module page (caught by the overflow contract in the live e2e
 * suite). Below 1024 the sidebar renders as an overlay Sheet, so the
 * content always gets the full viewport width.
 */
import * as React from 'react';

const MOBILE_BREAKPOINT = 1024;

export function useIsMobile() {
  const [isMobile, setIsMobile] = React.useState<boolean | undefined>(undefined);

  React.useEffect(() => {
    const mql = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`);
    const onChange = () => {
      setIsMobile(window.innerWidth < MOBILE_BREAKPOINT);
    };
    mql.addEventListener('change', onChange);
    setIsMobile(window.innerWidth < MOBILE_BREAKPOINT);
    return () => mql.removeEventListener('change', onChange);
  }, []);

  return !!isMobile;
}
