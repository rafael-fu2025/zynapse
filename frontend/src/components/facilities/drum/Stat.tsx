/**
 * Stat — one label/value tile for the drum page.
 *
 * Every readout on the drum page (batch summary, analytics, controller
 * state) uses this single tile so the screen has one visual rhythm
 * instead of a different bordered box per card. Render inside a `<dl>`.
 */
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface StatProps {
  label: string;
  value: ReactNode;
  /** Secondary line under the value. */
  hint?: ReactNode;
  valueTone?: 'default' | 'danger';
  hintTone?: 'muted' | 'danger';
  className?: string;
}

export function Stat({
  label,
  value,
  hint,
  valueTone = 'default',
  hintTone = 'muted',
  className,
}: StatProps) {
  return (
    <div className={cn('min-w-0 space-y-0.5 rounded-md bg-muted/40 px-3 py-2', className)}>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd
        className={cn(
          'break-words text-sm font-semibold leading-snug tabular-nums',
          valueTone === 'danger' && 'text-destructive',
        )}
      >
        {value}
      </dd>
      {hint != null && (
        <dd
          className={cn(
            'text-xs leading-snug',
            hintTone === 'danger' ? 'text-destructive' : 'text-muted-foreground',
          )}
        >
          {hint}
        </dd>
      )}
    </div>
  );
}
