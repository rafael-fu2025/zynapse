/**
 * UploadProgress — determinate progress bar for a multipart upload.
 *
 * Two phases, because `onUploadProgress` only measures the request body:
 *   - "Uploading" while bytes go out (0–100% of the body).
 *   - "Processing" once the body is flushed, since the server still has
 *     to MIME-sniff, move and persist the file. Showing 100% and
 *     claiming it is still uploading would be a lie; the label says
 *     what is actually happening instead.
 *
 * `role="progressbar"` + `aria-valuenow` so AT announces the change, and
 * the live region announces the phase text once (not on every tick).
 */
import { CheckCircle2, Loader2 } from 'lucide-react';

interface UploadProgressProps {
  /** Whole percent of the request body sent, 0–100. */
  percent: number;
  /** Body is fully sent; the server is now working. */
  processing: boolean;
  visible: boolean;
}

export function UploadProgress({ percent, processing, visible }: UploadProgressProps) {
  if (!visible) return null;

  const value = Math.min(100, Math.max(0, Math.round(percent)));
  const done = processing && value >= 100;

  return (
    <div className="space-y-1.5 rounded-lg border border-border bg-muted/30 p-3">
      <div className="flex items-center justify-between gap-2">
        <span
          role="status"
          aria-live="polite"
          className="flex items-center gap-1.5 text-xs font-medium"
        >
          {done ? (
            <CheckCircle2 aria-hidden className="size-3.5 text-emerald-600" />
          ) : (
            <Loader2 aria-hidden className="size-3.5 animate-spin text-muted-foreground" />
          )}
          {done ? 'Uploaded' : processing ? 'Processing…' : 'Uploading…'}
        </span>
        <span className="font-mono text-xs tabular-nums text-muted-foreground">{value}%</span>
      </div>
      <div
        role="progressbar"
        aria-valuenow={value}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label="Upload progress"
        className="h-1.5 w-full overflow-hidden rounded-full bg-border"
      >
        <div
          className={[
            'h-full rounded-full transition-[width] duration-200 ease-out',
            done ? 'bg-emerald-600' : 'bg-primary',
          ].join(' ')}
          style={{ width: `${value}%` }}
        />
      </div>
      {processing && !done && (
        <p className="text-xs text-muted-foreground">
          File sent. The server is validating and storing it.
        </p>
      )}
    </div>
  );
}