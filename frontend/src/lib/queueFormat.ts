/**
 * Queue number formatting shared by the staff queue surfaces.
 */

/** Format an unambiguous destination queue number. */
export function formatQueueNumber(position: number, destination: 'clinic' | 'counselling' = 'clinic'): string {
  const prefix = destination === 'counselling' ? 'G' : 'C';
  if (!Number.isInteger(position) || position <= 0) return `${prefix}-???`;
  return `${prefix}-${String(position).padStart(3, '0')}`;
}
