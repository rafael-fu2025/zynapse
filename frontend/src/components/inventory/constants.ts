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

/**
 * Display labels for reorder statuses. There is no external supplier —
 * staff buy stock themselves — so the workflow reads as an internal
 * purchase tracker: `ordered` = the purchase was made, `received` =
 * the goods are in hand (stock not yet entered), `completed` = stock
 * entered. The API values stay as-is; only the labels are purchase
 * semantics.
 */
export const REORDER_STATUS_LABEL = {
  pending: 'Pending',
  approved: 'Approved',
  ordered: 'Purchased',
  received: 'Delivered',
  completed: 'Completed',
  cancelled: 'Cancelled',
} as const;

/**
 * Equipment unit statuses (durable-asset catalog). `for_replacement` is
 * the management-visible ask — it feeds the inventory report's
 * equipment section and the insights tile.
 */
export const EQUIPMENT_STATUS_VARIANT = {
  working: 'success',
  for_repair: 'warning',
  for_replacement: 'destructive',
  retired: 'secondary',
} as const;

export const EQUIPMENT_STATUS_LABEL = {
  working: 'Working',
  for_repair: 'For repair',
  for_replacement: 'For replacement',
  retired: 'Retired',
} as const;
