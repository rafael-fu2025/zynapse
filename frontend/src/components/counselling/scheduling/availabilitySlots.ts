/**
 * Grouping availability windows into the slots an admin actually reads.
 *
 * `counselling_availability` stores **one row per counsellor per window**, so
 * three counsellors who all work Monday 9–10 are three rows. Rendered
 * literally that is three identical-looking entries and no way to answer
 * "who covers this slot?" — which is the question an admin is actually asking.
 *
 * So windows sharing (weekday, start, end) collapse into one slot carrying
 * every staff member assigned to it. That member count *is* the capacity the
 * patient portal books against — one appointment per counsellor covering a
 * time — so there is no separate number to carry here (2026-09-24).
 *
 * Kept pure — no query hooks — so the grouping can be unit tested.
 */
import type { Availability } from '@/schemas/schedule';
import type { StaffPerson } from '@/components/staffAvatar';

export interface AvailabilitySlot {
  /** Stable identity for React keys: `day|start|end`. */
  key: string;
  day_of_week: number;
  start_time: string;
  end_time: string;
  /**
   * Every window collapsed into this slot. Removal and edit both fan out one
   * request per id, so this is what keeps those operations honest.
   */
  windowIds: number[];
  members: StaffPerson[];
}

/** `nameOf` resolves a staff id to a display name, or null when unknown. */
export function groupAvailability(
  windows: Availability[],
  nameOf: (id: number) => string | null,
): AvailabilitySlot[] {
  const byKey = new Map<string, AvailabilitySlot>();

  for (const w of windows) {
    const key = `${w.day_of_week}|${w.start_time}|${w.end_time}`;
    const existing = byKey.get(key);
    if (existing !== undefined) {
      existing.windowIds.push(w.id);
      // A person can still hold two windows over the same hours from before
      // duplicates were rejected; list them once.
      if (!existing.members.some((m) => m.id === w.counsellor_user_id)) {
        existing.members.push({ id: w.counsellor_user_id, name: nameOf(w.counsellor_user_id) });
      }
      continue;
    }
    byKey.set(key, {
      key,
      day_of_week: w.day_of_week,
      start_time: w.start_time,
      end_time: w.end_time,
      windowIds: [w.id],
      members: [{ id: w.counsellor_user_id, name: nameOf(w.counsellor_user_id) }],
    });
  }

  // Stable, human order: weekday, then time of day, then first member's name.
  return [...byKey.values()].sort((a, b) => {
    if (a.day_of_week !== b.day_of_week) return a.day_of_week - b.day_of_week;
    if (a.start_time !== b.start_time) return a.start_time < b.start_time ? -1 : 1;
    if (a.end_time !== b.end_time) return a.end_time < b.end_time ? -1 : 1;
    return (a.members[0]?.name ?? '').localeCompare(b.members[0]?.name ?? '');
  });
}

/** Build the id -> name lookup from the counsellors endpoint. */
export function staffNameLookup(
  counsellors: Array<{ id: number; name: string }> | undefined,
): (id: number) => string | null {
  const map = new Map<number, string>();
  for (const c of counsellors ?? []) map.set(c.id, c.name);
  return (id) => map.get(id) ?? null;
}
