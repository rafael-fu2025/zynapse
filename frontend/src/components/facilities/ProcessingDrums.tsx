/**
 * ProcessingDrums — the at-a-glance grid of every drum with a live
 * batch, plus the count in its header.
 *
 * Lifted out of the facilities page so the page file is not the place
 * that decides what a loading or empty drum grid looks like. State
 * rendering is delegated to `TableStateBlock`, the same component the
 * drum and device tables use, so the four surfaces in this module
 * cannot drift apart.
 */
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TableStateBlock } from '@/components/TableStates';
import { DrumArt, DrumCard } from './DrumCard';
import type { ActiveBatch } from '@/schemas/facilities';

export interface ProcessingDrumsProps {
  batches: ActiveBatch[];
  /** Turning cadence from the API; null until the payload loads. */
  turningDueDays: number | null;
  isLoading: boolean;
  isError: boolean;
  isFetching: boolean;
  onRetry: () => void;
}

export function ProcessingDrums({
  batches,
  turningDueDays,
  isLoading,
  isError,
  isFetching,
  onRetry,
}: ProcessingDrumsProps) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0 pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <DrumArt className="size-5" />
          Processing Drums
        </CardTitle>
        <Badge
          variant={batches.length > 0 ? 'warning' : 'secondary'}
          className="tabular-nums"
        >
          {batches.length} active
        </Badge>
      </CardHeader>

      <CardContent>
        {batches.length > 0 ? (
          <ul
            className="grid grid-cols-[repeat(auto-fill,minmax(220px,1fr))] gap-3"
            aria-label="Drums with an active batch"
          >
            {batches.map((batch) => (
              <li key={batch.batch_id}>
                <DrumCard batch={batch} turningDueDays={turningDueDays} className="h-full" />
              </li>
            ))}
          </ul>
        ) : (
          <TableStateBlock
            isLoading={isLoading}
            isError={isError}
            isEmpty
            onRetry={onRetry}
            pending={isFetching}
            errorMessage="Failed to load active drums."
            loadingLabel="Loading active drums"
            empty={{
              title: 'No drums currently processing.',
              description: 'Start a batch on an idle unit to begin composting.',
            }}
          />
        )}
      </CardContent>
    </Card>
  );
}
