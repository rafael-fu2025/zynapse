/**
 * Drum automation — the UNIT's device surface, not the batch's: board
 * liveness, RTC health, the run schedule with its next run, operator
 * Run now / Stop, and the recent run history with missed-slot callouts.
 *
 * It renders whether or not a batch is active — a board that is offline
 * between batches is exactly the state staff need to see. Schedule edits
 * and run/stop commands are admin-gated (`facilities.units.manage`).
 *
 * `DeviceStatusPill` is the one-glance version for the page header, so
 * an offline controller is visible from every tab.
 */
import { Calendar, Gauge, Loader2, Power, RotateCw, Wifi, WifiOff } from 'lucide-react';
import { useId, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  useSaveSchedule,
  useSendDeviceCommand,
  useUnitMonitor,
  useUnitSchedule,
} from '@/hooks/useFacilities';
import { useCan } from '@/hooks/useCan';
import {
  describeClockDrift,
  describeHeartbeatAge,
  describeNextRun,
  describeRunResult,
} from '@/lib/bmgFormat';
import { cn, titleCase } from '@/lib/utils';
import {
  MAX_PWM,
  MAX_RUN_DURATION_SECONDS,
  MAX_SCHEDULE_TIMES,
  saveScheduleSchema,
} from '@/schemas/facilities';
import { fmtUtcToApp } from '@/utils/date';
import { Stat } from './Stat';

const RUNS_COLLAPSED = 5;
const RUNS_MAX = 10;

/** "09:00, 17:00" for a schedule time list, or a dash. */
function scheduleSummary(times: string[]): string {
  return times.length === 0 ? '—' : times.join(', ');
}

/** Schedule editor — admin-only, publishes a new version to the board. */
function ScheduleDialog({ unitId, onClose }: { unitId: number; onClose: () => void }) {
  const schedule = useUnitSchedule(unitId);
  const save = useSaveSchedule(unitId);
  const current = schedule.data?.schedule;
  const [timesText, setTimesText] = useState<string | null>(null);
  const [duration, setDuration] = useState<string | null>(null);
  const [pwm, setPwm] = useState<string | null>(null);
  const [enabled, setEnabled] = useState<boolean | null>(null);

  // Seed the form from the loaded schedule the first time it arrives;
  // null means "not seeded yet" so the operator's typing is never reset.
  const times = timesText ?? (current?.times.join(', ') ?? '');
  const durationValue = duration ?? String(current?.duration_seconds ?? 600);
  const pwmValue = pwm ?? String(current?.pwm ?? 200);
  const enabledValue = enabled ?? current?.enabled ?? false;
  const [errors, setErrors] = useState<string[]>([]);
  const timesId = useId();
  const durationId = useId();
  const pwmId = useId();

  function submit() {
    const parsed = saveScheduleSchema.safeParse({
      times: times.split(',').map((t) => t.trim()),
      duration_seconds: durationValue,
      pwm: pwmValue,
      enabled: enabledValue,
    });
    if (!parsed.success) {
      setErrors(parsed.error.issues.map((i) => i.message));
      return;
    }
    setErrors([]);
    save.mutate(parsed.data, { onSuccess: onClose });
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Run schedule</DialogTitle>
          <DialogDescription>
            Up to {MAX_SCHEDULE_TIMES} daily run times in Manila time. The drum applies changes on its
            next check-in (~10 min) — publishing bumps the version the board polls for.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div className="space-y-1.5">
            <Label htmlFor={timesId}>Run times (24-hour HH:MM, comma-separated)</Label>
            <Input
              id={timesId}
              placeholder="09:00, 12:00, 17:00"
              value={times}
              onChange={(e) => setTimesText(e.target.value)}
            />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor={durationId}>Run duration (seconds)</Label>
              <Input
                id={durationId}
                type="number"
                min={1}
                max={MAX_RUN_DURATION_SECONDS}
                step={1}
                value={durationValue}
                onChange={(e) => setDuration(e.target.value)}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor={pwmId}>Motor speed (PWM 0–{MAX_PWM})</Label>
              <Input
                id={pwmId}
                type="number"
                min={0}
                max={MAX_PWM}
                step={1}
                value={pwmValue}
                onChange={(e) => setPwm(e.target.value)}
              />
            </div>
          </div>
          <div className="flex items-center justify-between rounded-md border p-3">
            <div>
              <p className="text-sm font-medium">Schedule enabled</p>
              <p className="text-xs text-muted-foreground">Off keeps the settings but runs nothing.</p>
            </div>
            <Checkbox
              checked={enabledValue}
              onCheckedChange={(checked) => setEnabled(checked === true)}
              aria-label="Schedule enabled"
            />
          </div>
          {errors.length > 0 && (
            <ul className="space-y-1 rounded-md border border-destructive/30 bg-destructive/5 p-2 text-xs text-destructive">
              {errors.map((message) => (
                <li key={message}>{message}</li>
              ))}
            </ul>
          )}
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button onClick={submit} disabled={save.isPending}>
              {save.isPending && <Loader2 className="animate-spin" />}
              Publish schedule
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}

/**
 * One-glance controller state for the page header. Deliberately says only
 * online / offline — the detail (last check-in, RTC, schedule) lives in
 * the Automation panel it links to.
 */
export function DeviceStatusPill({ unitId, onClick }: { unitId: number; onClick: () => void }) {
  const monitor = useUnitMonitor(unitId);
  if (monitor.data === undefined) return null;

  const device = monitor.data.device;
  const state =
    device === null || device === undefined
      ? { label: 'No controller', variant: 'secondary' as const, Icon: WifiOff }
      : device.online
        ? { label: 'Controller online', variant: 'success' as const, Icon: Wifi }
        : { label: 'Controller offline', variant: 'destructive' as const, Icon: WifiOff };

  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={`${state.label}. Open automation`}
      className="rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <Badge variant={state.variant} className="gap-1.5 py-1">
        <state.Icon className="size-3" aria-hidden /> {state.label}
      </Badge>
    </button>
  );
}

