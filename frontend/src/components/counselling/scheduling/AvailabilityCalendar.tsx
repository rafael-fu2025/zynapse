import { Trash2 } from 'lucide-react';
import { DAY_NAMES, type Availability } from '@/schemas/schedule';
import { timeToMinutes } from '../format';

function layoutDayLanes(windows: Availability[]): Array<{ w: Availability; lane: number; lanes: number }> {
  const sorted = [...windows].sort(
    (a, b) => timeToMinutes(a.start_time) - timeToMinutes(b.start_time),
  );
  const laneEnds: number[] = [];
  const placed = sorted.map((w) => {
    const start = timeToMinutes(w.start_time);
    let lane = laneEnds.findIndex((end) => end <= start);
    if (lane === -1) {
      lane = laneEnds.length;
      laneEnds.push(0);
    }
    laneEnds[lane] = timeToMinutes(w.end_time);
    return { w, lane };
  });
  const lanes = Math.max(1, laneEnds.length);
  return placed.map((p) => ({ ...p, lanes }));
}

const HOUR_PX = 48;

interface AvailabilityCalendarProps {
  windows: Availability[];
  onRemove: (w: Availability) => void;
  removing: boolean;
}

export function AvailabilityCalendar({
  windows,
  onRemove,
  removing,
}: AvailabilityCalendarProps) {
  let startHour = 8;
  let endHour = 18;
  if (windows.length > 0) {
    startHour = Math.floor(Math.min(...windows.map((w) => timeToMinutes(w.start_time))) / 60);
    endHour = Math.ceil(Math.max(...windows.map((w) => timeToMinutes(w.end_time))) / 60);
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
                className="absolute right-1.5 font-mono text-[10px] text-muted-foreground"
                style={{ top: i * HOUR_PX + 2 }}
              >
                {String(h).padStart(2, '0')}:00
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
              {layoutDayLanes(windows.filter((w) => w.day_of_week === day)).map(({ w, lane, lanes }) => {
                const top = ((timeToMinutes(w.start_time) - startHour * 60) / 60) * HOUR_PX;
                const height = Math.max(
                  28,
                  ((timeToMinutes(w.end_time) - timeToMinutes(w.start_time)) / 60) * HOUR_PX,
                );
                return (
                  <div
                    key={w.id}
                    className="absolute overflow-hidden rounded-md border border-primary/30 bg-primary/10 p-1"
                    style={{
                      top,
                      height,
                      left: `calc(${(lane / lanes) * 100}% + 2px)`,
                      width: `calc(${(1 / lanes) * 100}% - 4px)`,
                    }}
                  >
                    <div className="flex items-start justify-between gap-1">
                      <p className="font-mono text-[10px] leading-tight text-foreground">
                        {w.start_time.slice(0, 5)}–{w.end_time.slice(0, 5)}
                      </p>
                      <button
                        type="button"
                        aria-label={`Remove window #${w.id}`}
                        disabled={removing}
                        onClick={() => onRemove(w)}
                        className="shrink-0 rounded-sm p-0.5 text-muted-foreground hover:text-destructive disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                      >
                        <Trash2 className="size-3" aria-hidden />
                      </button>
                    </div>
                    <p className="text-[10px] text-muted-foreground">cap {w.max_slots}</p>
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
