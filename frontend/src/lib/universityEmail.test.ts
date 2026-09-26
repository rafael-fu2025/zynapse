import { describe, expect, it } from 'vitest';

import { deriveUniversityEmail } from './universityEmail';

describe('deriveUniversityEmail', () => {
  it('concatenates multi-word given names and the surname', () => {
    expect(deriveUniversityEmail('John Lloyd', 'Macias')).toBe('johnlloyd.macias@foundationu.com');
  });

  it('folds case and concatenates multi-word surnames', () => {
    expect(deriveUniversityEmail('JUAN', 'DELA CRUZ')).toBe('juan.delacruz@foundationu.com');
  });

  it('strips generational suffixes from the surname', () => {
    expect(deriveUniversityEmail('Jose', 'Dela Cruz Jr.')).toBe('jose.delacruz@foundationu.com');
    expect(deriveUniversityEmail('Ana', 'Santos III')).toBe('ana.santos@foundationu.com');
  });

  it('folds PH diacritics to ASCII letters', () => {
    expect(deriveUniversityEmail('María', 'Ñuñez')).toBe('maria.nunez@foundationu.com');
  });

  it('drops non-alphabetic characters inside names', () => {
    expect(deriveUniversityEmail("Ma. Joy-Anne", "O'Neil")).toBe('majoyanne.oneil@foundationu.com');
  });

  it('returns null when either side yields no letters', () => {
    expect(deriveUniversityEmail('', 'Macias')).toBeNull();
    expect(deriveUniversityEmail('John', '')).toBeNull();
    expect(deriveUniversityEmail('123', 'Macias')).toBeNull();
    expect(deriveUniversityEmail(undefined, undefined)).toBeNull();
  });

  it('keeps a suffix-only surname from collapsing to nothing', () => {
    // "Jr" alone strips to empty — no honest address to derive.
    expect(deriveUniversityEmail('Jose', 'Jr')).toBeNull();
  });
});
