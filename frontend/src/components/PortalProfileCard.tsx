/**
 * PortalProfileCard — the ATM-style identity card at the top of the
 * student and employee portals ("Student Profile" / "Employee Profile").
 * One component so the two portals cannot drift apart (the class string
 * used to be copy-pasted into each page).
 *
 * The card is content-driven with a `min-h` floor instead of the old
 * fixed `aspect-[1.586]` + `overflow-hidden`: an aspect-ratio card keeps
 * its height while its rem-based text grows with the root font size, so
 * at 125% the fields silently clipped. With a rem min-height the whole
 * card — text and box — scales together, and long values can wrap or
 * push the card taller instead of being cut off. `truncate` stays only
 * on single-line identifiers (name, email) where one-line overflow is
 * acceptable.
 */
import type { ReactNode } from 'react';
import { IdCard } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { PortalCardArt } from '@/components/PortalCardArt';
import { cn } from '@/lib/utils';

export function PortalProfileCard({
  caption,
  name,
  idValue,
  children,
  className,
}: {
  /** Small uppercase label above the name, e.g. "Student Profile". */
  caption: string;
  /** Full display name, rendered on one line. */
  name: string;
  /** Registry number shown under the name (null renders as "ID: "). */
  idValue: string | null;
  /**
   * The card's field rows — a `<dl className="mt-auto …">`, so the
   * fields anchor to the card's bottom edge when the min-height floor
   * leaves spare room.
   */
  children: ReactNode;
  className?: string;
}) {
  return (
    <Card
      className={cn(
        'relative flex min-h-[15.5rem] w-full flex-col self-start border-primary bg-primary text-white shadow-[0_1px_2px_rgba(0,0,0,0.06),0_18px_40px_-12px_rgba(0,0,0,0.28)] dark:border-border dark:bg-card dark:text-card-foreground',
        className,
      )}
    >
      <PortalCardArt />
      <div className="relative flex flex-1 flex-col p-5">
        <p className="flex items-center gap-1.5 text-[0.6875rem] font-medium uppercase tracking-wider text-white/70 dark:text-muted-foreground">
          <IdCard className="size-3.5" aria-hidden /> {caption}
        </p>
        <div className="mt-3 min-w-0">
          <p className="truncate text-base font-semibold leading-tight text-white dark:text-foreground">
            {name}
          </p>
          <p className="mt-0.5 tabular-nums text-xs text-white/70 dark:text-muted-foreground">
            ID: {idValue}
          </p>
        </div>
        {children}
      </div>
    </Card>
  );
}
