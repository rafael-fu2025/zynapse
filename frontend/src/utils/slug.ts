/**
 * URL-safe slug helpers — shared by the BMG drum create form.
 *
 * Mirrors the backend slug contract enforced by
 * `BmgService::assertSlug` (`a-z0-9` groups joined by single hyphens,
 * e.g. `drum-01`). These are UX-only helpers; the backend (slug
 * normalization + the `resource.conflict` 409 + unique index on
 * `facilities_bmg_units.code`) remains the authority.
 */

/**
 * Lowercase; runs of spaces/special characters → a single hyphen;
 * no leading/trailing hyphens; no consecutive hyphens.
 *   "Drum 01 - North Canopy" → "drum-01-north-canopy"
 */
export function slugify(raw: string): string {
  return raw
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

/**
 * Return `base` if it's not in `existing`, otherwise append a numeric
 * suffix (`-2`, `-3`, …) until it is unique. Skips any suffix that is
 * itself taken, so `["drum-01", "drum-01-2"]` yields `drum-01-3`.
 * Empty base returns `''`.
 *
 * Matching is CASE-INSENSITIVE to mirror the backend: the
 * `facilities_bmg_units.code` column uses `utf8mb4_unicode_ci`, so
 * `drum-01` and `DRM-01` collide server-side. Legacy rows are stored
 * uppercase (`DRM-01`); generated slugs are lowercase — the dedupe
 * must treat them as the same.
 */
export function uniqueSlug(base: string, existing: ReadonlyArray<string>): string {
  if (base === '') return '';
  const taken = new Set(existing.map((c) => c.toLowerCase()));
  const candidate = base.toLowerCase();
  if (!taken.has(candidate)) return base;
  let n = 2;
  while (taken.has(`${candidate}-${n}`)) {
    n += 1;
  }
  return `${base}-${n}`;
}
