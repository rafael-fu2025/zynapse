import type { CheckinDestination } from '@/schemas/checkin';

export const KIOSK_DESTINATIONS = [
  { value: 'counselling', label: 'Guidance' },
  { value: 'clinic', label: 'Clinic' },
] as const satisfies ReadonlyArray<{ value: CheckinDestination; label: string }>;

export const KIOSK_PURPOSES: Record<CheckinDestination, readonly string[]> = {
  clinic: [
    'Consultation',
    'Medical Certificate',
    'Dental',
    'Physical Exam',
    'Vaccination',
    'Laboratory',
    'Pharmacy',
    'Injury',
  ],
  // PRODUCT ASSUMPTION: approved Guidance wording was not supplied.
  // Keep this list isolated and synchronized with CheckinPurposeCatalog.php.
  counselling: [
    'Initial Consultation',
    'Follow-up Session',
    'Crisis Support',
    'Referral Follow-up',
  ],
};

export function destinationLabel(destination: CheckinDestination): string {
  return destination === 'counselling' ? 'Guidance' : 'Clinic';
}
