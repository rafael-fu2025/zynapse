/**
 * External API developer-portal schemas (2026-09, D4/D7).
 *
 * Mirrors Modules\External\Services\DeveloperAppsService payloads.
 * The full secret appears ONLY in the create-key response — it is
 * never retrievable afterwards (hash-only storage server-side).
 */
import { z } from 'zod';

export const appStatusSchema = z.enum(['active', 'suspended']);
export type AppStatus = z.infer<typeof appStatusSchema>;

export const keyStatusSchema = z.enum(['active', 'revoked', 'expired']);
export type KeyStatus = z.infer<typeof keyStatusSchema>;

export const keyEnvSchema = z.enum(['test', 'live']);
export type KeyEnv = z.infer<typeof keyEnvSchema>;

export const developerAppSchema = z.object({
  id: z.number().int().positive(),
  name: z.string().min(1),
  description: z.string().nullable(),
  owner_contact: z.string().nullable(),
  status: appStatusSchema,
  dpa_acknowledged_at: z.string().nullable(),
  key_count: z.number().int().nonnegative(),
  created_at: z.string(),
});
export type DeveloperApp = z.infer<typeof developerAppSchema>;

export const developerKeySchema = z.object({
  id: z.number().int().positive(),
  app_id: z.number().int().positive(),
  env: keyEnvSchema,
  prefix: z.string().min(1),
  last4: z.string().length(4),
  scopes: z.array(z.string()),
  rate_limit_per_min: z.number().int().positive(),
  expires_at: z.string().nullable(),
  revoked_at: z.string().nullable(),
  last_used_at: z.string().nullable(),
  created_at: z.string(),
  status: keyStatusSchema,
});
export type DeveloperKey = z.infer<typeof developerKeySchema>;

export const createKeyResponseSchema = z.object({
  key: developerKeySchema,
  secret: z.string().min(40),
});
export type CreateKeyResponse = z.infer<typeof createKeyResponseSchema>;

export const sandboxExecuteInputSchema = z.object({
  key_id: z.number().int().positive(),
  method: z.literal('GET'),
  path: z.string().min(1),
  query: z.record(z.string()).optional(),
  body: z.record(z.unknown()).optional(),
});
export type SandboxExecuteInput = z.infer<typeof sandboxExecuteInputSchema>;

export const sandboxExecuteResponseSchema = z.object({
  status: z.number().int(),
  headers: z.record(z.string()),
  body: z.unknown(),
});
export type SandboxExecuteResponse = z.infer<typeof sandboxExecuteResponseSchema>;

/**
 * The v1 scope catalog — must stay in sync with
 * backend Config\ExternalApps::$scopes (the backend validates
 * authoritatively; this drives the docs table + issue-key picker).
 */
export const EXTERNAL_SCOPE_CATALOG: ReadonlyArray<{ code: string; description: string }> = [
  { code: 'reports.read', description: 'De-identified visit and queue aggregates' },
  { code: 'referrals.read', description: 'Referral flow and verification aggregates' },
];
