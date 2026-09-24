import { describe, expect, it } from 'vitest';
import { groupAvailability, staffNameLookup } from './availabilitySlots';
import type { Availability } from '@/schemas/schedule';

describe('groupAvailability', () => {
  const counsellors = [
    { id: 1, name: 'Alice Walker' },
    { id: 2, name: 'Bob Vance' },
    { id: 3, name: 'Charlie Brown' },
  ];
  const nameOf = staffNameLookup(counsellors);

  it('collapses windows sharing day, start_time, and end_time into one slot with all members', () => {
    const windows: Availability[] = [
      { id: 101, counsellor_user_id: 1, day_of_week: 1, start_time: '09:00:00', end_time: '10:00:00' },
      { id: 102, counsellor_user_id: 2, day_of_week: 1, start_time: '09:00:00', end_time: '10:00:00' },
      { id: 103, counsellor_user_id: 3, day_of_week: 2, start_time: '09:00:00', end_time: '10:00:00' },
    ];

    const slots = groupAvailability(windows, nameOf);

    expect(slots).toHaveLength(2);
    const slot0 = slots[0];
    const slot1 = slots[1];
    expect(slot0).toBeDefined();
    expect(slot1).toBeDefined();

    // Monday slot — two staff covering the same hours is one row, and the
    // member count is the capacity the portal books against.
    expect(slot0?.day_of_week).toBe(1);
    expect(slot0?.start_time).toBe('09:00:00');
    expect(slot0?.end_time).toBe('10:00:00');
    expect(slot0?.windowIds).toEqual([101, 102]);
    expect(slot0?.members).toEqual([
      { id: 1, name: 'Alice Walker' },
      { id: 2, name: 'Bob Vance' },
    ]);

    // Tuesday slot
    expect(slot1?.day_of_week).toBe(2);
    expect(slot1?.windowIds).toEqual([103]);
    expect(slot1?.members).toEqual([
      { id: 3, name: 'Charlie Brown' },
    ]);
  });

  it('lists a counsellor once when they hold the same window twice, keeping both ids', () => {
    // Duplicates are rejected on write now (and cleared by
    // synapse:counselling-dedupe-availability), but rows predating that guard
    // still exist, so the row must not show the same person twice.
    const windows: Availability[] = [
      { id: 201, counsellor_user_id: 1, day_of_week: 1, start_time: '09:00:00', end_time: '10:00:00' },
      { id: 202, counsellor_user_id: 1, day_of_week: 1, start_time: '09:00:00', end_time: '10:00:00' },
    ];

    const slots = groupAvailability(windows, nameOf);

    expect(slots).toHaveLength(1);
    expect(slots[0]?.members).toEqual([{ id: 1, name: 'Alice Walker' }]);
    expect(slots[0]?.windowIds).toEqual([201, 202]);
  });

  it('handles unknown counsellors gracefully by returning null name', () => {
    const windows: Availability[] = [
      { id: 104, counsellor_user_id: 999, day_of_week: 3, start_time: '14:00:00', end_time: '15:00:00' },
    ];

    const slots = groupAvailability(windows, nameOf);
    expect(slots[0]?.members).toEqual([
      { id: 999, name: null },
    ]);
  });

  it('sorts slots deterministically by day, then start_time, then end_time', () => {
    const windows: Availability[] = [
      { id: 105, counsellor_user_id: 1, day_of_week: 3, start_time: '14:00:00', end_time: '15:00:00' },
      { id: 106, counsellor_user_id: 2, day_of_week: 1, start_time: '11:00:00', end_time: '12:00:00' },
      { id: 107, counsellor_user_id: 1, day_of_week: 1, start_time: '08:00:00', end_time: '09:00:00' },
    ];

    const slots = groupAvailability(windows, nameOf);
    expect(slots.map((s) => `${s.day_of_week}_${s.start_time}`)).toEqual([
      '1_08:00:00',
      '1_11:00:00',
      '3_14:00:00',
    ]);
  });
});
