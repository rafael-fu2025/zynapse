import { Button } from '@/components/ui/button';
import { schedulingStaffError } from '@/hooks/useSchedulingStaff';

/** Keep directory failures distinguishable from an empty eligible-provider list. */
export function SchedulingProviderState({ query }: { query: {
  isLoading: boolean;
  isError: boolean;
  isFetching: boolean;
  error: unknown;
  data: readonly unknown[] | undefined;
  refetch: () => unknown;
} }) {
  if (query.isLoading) return <p role="status" className="text-xs text-muted-foreground">Loading eligible enrolled staff…</p>;
  if (query.isError) return <div role="alert" className="space-y-1 text-xs text-destructive">
    <p>{schedulingStaffError(query.error)} If unavailable after deployment, ask an administrator to verify the scheduling-staff assignments migration.</p>
    <Button type="button" size="sm" variant="outline" disabled={query.isFetching} onClick={() => void query.refetch()}>Retry staff directory</Button>
  </div>;
  if (query.data?.length === 0) return <p role="status" className="text-xs text-muted-foreground">No eligible enrolled staff. Ask the scheduling team manager to review enrollment, local directory sync and configured department aliases.</p>;
  return null;
}
