import { z } from 'zod';

export const kioskMediaAssetSchema = z.object({
  id: z.number().int().positive(),
  public_id: z.string().uuid(),
  kind: z.enum(['photo', 'video']),
  label: z.string(),
  original_name: z.string(),
  mime_type: z.string(),
  size_bytes: z.number().int().nonnegative(),
  url: z.string().startsWith('/api/v1/kiosk-media/'),
  thumbnail_url: z.string().regex(/^\/(?:api\/v1\/kiosk-media|kiosk-thumbnails)\//).optional(),
  archived: z.boolean(),
  created_at: z.string(),
});

export const kioskMediaPageSchema = z.object({
  items: z.array(kioskMediaAssetSchema),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
  total: z.number().int().nonnegative(),
});

export type KioskMediaAsset = z.infer<typeof kioskMediaAssetSchema>;
export type KioskMediaPage = z.infer<typeof kioskMediaPageSchema>;
