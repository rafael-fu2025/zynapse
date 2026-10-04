/**
 * SkippedPatientsTab — the Skipped Patients module (October 2026 panel
 * revision).
 *
 * A patient who has not arrived is *skipped* rather than no-showed: the
 * row leaves the active queue, opens a 60-minute recall window, and
 * lands here. Staff can bring them back (`Return to Queue` → waiting,
 * `Call Again` → called) or resolve the visit by hand (`Mark No-Show`).
 * If nobody acts, the backend sweep flips the visit to No-Show the
 * moment the window lapses.
 *
 * Why the countdown ticks locally but the DEADLINE is server-owned:
 * a frontend-only timer would reset on reload and could not fire with
 * the page closed, which is exactly what the panel ruled out. The
 * server stamps `skip_deadline_at` once; this component renders the
 * remainder against it and re-polls every 5s, so a resolved row
 * (swept no-show, returned elsewhere) appears on every open tab
 * without a manual refresh.
 *
 * Clock skew: the feed carries `server_now`, so the tick is offset
 * against the server's clock rather than trusting the workstation.
 */
import {
  CalendarClock,
  ChevronDown,
  Loader2,
  PhoneCall,
  RotateCcw,
  Stethoscope,
  UserX,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { MobileCard, MobileCardActions, MobileCardField, MobileCardList, MobileCardListState } from '@/components/MobileCardList';
import { PatientIdCell } from '@/components/PatientIdCell';
import { TableStateRows } from '@/components/TableStates';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useEncounterNoShow } from '@/hooks/useClinic';
import { useQueueTransition, useSkippedPatients } from '@/hooks/useQueue';
import type { SkippedPatient } from '@/schemas/queue';
import { fmtUtcToApp, parseUtc } from '@/utils/date';
import {
  isActionable,
  remainingLabel,
  remainingMinutes,
  skipStatusLabel,
  skipStatusVariant,
  windowProgress,
} from '@/lib/queueSkip';
import { cn } from '@/lib/utils';

interface SkippedPatientsTabProps {
  onOpenEncounter: (encounterId: number) => void;
}

/**
 * Re-render on an interval so the countdown advances between polls.
 * One ticker for the whole tab (not per row) — 60 rows each owning a
 * `setInterval` would be 60 timers fighting for the same frame.
 */
function useNowTick(intervalMs = 1_000): Date {
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), intervalMs);
    return () => window.clearInterval(id);
  }, [intervalMs]);
  return now;
}

/**
 * Server-corrected "now". The feed's `server_now` is stamped when the
 * response was built; the delta against the client clock at that moment
 * is the skew, which we then apply on every tick. Without this, a
 * workstation with a fast clock shows windows expiring early (and a
 * slow one shows `Expired` rows that the backend still considers live).
 */
function useServerClock(serverNow: string | undefined, tick: Date): Date {
  const skewOffsetMs = useMemo(() => {
    if (serverNow === undefined) return 0;
    const parsed = parseUtc(serverNow);
    return Number.isNaN(parsed.getTime()) ? 0 : parsed.getTime() - Date.now();
  }, [serverNow]);

  return skewOffsetMs === 0 ? tick : new Date(tick.getTime() + skewOffsetMs);
}

