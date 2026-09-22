import { Check, Circle, Clock3, LockKeyhole } from 'lucide-react';
import { cn } from '@/lib/utils';

export type ProgressStepState = 'complete' | 'current' | 'available' | 'optional' | 'unavailable';

export interface SessionProgressStep {
  id: string;
  label: string;
  state: ProgressStepState;
  summary?: string;
}

/**
 * SessionProgressTracker — a cardless node rail.
 *
 * Each step is a bare node (status icon in a ring) with its label shown
 * ONLY while the step is selected — the label sits above the node and
 * truncates, so long titles ("Care / Treatment") can never deform the
 * rail. Summaries never render here; they belong to the detail pane
 * the workspace shows below, which switches on the same `selected` id.
 *
 * Geometry contract — read before editing:
 *
 *  - The rail is ONE fluid flex row of equal-width cells
 *    (`flex-1 min-w-0`). Nothing in here has a fixed width except the
 *    28px node itself, and `min-w-0` sits on the nav, the list and every
 *    cell. That chain is what stops the rail's min-content width from
 *    being propagated up to the dialog's grid track — without it the
 *    track grows to the rail's intrinsic width and the dialog sprouts a
 *    horizontal scrollbar (the dialog is `overflow-y-auto`, which makes
 *    the computed `overflow-x` `auto` too).
 *  - Labels are absolutely positioned INSIDE a fixed-height slot, not
 *    laid out in flow. This is load-bearing: `min-w-0` cancels a flex
 *    item's *automatic minimum size* but not its *min-content
 *    contribution*, so a nowrap label in flow makes its cell report the
 *    label's full text width (~94px for "Care / Treatment") as its
 *    intrinsic width. Six of those sum to ~397px and the rail can never
 *    shrink below that, however narrow the viewport. The slot keeps the
 *    16px label band reserved so selecting a step still cannot shift the
 *    rail, while contributing zero intrinsic width.
 *  - Each cell stacks [label slot] over [node row]. The node row is the
 *    positioning context for the connector, and the connector is pinned
 *    with `top-1/2 -translate-y-1/2` — so the line is centred on the node
 *    by construction, not by a hand-tuned pixel offset. (The old version
 *    used `mt-[13px]` against a 36px-tall node centre, leaving the line
 *    floating 22px above every node.)
 *  - The connector runs `left-1/2 w-full`: from this node's centre to the
 *    next node's centre. The node paints over its tail (it is positioned
 *    and later in DOM order), so the line meets each node edge-to-edge
 *    with no gap and never overshoots past a node.
 *
 * Motion: the connector between two nodes fills left → right when the
 * preceding step completes, nodes pop (zoom + fade) in on first render
 * with a stagger, and state/colour changes ease via transitions. All
 * animation classes come from tw-animate-css.
 */
export function SessionProgressTracker({
  steps,
  selected,
  onSelect,
}: {
  steps: SessionProgressStep[];
  selected: string;
  onSelect: (id: string) => void;
}) {
  return (
    <nav aria-label="Session progress" className="min-w-0">
      {/* `pb-1` reserves room for the selected node's `ring-2 ring-offset-2`,
          which paints 4px outside the node's box. */}
      <ol className="flex w-full min-w-0 items-start pb-1">
        {steps.map((step, index) => {
          const disabled = step.state === 'unavailable';
          const Icon = step.state === 'complete' ? Check : step.state === 'current' ? Clock3 : disabled ? LockKeyhole : Circle;
          const isLast = index === steps.length - 1;
          return (
            <li
              key={step.id}
              className={cn('flex min-w-0 flex-1 flex-col items-center', disabled && 'opacity-55')}
            >
              {/* Label slot — fixed height, zero intrinsic width (the text
                  is out of flow inside it). Always rendered so selecting a
                  step can never shift the rail's height. */}
              <span className="relative h-4 w-full shrink-0">
                <span
                  className={cn(
                    'absolute inset-0 truncate px-0.5 text-center text-xs font-semibold leading-4',
                    selected === step.id ? 'text-foreground' : 'invisible',
                  )}
                >
                  {step.label}
                </span>
              </span>
              {/* Node row — the connector's positioning context. Its height
                  is exactly the node's height, which is what makes
                  `top-1/2` land on the node's centre line. */}
              <span className="relative mt-1.5 flex w-full items-center justify-center">
                {!isLast && (
                  <span
                    aria-hidden
                    className="absolute left-1/2 top-1/2 h-0.5 w-full -translate-y-1/2 overflow-hidden rounded-full bg-border"
                  >
                    <span
                      className={cn(
                        'absolute inset-y-0 left-0 bg-emerald-500 transition-all duration-500 ease-out',
                        step.state === 'complete' ? 'w-full' : 'w-0',
                      )}
                    />
                  </span>
                )}
                <button
                  type="button"
                  disabled={disabled}
                  aria-current={step.state === 'current' ? 'step' : undefined}
                  aria-label={step.state === 'optional' ? `${step.label} (optional)` : step.label}
                  onClick={() => onSelect(step.id)}
                  className={cn(
                    'group relative flex size-7 shrink-0 animate-in items-center justify-center rounded-full border bg-card fade-in zoom-in-75 duration-300 fill-mode-backwards',
                    step.state === 'complete' && 'border-emerald-500 bg-emerald-500 text-white',
                    step.state === 'current' && 'border-primary text-primary',
                    selected === step.id && 'ring-2 ring-primary ring-offset-2 ring-offset-background',
                    !disabled && 'group-hover:scale-110',
                    disabled && 'cursor-not-allowed',
                  )}
                  style={{ animationDelay: `${index * 60}ms` }}
                >
                  <Icon className="size-3.5" />
                </button>
              </span>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
