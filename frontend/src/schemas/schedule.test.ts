import { describe, expect, it } from 'vitest';
import { updateSlotSchema } from './schedule';

/**
 * The availability edit payload. The backend keeps the current value for any
 * field it does not receive, so the schema has to accept a partial send —
 * that is what makes "change only the end time" possible at all.
 */
describe('updateSlotSchema', () => {
  it('accepts a partial payload', () => {
    const parsed = updateSlotSchema.safeParse({ start_time: '09:30' });
    expect(parsed.success).toBe(true);
  });

  it('accepts an empty payload, which changes nothing', () => {
    expect(updateSlotSchema.safeParse({}).success).toBe(true);
  });

  it('coerces the weekday a Select sends as a string', () => {
    const parsed = updateSlotSchema.safeParse({ day_of_week: '3' });
    expect(parsed.success).toBe(true);
    if (parsed.success) expect(parsed.data.day_of_week).toBe(3);
  });

  it('accepts the whole set the edit dialog submits', () => {
    const parsed = updateSlotSchema.safeParse({
      day_of_week: 1,
      start_time: '09:00',
      end_time: '10:30',
    });
    expect(parsed.success).toBe(true);
  });

  it('accepts HH:MM:SS, the shape the API returns stored times in', () => {
    const parsed = updateSlotSchema.safeParse({ start_time: '09:00:00', end_time: '10:00:00' });
    expect(parsed.success).toBe(true);
  });

  it('rejects a weekday outside 0-6', () => {
    expect(updateSlotSchema.safeParse({ day_of_week: 7 }).success).toBe(false);
    expect(updateSlotSchema.safeParse({ day_of_week: -1 }).success).toBe(false);
  });

  it('rejects a malformed time', () => {
    expect(updateSlotSchema.safeParse({ start_time: '9am' }).success).toBe(false);
    expect(updateSlotSchema.safeParse({ start_time: '09:00:00:00' }).success).toBe(false);
  });
});
