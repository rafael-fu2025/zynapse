/**
 * Carousel schemas — wire contract for the login-page carousel.
 *
 * The backend stores slides as MySQL rows. The public endpoint
 * (`GET /api/v1/auth/carousel-slides`) returns the active subset; the
 * admin endpoints return all rows. Both shapes share `carouselSlideSchema`
 * because admin CRUD also benefits from the same runtime parse.
 *
 * `light_image` / `dark_image` are *relative public paths* on the
 * server (`assets/img/brand/...` or `uploads/carousel/...`). The SPA
 * prepends `/` to form a URL — see `slideImageUrl()`.
 */
import { z } from 'zod';

export const fitModeSchema = z.enum(['cover', 'contain_blur']);
export type FitMode = z.infer<typeof fitModeSchema>;

/**
 * MySQLi returns every column as a string unless the connection opts
 * into native types, so `id`, `sort_order` and `is_active` arrive as
 * `"3"`, `"1"` etc. `z.coerce.number()` normalizes both forms — the
 * same pattern the other schemas use (see schemas/facilities.ts).
 */
export const carouselSlideSchema = z.object({
  id: z.coerce.number().int().positive(),
  tenant_id: z.coerce.number().int(),
  title: z.string().max(150).nullable(),
  subtitle: z.string().max(255).nullable(),
  light_image: z.string().min(1).max(255),
  dark_image: z.string().max(255).nullable(),
  fit_mode: fitModeSchema,
  sort_order: z.coerce.number().int().nonnegative(),
  // 0/1 (or "0"/"1") → boolean so the UI can branch on it directly.
  is_active: z.coerce.number().int().transform((v) => v === 1),
  created_at: z.string(),
  updated_at: z.string(),
});
export type CarouselSlide = z.infer<typeof carouselSlideSchema>;

export const carouselSlideListSchema = z.array(carouselSlideSchema);
export type CarouselSlideList = z.infer<typeof carouselSlideListSchema>;

/** Admin write shape — all fields optional except the required ones. */
export const carouselSlideInputSchema = z.object({
  title: z.string().max(150).optional(),
  subtitle: z.string().max(255).optional(),
  light_image: z.string().max(255).optional(),
  dark_image: z.string().max(255).optional(),
  fit_mode: fitModeSchema.optional(),
  is_active: z.boolean().optional(),
});
export type CarouselSlideInput = z.infer<typeof carouselSlideInputSchema>;

/**
 * Convert a backend-relative image path to a URL the browser can fetch.
 * The DB stores `assets/img/brand/...` or `uploads/carousel/...`; the
 * server (and Vite dev proxy) serve them at the matching `/...` path.
 */
export function slideImageUrl(relativePath: string): string {
  if (relativePath.startsWith('/')) return relativePath;
  return `/${relativePath}`;
}

/**
 * Reduced shape consumed by `LoginCarousel`. The component only needs
 * what it renders; the rest of the row is irrelevant on the login
 * screen.
 */
export interface LoginSlide {
  lightSrc: string;
  darkSrc: string;
  bgLightSrc?: string | undefined;
  bgDarkSrc?: string | undefined;
  title: string;
  subtitle?: string | undefined;
  fitMode: FitMode;
}

export function toLoginSlides(rows: CarouselSlide[]): LoginSlide[] {
  return rows.map((row) => {
    // Fallback to light image when dark is null or empty string
    const darkPath = row.dark_image !== null && row.dark_image !== ''
      ? row.dark_image
      : row.light_image;
    const slide: LoginSlide = {
      lightSrc: slideImageUrl(row.light_image),
      darkSrc: slideImageUrl(darkPath),
      title: row.title ?? '',
      fitMode: row.fit_mode,
    };
    if (row.subtitle !== null && row.subtitle !== '') {
      slide.subtitle = row.subtitle;
    }
    return slide;
  });
}