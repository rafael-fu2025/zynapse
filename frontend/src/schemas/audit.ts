/**
 * Zod schemas — Audit reader.
 */
import { z } from 'zod';

export const auditActorSchema = z.object({
  id: z.number().int().positive(),
  email: z.string().nullable(),
  display_name: z.string().nullable(),
});
export type AuditActor = z.infer<typeof auditActorSchema>;

export const auditEventSchema = z.object({
  id: z.number().int().positive(),
  prev_id: z.number().int().nullable(),
  action_code: z.string(),
  entity_type: z.string(),
  entity_id: z.number().int().nullable(),
  actor: auditActorSchema.nullable(),
  request_id: z.string().nullable(),
  committed_at: z.string(),
  commit_hash: z.string(),
});
export type AuditEvent = z.infer<typeof auditEventSchema>;

export const auditEventDetailSchema = auditEventSchema.extend({
  payload: z.record(z.unknown()),
});
export type AuditEventDetail = z.infer<typeof auditEventDetailSchema>;

export const auditFacetsSchema = z.object({
  action_codes: z.array(z.string()),
  entity_types: z.array(z.string()),
  actors: z.array(auditActorSchema),
});
export type AuditFacets = z.infer<typeof auditFacetsSchema>;

export const auditChainVerificationSchema = z.object({
  ok: z.boolean(),
  checked: z.number().int().nonnegative(),
  verified_up_to: z.number().int().positive().nullable(),
  first_divergence: z.object({
    id: z.number().int().positive(),
    reason: z.string(),
    expected: z.string(),
    actual: z.string(),
  }).nullable(),
});
export type AuditChainVerification = z.infer<typeof auditChainVerificationSchema>;