export function SkippedPatientsTab({ onOpenEncounter }: SkippedPatientsTabProps) {
  const skipped = useSkippedPatients();
  const transition = useQueueTransition();
  const noShow = useEncounterNoShow();
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);

  const tick = useNowTick();
  const now = useServerClock(skipped.data?.server_now, tick);

  const rows = useMemo(() => skipped.data?.data ?? [], [skipped.data]);

  // Active windows first (soonest deadline on top — the backend already
  // orders them that way, but a resolved row that flips mid-view must
  // not jump above the work still to do), then the resolved history.
  const sortedRows = useMemo(() => {
    const rank = (r: SkippedPatient): number => (isActionable(r.status) ? 0 : 1);
    return [...rows].sort((a, b) => {
      const ra = rank(a);
      const rb = rank(b);
      if (ra !== rb) return ra - rb;
      if (ra === 0) return a.position - b.position;
      return 0;
    });
  }, [rows]);

  const anyPending = transition.isPending || noShow.isPending;

  const returnToQueue = (row: SkippedPatient): void =>
    transition.mutate({ id: row.id, action: 'return' });
  const callAgain = (row: SkippedPatient): void =>
    transition.mutate({ id: row.id, action: 'recall' });

  const remainingCell = (row: SkippedPatient) => {
    if (!isActionable(row.status)) {
      return <span className="text-xs text-muted-foreground">—</span>;
    }
    const ms = remainingMinutes(row.skip_deadline_at, now);
    const progress = windowProgress(row.skip_deadline_at, now);
    const urgent = ms !== null && ms <= 10;
    return (
      <div className="min-w-[7rem] space-y-1">
        <span
          className={cn('tabular-nums text-sm font-semibold', urgent ? 'text-destructive' : 'text-foreground')}
        >
          {remainingLabel(row.skip_deadline_at, now)}
        </span>
        {progress !== null && (
          <div
            className="h-1 w-full overflow-hidden rounded-full bg-muted"
            role="progressbar"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={Math.round(progress * 100)}
            aria-label={`Recall window remaining for ${row.queue_number}`}
          >
            <div
              className={cn('h-full rounded-full transition-[width] duration-1000 ease-linear', urgent ? 'bg-destructive' : 'bg-amber-500')}
              style={{ width: `${Math.round(progress * 100)}%` }}
            />
          </div>
        )}
      </div>
    );
  };

  const actions = (row: SkippedPatient) => {
    if (!isActionable(row.status)) {
      return (
        <Button size="sm" variant="outline" disabled={anyPending} onClick={() => onOpenEncounter(row.encounter_id)}>
          <Stethoscope /> Open encounter
        </Button>
      );
    }

    return (
      <div className="flex flex-wrap justify-end gap-1">
        <Button size="sm" variant="secondary" disabled={anyPending} onClick={() => callAgain(row)}>
          <PhoneCall /> Call Again
        </Button>
        <Button
          size="sm"
          variant="outline"
          disabled={anyPending}
          onClick={() =>
            setConfirm({
              title: `Return ${row.queue_number} to the queue?`,
              description: `${row.display_name} goes back into today's active queue as Waiting. The countdown stops and the visit stays open.`,
              confirmLabel: 'Return to Queue',
              run: () => returnToQueue(row),
            })
          }
        >
          <RotateCcw /> Return to Queue
        </Button>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button className="min-h-11" size="sm" variant="outline" aria-label={`Skipped patient actions for ${row.queue_number}`}>
              Actions <ChevronDown className="size-3.5" aria-hidden />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            <DropdownMenuItem className="min-h-11" disabled={anyPending} onSelect={() => onOpenEncounter(row.encounter_id)}>
              <Stethoscope /> Open encounter workspace
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              className="min-h-11 text-destructive focus:text-destructive"
              disabled={anyPending || row.encounter_status !== 'open'}
              onSelect={() =>
                setConfirm({
                  title: `Mark ${row.queue_number} as no-show?`,
                  description: `${row.display_name}'s visit closes with outcome=no_show, any linked appointment advances to no_show, and the encounter moves to Closed. This cannot be undone from here.`,
                  confirmLabel: 'Mark No-Show',
                  run: () => noShow.mutate(row.encounter_id),
                })
              }
            >
              <UserX /> Mark No-Show
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    );
  };

  return (
    <div className="space-y-4">
      <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
        <Table ariaLabel="Skipped clinic patients">
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">Pos</TableHead>
              <TableHead className="px-3">Patient</TableHead>
              <TableHead className="px-3">Appointment</TableHead>
              <TableHead className="px-3">Skipped</TableHead>
              <TableHead className="px-3">Remaining</TableHead>
              <TableHead className="px-3">Status</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableStateRows
              colSpan={7}
              isLoading={skipped.isLoading}
              isError={skipped.isError}
              isEmpty={rows.length === 0}
              onRetry={() => void skipped.refetch()}
              pending={skipped.isFetching}
              errorMessage="Failed to load skipped patients."
              loadingLabel="Loading skipped patients"
              empty={{
                title: 'No skipped patients today.',
                description: 'Patients skipped from the queue appear here with their 60-minute recall window.',
              }}
            />
            {sortedRows.map((row) => (
              <TableRow key={row.id} className={isActionable(row.status) ? undefined : 'opacity-70'}>
                <TableCell className="px-3 tabular-nums text-sm font-semibold">{row.queue_number}</TableCell>
                <TableCell className="px-3">
                  {row.display_name}
                  <span className="ml-1.5">
                    <PatientIdCell id={row.patient_school_id} name={row.patient_name} />
                  </span>
                </TableCell>
                <TableCell className="px-3 text-xs">
                  {row.appointment_id === null ? (
                    <span className="text-muted-foreground">Walk-in</span>
                  ) : (
                    <span className="inline-flex items-center gap-1">
                      <CalendarClock className="size-3 text-muted-foreground" aria-hidden />
                      {row.appointment_at === null ? `Appt #${row.appointment_id}` : fmtUtcToApp(row.appointment_at)}
                    </span>
                  )}
                </TableCell>
                <TableCell className="px-3 text-xs text-muted-foreground">
                  {row.skipped_at === null ? '—' : fmtUtcToApp(row.skipped_at)}
                </TableCell>
                <TableCell className="px-3">{remainingCell(row)}</TableCell>
                <TableCell className="px-3">
                  <Badge variant={skipStatusVariant(row.status)}>{skipStatusLabel(row.status)}</Badge>
                </TableCell>
                <TableCell className="px-3 text-right">{actions(row)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

      {/* Mobile: cards from the same rows. */}
      <MobileCardList>
        <MobileCardListState
          isLoading={skipped.isLoading}
          isError={skipped.isError}
          isEmpty={rows.length === 0}
          onRetry={() => void skipped.refetch()}
          pending={skipped.isFetching}
          errorMessage="Failed to load skipped patients."
          loadingLabel="Loading skipped patients"
          empty={{
            title: 'No skipped patients today.',
            description: 'Patients skipped from the queue appear here with their 60-minute recall window.',
          }}
        />
        {sortedRows.map((row) => (
          <MobileCard key={row.id} aria-label={`Skipped patient ${row.queue_number}`}>
            <div className="mb-1 flex items-center justify-between gap-2">
              <span className="tabular-nums text-sm font-semibold text-foreground">{row.queue_number}</span>
              <Badge variant={skipStatusVariant(row.status)}>{skipStatusLabel(row.status)}</Badge>
            </div>
            <p className="text-sm font-medium text-foreground">{row.display_name}</p>
            <p className="tabular-nums text-[0.625rem] text-muted-foreground">
              <PatientIdCell id={row.patient_school_id} name={row.patient_name} />
            </p>
            <MobileCardField label="Appointment">
              <span className="text-xs">
                {row.appointment_id === null
                  ? 'Walk-in'
                  : row.appointment_at === null
                    ? `Appt #${row.appointment_id}`
                    : fmtUtcToApp(row.appointment_at)}
              </span>
            </MobileCardField>
            <MobileCardField label="Skipped">
              <span className="text-xs text-muted-foreground">
                {row.skipped_at === null ? '—' : fmtUtcToApp(row.skipped_at)}
              </span>
            </MobileCardField>
            {isActionable(row.status) && (
              <MobileCardField label="Remaining">{remainingCell(row)}</MobileCardField>
            )}
            <MobileCardActions>{actions(row)}</MobileCardActions>
          </MobileCard>
        ))}
      </MobileCardList>

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        pending={anyPending}
        onConfirm={() => {
          confirm?.run();
          setConfirm(null);
        }}
        onCancel={() => setConfirm(null)}
      />

      {skipped.isFetching && !skipped.isLoading && (
        <p className="flex items-center justify-end gap-1.5 text-xs text-muted-foreground" role="status">
          <Loader2 className="size-3 animate-spin" aria-hidden /> Refreshing…
        </p>
      )}
    </div>
  );
}

/**
 * Small summary strip for the module header — active windows and the
 * nearest deadline at a glance, so staff can triage without scanning
 * every row. Rendered by the page header area.
 */
export function SkippedPatientsSummary(): JSX.Element {
  const skipped = useSkippedPatients();
  const tick = useNowTick();
  const now = useServerClock(skipped.data?.server_now, tick);

  const rows = skipped.data?.data ?? [];
  const active = rows.filter((r) => isActionable(r.status)).length;
  const expiringSoon = rows.filter(
    (r) => isActionable(r.status) && (remainingMinutes(r.skip_deadline_at, now) ?? 999) <= 10,
  ).length;

  return (
    <p className="text-sm text-muted-foreground" aria-live="polite">
      {active === 0
        ? 'No active recall windows.'
        : `${active} active recall window${active === 1 ? '' : 's'}${expiringSoon > 0 ? ` · ${expiringSoon} expiring within 10 minutes` : ''}.`}
    </p>
  );
}
