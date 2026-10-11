import { describe, expect, it } from 'vitest';
import {
  GEAR_ROOT_RADIUS,
  GEAR_SPEED,
  GEAR_TEETH,
  GEAR_TIP_RADIUS,
  describeGear,
  gearOutline,
  type GearInput,
} from './gearModel';

const base: GearInput = {
  inputKg: 12,
  daysSinceLastTurning: 0,
  turningDueDays: 4,
  progressPct: 40,
};

describe('describeGear', () => {
  it('is still and grey while the drum is waiting for waste', () => {
    const g = describeGear({ ...base, inputKg: 0 });
    expect(g.mode).toBe('idle');
    expect(g.speed).toBe(0);
  });

  it('turns steadily when processing and turned on cadence', () => {
    const g = describeGear(base);
    expect(g.mode).toBe('running');
    expect(g.speed).toBe(GEAR_SPEED.running);
  });

  it('turns sluggishly when turning is past the cadence', () => {
    const g = describeGear({ ...base, daysSinceLastTurning: 6 });
    expect(g.mode).toBe('overdue');
    expect(g.speed).toBe(GEAR_SPEED.overdue);
    expect(g.speed).toBeGreaterThan(0);
    expect(g.speed).toBeLessThan(GEAR_SPEED.running);
    expect(g.label).toContain('Turned 6 days ago');
  });

  it('is not overdue exactly at the cadence boundary', () => {
    expect(describeGear({ ...base, daysSinceLastTurning: 4 }).mode).toBe('running');
    expect(describeGear({ ...base, daysSinceLastTurning: 5 }).mode).toBe('overdue');
  });

  it('treats a batch that was never turned as overdue, like the card row does', () => {
    expect(describeGear({ ...base, daysSinceLastTurning: null }).mode).toBe('overdue');
    expect(describeGear({ ...base, daysSinceLastTurning: undefined }).mode).toBe('overdue');
  });

  it('does not call a drum overdue before the cadence has loaded', () => {
    expect(describeGear({ ...base, daysSinceLastTurning: 30, turningDueDays: null }).mode).toBe('running');
  });

  it('prefers idle over overdue: an empty drum has nothing to turn', () => {
    expect(describeGear({ ...base, inputKg: 0, daysSinceLastTurning: null }).mode).toBe('idle');
  });

  it('clamps progress into 0..1', () => {
    expect(describeGear({ ...base, progressPct: -5 }).progress).toBe(0);
    expect(describeGear({ ...base, progressPct: 140 }).progress).toBe(1);
    expect(describeGear({ ...base, progressPct: 25 }).progress).toBe(0.25);
  });
});

describe('gearOutline', () => {
  const points = gearOutline();

  it('has four points per tooth', () => {
    expect(points).toHaveLength(GEAR_TEETH * 4);
  });

  it('stays between the root and tip radii and touches both', () => {
    const radii = points.map(([x, y]) => Math.hypot(x, y));
    expect(Math.min(...radii)).toBeCloseTo(GEAR_ROOT_RADIUS, 6);
    expect(Math.max(...radii)).toBeCloseTo(GEAR_TIP_RADIUS, 6);
  });

  it('is wound counter-clockwise (positive signed area)', () => {
    let area = 0;
    points.forEach(([x1, y1], i) => {
      const next = points[(i + 1) % points.length];
      if (next === undefined) return;
      area += x1 * next[1] - next[0] * y1;
    });
    expect(area / 2).toBeGreaterThan(0);
  });

  it('is rotationally symmetric: every tooth is the same distance from the next', () => {
    const edge = (i: number): number => {
      const a = points[i];
      const b = points[(i + 1) % points.length];
      if (a === undefined || b === undefined) throw new Error('missing point');
      return Math.hypot(a[0] - b[0], a[1] - b[1]);
    };
    for (let i = 0; i < 4; i += 1) {
      expect(edge(i)).toBeCloseTo(edge(i + 4 * 5), 9);
    }
  });

  it('rejects shapes that cannot be a gear', () => {
    expect(() => gearOutline(5)).toThrow(RangeError);
    expect(() => gearOutline(12.5)).toThrow(RangeError);
    expect(() => gearOutline(12, 1, 1)).toThrow(RangeError);
    expect(() => gearOutline(12, 0, 1)).toThrow(RangeError);
  });
});
