import type { AppointmentStatus } from '@/schemas/schedule';

export const STATUS_VARIANT: Record<AppointmentStatus, 'secondary' | 'info' | 'success' | 'outline' | 'destructive'> = {
  scheduled: 'info',
  confirmed: 'secondary',
  completed: 'success',
  cancelled: 'outline',
  no_show: 'destructive',
};

export const TYPE_LABEL: Record<string, string> = {
  initial: 'Initial',
  follow_up: 'Follow-up',
  crisis: 'Crisis',
  referral_based: 'Referral-based',
};
