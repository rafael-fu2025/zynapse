import { Pencil, Trash2 } from 'lucide-react';
import { DAY_NAMES } from '@/schemas/schedule';
import { fmtClock, fmtTimeRange } from '@/utils/date';
import { StaffAvatarStack } from '@/components/StaffAvatarStack';
import type { AvailabilitySlot } from './availabilitySlots';
import { timeToMinutes } from '../format';

function layoutDayLanes(slots: AvailabilitySlot[]): Array<{ s: AvailabilitySlot; lane: number; lanes: number }> {
  const sorted = [...slots].sort(
    (a, b) => timeToMinutes(a.start_time) - timeToMinutes(b.start_time),
  );
  const laneEnds: number[] = [];
  const placed = sorted.map((s) => {
    const start = timeToMinutes(s.start_time);
    let lane = laneEnds.findIndex((end) => end <= start);
    if (lane === -1) {
      lane = laneEnds.length;
      laneEnds.push(0);
    }
    laneEnds[lane] = timeToMinutes(s.end_time);
    return { s, lane };
  });
  const lanes = Math.max(1, laneEnds.length);
  return placed.map((p) => ({ ...p, lanes }));
}

const HOUR_PX = 48;
/** Room for the time row plus the avatar row; a 1-hour slot is 48px anyway. */
const MIN_BLOCK_PX = 48;

interface AvailabilityCalendarProps {
  slots: AvailabilitySlot[];
  onRemove: (s: AvailabilitySlot) => void;
  removing: boolean;
  /**
   * Absent for viewers who cannot manage the schedule, which is what hides the
   * edit affordance — the trash button beside it predates that gate and is left
   * as it was.
   */
  onEdit?: ((s: AvailabilitySlot) => void) | undefined;
}

export function AvailabilityCalendar({
  slots,
  onRemove,
  removing,
  onEdit,
}: AvailabilityCalendarProps) {
  let startHour = 8;
  let endHour = 18;
  if (slots.length > 0) {
    startHour = Math.floor(Math.min(...slots.map((s) => timeToMinutes(s.start_time))) / 60);
    endHour = Math.ceil(Math.max(...slots.map((s) => timeToMinutes(s.end_time))) / 60);
    if (endHour <= startHour) {
      startHour = 8;
      endHour = 18;
    }
  }
  const hours = Array.from({ length: endHour - startHour }, (_, i) => startHour + i);
  const gridHeight = hours.length * HOUR_PX;

  return (
    <div className="overflow-x-auto">
      <div className="min-w-[840px]">
        <div className="grid grid-cols-[3.5rem_repeat(7,minmax(0,1fr))] border-b bg-muted/50">
          <div />
          {DAY_NAMES.map((name) => (
            <p key={name} className="border-l px-2 py-2 text-center text-xs font-medium text-foreground">
              {name.slice(0, 3)}
            </p>
          ))}
        </div>
        <div className="grid grid-cols-[3.5rem_repeat(7,minmax(0,1fr))]">
          <div className="relative" style={{ height: gridHeight }}>
            {hours.map((h, i) => (
              <p
                key={h}
                className="absolute right-1.5 text-[10px] text-muted-foreground"
                style={{ top: i * HOUR_PX + 2 }}
              >
                {fmtClock(`${h}:00`)}
              </p>
            ))}
          </div>
          {DAY_NAMES.map((name, day) => (
            <div key={name} className="relative border-l" style={{ height: gridHeight }}>
              {hours.map((h, i) => i > 0 && (
                <div
                  key={h}
                  aria-hidden
                  className="absolute inset-x-0 border-t border-border/50"
                  style={{ top: i * HOUR_PX }}
                />
              ))}
              {layoutDayLanes(slots.filter((s) => s.day_of_week === day)).map(({ s, lane, lanes }) => {
                const top = ((timeToMinutes(s.start_time) - startHour * 60) / 60) * HOUR_PX;
                const height = Math.max(
                  MIN_BLOCK_PX,
                  ((timeToMinutes(s.end_time) - timeToMinutes(s.start_time)) / 60) * HOUR_PX,
                );
                const names = s.members.map((m) => m.name ?? `Staff #${m.id}`).join(', ');
                return (
                  <div
                    key={s.key}
                    className="absolute flex flex-col overflow-hidden rounded-md border border-primary/30 bg-primary/10 p-1"
                    style={{
                      top,
                      height,
                      left: `calc(${(lane / lanes) * 100}% + 2px)`,
                      width: `calc(${(1 / lanes) * 100}% - 4px)`,
                    }}
                  >
                    <div className="flex items-start justify-between gap-1">
                      <p className="truncate text-[10px] leading-tight text-foreground">
                        {fmtTimeRange(s.start_time, s.end_time)}
                      </p>
                      <button
                        type="button"
                        aria-label={`Remove window ${fmtTimeRange(s.start_time, s.end_time)} on ${DAY_NAMES[s.day_of_week]} — ${names}`}
                        disabled={removing}
                        onClick={() => onRemove(s)}
                        className="shrink-0 rounded-sm p-0.5 text-muted-foreground hover:text-destructive disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                      >
                        <Trash2 className="size-3" aria-hidden />
                      </button>
                    </div>
                    <div className="mt-auto flex items-center justify-between gap-1">
                      <StaffAvatarStack people={s.members} size="xs" max={3} />
                      {onEdit !== undefined && (
                        <button
                          type="button"
                          aria-label={`Edit window ${fmtTimeRange(s.start_time, s.end_time)} on ${DAY_NAMES[s.day_of_week]} — ${names}`}
                          onClick={() => onEdit(s)}
                          className="shrink-0 rounded-sm p-0.5 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        >
                          <Pencil className="size-3" aria-hidden />
                        </button>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