export function AutomationPanel({ unitId }: { unitId: number }) {
  const monitor = useUnitMonitor(unitId);
  const sendCommand = useSendDeviceCommand();
  const canManage = useCan('facilities.units.manage');
  const [scheduleOpen, setScheduleOpen] = useState(false);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const [showAllRuns, setShowAllRuns] = useState(false);

  const data = monitor.data;
  const device = data?.device ?? null;
  const heartbeat = describeHeartbeatAge(device?.heartbeat_age_minutes, device?.offline_after_minutes);
  const drift = describeClockDrift(device?.rtc_valid, device?.rtc_offset_seconds);
  const nextRun = describeNextRun(data?.schedule.next_run_at, data?.schedule.enabled);

  const rssi =
    device?.last_rssi_dbm !== null && device?.last_rssi_dbm !== undefined
      ? ` · WiFi ${device.last_rssi_dbm} dBm`
      : '';
  const runs = data?.runs.recent.slice(0, RUNS_MAX) ?? [];
  const visibleRuns = showAllRuns ? runs : runs.slice(0, RUNS_COLLAPSED);

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0 pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          {device?.online === true ? (
            <Wifi className="size-4 text-primary" />
          ) : (
            <WifiOff className="size-4 text-muted-foreground" />
          )}
          Drum automation
          {data !== undefined && (
            <Badge variant={data.schedule.enabled ? 'info' : 'secondary'}>
              {data.schedule.enabled ? `Schedule v${data.schedule.version}` : 'Schedule off'}
            </Badge>
          )}
        </CardTitle>
        {data !== undefined && canManage && (
          <div className="flex flex-wrap gap-2">
            {device !== null && (
              <>
                <Button
                  size="sm"
                  disabled={sendCommand.isPending}
                  onClick={() =>
                    setConfirm({
                      title: 'Run the drum now?',
                      description:
                        'Queues a one-off run with the current schedule settings. The drum starts within about 10 minutes of its next check-in.',
                      confirmLabel: 'Run now',
                      destructive: false,
                      run: () => sendCommand.mutate({ deviceId: device.id, command: 'run_now' }),
                    })
                  }
                >
                  <RotateCw /> Run now
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={sendCommand.isPending}
                  onClick={() =>
                    setConfirm({
                      title: 'Stop the drum?',
                      description: 'Commands the controller to stop the motor as soon as it checks in.',
                      confirmLabel: 'Stop',
                      destructive: true,
                      run: () => sendCommand.mutate({ deviceId: device.id, command: 'stop' }),
                    })
                  }
                >
                  <Power /> Stop
                </Button>
              </>
            )}
            <Button size="sm" variant="outline" onClick={() => setScheduleOpen(true)}>
              <Calendar /> Edit schedule
            </Button>
          </div>
        )}
      </CardHeader>

      <CardContent className="space-y-4">
        {monitor.isError && (
          <p className="rounded-md border border-destructive/30 bg-destructive/5 p-2 text-xs text-destructive">
            Failed to load the drum's automation state.
          </p>
        )}
        {monitor.isLoading && (
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" /> Loading…
          </div>
        )}

        {data !== undefined && (
          <>
            <dl className="grid gap-2 sm:grid-cols-3">
              <Stat
                label="Controller"
                value={device === null ? 'No board bound' : device.display_name}
                hint={`${heartbeat.label}${rssi}`}
                hintTone={heartbeat.tone === 'danger' ? 'danger' : 'muted'}
              />
              <Stat
                label="Clock"
                value={drift.label}
                valueTone={drift.tone === 'danger' ? 'danger' : 'default'}
                hint={
                  device?.motor_state !== null && device?.motor_state !== undefined
                    ? `Motor ${device.motor_state}`
                    : 'Motor state unknown'
                }
              />
              <Stat
                label="Next run"
                value={nextRun.label}
                hint={data.schedule.times.length > 0 ? scheduleSummary(data.schedule.times) : 'No times set'}
              />
            </dl>

            {data.runs.missed_count > 0 && (
              <p className="rounded-md border border-amber-500/40 bg-amber-500/5 p-2 text-xs text-amber-600">
                {data.runs.missed_count} scheduled run{data.runs.missed_count === 1 ? '' : 's'} missed:{' '}
                {data.runs.missed_times.slice(0, 4).join(', ')}
                {data.runs.missed_times.length > 4 ? '…' : ''}
              </p>
            )}

            {runs.length > 0 && (
              <section className="space-y-2">
                <div className="flex items-center gap-2">
                  <Gauge className="size-4 text-primary" />
                  <h3 className="text-sm font-medium">Recent runs</h3>
                  {data.runs.failed_count > 0 && (
                    <Badge variant="destructive">{data.runs.failed_count} not completed</Badge>
                  )}
                </div>
                <ul className="divide-y rounded-md border text-sm">
                  {visibleRuns.map((run) => {
                    const result = describeRunResult(run.result);
                    return (
                      <li key={run.id} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-3 py-2">
                        <span className="text-muted-foreground">
                          {fmtUtcToApp(run.started_at ?? run.created_at)}
                          <span className="ml-2 text-xs">({titleCase(run.origin)})</span>
                        </span>
                        <span className="flex items-center gap-2">
                          <span className="text-xs tabular-nums text-muted-foreground">{run.duration_seconds}s</span>
                          <span
                            className={cn(
                              'text-xs font-medium',
                              result.tone === 'danger'
                                ? 'text-destructive'
                                : result.tone === 'warning'
                                  ? 'text-amber-600'
                                  : 'text-muted-foreground',
                            )}
                          >
                            {result.label}
                          </span>
                          {run.batch_id === null && (
                            <Badge variant="warning" title="No active batch when this run was reported">
                              No batch
                            </Badge>
                          )}
                        </span>
                      </li>
                    );
                  })}
                </ul>
                {runs.length > RUNS_COLLAPSED && (
                  <Button size="sm" variant="ghost" onClick={() => setShowAllRuns((v) => !v)}>
                    {showAllRuns ? 'Show fewer' : `Show all ${runs.length}`}
                  </Button>
                )}
              </section>
            )}
          </>
        )}
      </CardContent>

      {scheduleOpen && <ScheduleDialog unitId={unitId} onClose={() => setScheduleOpen(false)} />}
      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        destructive={confirm?.destructive ?? true}
        pending={sendCommand.isPending}
        onConfirm={() => {
          confirm?.run();
          setConfirm(null);
        }}
        onCancel={() => setConfirm(null)}
      />
    </Card>
  );
}
