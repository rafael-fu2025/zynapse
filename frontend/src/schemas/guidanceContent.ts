/**
 * Guidance content schemas (2026-09 parity plan Phase A) — announcements
 * + CMO service catalogue. Mirrors Modules\Counselling\Services\
 * GuidanceContentService payloads.
 */
import { z } from 'zod';

export const announcementAudienceSchema = z.enum([
  'all',
  'new_students',
  'continuing_students',
  'graduating_students',
]);
export type AnnouncementAudience = z.infer<typeof announcementAudienceSchema>;

export const announcementStatusSchema = z.enum(['live', 'scheduled', 'expired']);

export const announcementSchema = z.object({
  id: z.number().int().positive(),
  title: z.string().min(1),
  body: z.string().min(1),
  audience: announcementAudienceSchema,
  action_url: z.string().nullable(),
  action_label: z.string().nullable(),
  is_required: z.boolean(),
  publish_at: z.string().nullable(),
  unpublish_at: z.string().nullable(),
  created_at: z.string(),
  updated_at: z.string(),
  status: announcementStatusSchema,
});
export type GuidanceAnnouncement = z.infer<typeof announcementSchema>;

export const guidanceServiceSchema = z.object({
  id: z.number().int().positive(),
  code: z.string().min(1),
  name: z.string().min(1),
  description: z.string().nullable(),
  cmo_reference: z.string().nullable(),
  sort_order: z.number().int().nonnegative(),
  queue_destination: z.enum(['clinic', 'counselling']).nullable(),
  is_active: z.boolean(),
  created_at: z.string(),
});
export type GuidanceService = z.infer<typeof guidanceServiceSchema>;

/** Coarse day-time inputs for the publish-window fields (datetime-local). */
export const announcementInputSchema = z.object({
  title: z.string().min(1, 'Title is required.').max(200),
  body: z.string().min(1, 'Body is required.'),
  audience: announcementAudienceSchema,
  action_url: z.string().max(500).optional(),
  action_label: z.string().max(60).optional(),
  is_required: z.boolean(),
  publish_at: z.string().optional(),
  unpublish_at: z.string().optional(),
});
export type AnnouncementInput = z.infer<typeof announcementInputSchema>;

export const serviceInputSchema = z.object({
  name: z.string().min(1, 'Service name is required.').max(120),
  code: z.string().max(64).optional(),
  description: z.string().max(1000).optional(),
  cmo_reference: z.string().max(160).optional(),
  sort_order: z.number().int().nonnegative(),
  queue_destination: z.enum(['clinic', 'counselling']).nullable(),
  is_active: z.boolean(),
});
export type ServiceInput = z.infer<typeof serviceInputSchema>;
