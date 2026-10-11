/**
 * ProcessLogCard — the batch's observation timeline. New entries are
 * made through a dialog so the timeline, not the form, is what the
 * operator sees first.
 */
import { ClipboardList, Loader2, Plus } from 'lucide-react';
import { useId, useState, type ReactNode } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useAddProcessLog, useProcessLogs } from '@/hooks/useFacilities';
import { useCan } from '@/hooks/useCan';
import { titleCase } from '@/lib/utils';
import {
  BMG_PROCESS_EVENT_TYPES,
  MOISTURE_LEVELS,
  type BmgProcessEventType,
  type MoistureLevel,
  type ProcessLog,
} from '@/schemas/facilities';
import { fmtHumanDate, fmtUtcToApp } from '@/utils/date';
import { FormDialog } from './FormDialog';

type Calibration = 'ok' | 'due' | 'overdue' | 'unset';

/** Measurements attached to one log entry, as compact chips. */
function LogChips({ log }: { log: ProcessLog }) {
  const chips: ReactNode[] = [];
  if (log.temperature_celsius !== null) {
    chips.push(<Badge key="temp" variant="info">{log.temperature_celsius}°C</Badge>);
  }
  if (log.moisture_level !== null) {
    chips.push(
      <Badge key="moisture" variant={log.moisture_level === 'normal' ? 'success' : 'warning'}>
        {titleCase(log.moisture_level)}
      </Badge>,
    );
  }
  if (log.oxygen_pct !== null && log.oxygen_pct !== undefined) {
    chips.push(<Badge key="o2" variant="secondary">O₂ {log.oxygen_pct}%</Badge>);
  }
  if (log.calibration_status !== null && log.calibration_status !== undefined) {
    chips.push(
      <Badge
        key="cal"
        variant={
          log.calibration_status === 'ok' ? 'success' : log.calibration_status === 'due' ? 'warning' : 'destructive'
        }
      >
        cal {titleCase(log.calibration_status)}
      </Badge>,
    );
  }
  if (log.turns_count !== null && log.turns_count !== undefined) {
    const seconds =
      log.duration_seconds !== null && log.duration_seconds !== undefined ? ` · ${log.duration_seconds}s` : '';
    chips.push(
      <Badge key="turns" variant="outline" className="tabular-nums">
        {log.turns_count} rotations{seconds}
      </Badge>,
    );
  }
  if (log.session_started_at !== null && log.session_started_at !== undefined) {
    chips.push(
      <Badge key="session" variant="outline" className="tabular-nums" title="RTC-stamped session start">
        started {fmtUtcToApp(log.session_started_at, 'h:mm a')}
      </Badge>,
    );
  }
  if (log.device_id !== null && log.device_id !== undefined) {
    chips.push(<Badge key="device" variant="outline">{log.device_id}</Badge>);
  }
  if (chips.length === 0) return null;
  return <div className="flex flex-wrap gap-1 [&>div]:font-normal">{chips}</div>;
}

