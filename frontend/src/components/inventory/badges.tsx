/**
 * Badge components shared by the inventory tabs, dialogs, and insight
 * cards. Extracted verbatim from InventoryPage so the medicine rows,
 * supply rows, batch lists, and reorder tables all render the same
 * status chips.
 */
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { InventoryItemLastMovement } from '@/schemas/inventory';
import type { MedicineLastMovement } from '@/schemas/medicines';
import type { EquipmentItem, EquipmentStatus } from '@/schemas/equipment';
import type { Reorder } from '@/schemas/reorders';
import { EQUIPMENT_STATUS_LABEL, EQUIPMENT_STATUS_VARIANT } from './constants';
import { daysUntil, fmtRelativeFromNow, initialsFromEmail } from './format';

/**
 * StockBadge — colored status chip for the catalog rows. Used by both
 * medicines and supplies. Renders one of four states:
 *
 *   - Archived  (secondary)            — soft-deleted, out of the live list
 *   - Out of Stock      (destructive)  — on_hand === 0
 *   - Needs to Reorder  (warning)      — on_hand <= threshold
 *   - In Stock          (success)      — above threshold
 *
 * The tooltip exposes the threshold and configured target. `threshold` accepts either
 * `reorder_threshold` (medicines) or `reorder_level` (supplies).
 */
export function StockBadge({
  onHand,
  threshold,
  target,
  stockStatus,
  archived,
}: {
  onHand: number;
  threshold: number;
  target: number | null;
  stockStatus: 'in_stock' | 'needs_to_reorder' | 'out_of_stock';
  archived: boolean;
}): JSX.Element {
  if (archived) return <Badge variant="secondary">Archived</Badge>;
  const context = `On hand ${onHand}; reorder at ${threshold}${target !== null ? `; target ${target}` : ''}`;
  if (stockStatus === 'out_of_stock') return <Badge variant="destructive" title={context}>Out of Stock</Badge>;
  if (stockStatus === 'needs_to_reorder') return <Badge variant="warning" title={context}>Needs to Reorder</Badge>;
  return <Badge variant="success" title={context}>In Stock</Badge>;
}

/**
 * LastMovementHint — one-line mini-strip showing the most recent
 * transaction on a medicine. Rendered under the medicine name in the
 * catalog row so the clerk sees at a glance who last touched the stock
 * and when. Composer for "gap 13" — server joins `users.email`; we
 * format it as initials on display.
 *
 * Returns `null` when there are no movements yet (just-created row),
 * which collapses the row's vertical footprint naturally.
 */
export function LastMovementHint({
  movement,
  unit,
}: {
  movement: MedicineLastMovement | null;
  unit: string;
}): JSX.Element | null {
  if (movement === null) return null;
  // Write-offs (expired/recalled) are neither in nor out — render as a
  // muted ✕ so the row doesn't read as a stock increase.
  const isWriteOff = movement.type === 'expired' || movement.type === 'recalled';
  const sign       = movement.type === 'dispensed' ? '−' : isWriteOff ? '✕' : '+';
  const tone       = movement.type === 'dispensed' ? 'text-rose-600 dark:text-rose-400'
              : movement.type === 'received'  ? 'text-emerald-600 dark:text-emerald-400'
              : 'text-muted-foreground';
  const agoText  = fmtRelativeFromNow(movement.created_at);
  const userText = movement.user_email !== null && movement.user_email !== ''
    ? `by ${initialsFromEmail(movement.user_email)}`
    : '';
  return (
    <p className={cn('mt-0.5 text-[11px] font-mono', tone)}>
      {movement.type === 'received' ? '↑ ' : movement.type === 'dispensed' ? '↓ ' : isWriteOff ? '· ' : '· '}
      {sign}{movement.quantity} {unit}
      {userText !== '' && <> · {userText}</>}
      {agoText !== '' && <> · {agoText}</>}
    </p>
  );
}

/**
 * SupplyLastMovementHint — the Supplies-tab twin of LastMovementHint.
 * The supply movement payload differs (reason_code/qty_delta instead of
 * type/quantity) so it needs its own formatter.
 */
