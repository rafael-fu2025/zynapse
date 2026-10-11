/**
 * gearModel — what the drum-card gear MEANS, and its tooth geometry.
 *
 * The gear replaces the static drum picture on a drum card, so it has to
 * earn its place by carrying information the card already knows. Keeping
 * those decisions here — pure and free of three.js/DOM — means the status
 * rules can be unit-tested, and the renderer is left with nothing but
 * drawing.
 *
 *   idle     no waste loaded yet            → still, grey
 *   running  processing, turned on cadence  → turns steadily, brand colour
 *   overdue  processing, turning is overdue → turns sluggishly, amber
 *
 * "Overdue" reuses `describeTurning`, so the gear and the card's
 * "Last turned" row can never disagree about what needs attention.
 *
 * Honest caveat: this reflects the BATCH's health, not the motor. The
 * card has no live motor state (that lives on the per-unit monitor
 * endpoint), so "turning" means "being processed", not "motor on now".
 */
import { describeTurning } from '@/lib/bmgFormat';

export type GearMode = 'idle' | 'running' | 'overdue';

export interface GearVisual {
  mode: GearMode;
  /** Angular speed in rad/s at rest (hover boosts it in the renderer). */
  speed: number;
  /** Decomposition progress, 0..1, drawn as the bezel arc. */
  progress: number;
  /** Plain-language explanation, used as the hover tooltip. */
  label: string;
}

/** rad/s. ~8.6 rpm when healthy, ~2.1 rpm when turning is overdue. */
export const GEAR_SPEED = { idle: 0, running: 0.9, overdue: 0.22 } as const;

export interface GearInput {
  inputKg: number;
  daysSinceLastTurning: number | null | undefined;
  turningDueDays: number | null | undefined;
  progressPct: number;
}

export function describeGear(input: GearInput): GearVisual {
  const progress = Math.min(1, Math.max(0, input.progressPct / 100));

  if (input.inputKg <= 0) {
    return {
      mode: 'idle',
      speed: GEAR_SPEED.idle,
      progress,
      label: 'Loading — waiting for waste input',
    };
  }

  const turning = describeTurning(input.daysSinceLastTurning, input.turningDueDays);
  if (turning.tone === 'warning') {
    return {
      mode: 'overdue',
      speed: GEAR_SPEED.overdue,
      progress,
      label: `Needs turning — ${turning.label}`,
    };
  }

  return {
    mode: 'running',
    speed: GEAR_SPEED.running,
    progress,
    label: 'Processing — turned on schedule',
  };
}

// ---- Tooth geometry --------------------------------------------------

export const GEAR_TEETH = 14;
/** Radius of the tooth valleys / tips, in scene units (tip = 1). */
export const GEAR_ROOT_RADIUS = 0.8;
export const GEAR_TIP_RADIUS = 1;

export type Point2 = readonly [x: number, y: number];

function polar(radius: number, angle: number): Point2 {
  return [radius * Math.cos(angle), radius * Math.sin(angle)];
}

/**
 * Closed, counter-clockwise outline of a spur gear: each tooth is a
 * trapezoid (wide at the root, narrow at the tip) and the valley between
 * two teeth is the straight chord between their roots. With 8+ teeth that
 * chord sits within a fraction of a percent of the true arc, so no
 * curve sampling is needed.
 *
 * Returns `teeth * 4` points, wound counter-clockwise (what three's
 * `Shape` expects for an outer contour).
 */
export function gearOutline(
  teeth: number = GEAR_TEETH,
  rootRadius: number = GEAR_ROOT_RADIUS,
  tipRadius: number = GEAR_TIP_RADIUS,
): Point2[] {
  if (!Number.isInteger(teeth) || teeth < 6) {
    throw new RangeError(`A gear needs at least 6 whole teeth, got ${teeth}`);
  }
  if (!(rootRadius > 0 && tipRadius > rootRadius)) {
    throw new RangeError('Tip radius must exceed a positive root radius');
  }

  const pitch = (Math.PI * 2) / teeth;
  const baseHalf = pitch * 0.3;
  const tipHalf = pitch * 0.15;
  const points: Point2[] = [];
  for (let i = 0; i < teeth; i += 1) {
    const centre = i * pitch;
    points.push(
      polar(rootRadius, centre - baseHalf),
      polar(tipRadius, centre - tipHalf),
      polar(tipRadius, centre + tipHalf),
      polar(rootRadius, centre + baseHalf),
    );
  }
  return points;
}
