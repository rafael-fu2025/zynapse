/**
 * ImageDropzone unit tests — the client-side half of the upload contract.
 *
 * The type rules asserted here are duplicated by the backend
 * (`CarouselService::sniffExtension`). Duplication is deliberate: this one
 * rejects a bad file without a round-trip, while the server one is
 * authoritative. A test here is about not wasting a user's upload, not
 * about security.
 *
 * There is deliberately NO size ceiling to test — the limit is PHP's
 * `upload_max_filesize` / `post_max_size`, reported by the server when
 * hit. Pinning a second, smaller number here would only reject files the
 * backend would accept.
 */
import { describe, expect, it } from 'vitest';
import { validateImageFile } from '@/components/ImageDropzone';

function fileOf(type: string, size: number, name = 'image'): File {
  const f = new File([new Uint8Array(1)], name, { type });
  // `size` is read-only on File, so define it for the size-only cases.
  Object.defineProperty(f, 'size', { value: size });
  return f;
}

describe('validateImageFile', () => {
  it.each(['image/png', 'image/jpeg', 'image/webp', 'image/gif'])('accepts %s', (type) => {
    expect(validateImageFile(fileOf(type, 1024))).toBeNull();
  });

  it('rejects a non-image type', () => {
    expect(validateImageFile(fileOf('application/pdf', 1024))).toMatch(/PNG, JPEG, WEBP, or GIF/);
  });

  it('rejects a file with no declared type', () => {
    expect(validateImageFile(fileOf('', 1024))).toMatch(/unknown type/);
  });

  it('rejects an empty file', () => {
    expect(validateImageFile(fileOf('image/png', 0))).toMatch(/empty/);
  });

  it('does NOT impose a size ceiling — a 2 GB PNG is client-valid', () => {
    // The server owns the limit and reports it with a specific message.
    // Refusing here would break the "no application size cap" posture.
    expect(validateImageFile(fileOf('image/png', 2 * 1024 * 1024 * 1024))).toBeNull();
  });
});