/**
 * Carousel schema tests — the wire contract for the login carousel.
 *
 * The important case here is the MySQLi string-numeric behaviour: the
 * backend's rows come back with every column as a string
 * (`id: "3"`, `sort_order: "1"`, `is_active: "1"`). If the schema ever
 * drops `z.coerce.number()` the login carousel would silently fall back
 * to its hard-coded defaults and the admin page would show every row as
 * broken — so pin the coercion explicitly.
 */
import { describe, expect, it } from 'vitest';
import {
  carouselSlideSchema,
  slideImageUrl,
  toLoginSlides,
  type CarouselSlide,
} from '@/schemas/carousel';

/** Shape the API actually returns today (MySQLi string columns). */
const rawRow = {
  id: '3',
  tenant_id: '1',
  title: 'University Health Services',
  subtitle: 'Accessible Campus Medical & Wellness Care',
  light_image: 'assets/img/brand/health-services.png',
  dark_image: 'assets/img/brand/health-services.png',
  fit_mode: 'contain_blur',
  sort_order: '3',
  is_active: '1',
  created_at: '2026-10-17 01:24:17',
  updated_at: '2026-10-17 01:24:17',
};

describe('carouselSlideSchema', () => {
  it('coerces MySQLi string columns to numbers', () => {
    const parsed = carouselSlideSchema.parse(rawRow);
    expect(parsed.id).toBe(3);
    expect(parsed.tenant_id).toBe(1);
    expect(parsed.sort_order).toBe(3);
    expect(typeof parsed.id).toBe('number');
  });

  it('maps is_active 1/0 to a boolean', () => {
    expect(carouselSlideSchema.parse(rawRow).is_active).toBe(true);
    expect(carouselSlideSchema.parse({ ...rawRow, is_active: '0' }).is_active).toBe(false);
    // A native-boolean connection must still work.
    expect(carouselSlideSchema.parse({ ...rawRow, is_active: true }).is_active).toBe(true);
  });

  it('rejects a non-integer id', () => {
    expect(() => carouselSlideSchema.parse({ ...rawRow, id: 'abc' })).toThrow();
    expect(() => carouselSlideSchema.parse({ ...rawRow, id: 0 })).toThrow();
  });

  it('rejects an unknown fit mode', () => {
    expect(() => carouselSlideSchema.parse({ ...rawRow, fit_mode: 'zoom' })).toThrow();
  });

  it('allows null title/subtitle/dark_image', () => {
    const parsed = carouselSlideSchema.parse({
      ...rawRow,
      title: null,
      subtitle: null,
      dark_image: null,
    });
    expect(parsed.title).toBeNull();
    expect(parsed.dark_image).toBeNull();
  });
});

describe('slideImageUrl', () => {
  it('prepends a slash to a server-relative path', () => {
    expect(slideImageUrl('assets/img/brand/fuel-bg.jpg')).toBe('/assets/img/brand/fuel-bg.jpg');
    expect(slideImageUrl('uploads/carousel/slide_light_abc123.png')).toBe('/uploads/carousel/slide_light_abc123.png');
  });

  it('leaves an already-absolute path alone', () => {
    expect(slideImageUrl('/assets/img/brand/fuel-bg.jpg')).toBe('/assets/img/brand/fuel-bg.jpg');
  });
});

describe('toLoginSlides', () => {
  const row: CarouselSlide = carouselSlideSchema.parse(rawRow);

  it('falls back to the light image when dark_image is null', () => {
    const slides = toLoginSlides([{ ...row, dark_image: null }]);
    expect(slides[0]?.darkSrc).toBe(slides[0]?.lightSrc);
  });

  it('omits subtitle entirely when it is null', () => {
    const slides = toLoginSlides([{ ...row, subtitle: null }]);
    // `exactOptionalPropertyTypes` means the key must be absent, not undefined.
    expect('subtitle' in (slides[0] ?? {})).toBe(false);
  });

  it('keeps a present subtitle', () => {
    const slides = toLoginSlides([row]);
    expect(slides[0]?.subtitle).toBe('Accessible Campus Medical & Wellness Care');
  });

  it('maps fit_mode through and resolves image URLs', () => {
    const slides = toLoginSlides([row]);
    expect(slides[0]?.fitMode).toBe('contain_blur');
    expect(slides[0]?.lightSrc).toBe('/assets/img/brand/health-services.png');
  });
});