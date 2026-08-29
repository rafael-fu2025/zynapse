import { Check, Circle, Clock3, LockKeyhole } from 'lucide-react';
import { cn } from '@/lib/utils';

export type ProgressStepState = 'complete' | 'current' | 'available' | 'optional' | 'unavailable';

export interface SessionProgressStep {
  id: string;
  label: string;
  state: ProgressStepState;
  summary?: string;
}

export function SessionProgressTracker({
  steps,
  selected,
  onSelect,
}: {
  steps: SessionProgressStep[];
  selected: string;
  onSelect: (id: string) => void;
}) {
  return <nav aria-label="Session progress" className="rounded-xl border bg-card p-3">
    <ol className="grid gap-2 md:grid-flow-col md:auto-cols-fr">
      {steps.map((step, index) => {
        const disabled = step.state === 'unavailable';
        const Icon = step.state === 'complete' ? Check : step.state === 'current' ? Clock3 : disabled ? LockKeyhole : Circle;
        return <li key={step.id} className="relative">
          {index > 0 && <span aria-hidden className="absolute -left-2 top-4 hidden h-0.5 w-2 bg-border md:block" />}
          <button
            type="button"
            disabled={disabled}
            aria-current={step.state === 'current' ? 'step' : undefined}
            onClick={() => onSelect(step.id)}
            className={cn(
              'flex min-h-16 w-full items-start gap-2 rounded-lg border px-3 py-2 text-left transition-colors',
              selected === step.id && 'border-primary bg-primary/5',
              step.state === 'complete' && 'border-emerald-500/30 bg-emerald-500/5',
              step.state === 'unavailable' && 'cursor-not-allowed opacity-55',
            )}
          >
            <span className={cn('mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border', step.state === 'complete' && 'border-emerald-500 bg-emerald-500 text-white', step.state === 'current' && 'border-primary text-primary')}>
              <Icon className="size-3.5" />
            </span>
            <span className="min-w-0"><span className="block text-xs font-semibold">{step.label}</span>{step.summary !== undefined && <span className="mt-0.5 block truncate text-[10px] text-muted-foreground">{step.summary}</span>}{step.state === 'optional' && <span className="block text-[10px] text-muted-foreground">Optional</span>}</span>
          </button>
        </li>;
      })}
    </ol>
  </nav>;
}
