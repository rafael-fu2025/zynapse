/**
 * GuidanceRecordSection — the per-person guidance record (counselling
 * session history) shown on the GUIDANCE side of a registry detail.
 *
 * Data separation (2026-10): this is the employee/student parity surface
 * for "View/Manage Guidance Record". It renders ONLY for holders of
 * `counselling.records.read` — a clinic user does not get the guidance
 * record from clinic permissions, and vice versa. The backend session
 * endpoint enforces the same code, so the UI gate and the authority agree.
 *
 * Notes are deliberately NOT part of this section: they stay on their own
 * session endpoints behind the per-session access policy.
 */
import { ClipboardList } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { useCan } from '@/hooks/useCan';
import { usePatientSessions } from '@/hooks/useCounselling';
import { fmtUtcToApp } from '@/utils/date';

export function GuidanceRecordSection({
  patientSchoolId,
}: {
  patientSchoolId: string | null | undefined;
}) {
  const canView = useCan('counselling.records.read');
  const sessions = usePatientSessions(patientSchoolId ?? null, canView);
  // `typeof === 'string'` also rejects `undefined` — a plain
  // `!== null && !== ''` guard let `undefined` through and rendered an
  // empty record for a person with no identifier (caught by test, 2026-10).
  const visible = canView && typeof patientSchoolId === 'string' && patientSchoolId !== '';

  if (!visible) return null;

  return (
    <section className="space-y-2" aria-label="Guidance record">
      <h3 className="flex items-center gap-1.5 text-sm font-semibold">
        <ClipboardList className="size-4" /> Guidance record
      </h3>
      {sessions.isLoading && <Skeleton className="h-10" />}
      {sessions.isError && (
        <p className="text-xs text-destructive">Failed to load the guidance record.</p>
      )}
      {sessions.data !== undefined && sessions.data.length === 0 && (
        <p className="text-xs text-muted-foreground">No counselling sessions on record.</p>
      )}
      {(sessions.data ?? []).map((s) => (
        <div key={s.id} className="flex items-center justify-between gap-2 rounded-lg border bg-background p-2.5">
          <div className="min-w-0">
            <p className="text-xs font-medium text-foreground">{fmtUtcToApp(s.started_at, 'MMM d, yyyy')}</p>
            <p className="truncate text-xs text-muted-foreground">{s.counsellor_name ?? 'Unassigned'}</p>
          </div>
          <Badge variant={s.ended_at !== null ? 'secondary' : 'success'}>
            {s.ended_at !== null ? 'Closed' : 'Open'}
          </Badge>
        </div>
      ))}
    </section>
  );
}