export function ProcessLogCard({ batchId }: { batchId: number }) {
  const logs = useProcessLogs(batchId);
  const add = useAddProcessLog();
  const canRecord = useCan('facilities.bmg.logs.record');
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState('');
  const [temp, setTemp] = useState('');
  const [moisture, setMoisture] = useState<MoistureLevel | 'unset'>('unset');
  // Audit #6: event_type records WHAT was done (turning, aeration…).
  const [eventType, setEventType] = useState<BmgProcessEventType | 'unset'>('unset');
  // Tier 2.2 observability fields — only submitted when populated so the
  // simple "note only" path is unaffected.
  const [oxygen, setOxygen] = useState('');
  const [deviceId, setDeviceId] = useState('');
  const [calibration, setCalibration] = useState<Calibration>('unset');
  const noteId = useId();
  const tempId = useId();
  const oxygenId = useId();
  const deviceIdId = useId();
  const calibrationId = useId();
  const eventTypeId = useId();
  const moistureId = useId();

  const entries = logs.data ?? [];

  function reset() {
    setNote('');
    setTemp('');
    setMoisture('unset');
    setEventType('unset');
    setOxygen('');
    setDeviceId('');
    setCalibration('unset');
  }

  function submit() {
    if (
      note.trim() === '' &&
      temp.trim() === '' &&
      moisture === 'unset' &&
      eventType === 'unset' &&
      oxygen.trim() === '' &&
      deviceId.trim() === '' &&
      calibration === 'unset'
    ) {
      toast.error('Enter at least one observation field.');
      return;
    }
    add.mutate(
      {
        batchId,
        input: {
          observation_note: note.trim(),
          temperature_celsius: temp.trim(),
          ...(moisture !== 'unset' ? { moisture_level: moisture } : {}),
          ...(eventType !== 'unset' ? { event_type: eventType } : {}),
          ...(oxygen.trim() !== '' ? { oxygen_pct: oxygen.trim() } : {}),
          ...(deviceId.trim() !== '' ? { device_id: deviceId.trim() } : {}),
          ...(calibration !== 'unset' ? { calibration_status: calibration } : {}),
        },
      },
      {
        onSuccess: () => {
          reset();
          setOpen(false);
        },
      },
    );
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0 pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <ClipboardList className="size-4 text-primary" /> Process log
          {entries.length > 0 && <Badge variant="secondary">{entries.length}</Badge>}
        </CardTitle>
        {canRecord && (
          <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
            <Plus /> Log observation
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {logs.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
        {logs.isError && (
          <p className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
            {logs.error.httpStatus === 403
              ? "You don't have access to this batch's process log."
              : 'Failed to load the process log.'}
          </p>
        )}
        {!logs.isLoading && !logs.isError && entries.length === 0 && (
          <p className="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">
            No observations yet.
          </p>
        )}
        {entries.length > 0 && (
          <ul className="max-h-[28rem] divide-y overflow-auto rounded-md border">
            {entries.map((l) => (
              <li key={l.id} className="space-y-1.5 px-3 py-2.5">
                <div className="flex items-baseline justify-between gap-2">
                  <span className="text-sm font-medium">{titleCase(l.event_type ?? 'observation')}</span>
                  <span className="shrink-0 text-xs text-muted-foreground">{fmtHumanDate(l.log_date)}</span>
                </div>
                {l.observation_note !== null && l.observation_note !== '' && (
                  <p className="whitespace-pre-wrap text-sm text-muted-foreground">{l.observation_note}</p>
                )}
                <LogChips log={l} />
              </li>
            ))}
          </ul>
        )}
      </CardContent>

      <FormDialog
        open={open}
        onOpenChange={setOpen}
        title="Log observation"
        description="Record what was done or observed on this batch. Fill in only the fields you have."
        submitLabel="Log observation"
        pending={add.isPending}
        onSubmit={submit}
      >
        <div className="space-y-1.5">
          <Label htmlFor={eventTypeId}>Event type</Label>
          <Select value={eventType} onValueChange={(v) => setEventType(v as BmgProcessEventType | 'unset')}>
            <SelectTrigger id={eventTypeId}><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="unset">Observation (default)</SelectItem>
              {BMG_PROCESS_EVENT_TYPES.filter((t) => t !== 'observation').map((t) => (
                <SelectItem key={t} value={t}>{titleCase(t)}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={noteId}>Observation note</Label>
          <Textarea id={noteId} rows={3} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor={tempId}>Temperature (°C)</Label>
            <Input id={tempId} type="number" step={0.1} value={temp} onChange={(e) => setTemp(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor={moistureId}>Moisture level</Label>
            <Select value={moisture} onValueChange={(v) => setMoisture(v as MoistureLevel | 'unset')}>
              <SelectTrigger id={moistureId}><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="unset">—</SelectItem>
                {MOISTURE_LEVELS.map((m) => (
                  <SelectItem key={m} value={m}>{titleCase(m)}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
        <details className="rounded-md border bg-muted/30 px-3 py-2">
          <summary className="cursor-pointer text-xs font-medium text-muted-foreground">
            Sensor / chain-of-custody (optional)
          </summary>
          <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div className="space-y-1.5">
              <Label htmlFor={oxygenId}>O₂ (%)</Label>
              <Input id={oxygenId} type="number" step={0.1} min={0} max={100} value={oxygen} onChange={(e) => setOxygen(e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor={deviceIdId}>Device ID</Label>
              <Input id={deviceIdId} maxLength={64} value={deviceId} onChange={(e) => setDeviceId(e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor={calibrationId}>Calibration</Label>
              <Select value={calibration} onValueChange={(v) => setCalibration(v as Calibration)}>
                <SelectTrigger id={calibrationId}><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="unset">—</SelectItem>
                  <SelectItem value="ok">Ok</SelectItem>
                  <SelectItem value="due">Due</SelectItem>
                  <SelectItem value="overdue">Overdue</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        </details>
      </FormDialog>
    </Card>
  );
}