export function SupplyLastMovementHint({
  movement,
  unit,
}: {
  movement: InventoryItemLastMovement | null;
  unit: string;
}): JSX.Element | null {
  if (movement === null) return null;
  const sign   = movement.qty_delta < 0 ? '−' : '+';
  const tone   = movement.qty_delta < 0 ? 'text-rose-600 dark:text-rose-400'
              : movement.reason_code === 'receive' ? 'text-emerald-600 dark:text-emerald-400'
              : 'text-muted-foreground';
  const agoText  = fmtRelativeFromNow(movement.created_at);
  const userText = movement.user_email !== null && movement.user_email !== ''
    ? `by ${initialsFromEmail(movement.user_email)}`
    : '';
  return (
    <p className={cn('mt-0.5 text-[11px] font-mono', tone)}>
      {movement.qty_delta < 0 ? '↓ ' : movement.reason_code === 'receive' ? '↑ ' : '· '}
      {sign}{Math.abs(movement.qty_delta)} {unit}
      {userText !== '' && <> · {userText}</>}
      {agoText !== '' && <> · {agoText}</>}
    </p>
  );
}

/**
 * ExpiryChip — days-to-expiry badge next to an `earliest_expiry` date.
 * Same shape used by the medicine row + the per-batch list inside the
 * Batches dialog. Shows `expired` for non-positive days instead of
 * `-3d`, which was confusing in the previous build.
 */
export function ExpiryChip({ days }: { days: number | null }): JSX.Element | null {
  if (days === null) return null;
  if (days > 30) return null;
  if (days <= 0) return <Badge variant="destructive" className="ml-1.5">expired</Badge>;
  if (days <= 7) return <Badge variant="destructive" className="ml-1.5">{days}d</Badge>;
  return <Badge variant="warning" className="ml-1.5">{days}d</Badge>;
}

/**
 * EquipmentStatusBadge — single unit status chip (units dialog rows).
 */
export function EquipmentStatusBadge({ status }: { status: EquipmentStatus }): JSX.Element {
  return <Badge variant={EQUIPMENT_STATUS_VARIANT[status]}>{EQUIPMENT_STATUS_LABEL[status]}</Badge>;
}

/**
 * EquipmentStatusChips — the row-level status mix for an equipment
 * catalog item ("4 working · 1 for repair · 1 for replacement"). Zero
 * counts are omitted; an item with no units shows a muted hint instead
 * (freshly created catalog row).
 */
export function EquipmentStatusChips({ item }: { item: EquipmentItem }): JSX.Element {
  if (item.total_units === 0) {
    return <span className="text-xs text-muted-foreground">No units yet</span>;
  }

  const entries: Array<[EquipmentStatus, number]> = [
    ['working', item.working],
    ['for_repair', item.for_repair],
    ['for_replacement', item.for_replacement],
    ['retired', item.retired],
  ];

  return (
    <span className="inline-flex flex-wrap items-center gap-1">
      {entries
        .filter(([, count]) => count > 0)
        .map(([status, count]) => (
          <Badge key={status} variant={EQUIPMENT_STATUS_VARIANT[status]} className="gap-1">
            {count} {EQUIPMENT_STATUS_LABEL[status]}
          </Badge>
        ))}
    </span>
  );
}

/**
 * EtaBadge — countdown chip for in-flight purchase requests. The original
 * table just showed the raw `eta 2026-02-15` string, which forces the
 * operator to do the day-math in their head. This turns the date
 * into "due tomorrow" / "in 3d" / "overdue 2d" so a slipping
 * stock-in date pops immediately.
 *
 * Returns null for terminal statuses (`completed`, `cancelled`) or
 * when no date has been set on the request yet — those rows just show
 * the raw date text.
 */
export function EtaBadge({ status, expected }: { status: Reorder['status']; expected: string | null }): JSX.Element | null {
  if (expected === null) return null;
  if (status === 'completed' || status === 'cancelled') return null;
  const days = daysUntil(expected);
  if (days < 0) return <Badge variant="destructive" className="ml-1.5">overdue {Math.abs(days)}d</Badge>;
  if (days === 0) return <Badge variant="destructive" className="ml-1.5">due today</Badge>;
  if (days === 1) return <Badge variant="warning" className="ml-1.5">due tomorrow</Badge>;
  if (days <= 7) return <Badge variant="warning" className="ml-1.5">in {days}d</Badge>;
  return <Badge variant="info" className="ml-1.5">in {days}d</Badge>;
}
