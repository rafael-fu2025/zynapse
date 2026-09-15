/**
 * Zod schemas — Clinic Equipment (mirrors backend EquipmentController rules).
 *
 * Equipment is the durable-asset catalog: each item owns per-unit rows
 * whose status (working / for repair / for replacement / retired) is
 * changed one click at a time, with every change recorded in the
 * append-only status log.
 */
import { z } from 'zod';

export const equipmentStatusSchema = z.enum(['working', 'for_repair', 'for_replacement', 'retired']);
export type EquipmentStatus = z.infer<typeof equipmentStatusSchema>;

/** Catalog row with its per-status unit counts (list shape). */
export const equipmentItemSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  category: z.string().nullable(),
  location: z.string().nullable(),
  notes: z.string().nullable(),
  archived: z.boolean(),
  working: z.number().int().min(0),
  for_repair: z.number().int().min(0),
  for_replacement: z.number().int().min(0),
  retired: z.number().int().min(0),
  total_units: z.number().int().min(0),
  created_at: z.string(),
});
export type EquipmentItem = z.infer<typeof equipmentItemSchema>;

export const equipmentUnitSchema = z.object({
  id: z.number().int().positive(),
  status: equipmentStatusSchema,
  condition_note: z.string().nullable(),
  acquired_date: z.string().nullable(),
  status_changed_at: z.string(),
  created_at: z.string(),
});
export type EquipmentUnit = z.infer<typeof equipmentUnitSchema>;

/** One from→to entry in the append-only per-unit trail. */
export const equipmentStatusLogSchema = z.object({
  id: z.number().int().positive(),
  unit_id: z.number().int().positive(),
  from_status: equipmentStatusSchema.nullable(),
  to_status: equipmentStatusSchema,
  note: z.string().nullable(),
  user_email: z.string().nullable(),
  created_at: z.string(),
});
export type EquipmentStatusLogEntry = z.infer<typeof equipmentStatusLogSchema>;

/** Detail shape — catalog + units (attention first) + the status log. */
export const equipmentDetailSchema = equipmentItemSchema.extend({
  units: z.array(equipmentUnitSchema),
  status_log: z.array(equipmentStatusLogSchema),
});
export type EquipmentDetail = z.infer<typeof equipmentDetailSchema>;

export const createEquipmentSchema = z.object({
  name: z.string().min(1).max(128),
  category: z.string().max(100).optional(),
  location: z.string().max(128).optional(),
  notes: z.string().max(500).optional(),
});
export type CreateEquipmentInput = z.infer<typeof createEquipmentSchema>;

export const updateEquipmentSchema = z.object({
  name: z.string().min(1, 'Required').max(128),
  category: z.string().max(100).optional(),
  location: z.string().max(128).optional(),
  notes: z.string().max(500).optional(),
});
export type UpdateEquipmentInput = z.infer<typeof updateEquipmentSchema>;

export const addEquipmentUnitsSchema = z.object({
  quantity: z.number().int().positive('Must be at least 1'),
  acquired_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional(),
  note: z.string().max(255).optional(),
});
export type AddEquipmentUnitsInput = z.infer<typeof addEquipmentUnitsSchema>;

export const changeEquipmentUnitStatusSchema = z.object({
  status: equipmentStatusSchema,
  note: z.string().max(255).optional(),
});
export type ChangeEquipmentUnitStatusInput = z.infer<typeof changeEquipmentUnitStatusSchema>;
