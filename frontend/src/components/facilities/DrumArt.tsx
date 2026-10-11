/**
 * Theme-aware drum graphic: maroon on light, white on dark. The white
 * asset's filename is misspelled upstream (`drum-whte`), so it is
 * referenced as shipped rather than "fixed" and broken.
 *
 * Used at a small size in the Processing Drums section header, and as
 * the placeholder/fallback behind `GearGauge` on each drum card.
 */
import { cn } from '@/lib/utils';

export function DrumArt({ className }: { className?: string }) {
  return (
    <>
      <img
        src="/drum-maroon.png"
        alt=""
        aria-hidden
        draggable={false}
        className={cn(className, 'object-contain dark:hidden')}
      />
      <img
        src="/drum-whte.png"
        alt=""
        aria-hidden
        draggable={false}
        className={cn(className, 'hidden object-contain dark:block')}
      />
    </>
  );
}
