/**
 * Zod schemas — Clinic Inventory (mirrors backend InventoryController rules).
 */
import { z } from 'zod';

export const inventoryItemSchema = z.object({
  id: z.number().int().positive(),
  sku: z.string(),
  name: z.string(),
  unit: z.string(),
  quantity_on_hand: z.number().int().min(0),
  reorder_level: z.number().int().min(0),
  low_stock: z.boolean(),
  created_at: z.string(),
});
export type InventoryItem = z.infer<typeof inventoryItemSchema>;

export const createItemSchema = z.object({
  sku: z.string().min(1).max(64).regex(/^[A-Za-z0-9_-]+$/, 'Letters, digits, - and _ only'),
  name: z.string().min(1).max(128),
  unit: z.string().min(1).max(32).default('pc'),
  reorder_level: z.number().int().min(0).default(0),
});
export type CreateItemInput = z.infer<typeof createItemSchema>;

export const moveStockSchema = z
  .object({
    qty_delta: z.number().int().refine((v) => v !== 0, 'Delta must be non-zero'),
    reason_code: z.enum(['receive', 'dispense', 'adjustment']),
    note: z.string().max(255).optional(),
  })
  .refine(
    (v) =>
      !(v.reason_code === 'receive' && v.qty_delta < 0)
      && !(v.reason_code === 'dispense' && v.qty_delta > 0),
    { message: 'Delta sign does not match reason', path: ['qty_delta'] },
  );
export type MoveStockInput = z.infer<typeof moveStockSchema>;
