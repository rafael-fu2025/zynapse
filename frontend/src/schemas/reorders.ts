/**
 * Zod schemas — Reorder requests (mirrors backend ReorderController rules).
 */
import { z } from 'zod';

export const reorderSchema = z.object({
  id: z.number().int().positive(),
  item_type: z.enum(['medicine', 'supply']),
  medicine_id: z.number().int().positive().nullable(),
  supply_item_id: z.number().int().positive().nullable(),
  generic_name: z.string().nullable(),
  item_name: z.string().nullable(),
  unit: z.string().nullable(),
  requested_quantity: z.number().int().min(1),
  current_stock: z.number().int().min(0),
  reorder_level: z.number().int().min(0),
  urgency: z.enum(['low', 'medium', 'high', 'critical']),
  status: z.enum(['pending', 'approved', 'ordered', 'received', 'completed', 'cancelled']),
  auto_triggered: z.boolean(),
  procurement_note: z.string().nullable(),
  order_date: z.string().nullable(),
  expected_delivery_date: z.string().nullable(),
  actual_delivery_date: z.string().nullable(),
  fulfilled_at: z.string().nullable(),
  created_at: z.string(),
});
export type Reorder = z.infer<typeof reorderSchema>;

export const createReorderSchema = z
  .object({
    item_type: z.enum(['medicine', 'supply']).default('medicine'),
    medicine_id: z.number().int().positive().optional(),
    supply_item_id: z.number().int().positive().optional(),
    quantity: z.number().int().min(1, 'Must be at least 1'),
    urgency: z.enum(['low', 'medium', 'high', 'critical']).default('medium'),
    note: z.string().max(255).optional(),
  })
  .refine(
    (v) => (v.item_type === 'supply' ? v.supply_item_id !== undefined : v.medicine_id !== undefined),
    { message: 'Select an item', path: ['medicine_id'] },
  );
export type CreateReorderInput = z.infer<typeof createReorderSchema>;

export type ReorderAction = 'approve' | 'order' | 'receive' | 'cancel';
