/**
 * ImageDropzone — drag-and-drop image picker with a live preview.
 *
 * Native HTML5 drag-and-drop, matching the humus admin form: no
 * dependency, works in every browser the SPA targets.
 *
 * Behaviour mirrored from `humus/app/Views/admin/carousel/_form.php`:
 *   - Click, Enter/Space, or drop selects a file.
 *   - The picked file is previewed via `URL.createObjectURL` and the
 *     object URL is revoked on replace/unmount so the blob is collected.
 *   - Dropping a non-image or a second file is refused with a message
 *     rather than silently accepted.
 *   - `onPick(null)` clears the selection.
 *
 * The object URL is the preview source, so a huge image never gets
 * base64'd into React state — that was the classic memory leak in the
 * original inline-file-reader approach.
 */
import { CloudUpload, ImageOff, Loader2, Moon, Sun, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';

export const ACCEPTED_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'] as const;

/**
 * Same validation the backend applies, so the user is told before the
 * round-trip.
 *
 * Deliberately NO size ceiling — the limit is whatever PHP's
 * `upload_max_filesize` / `post_max_size` are set to, matching the humus
 * admin form ("no application size cap — server limits apply"). The
 * backend reports the real limit when one is hit; hard-coding a second,
 * smaller number here would only reject files the server would accept.
 */
export function validateImageFile(file: File): string | null {
  if (!(ACCEPTED_IMAGE_TYPES as readonly string[]).includes(file.type)) {
    return `Images must be PNG, JPEG, WEBP, or GIF (got ${file.type || 'unknown type'}).`;
  }
  if (file.size <= 0) return 'That file is empty.';
  return null;
}

interface ImageDropzoneProps {
  /** Currently staged file, or null when the existing image is kept. */
  file: File | null;
  /** URL of the image already stored on the server (edit mode). */
  currentUrl?: string | undefined;
  /** Accessible name — also used as the preview alt text. */
  label: string;
  /** Shown under the dropzone as a hint. */
  hint?: string | undefined;
  /** Required on create; the dropzone shows a marker and the form blocks. */
  required?: boolean;
  /** Disabled while an upload is in flight. */
  disabled?: boolean;
  /** Variant glyph + copy so light/dark slots are visually distinct. */
  variant?: 'light' | 'dark';
  onPick: (file: File | null) => void;
  /** Called with a validation message, or null when the pick was accepted. */
  onError?: (message: string | null) => void;
}

export function ImageDropzone({
  file,
  currentUrl,
  label,
  hint,
  required = false,
  disabled = false,
  variant = 'light',
  onPick,
  onError,
}: ImageDropzoneProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragOver, setDragOver] = useState(false);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  // Object URLs are revoked when replaced or on unmount — without this
  // each pick would pin its blob in memory for the life of the tab.
  const objectUrlRef = useRef<string | null>(null);

  useEffect(() => {
    const previous = objectUrlRef.current;
    if (previous !== null) {
      URL.revokeObjectURL(previous);
      objectUrlRef.current = null;
    }
    if (file === null) {
      setPreviewUrl(null);
      return;
    }
    const url = URL.createObjectURL(file);
    objectUrlRef.current = url;
    setPreviewUrl(url);
    // `file` identity is the trigger; a same-name reselect still changes it.
  }, [file]);

  useEffect(() => {
    return () => {
      if (objectUrlRef.current !== null) URL.revokeObjectURL(objectUrlRef.current);
    };
  }, []);

  const accept = useCallback(
    (candidate: File | null | undefined) => {
      if (candidate === null || candidate === undefined) {
        onPick(null);
        onError?.(null);
        return;
      }
      const problem = validateImageFile(candidate);
      if (problem !== null) {
        onError?.(problem);
        // Reset so re-picking the same bad file still fires `change`.
        if (inputRef.current !== null) inputRef.current.value = '';
        onPick(null);
        return;
      }
      onError?.(null);
      onPick(candidate);
    },
    [onError, onPick],
  );

  const shown = previewUrl ?? currentUrl ?? null;
  const Glyph = variant === 'dark' ? Moon : Sun;

  return (
    <div className="space-y-1.5">
      <div className="flex items-center gap-1.5">
        <Glyph aria-hidden className="size-3.5 text-muted-foreground" />
        <span className="text-sm font-medium">{label}</span>
        {required ? (
          <span className="text-destructive" aria-hidden>*</span>
        ) : (
          <span className="text-xs font-normal text-muted-foreground">(optional)</span>
        )}
      </div>

      <div
        role="button"
        tabIndex={disabled ? -1 : 0}
        aria-label={`Choose ${label.toLowerCase()}`}
        aria-disabled={disabled}
        onClick={() => {
          if (!disabled) inputRef.current?.click();
        }}
        onKeyDown={(e) => {
          if (e.target !== e.currentTarget) return;
          if (e.key !== 'Enter' && e.key !== ' ') return;
          e.preventDefault();
          if (!disabled) inputRef.current?.click();
        }}
        onDragEnter={(e) => {
          e.preventDefault();
          if (!disabled) setDragOver(true);
        }}
        onDragOver={(e) => {
          // Required, or Chrome refuses to fire `drop` at all.
          e.preventDefault();
          if (!disabled) setDragOver(true);
        }}
        onDragLeave={(e) => {
          // Ignore leave events fired while moving between children.
          if (e.currentTarget.contains(e.relatedTarget as Node | null)) return;
          setDragOver(false);
        }}
        onDrop={(e) => {
          e.preventDefault();
          setDragOver(false);
          if (disabled) return;
          const dropped = e.dataTransfer.files;
          if (dropped.length === 0) return;
          if (dropped.length > 1) {
            onError?.('Drop one image per slot.');
            return;
          }
          accept(dropped[0]);
        }}
        className={[
          'flex min-h-36 cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-4 text-center transition-colors',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
          dragOver
            ? 'border-primary bg-primary/5'
            : 'border-border bg-muted/20 hover:border-primary/50 hover:bg-muted/40',
          disabled && 'cursor-not-allowed opacity-60',
        ].join(' ')}
      >
        <input
          ref={inputRef}
          type="file"
          accept={ACCEPTED_IMAGE_TYPES.join(',')}
          className="sr-only"
          disabled={disabled}
          onChange={(e) => accept(e.target.files?.[0])}
          aria-label={`${label} file`}
        />

        {shown !== null ? (
          <div className="flex w-full flex-col items-center gap-2">
            <img
              src={shown}
              alt={file !== null ? `Preview of ${file.name}` : `${label} (current)`}
              className="max-h-28 w-auto max-w-full rounded-md object-contain ring-1 ring-border"
            />
            <p className="max-w-full truncate text-xs font-medium">{file !== null ? file.name : 'Current image'}</p>
            <p className="text-xs text-muted-foreground">Click or drop to replace</p>
          </div>
        ) : (
          <div className="flex flex-col items-center gap-1.5">
            {disabled ? (
              <Loader2 aria-hidden className="size-7 animate-spin text-muted-foreground" />
            ) : (
              <CloudUpload aria-hidden className="size-7 text-muted-foreground" />
            )}
            <p className="text-sm font-medium">Drag &amp; drop or click to browse</p>
            <p className="text-xs text-muted-foreground">
              PNG, JPEG, WEBP or GIF · any size the server accepts
            </p>
          </div>
        )}
      </div>

      {shown !== null && !disabled && (
        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-7 px-2 text-xs"
            onClick={(e) => {
              e.stopPropagation();
              if (inputRef.current !== null) inputRef.current.value = '';
              accept(null);
            }}
          >
            <X aria-hidden className="size-3.5" />
            {file !== null ? 'Undo selection' : 'Clear'}
          </Button>
          {file === null && currentUrl !== undefined && currentUrl !== '' && (
            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
              <ImageOff aria-hidden className="size-3" />
              Keeping the stored image
            </span>
          )}
        </div>
      )}

      {hint !== undefined && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}