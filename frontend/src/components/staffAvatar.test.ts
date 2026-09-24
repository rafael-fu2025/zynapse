import { describe, expect, it } from 'vitest';
import { initialsOf, avatarTone, AVATAR_TONES } from './staffAvatar';

describe('initialsOf', () => {
  it('handles null and empty values', () => {
    expect(initialsOf(null)).toBe('?');
    expect(initialsOf('')).toBe('?');
    expect(initialsOf('   ')).toBe('?');
  });

  it('handles single word names', () => {
    expect(initialsOf('Admin')).toBe('AD');
    expect(initialsOf('counsellor')).toBe('CO');
    expect(initialsOf('J')).toBe('J');
  });

  it('extracts first and last word initials for multi-word names', () => {
    expect(initialsOf('John Doe')).toBe('JD');
    expect(initialsOf('Jane Alice Smith')).toBe('JS');
    expect(initialsOf('Maria Clara de los Santos')).toBe('MS');
    expect(initialsOf('nina reyes')).toBe('NR');
  });
});

describe('avatarTone', () => {
  it('returns deterministic tone based on name or id', () => {
    const tone1 = avatarTone(10, 'Nina Reyes');
    const tone2 = avatarTone(10, 'Nina Reyes');
    expect(tone1).toBe(tone2);
    expect(AVATAR_TONES).toContain(tone1);

    const toneIdOnly1 = avatarTone(42, null);
    const toneIdOnly2 = avatarTone(42, null);
    expect(toneIdOnly1).toBe(toneIdOnly2);
    expect(AVATAR_TONES).toContain(toneIdOnly1);
  });
});
