/**
 * Pure helpers behind {@link StaffAvatarStack}.
 *
 * Split out of the component so they can be unit tested in the node
 * environment — the component itself pulls React and Radix, which would need
 * jsdom just to import.
 */

export interface StaffPerson {
  id: number;
  /** Null when the id is not in the counsellor list (archived / other tenant). */
  name: string | null;
}

/** Tailwind tone pairs — light and dark, so both themes stay readable. */
export const AVATAR_TONES = [
  'bg-sky-100 text-sky-900 dark:bg-sky-900 dark:text-sky-100',
  'bg-emerald-100 text-emerald-900 dark:bg-emerald-900 dark:text-emerald-100',
  'bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100',
  'bg-rose-100 text-rose-900 dark:bg-rose-900 dark:text-rose-100',
  'bg-violet-100 text-violet-900 dark:bg-violet-900 dark:text-violet-100',
  'bg-teal-100 text-teal-900 dark:bg-teal-900 dark:text-teal-100',
  'bg-indigo-100 text-indigo-900 dark:bg-indigo-900 dark:text-indigo-100',
  'bg-orange-100 text-orange-900 dark:bg-orange-900 dark:text-orange-100',
] as const;

export const AVATAR_SIZES = {
  /** Calendar blocks. */
  xs: 'size-4 text-[0.5rem]',
  /** Table rows. */
  sm: 'size-5 text-[0.5625rem]',
} as const;

/**
 * First + last word initial ("Nina Reyes" -> "NR"). A single word takes its
 * first two letters; anything unnameable falls back to "?" rather than
 * rendering an empty circle.
 */
export function initialsOf(name: string | null): string {
  if (name === null) return '?';
  const parts = name.trim().split(/\s+/).filter((p) => p !== '');
  const first = parts[0];
  if (first === undefined) return '?';
  if (parts.length === 1) return first.slice(0, 2).toUpperCase();
  const last = parts[parts.length - 1] ?? first;
  return (first.charAt(0) + last.charAt(0)).toUpperCase();
}

/**
 * Stable colour for a person.
 *
 * Seeded from the name (falling back to the id) rather than from the position
 * in the group, so someone keeps their colour when members are reordered or
 * another person joins — otherwise the colour would be noise instead of an
 * identifier.
 */
export function avatarTone(id: number, name: string | null): string {
  const seed = name ?? String(id);
  let hash = 0;
  for (let i = 0; i < seed.length; i++) {
    hash = (Math.imul(hash, 31) + seed.charCodeAt(i)) >>> 0;
  }
  return AVATAR_TONES[hash % AVATAR_TONES.length] ?? 'bg-muted text-muted-foreground';
}
