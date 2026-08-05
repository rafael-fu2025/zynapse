/**
 * PatientIdCell — id number with a hover tooltip showing the matching
 * person's name (from the unified registry). No tooltip when no name
 * is on file (guest walk-in / orphaned row).
 *
 * Shared across the Clinic tabs (Queue / Closed / Staff schedules) and
 * the Appointments table so the hover-to-identify affordance stays
 * consistent app-wide.
 */
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

export function PatientIdCell({ id, name }: { id: string; name?: string | null | undefined }) {
  const trimmed = name?.trim();
  if (!trimmed) {
    return <span className="font-mono text-xs">{id}</span>;
  }
  return (
    <TooltipProvider delayDuration={150}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="cursor-help font-mono text-xs underline decoration-dotted underline-offset-2">
            {id}
          </span>
        </TooltipTrigger>
        <TooltipContent>{trimmed}</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}
