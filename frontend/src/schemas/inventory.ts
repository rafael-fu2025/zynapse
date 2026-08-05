/**
 * Zod schemas — Clinic Inventory (mirrors backend InventoryController rules).
 */
import { z } from 'zod';

export const inventoryItemLastMovementSchema = z.object({
  reason_code: z.string(),
  qty_delta: z.number().int(),
  created_at: z.string(),
  user_email: z.string().nullable(),
});
export type InventoryItemLastMovement = z.infer<typeof inventoryItemLastMovementSchema>;

export const inventoryItemSchema = z.object({
  id: z.number().int().positive(),
  sku: z.string(),
  name: z.string(),
  unit: z.string(),
  quantity_on_hand: z.number().int().min(0),
  reorder_level: z.number().int().min(0),
  low_stock: z.boolean(),
  archived: z.boolean().optional().default(false),
  created_at: z.string(),
  // Row-level "last move" hint — null when the item has no movements yet.
  last_movement: inventoryItemLastMovementSchema.nullable(),
});
export type InventoryItem = z.infer<typeof inventoryItemSchema>;

export const createItemSchema = z.object({
  sku: z.string().min(1).max(64).regex(/^[A-Za-z0-9_-]+$/, 'Letters, digits, - and _ only'),
  name: z.string().min(1).max(128),
  unit: z.string().min(1).max(32).default('pc'),
  reorder_level: z.number().int().min(0).default(0),
});
export type CreateItemInput = z.infer<typeof createItemSchema>;

/**
 * Update a supply item. SKU is NOT editable (it backs the
 * movement ledger); name, unit, reorder_level are mutable. Stock
 * is untouched here — use the Move dialog for that.
 */
export const updateItemSchema = z.object({
  name: z.string().min(1, 'Required').max(128),
  unit: z.string().min(1).max(32).optional(),
  reorder_level: z.number().int().min(0).optional(),
});
export type UpdateItemInput = z.infer<typeof updateItemSchema>;

/**
 * Free-form ledger movement. Receipts are excluded — they enter via
 * the gated receive flow (`/inventory/{id}/receive`), which pulls the
 * quantity from the item's `received` reorder request.
 *
 * `dispense` is now encounter-anchored (inventory audit fix): the
 * backend requires an open `encounter_id`, mirroring medicines.
 */
export const moveStockSchema = z
  .object({
    qty_delta: z.number().int().refine((v) => v !== 0, 'Delta must be non-zero'),
    reason_code: z.enum(['dispense', 'adjustment']),
    encounter_id: z.number().int().positive().optional(),
    note: z.string().max(255).optional(),
  })
  .refine(
    (v) => !(v.reason_code === 'dispense' && v.qty_delta > 0),
    { message: 'Delta sign does not match reason', path: ['qty_delta'] },
  )
  .refine(
    (v) => v.reason_code !== 'dispense' || v.encounter_id !== undefined,
    { message: 'Select the open encounter', path: ['encounter_id'] },
  );
export type MoveStockInput = z.infer<typeof moveStockSchema>;

/**
 * One row of the supply ledger (in/out with running balance). `qty_in`
 * / `qty_out` are mutually exclusive; `balance_after` is on-hand right
 * after the movement (panel revision — ledger-style tracking).
 * `reference_*` records which encounter/reorder the movement belonged
 * to; `user_email` is the actor (inventory audit fix).
 */
export const inventoryMovementSchema = z.object({
  id: z.number().int().positive(),
  reason_code: z.string(),
  qty_in: z.number().int().nullable(),
  qty_out: z.number().int().nullable(),
  balance_after: z.number().int().nullable(),
  reference_type: z.string().nullable(),
  reference_id: z.number().int().nullable(),
  user_email: z.string().nullable(),
  note: z.string().nullable(),
  created_at: z.string(),
});
export type InventoryMovement = z.infer<typeof inventoryMovementSchema>;
