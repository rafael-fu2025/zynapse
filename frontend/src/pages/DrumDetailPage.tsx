/**
 * DrumDetailPage — focused screen for a single processing drum.
 *
 * Routed at `/facilities/drums/:unitId`. Opened from the "Processing
 * Drums" widget: instead of jumping to a table row, the operator lands
 * on a dedicated surface that shows everything about the drum's active
 * batch — identity, input/output weights, ETA + progress, analytics
 * (yield / mass reduction), and the process-log timeline with an
 * inline observation form.
 *
 * Read-mostly: state transitions (finish / cancel / record output)
 * still live on the Facilities table. The only write here is logging
 * an observation, which mirrors the legacy per-drum log sheet.
 */
import { ArrowLeft, Boxes, Calendar, ClipboardList, Cylinder, LineChart, Loader2, MapPin, Scale } from 'lucide-react';
import { useId, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
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
import {
  useActiveBatches,
  useAddProcessLog,
  useBatchAnalytics,
  useProcessLogs,
} from '@/hooks/useFacilities';
import { MOISTURE_LEVELS, type ActiveBatch, type MoistureLevel } from '@/schemas/facilities';
import { fmtShort, fmtUtcToApp } from '@/utils/date';

/** Process-log timeline + inline observation form for the batch. */
function ProcessLogSection({ batchId }: { batchId: number }) {
  const logs = useProcessLogs(batchId);
  const add = useAddProcessLog();
  const [note, setNote] = useState('');
  const [temp, setTemp] = useState('');
  const [moisture, setMoisture] = useState<MoistureLevel | 'unset'>('unset');
  const noteId = useId();
  const tempId = useId();

  function submit() {
    if (note.trim() === '' && temp.trim() === '' && moisture === 'unset') {
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
        },
      },
      {
        onSuccess: () => {
          setNote('');
          setTemp('');
          setMoisture('unset');
        },
      },
    );
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <ClipboardList className="size-4 text-primary" /> Process log
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="max-h-80 space-y-2 overflow-auto rounded-md border p-2">
          {logs.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
          {!logs.isLoading && (logs.data?.length ?? 0) === 0 && (
            <p className="p-2 text-sm text-muted-foreground">No observations yet.</p>
          )}
          {logs.data?.map((l) => (
            <section key={l.id} className="rounded-md border p-2">
              <header className="flex items-center justify-between">
                <p className="font-mono text-[10px] text-muted-foreground">{l.log_date}</p>
                <div className="flex gap-1">
                  {l.temperature_celsius !== null && <Badge variant="info">{l.temperature_celsius}°C</Badge>}
                  {l.moisture_level !== null && (
                    <Badge variant={l.moisture_level === 'normal' ? 'success' : 'warning'}>{l.moisture_level}</Badge>
                  )}
                </div>
              </header>
              {l.observation_note !== null && (
                <p className="mt-1 whitespace-pre-wrap text-sm text-foreground">{l.observation_note}</p>
              )}
            </section>
          ))}
        </div>
        <div className="space-y-3">
          <div className="space-y-1.5">
            <Label htmlFor={noteId}>Observation note</Label>
            <Textarea id={noteId} rows={2} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor={tempId}>Temperature (°C)</Label>
              <Input id={tempId} type="number" step={0.1} value={temp} onChange={(e) => setTemp(e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label id="drum-moisture-label">Moisture level</Label>
              <Select value={moisture} onValueChange={(v) => setMoisture(v as MoistureLevel | 'unset')}>
                <SelectTrigger aria-labelledby="drum-moisture-label"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="unset">—</SelectItem>
                  {MOISTURE_LEVELS.map((m) => (
                    <SelectItem key={m} value={m}>{m}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="flex justify-end">
            <Button onClick={submit} disabled={add.isPending}>
              {add.isPending && <Loader2 className="animate-spin" />}
              Log observation
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

/** Yield / mass-reduction analytics for the batch. */
function AnalyticsSection({ batchId }: { batchId: number }) {
  const analytics = useBatchAnalytics(batchId);
  const a = analytics.data;

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <LineChart className="size-4 text-primary" /> Analytics
        </CardTitle>
      </CardHeader>
      <CardContent>
        {analytics.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
        {analytics.isError && (
          <p className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
            Failed to load analytics.
          </p>
        )}
        {a !== undefined && (
          <dl className="grid grid-cols-2 gap-3 text-sm">
            <div className="rounded-md border p-2">
              <dt className="text-xs text-muted-foreground">Input</dt>
              <dd className="font-mono font-semibold">{a.input_kg} kg</dd>
            </div>
            <div className="rounded-md border p-2">
              <dt className="text-xs text-muted-foreground">Output</dt>
              <dd className="font-mono font-semibold">{a.output_kg} kg</dd>
            </div>
            <div className="rounded-md border p-2">
              <dt className="text-xs text-muted-foreground">Yield</dt>
              <dd>
                <Badge variant="info">{a.yield_pct}%</Badge>{' '}
                <span className="text-xs text-muted-foreground">({a.yield_class})</span>
              </dd>
            </div>
            <div className="rounded-md border p-2">
              <dt className="text-xs text-muted-foreground">Mass reduction</dt>
              <dd className="font-mono font-semibold">{a.mass_reduction_pct}%</dd>
            </div>
            {a.expected_yield_pct !== null && (
              <div className="rounded-md border p-2">
                <dt className="text-xs text-muted-foreground">Expected yield</dt>
                <dd className="font-mono font-semibold">{a.expected_yield_pct}%</dd>
              </div>
            )}
            {a.reference_duration_days !== null && (
              <div className="rounded-md border p-2">
                <dt className="text-xs text-muted-foreground">Reference duration</dt>
                <dd className="font-mono font-semibold">{a.reference_duration_days} days</dd>
              </div>
            )}
          </dl>
        )}
      </CardContent>
    </Card>
  );
}

/** Everything derived from the active-batch row: header + batch card. */
function DrumDetail({ batch }: { batch: ActiveBatch }) {
  const isInput = batch.input_kg <= 0;
  const overdue = batch.days_until_expected !== null && batch.days_until_expected < 0;
  const dueToday = batch.days_until_expected === 0;
  const days = batch.days_active;

  return (
    <>
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold text-foreground">
            <Cylinder className="size-5 text-primary" />
            <span className="font-mono">{batch.unit_code}</span>
            <Badge variant={isInput ? 'info' : 'warning'} className="uppercase">
              {isInput ? 'Input' : 'Processing'}
            </Badge>
          </h1>
          <p className="mt-0.5 text-sm text-muted-foreground">{batch.unit_name}</p>
          {batch.unit_location !== null && (
            <p className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
              <MapPin className="size-3" /> {batch.unit_location}
            </p>
          )}
        </div>
        <Button variant="outline" asChild>
          <Link to="/facilities">
            <ArrowLeft /> Back to Facilities
          </Link>
        </Button>
      </header>

      <Card className="border-l-4 border-l-primary/70">
        <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0 pb-3">
          <CardTitle className="flex items-center gap-2 text-base">
            <Scale className="size-4 text-primary" /> Batch {batch.batch_code}
          </CardTitle>
          <Badge variant={days > 30 ? 'warning' : 'info'}>
            {days} day{days === 1 ? '' : 's'} active
          </Badge>
        </CardHeader>
        <CardContent className="space-y-3">
          <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div className="rounded-md border p-2">
              <dt className="text-xs text-muted-foreground">Waste category</dt>
              <dd className="flex items-center gap-1 font-semibold">
                <Boxes className="size-3.5 text-muted-foreground" /> {batch.category_name ?? '—'}
              </dd>
            </div>
            <div className="rounded-md border p-2">
              <dt className="text-xs text-muted-foreground">Input</dt>
              <dd className="font-mono font-semibold">{batch.input_kg.toFixed(2)} kg</dd>
            </div>
            <div className="rounded-md border p-2">
              <dt className="text-xs text-muted-foreground">Output</dt>
              <dd className="font-mono font-semibold">
                {batch.output_kg !== null ? `${batch.output_kg.toFixed(2)} kg` : '—'}
              </dd>
            </div>
            <div className="rounded-md border p-2">
              <dt className="flex items-center gap-1 text-xs text-muted-foreground">
                <Calendar className="size-3" /> Started
              </dt>
              <dd className="font-mono text-xs font-semibold">{fmtUtcToApp(batch.started_at)}</dd>
            </div>
          </dl>

          <div className="space-y-1.5 rounded-md border p-3">
            <div className="flex items-center justify-between text-sm">
              <span className="text-muted-foreground">Expected completion</span>
              <span className="text-right">
                <span className="font-mono font-semibold">
                  {batch.expected_completion_date !== null ? fmtShort(batch.expected_completion_date) : '—'}
                </span>
                {batch.days_until_expected !== null && (
                  <span
                    className={
                      overdue
                        ? 'ml-2 text-xs font-medium text-destructive'
                        : 'ml-2 text-xs font-medium text-muted-foreground'
                    }
                  >
                    {overdue
                      ? `${Math.abs(batch.days_until_expected)} day${Math.abs(batch.days_until_expected) === 1 ? '' : 's'} overdue`
                      : dueToday
                        ? 'Due today'
                        : `in ${batch.days_until_expected} day${batch.days_until_expected === 1 ? '' : 's'}`}
                  </span>
                )}
              </span>
            </div>
            <div
              className="h-2 overflow-hidden rounded-full bg-muted"
              role="progressbar"
              aria-label="Decomposition progress"
              aria-valuenow={batch.progress_pct}
              aria-valuemin={0}
              aria-valuemax={100}
            >
              <div
                className="h-full rounded-full bg-gradient-to-r from-primary/70 to-primary transition-[width] duration-500"
                style={{ width: `${batch.progress_pct}%` }}
              />
            </div>
            <p className="text-right text-xs text-muted-foreground">{batch.progress_pct}% progress</p>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <AnalyticsSection batchId={batch.batch_id} />
        <ProcessLogSection batchId={batch.batch_id} />
      </div>
    </>
  );
}

export default function DrumDetailPage() {
  const { unitId: unitIdParam } = useParams();
  const unitId = Number(unitIdParam);
  const active = useActiveBatches();
  const batch = (active.data ?? []).find((b) => b.unit_id === unitId) ?? null;

  return (
    <main className="mx-auto max-w-5xl space-y-4 p-6">
      {active.isLoading && (
        <div className="flex items-center justify-center py-16 text-muted-foreground">
          <Loader2 className="size-5 animate-spin" />
        </div>
      )}
      {active.isError && (
        <p className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
          Failed to load active drums.
        </p>
      )}
      {!active.isLoading && !active.isError && batch === null && (
        <section className="grid min-h-[40vh] place-items-center">
          <div className="max-w-md text-center">
            <Cylinder className="mx-auto size-8 text-muted-foreground" />
            <h1 className="mt-2 text-lg font-semibold text-foreground">No active batch on this drum</h1>
            <p className="mt-1 text-sm text-muted-foreground">
              This drum is not currently processing — its batch may have been finished or cancelled.
            </p>
            <Button asChild className="mt-4">
              <Link to="/facilities">
                <ArrowLeft /> Back to Facilities
              </Link>
            </Button>
          </div>
        </section>
      )}
      {batch !== null && <DrumDetail batch={batch} />}
    </main>
  );
}
