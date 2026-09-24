/**
 * StaffAvatarStack — who is assigned to one slot, in as little space as
 * possible.
 *
 * Availability is stored per staff member, but an admin reading a schedule
 * thinks in terms of *slots*: "who covers Monday 9–10?". So several people
 * sharing one window render as a single overlapping group, the way
 * collaboration apps show participants.
 *
 * Two details that decide the design:
 *
 * - **Initials only.** A full name does not fit in a 16–20px circle, and a
 *   schedule cell has room for three or four of them at most. The full names
 *   are one hover/focus away instead of being squeezed in.
 * - **The colour is a hash, not an index.** Deriving it from the staff id (not
 *   the array position) means a person keeps their colour when the group is
 *   reordered or someone else joins, so the colour is actually usable as a
 *   identifier rather than decoration.
 */
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { AVATAR_SIZES, avatarTone, initialsOf } from './staffAvatar';

// Re-exported so callers import everything from one place; the logic itself
// lives in a .ts module so it is unit-testable without jsdom.
export {
  AVATAR_SIZES,
  AVATAR_TONES,
  avatarTone,
  initialsOf,
  type StaffPerson,
} from './staffAvatar';
import type { StaffPerson } from './staffAvatar';

interface StaffAvatarStackProps {
  people: StaffPerson[];
  /** Avatars shown before collapsing the rest into a +N chip. */
  max?: number;
  size?: keyof typeof AVATAR_SIZES;
  className?: string;
}

export function StaffAvatarStack({
  people,
  max = 3,
  size = 'sm',
  className,
}: StaffAvatarStackProps) {
  // No staff resolved for this slot — a subtle marker, never an empty gap that
  // reads as a rendering bug.
  if (people.length === 0) {
    return (
      <span
        className={cn(
          'inline-flex items-center rounded-full border border-dashed border-muted-foreground/40 text-[9px] text-muted-foreground',
          AVATAR_SIZES[size],
          'justify-center',
          className,
        )}
        aria-label="Unassigned"
        title="Unassigned"
      >
        –
      </span>
    );
  }

  const shown = people.slice(0, Math.max(1, max));
  const overflow = people.length - shown.length;
  // Unresolved ids still get a name in the tooltip so the admin can act on it.
  const fullNames = people.map((p) => p.name ?? `Staff #${p.id}`).join(', ');

  return (
    <TooltipProvider delayDuration={150}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span
            className={cn('inline-flex items-center', className)}
            // Focusable so the names are reachable by keyboard, not hover only.
            tabIndex={0}
            aria-label={`Assigned: ${fullNames}`}
            data-testid="staff-avatar-stack"
          >
            {shown.map((p) => (
              <span
                key={p.id}
                // -ml on all but the first produces the overlap; the ring is the
                // thin border that keeps them separable where they overlap.
                className={cn(
                  'grid shrink-0 place-items-center rounded-full font-medium ring-2 ring-background first:ml-0 -ml-1.5',
                  AVATAR_SIZES[size],
                  avatarTone(p.id, p.name),
                )}
                aria-hidden
              >
                {initialsOf(p.name)}
              </span>
            ))}
            {overflow > 0 && (
              <span
                className={cn(
                  'grid shrink-0 place-items-center rounded-full bg-muted font-medium text-muted-foreground ring-2 ring-background -ml-1.5',
                  AVATAR_SIZES[size],
                )}
                aria-hidden
              >
                +{overflow}
              </span>
            )}
          </span>
        </TooltipTrigger>
        <TooltipContent className="max-w-[16rem] break-words">
          {people.map((p) => (
            <span key={p.id} className="block">
              {p.name ?? `Staff #${p.id}`}
            </span>
          ))}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}
