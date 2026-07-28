/**
 * Zod schemas — Audit reader.
 */
import { z } from 'zod';

export const auditEventSchema = z.object({
  id: z.number().int().positive(),
  prev_id: z.number().int().nullable(),
  action_code: z.string(),
  entity_type: z.string(),
  entity_id: z.number().int().nullable(),
  actor_user_id: z.number().int().nullable(),
  request_id: z.string().nullable(),
  committed_at: z.string(),
  commit_hash: z.string(),
});
export type AuditEvent = z.infer<typeof auditEventSchema>;
