/**
 * Badge variant lookup tables for the inventory status chips.
 */
export const BATCH_STATUS_VARIANT = {
  active: 'success',
  depleted: 'secondary',
  expired: 'destructive',
  recalled: 'warning',
} as const;

export const URGENCY_VARIANT = {
  low: 'secondary',
  medium: 'info',
  high: 'warning',
  critical: 'destructive',
} as const;

export const REORDER_STATUS_VARIANT = {
  pending: 'info',
  approved: 'warning',
  ordered: 'default',
  received: 'success',
  completed: 'secondary',
  cancelled: 'secondary',
} as const;
