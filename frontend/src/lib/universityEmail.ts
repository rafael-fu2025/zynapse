/**
 * Derive the Foundation University email from a person's name — the same
 * convention as the backend's `App\Services\UniversityEmail` (which serves
 * MIS-provisioned users): given names concatenated, then a dot, then the
 * surname concatenated, then `@foundationu.com`.
 *
 *   "John Lloyd" + "Macias"  -> johnlloyd.macias@foundationu.com
 *   "JUAN" + "DELA CRUZ"     -> juan.delacruz@foundationu.com
 *
 * Rules fixed by the university's account convention:
 *   - middle names are never part of the address — callers pass
 *     first/last only;
 *   - every non-alphabetic character is dropped (spaces, hyphens,
 *     apostrophes, dots);
 *   - common Spanish/PH diacritics fold to their lowercase ASCII letter
 *     (ñ -> n);
 *   - generational suffixes (JR, SR, II..V) are stripped from the surname;
 *   - either side empty -> null (no honest address to derive).
 */

const DOMAIN = '@foundationu.com';

const SUFFIXES = new Set(['jr', 'sr', 'ii', 'iii', 'iv', 'v']);

const TRANSLITERATIONS: Record<string, string> = {
  'ñ': 'n',
  'á': 'a', 'à': 'a', 'â': 'a', 'ä': 'a',
  'é': 'e', 'è': 'e', 'ê': 'e', 'ë': 'e',
  'í': 'i', 'ì': 'i', 'î': 'i', 'ï': 'i',
  'ó': 'o', 'ò': 'o', 'ô': 'o', 'ö': 'o',
  'ú': 'u', 'ù': 'u', 'û': 'u', 'ü': 'u',
  'ç': 'c',
};

/** Lowercase, fold diacritics, then concatenate the a-z letters. */
function lettersOnly(value: string): string {
  const lowered = value.trim().toLowerCase().replace(/[ñáàâäéèêëíìîïóòôöúùûüç]/g, (c) => TRANSLITERATIONS[c] ?? c);
  return lowered.replace(/[^a-z]/g, '');
}

/** Drop trailing generational suffix tokens ("Macias Jr" -> "Macias"). */
function stripSuffix(lastName: string): string {
  const parts = lastName.trim().split(/\s+/).filter(Boolean);
  while (parts.length > 0) {
    const token = (parts[parts.length - 1] ?? '').toLowerCase().replace(/\.$/, '');
    if (!SUFFIXES.has(token) || token === '') break;
    parts.pop();
  }
  return parts.join(' ');
}

export function deriveUniversityEmail(
  firstName: string | null | undefined,
  lastName: string | null | undefined,
): string | null {
  const first = lettersOnly(firstName ?? '');
  const last = lettersOnly(stripSuffix(lastName ?? ''));
  if (first === '' || last === '') {
    return null;
  }
  return `${first}.${last}${DOMAIN}`;
}
