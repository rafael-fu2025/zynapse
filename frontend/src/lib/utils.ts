/**
 * cn — canonical shadcn/ui class combinator (clsx + tailwind-merge).
 */
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]): string {
  return twMerge(clsx(inputs));
}

/**
 * Title-case a dropdown option label.
 * - "moisture_adjustment" → "Moisture Adjustment"; "low" → "Low";
 *   "excellent" → "Excellent".
 * - Leaves already-cased strings untouched so codes / names that carry
 *   their own casing (e.g. "FOOD-SCRP", "DRM-01", "Food Scraps",
 *   "Kiosk-01") are never mangled.
 */
export function titleCase(value: string): string {
  if (value === value.toUpperCase() || /[A-Z]/.test(value)) {
    return value;
  }
  return value
    .split(/[_\s]+/)
    .filter(Boolean)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
    .join(' ');
}
