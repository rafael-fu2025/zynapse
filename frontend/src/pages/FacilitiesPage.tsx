/**
 * FacilitiesPage — BMG state machine control surface.
 *
 * Lists units (with their `active_batch_id` joined in) and lets the
 * operator start, record output, finish, or cancel a batch. TanStack
 * Query mutations apply optimistic state transitions to the unit's
 * status badge and roll back on error. shadcn Table / Dialog /
 * Textarea primitives.
 */
import { Play, StopCircle, Loader2, Ban, ChevronDown, ChevronLeft, ChevronRight, ClipboardList, Boxes, LineChart, Plus, Wrench, Eye, Pencil, Archive, ArchiveRestore, X, ShieldCheck, FileCheck2, TriangleAlert, History, Sparkles, SquarePen, List } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { Link, Navigate, useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { MobileCardList, MobileCard, MobileCardField, MobileCardActions } from '@/components/MobileCardList';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import {
  useActiveBatches,
  useAddBatchIo,
  useAddBatchUpdate,
  useAddProcessLog,
  useArchiveUnit,
  useBatchAnalytics,
  useBatchCompliance,
  useBatchHistory,
  useBatchUpdates,
  useBlendCn,
  useBmgUnits,
  useCancelBatch,
  useCreateUnit,
  useFinishBatch,
  useOpenAlerts,
  useProcessLogs,
  useRecordOutput,
  useReleaseBatch,
  useSetUnitMaintenance,
  useStartBatch,
  useSuggestUnit,
  useUnarchiveUnit,
  useUpdateUnit,
  useWasteCategories,
} from '@/hooks/useFacilities';
import {
  BMG_MATURITY_LEVELS,
  BMG_PROCESS_EVENT_TYPES,
  BMG_QUALITY_GRADES,
  MOISTURE_LEVELS,
  OUTPUT_GRADES,
  addBatchUpdateSchema,
  createUnitSchema,
  recordOutputSchema,
  startBatchSchema,
  updateUnitSchema,
  type ActiveBatch,
  type BmgMaturityLevel,
  type BmgProcessEventType,
  type BmgQualityGrade,
  type BmgUnit,
  type MoistureLevel,
} from '@/schemas/facilities';
import { ApiEnvelopeError } from '@/api/envelope';
import { fmtHumanDate, fmtUtcToApp, fmtShort } from '@/utils/date';
import { slugify, uniqueSlug } from '@/utils/slug';
import { statusLabel } from '@/utils/status';
import { titleCase } from '@/lib/utils';

function unitStatusVariant(status: BmgUnit['status']): 'default' | 'info' | 'warning' | 'success' | 'destructive' | 'secondary' {
  switch (status) {
    case 'idle': return 'success';
    case 'processing': return 'info';
    case 'awaiting_output': return 'warning';
    case 'curing': return 'info';
    case 'cancelled': return 'destructive';
    case 'maintenance': return 'secondary';
    default: return 'default';
  }
}

function StartBatchDialog({ unit, onClose }: { unit: BmgUnit; onClose: () => void }) {
  const start = useStartBatch();
  const cats = useWasteCategories(true);
  // Panel revision: segregated waste composition — one row per waste
  // category with its loaded weight. The ratios drive the batch ETA.
  const [rows, setRows] = useState<Array<{ category_id: string; weight_kg: string }>>([
    { category_id: '', weight_kg: '' },
  ]);
  const totalId = useId();
  const weightBaseId = useId();

  // Audit #8: suggest an idle drum matching the selected waste category.
  const firstCat = rows.find((r) => r.category_id !== '')?.category_id ?? null;
  const suggest = useSuggestUnit(firstCat !== null ? Number(firstCat) : null);

  const total = useMemo(
    () => rows.reduce((s, r) => s + (Number(r.weight_kg) || 0), 0),
    [rows],
  );

  function setRow(i: number, patch: Partial<{ category_id: string; weight_kg: string }>) {
    setRows((rs) => rs.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
  }
  function addRow() {
    setRows((rs) => [...rs, { category_id: '', weight_kg: '' }]);
  }
  function removeRow(i: number) {
    setRows((rs) => (rs.length > 1 ? rs.filter((_, idx) => idx !== i) : rs));
  }

  function submit() {
    if (unit.spec_capacity_kg !== null && unit.spec_capacity_kg > 0 && total > unit.spec_capacity_kg) {
      toast.error(`Total input weight (${total.toFixed(2)} kg) exceeds this drum's capacity (${unit.spec_capacity_kg} kg).`);
      return;
    }
    const composition = rows
      .filter((r) => r.category_id !== '' && r.weight_kg !== '')
      .map((r) => ({ category_id: Number(r.category_id), weight_kg: Number(r.weight_kg) }));
    // Empty rows used to be silently dropped and the schema's
    // min(1, 'Add at least one waste component') got masked by the
    // total>0 failure — surface the real reason instead.
    if (composition.length === 0) {
      toast.error('Add at least one waste component with a weight.');
      return;
    }
    const parsed = startBatchSchema.safeParse({
      total_input_weight_kg: Number(total.toFixed(2)),
      composition,
    });
    if (!parsed.success) {
      toast.error(parsed.error.issues[0]?.message ?? 'Invalid input.');
      return;
    }
    start.mutate(
      { unitId: unit.id, input: parsed.data },
      { onSuccess: () => onClose() },
    );
  }

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Start batch on {unit.code}</DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <p className="text-xs text-muted-foreground">
          Record the waste mix by specific category (meat, rice, bones, yard…). The
          weight ratios drive this drum’s expected composting duration.
        </p>
        <div className="space-y-2">
          {rows.map((r, i) => {
            const ratio = total > 0 && r.weight_kg !== '' ? ((Number(r.weight_kg) / total) * 100) : null;
            const weightId = `${weightBaseId}-${i}`;
            return (
              <div key={i} className="flex items-end gap-2">
                <div className="flex-1 space-y-1">
                  {i === 0 && <Label className="text-xs">Waste category</Label>}
                  <Select value={r.category_id} onValueChange={(v) => setRow(i, { category_id: v })}>
                    <SelectTrigger aria-label="Waste category"><SelectValue placeholder="Select category…" /></SelectTrigger>
                    <SelectContent>
                      {(cats.data ?? []).map((c) => (
                        <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="w-28 space-y-1">
                  {i === 0 && <Label htmlFor={weightId} className="text-xs">Weight (kg)</Label>}
                  <Input
                    id={i === 0 ? weightId : undefined}
                    type="number"
                    min={0}
                    step={0.01}
                    aria-label={i === 0 ? undefined : 'Weight (kg)'}
                    value={r.weight_kg}
                    onChange={(e) => setRow(i, { weight_kg: e.target.value })}
                  />
                </div>
                <div className="w-14 pb-2 text-right font-mono text-xs text-muted-foreground">
                  {ratio !== null ? `${ratio.toFixed(0)}%` : '—'}
                </div>
                <Button size="icon" variant="ghost" className="mb-0.5" disabled={rows.length < 2} onClick={() => removeRow(i)} aria-label="Remove component">
                  <X className="size-4" />
                </Button>
              </div>
            );
          })}
          <Button size="sm" variant="outline" onClick={addRow}><Plus className="size-3" /> Add component</Button>
        </div>
        <div className="flex items-center justify-between rounded-md border bg-muted/40 px-3 py-2">
          <Label htmlFor={totalId} className="text-xs">Total input weight</Label>
          <div className="text-right">
            <span id={totalId} className="font-mono text-sm font-semibold">{total.toFixed(2)} kg</span>
            {unit.spec_capacity_kg !== null && unit.spec_capacity_kg > 0 && (
              <p className={`text-[11px] ${total > unit.spec_capacity_kg ? 'font-medium text-destructive' : 'text-muted-foreground'}`}>
                Drum capacity: {unit.spec_capacity_kg} kg
              </p>
            )}
          </div>
        </div>
        {firstCat !== null && suggest.data !== null && suggest.data !== undefined && (
          <p className="flex items-center gap-1.5 rounded-md border border-primary/20 bg-primary/5 px-3 py-2 text-xs text-foreground">
            <Sparkles className="size-3.5 text-primary" />
            Suggested drum for this category: <span className="font-mono font-medium">{suggest.data.code}</span>
            {suggest.data.location_code !== null && ` · ${suggest.data.location_code}`}
            {suggest.data.spec_capacity_kg !== null && ` · ${suggest.data.spec_capacity_kg} kg cap`}
          </p>
        )}
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button onClick={submit} disabled={start.isPending}>
          {start.isPending && <Loader2 className="animate-spin" />}
          Start
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

function RecordOutputDialog({ unit, batchId, onClose }: { unit: BmgUnit; batchId: number; onClose: () => void }) {
  const rec = useRecordOutput();
  const [output, setOutput] = useState('');
  const outputId = useId();

  function submit() {
    const parsed = recordOutputSchema.safeParse({
      output_weight_kg: Number(output),
      output_items: [],
    });
    if (!parsed.success) {
      toast.error(parsed.error.issues[0]?.message ?? 'Invalid input.');
      return;
    }
    rec.mutate(
      { unitId: unit.id, batchId, input: parsed.data },
      { onSuccess: () => onClose() },
    );
  }

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Record output for batch #{batchId} on {unit.code}</DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor={outputId}>Output weight (kg)</Label>
          <Input id={outputId} type="number" min={0} step={0.01} value={output} onChange={(e) => setOutput(e.target.value)} />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button onClick={submit} disabled={rec.isPending}>
          {rec.isPending && <Loader2 className="animate-spin" />}
          Record
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

/**
 * 2. Add update — ONE plain-language action that appends an immutable
 * ledger entry. Internally branches by what the operator fills in:
 * output weight → output entry; curing note → curing entry; any log
 * detail → log entry. Mirrors the mobile `_addUpdate`.
 */
function AddUpdateDialog({ unit, batchId, onClose }: { unit: BmgUnit; batchId: number; onClose: () => void }) {
  const add = useAddBatchUpdate();
  const [eventType, setEventType] = useState<BmgProcessEventType | 'unset'>('unset');
  const [temp, setTemp] = useState('');
  const [moisture, setMoisture] = useState<MoistureLevel | 'unset'>('unset');
  const [note, setNote] = useState('');
  const [error, setError] = useState('');

  function submit() {
    const parsed = addBatchUpdateSchema.safeParse({
      update_type: 'log',
      event_type: eventType === 'unset' ? undefined : eventType,
      observation_note: note.trim() || undefined,
      temperature_celsius: temp.trim() !== '' ? Number(temp) : undefined,
      moisture_level: moisture === 'unset' ? undefined : moisture,
    });
    if (!parsed.success) {
      setError(parsed.error.issues[0]?.message ?? 'Invalid input.');
      return;
    }
    setError('');
    add.mutate(
      { unitId: unit.id, batchId, input: parsed.data },
      { onSuccess: () => onClose() },
    );
  }

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Add update — batch #{batchId} on {unit.code}</DialogTitle>
        <p className="text-sm text-muted-foreground">
          Add a log observation for this batch (temperature, turning, aeration, moisture, notes). Every entry is stored as an immutable, timestamped record. The final output (yield) is recorded when you finish the batch.
        </p>
      </DialogHeader>
      <div className="space-y-3">
        <div className="rounded-lg border p-3 space-y-2">
          <Label htmlFor="update-log-type" className="block text-xs font-semibold">Log type</Label>
          <Select value={eventType} onValueChange={(v) => setEventType(v as BmgProcessEventType)}>
            <SelectTrigger id="update-log-type" aria-label="Log type"><SelectValue placeholder="Select log type" /></SelectTrigger>
            <SelectContent>
              {BMG_PROCESS_EVENT_TYPES.map((t) => <SelectItem key={t} value={t}>{titleCase(t)}</SelectItem>)}
            </SelectContent>
          </Select>
          <div className="space-y-1.5">
            <Label htmlFor="update-temp">Temperature (°C)</Label>
            <Input id="update-temp" type="number" min={-20} max={120} step={0.1} placeholder="e.g. 58.5" value={temp} onChange={(e) => setTemp(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="update-moisture">Moisture level</Label>
            <Select value={moisture} onValueChange={(v) => setMoisture(v as MoistureLevel)}>
              <SelectTrigger id="update-moisture" aria-label="Moisture level"><SelectValue placeholder="Select moisture level" /></SelectTrigger>
              <SelectContent>
                {MOISTURE_LEVELS.map((m) => <SelectItem key={m} value={m}>{titleCase(m)}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="update-notes">Notes</Label>
            <Textarea id="update-notes" rows={2} maxLength={1000} placeholder="Optional notes" value={note} onChange={(e) => setNote(e.target.value)} />
          </div>
        </div>
        {error !== '' && <p className="text-sm text-destructive">{error}</p>}
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button onClick={submit} disabled={add.isPending}>
          {add.isPending && <Loader2 className="animate-spin" />}
          Add update
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

/**
 * 6a. Combined, append-only "Updates" feed for the active batch —
 * output / curing / log entries, oldest → newest. Read-only.
 */
function UpdatesFeedDialog({ unit, batchId, onClose }: { unit: BmgUnit; batchId: number; onClose: () => void }) {
  const updates = useBatchUpdates(batchId);
  return (
    <DialogContent className="max-h-[70vh] overflow-y-auto">
      <DialogHeader>
        <DialogTitle>Updates — batch #{batchId} on {unit.code}</DialogTitle>
        <p className="text-sm text-muted-foreground">
          Append-only record of every output, curing, and log entry for this batch.
        </p>
      </DialogHeader>
      <div className="space-y-2">
        {updates.isLoading && <div className="flex items-center gap-2 text-sm text-muted-foreground"><Loader2 className="animate-spin size-4" /> Loading…</div>}
        {updates.isError && <p className="text-sm text-destructive">Could not load updates.</p>}
        {!updates.isLoading && !updates.isError && (updates.data?.length ?? 0) === 0 && (
          <p className="text-sm text-muted-foreground">No updates recorded yet.</p>
        )}
        {updates.data?.map((u) => (
          <div key={u.id} className="rounded-lg border p-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{u.update_type}</span>
              <span className="text-xs text-muted-foreground">{fmtUtcToApp(u.created_at)}</span>
            </div>
            <p className="mt-1 text-sm">
              {u.update_type === 'output' && `Output: ${u.output_weight_kg} kg`}
              {u.update_type === 'curing' && `Moved to curing${u.curing_note ? ` — ${u.curing_note}` : ''}`}
              {u.update_type === 'log' && (
                <>
                  {[u.event_type, u.temperature_celsius !== null && u.temperature_celsius !== undefined ? `${u.temperature_celsius}°C` : null, u.moisture_level].filter(Boolean).join(' · ') || 'Observation'}
                  {u.observation_note ? <span className="text-muted-foreground"> — {u.observation_note}</span> : null}
                </>
              )}
            </p>
          </div>
        ))}
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
      </DialogFooter>
    </DialogContent>
  );
}

/**
 * 3. Finish = GRADED RELEASE. Requires quality grade + maturity; the
 * batch leaves as `released` and the drum returns to Available. A run
 * closed without a valid quality outcome should be Cancelled instead.
 */
function FinishBatchDialog({ unit, batchId, onClose }: { unit: BmgUnit; batchId: number; onClose: () => void }) {
  const finish = useFinishBatch();
  const [grade, setGrade] = useState<BmgQualityGrade | 'unset'>('unset');
  const [maturity, setMaturity] = useState<BmgMaturityLevel | 'unset'>('unset');
  const [outputKg, setOutputKg] = useState('');
  const [error, setError] = useState('');

  function submit() {
    if (grade === 'unset' || maturity === 'unset') {
      setError('Quality grade and maturity are both required to finish.');
      return;
    }
    setError('');
    finish.mutate(
      {
        unitId: unit.id,
        batchId,
        input: {
          quality_grade: grade,
          maturity_level: maturity,
          output_weight_kg: outputKg.trim() !== '' ? Number(outputKg) : undefined,
        },
      },
      { onSuccess: () => onClose() },
    );
  }

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Finish batch #{batchId} on {unit.code}</DialogTitle>
        <p className="text-sm text-muted-foreground">
          Finishing is a graded release — record the final output (yield) and quality outcome, then the drum returns to Available. If this run failed or was discarded, cancel it instead.
        </p>
      </DialogHeader>
      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="finish-output">Output weight (kg)</Label>
          <Input id="finish-output" type="number" min={0} step={0.01} placeholder="Final yield, e.g. 18.5" value={outputKg} onChange={(e) => setOutputKg(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label>Quality grade</Label>
          <Select value={grade} onValueChange={(v) => setGrade(v as BmgQualityGrade)}>
            <SelectTrigger aria-label="Quality grade"><SelectValue placeholder="Select quality grade" /></SelectTrigger>
            <SelectContent>
              {BMG_QUALITY_GRADES.map((g) => <SelectItem key={g} value={g}>{titleCase(g)}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label>Maturity</Label>
          <Select value={maturity} onValueChange={(v) => setMaturity(v as BmgMaturityLevel)}>
            <SelectTrigger aria-label="Maturity"><SelectValue placeholder="Select maturity level" /></SelectTrigger>
            <SelectContent>
              {BMG_MATURITY_LEVELS.map((m) => <SelectItem key={m} value={m}>{titleCase(m)}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>
        {error !== '' && <p className="text-sm text-destructive">{error}</p>}
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button onClick={submit} disabled={finish.isPending}>
          {finish.isPending && <Loader2 className="animate-spin" />}
          Finish batch
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

/**
 * 5. Drum status — toggle Available / Under Maintenance / Archived.
 * Only allowed when the drum has no active batch.
 */
function DrumStatusDialog({ unit, onClose }: { unit: BmgUnit; onClose: () => void }) {
  const maintenance = useSetUnitMaintenance();
  const archiveUnit = useArchiveUnit();
  const unarchiveUnit = useUnarchiveUnit();
  const [status, setStatus] = useState<string>(
    unit.archived_at ? 'archived' : unit.status === 'maintenance' ? 'maintenance' : 'available',
  );
  const pending = maintenance.isPending || archiveUnit.isPending || unarchiveUnit.isPending;

  function submit() {
    if (status === 'maintenance') {
      maintenance.mutate({ unitId: unit.id, maintenance: true }, { onSuccess: () => onClose() });
    } else if (status === 'archived') {
      archiveUnit.mutate({ unitId: unit.id }, { onSuccess: () => onClose() });
    } else {
      // Available: clear maintenance (if set) and unarchive (if archived).
      if (unit.archived_at) unarchiveUnit.mutate({ unitId: unit.id }, { onSuccess: () => onClose() });
      else if (unit.status === 'maintenance') maintenance.mutate({ unitId: unit.id, maintenance: false }, { onSuccess: () => onClose() });
      else onClose();
    }
  }

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Drum status — {unit.code}</DialogTitle>
        <p className="text-sm text-muted-foreground">
          Available: selectable for a new batch. Under Maintenance: temporarily taken out of service (returns to Available when cleared). Archived: permanently out of service — history is kept but the drum can no longer start a batch.
        </p>
      </DialogHeader>
      <div className="space-y-1.5">
        <Label>Status</Label>
        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger aria-label="Drum status"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="available">Available</SelectItem>
            <SelectItem value="maintenance">Under Maintenance</SelectItem>
            <SelectItem value="archived">Archived</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button onClick={submit} disabled={pending}>
          {pending && <Loader2 className="animate-spin" />}
          Update
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

function ProcessLogsDialog({ unit, batchId, onClose }: { unit: BmgUnit; batchId: number; onClose: () => void }) {
  const logs = useProcessLogs(batchId);
  const add = useAddProcessLog();
  const [note, setNote] = useState('');
  const [temp, setTemp] = useState('');
  const [moisture, setMoisture] = useState<MoistureLevel | 'unset'>('unset');
  // Audit #6: event_type records WHAT was done (turning, aeration…).
  const [eventType, setEventType] = useState<BmgProcessEventType | 'unset'>('unset');
  const noteId = useId();
  const tempId = useId();

  function submit() {
    if (note.trim() === '' && temp.trim() === '' && moisture === 'unset' && eventType === 'unset') {
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
        },
      },
      {
        onSuccess: () => {
          setNote('');
          setTemp('');
          setMoisture('unset');
          setEventType('unset');
        },
      },
    );
  }

  return (
    <DialogContent className="max-w-lg">
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <ClipboardList className="size-4" /> Process log — batch #{batchId} on {unit.code}
        </DialogTitle>
      </DialogHeader>
      <div className="max-h-56 space-y-2 overflow-auto rounded-md border p-2">
        {logs.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
        {!logs.isLoading && (logs.data?.length ?? 0) === 0 && (
          <p className="p-2 text-sm text-muted-foreground">No observations yet.</p>
        )}
        {logs.data?.map((l) => (
          <section key={l.id} className="rounded-md border p-2">
            <header className="flex items-center justify-between">
              <p className="text-[10px] text-muted-foreground">{fmtHumanDate(l.log_date)}</p>
              <div className="flex flex-wrap gap-1">
                {l.event_type !== undefined && l.event_type !== 'observation' && (
                  <Badge variant="secondary">{titleCase(l.event_type)}</Badge>
                )}
                {l.temperature_celsius !== null && <Badge variant="info">{l.temperature_celsius}°C</Badge>}
                {l.moisture_level !== null && (
                  <Badge variant={l.moisture_level === 'normal' ? 'success' : 'warning'}>{titleCase(l.moisture_level)}</Badge>
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
          <Label id="event-type-label">Event type</Label>
          <Select value={eventType} onValueChange={(v) => setEventType(v as BmgProcessEventType | 'unset')}>
            <SelectTrigger aria-labelledby="event-type-label"><SelectValue /></SelectTrigger>
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
          <Textarea id={noteId} rows={2} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor={tempId}>Temperature (°C)</Label>
            <Input id={tempId} type="number" step={0.1} value={temp} onChange={(e) => setTemp(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label id="moisture-label">Moisture level</Label>
            <Select value={moisture} onValueChange={(v) => setMoisture(v as MoistureLevel | 'unset')}>
              <SelectTrigger aria-labelledby="moisture-label"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="unset">—</SelectItem>
                {MOISTURE_LEVELS.map((m) => (
                  <SelectItem key={m} value={m}>{titleCase(m)}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
        <Button onClick={submit} disabled={add.isPending}>
          {add.isPending && <Loader2 className="animate-spin" />}
          Log observation
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

/**
 * Waste-category management moved to its own screen —
 * see `WasteCategoriesPage` (routed at `/facilities/waste-categories`).
 */

function AnalyticsDialog({ unit, batchId, onClose }: { unit: BmgUnit; batchId: number; onClose: () => void }) {
  const analytics = useBatchAnalytics(batchId);
  const io = useAddBatchIo();
  // Audit #5: weighted feedstock C:N blend.
  const blend = useBlendCn(batchId);
  const [inKg, setInKg] = useState('');
  const [outKg, setOutKg] = useState('');
  const [grade, setGrade] = useState<'excellent' | 'good' | 'fair'>('good');
  const a = analytics.data;

  return (
    <DialogContent className="max-w-lg">
      <DialogHeader>
        <DialogTitle>Analytics — batch #{batchId} on {unit.code}</DialogTitle>
      </DialogHeader>

      {analytics.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
      {a !== undefined && (
        <div className="grid grid-cols-2 gap-3 rounded-md border p-3 text-sm">
          <div>Input: <span className="font-mono">{a.input_kg} kg</span></div>
          <div>Output: <span className="font-mono">{a.output_kg} kg</span></div>
          <div>Yield: <Badge variant="info">{a.yield_pct}%</Badge> <span className="text-xs text-muted-foreground">({a.yield_class})</span></div>
          <div>Mass reduction: <span className="font-mono">{a.mass_reduction_pct}%</span></div>
          {a.expected_yield_pct !== null && <div>Expected: <span className="font-mono">{a.expected_yield_pct}%</span></div>}
          {a.expected_days !== null && <div>Expected days: <span className="font-mono">{a.expected_days}</span> <span className="text-xs text-muted-foreground">(mix-weighted)</span></div>}
          {a.expected_completion_date !== null && <div>ETA: <span>{fmtHumanDate(a.expected_completion_date)}</span></div>}
          {a.days_until_expected !== null && <div>Days left: <span className="font-mono">{a.days_until_expected}</span></div>}
          {a.progress_pct !== null && <div>Progress: <span className="font-mono">{a.progress_pct}%</span></div>}
        </div>
      )}

      {blend.data !== undefined && (
        <div className={`rounded-md border p-3 text-sm ${blend.data.status === 'optimal' ? 'border-success/30 bg-success/5' : blend.data.status === 'unknown' ? '' : 'border-warning/30 bg-warning/5'}`}>
          <p className="mb-1 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
            <Sparkles className="size-3.5" /> Feedstock C:N blend
          </p>
          {blend.data.blend_cn !== null ? (
            <p className="flex items-center gap-2">
              <span className="font-mono font-semibold">{blend.data.blend_cn}</span>
              <Badge variant={blend.data.status === 'optimal' ? 'success' : 'warning'}>
                {blend.data.status === 'optimal' ? 'Optimal (15–30)' : blend.data.status === 'high' ? 'Too high' : blend.data.status === 'low' ? 'Too low' : 'Unknown'}
              </Badge>
              <span className="text-xs text-muted-foreground">({blend.data.n_inputs} inputs)</span>
            </p>
          ) : (
            <p className="text-xs text-muted-foreground">No C:N data — record feedstock inputs with a C:N ratio.</p>
          )}
          {blend.data.note !== null && <p className="mt-1 text-xs text-muted-foreground">{blend.data.note}</p>}
        </div>
      )}

      {a !== undefined && a.composition.length > 0 && (
        <div className="rounded-md border p-3">
          <p className="mb-2 text-xs font-medium text-muted-foreground">Waste composition (weight ratio → expected days)</p>
          <div className="space-y-1">
            {a.composition.map((c) => (
              <div key={c.category_id} className="flex items-center justify-between text-sm">
                <span>{c.category_name}</span>
                <span className="font-mono text-xs text-muted-foreground">
                  {c.weight_kg} kg{c.ratio_pct !== null ? ` · ${c.ratio_pct}%` : ''}
                  {c.expected_days !== null ? ` · ~${c.expected_days}d` : ''}
                  {c.sample_count > 0 ? ` (${c.sample_count} trials)` : ' (no history)'}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="grid grid-cols-2 gap-3">
        <div className="space-y-1.5 rounded-md border p-2">
          <Label htmlFor="io-in" className="text-xs">Record input (kg)</Label>
          <div className="flex gap-1">
            <Input id="io-in" type="number" min={0.01} step={0.01} value={inKg} onChange={(e) => setInKg(e.target.value)} />
            <Button size="sm" disabled={io.isPending || inKg === ''} onClick={() => io.mutate({ batchId, kind: 'inputs', body: { weight_kg: Number(inKg) } }, { onSuccess: () => setInKg('') })}>Add</Button>
          </div>
        </div>
        <div className="space-y-1.5 rounded-md border p-2">
          <Label htmlFor="io-out" className="text-xs">Record output (kg)</Label>
          <div className="flex gap-1">
            <Input id="io-out" type="number" min={0.01} step={0.01} value={outKg} onChange={(e) => setOutKg(e.target.value)} />
            <Select value={grade} onValueChange={(v) => setGrade(v as 'excellent' | 'good' | 'fair')}>
              <SelectTrigger aria-label="Quality grade" className="h-8 w-28"><SelectValue /></SelectTrigger>
              <SelectContent>
                {OUTPUT_GRADES.map((g) => <SelectItem key={g} value={g}>{titleCase(g)}</SelectItem>)}
              </SelectContent>
            </Select>
            <Button size="sm" disabled={io.isPending || outKg === ''} onClick={() => io.mutate({ batchId, kind: 'outputs', body: { output_weight_kg: Number(outKg), quality_grade: grade } }, { onSuccess: () => setOutKg('') })}>Add</Button>
          </div>
        </div>
      </div>

      <DialogFooter><Button variant="outline" onClick={onClose}>Close</Button></DialogFooter>
    </DialogContent>
  );
}

/**
 * ProcessingDrumsCard — "Processing Drums" widget.
 *
 * Mirrors the dashboard tile from the legacy Synapse project: a card grid
 * (auto-fill, ~220px min) where each tile shows the drum code, the
 * underlying batch, the waste category, input weight, expected completion
 * date, days active, and a gradient progress bar. Clicking a card (or its
 * "Open" affordance) navigates to the dedicated drum detail screen at
 * `/facilities/drums/:unitId`, which focuses on that drum's information.
 *
 * Read-only: the widget is a status surface, not a control surface. All
 * state transitions still flow through the table actions below.
 */
/**
 * DrumImage — theme-aware drum graphic used by the "Processing Drums"
 * widget. Replaces the abstract `Cylinder` icon with the actual drum
 * asset: maroon drum in light mode, white drum in dark mode. Both files
 * are served from /public (note the white asset filename is `drum-whte`).
 */
function DrumImage({ className = '' }: { className?: string }) {
  return (
    <>
      <img
        src="/drum-maroon.png"
        alt=""
        aria-hidden
        draggable={false}
        className={`${className} object-contain dark:hidden`}
      />
      <img
        src="/drum-whte.png"
        alt=""
        aria-hidden
        draggable={false}
        className={`${className} hidden object-contain dark:block`}
      />
    </>
  );
}

function ProcessingDrumsCard() {
  const active = useActiveBatches();
  const items = active.data ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0 pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <DrumImage className="size-5" />
          Processing Drums
        </CardTitle>
        <Badge variant={items.length > 0 ? 'warning' : 'secondary'} className="font-mono">
          {items.length} active
        </Badge>
      </CardHeader>
      <CardContent>
        {active.isLoading && (
          <div className="flex items-center justify-center py-8 text-muted-foreground">
            <Loader2 className="size-4 animate-spin" />
          </div>
        )}
        {active.isError && (
          <p className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
            Failed to load active drums.
          </p>
        )}
        {!active.isLoading && !active.isError && items.length === 0 && (
          <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
            No drums currently processing. Start a batch on an idle unit to begin composting.
          </p>
        )}
        {items.length > 0 && (
          <div className="grid grid-cols-[repeat(auto-fill,minmax(220px,1fr))] gap-3">
            {items.map((b) => (
              <DrumCard key={b.batch_id} batch={b} />
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function DrumCard({ batch }: { batch: ActiveBatch }) {
  const isInput = batch.input_kg <= 0;
  const overdue = batch.days_until_expected !== null && batch.days_until_expected < 0;
  const dueToday = batch.days_until_expected === 0;
  const days = batch.days_active;

  /**
   * The whole tile is a router Link to the drum's dedicated detail
   * screen — a full page focused on this drum's batch info, analytics
   * and process log (replaces the old scroll-to-table-row behavior).
   */
  return (
    <Link
      to={`/facilities/drums/${batch.unit_id}`}
      className="group flex flex-col gap-2 rounded-lg border border-primary/50 bg-card p-3 text-left transition-all hover:border-primary hover:shadow-md hover:-translate-y-px focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
    >
      {/* Theme-aware drum graphic (white in dark mode, maroon in light mode). */}
      <div className="flex justify-center py-1">
        <DrumImage className="h-16 w-auto" />
      </div>

      <header className="flex items-start justify-between gap-2 border-b border-border/60 pb-2">
        <div className="min-w-0">
          <p className="font-mono text-sm font-bold tracking-wide text-foreground">{batch.unit_code}</p>
          <p className="truncate text-xs text-muted-foreground">{batch.unit_name}</p>
        </div>
        <Badge variant={isInput ? 'info' : 'warning'} className="shrink-0 uppercase">
          {isInput ? 'Input' : 'Processing'}
        </Badge>
      </header>

      <dl className="space-y-1 text-xs">
        <div className="flex items-center justify-between">
          <dt className="text-muted-foreground">Batch</dt>
          <dd className="font-mono font-semibold text-foreground">{batch.batch_code}</dd>
        </div>
        <div className="flex items-center justify-between">
          <dt className="text-muted-foreground">Waste</dt>
          <dd className="font-semibold text-foreground">{batch.category_name ?? '—'}</dd>
        </div>
        <div className="flex items-center justify-between">
          <dt className="text-muted-foreground">Input</dt>
          <dd className="font-mono font-semibold text-foreground">{batch.input_kg.toFixed(2)} kg</dd>
        </div>
        <div className="flex items-start justify-between gap-2">
          <dt className="shrink-0 text-muted-foreground">Expected Done</dt>
          <dd className="text-right">
            <span className="font-mono font-semibold text-foreground">
              {batch.expected_completion_date !== null ? fmtShort(batch.expected_completion_date) : '—'}
            </span>
            {batch.days_until_expected !== null && (
              <p
                className={
                  overdue
                    ? 'mt-0.5 text-[10px] font-medium text-destructive'
                    : 'mt-0.5 text-[10px] font-medium text-muted-foreground'
                }
              >
                {overdue
                  ? `${Math.abs(batch.days_until_expected)} day${Math.abs(batch.days_until_expected) === 1 ? '' : 's'} overdue`
                  : dueToday
                    ? 'Due today'
                    : `in ${batch.days_until_expected} day${batch.days_until_expected === 1 ? '' : 's'}`}
              </p>
            )}
          </dd>
        </div>
      </dl>

      {/* Progress bar — design-system gradient, no Radix required. */}
      <div
        className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted"
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

      <footer className="mt-1 flex items-center justify-between border-t border-dashed border-border/60 pt-2">
        <Badge variant={days > 30 ? 'warning' : 'info'}>
          {days} day{days === 1 ? '' : 's'} active
        </Badge>
        <span className="inline-flex items-center gap-1 text-xs font-medium text-primary opacity-70 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100">
          <Eye className="size-3" /> Open
        </span>
      </footer>
    </Link>
  );
}

/**
 * CreateUnitDialog — register a new BMG drum.
 *
 * Mirrors the legacy `bmg/drums/create` form: code, name, location,
 * capacity, notes. `code` is uppercased server-side; submit is blocked
 * until Zod validates.
 */
function CreateUnitDialog({ onClose, existingCodes }: { onClose: () => void; existingCodes: readonly string[] }) {
  const create = useCreateUnit();
  const cats = useWasteCategories(true);
  const [code, setCode] = useState('');
  const [codeEdited, setCodeEdited] = useState(false);
  const [name, setName] = useState('');
  const [location, setLocation] = useState('');
  const [capacity, setCapacity] = useState('');
  const [categoryId, setCategoryId] = useState<string>('unset');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const codeId = useId();
  const nameId = useId();
  const locId = useId();
  const capId = useId();
  const catId = useId();
  const notesId = useId();

  // `code` is a SLUG (lowercase, hyphen-separated). Auto-generate it
  // from the name — deduped with a `-2`/`-3` suffix against the drums
  // already on screen — until the operator edits the code by hand.
  function onNameChange(v: string) {
    setName(v);
    if (!codeEdited) {
      const base = slugify(v).slice(0, 32);
      setCode(uniqueSlug(base, existingCodes).slice(0, 32));
    }
  }
  // Manual edits keep the RAW value while typing so the field never
  // fights the operator (live per-keystroke slugify strips the
  // separator between words — "Hello World" → "helloworld"). The
  // value is normalized on blur and again on submit, so it always
  // honors the slug contract before it reaches the server.
  function onCodeChange(v: string) {
    setCode(v);
    setCodeEdited(true);
  }
  function onCodeBlur() {
    setCode(slugify(code).slice(0, 32));
  }

  function submit() {
    // Normalize the slug one final time so the server always receives
    // a clean value even if the field wasn't blurred before submit.
    const cleanCode = slugify(code).slice(0, 32);
    const payload = {
      code: cleanCode,
      display_name: name,
      location_code: location,
      spec_capacity_kg: capacity === '' ? undefined : Number(capacity),
      default_category_id: categoryId === 'unset' || categoryId === '' ? undefined : Number(categoryId),
      notes,
    };
    // Validate client-side against the shared schema so empty/invalid
    // fields surface inline instead of only as a server-error toast.
    const parsed = createUnitSchema.safeParse({ ...payload, spec_capacity_kg: capacity === '' ? '' : capacity, default_category_id: categoryId === 'unset' || categoryId === '' ? '' : categoryId });
    if (!parsed.success) {
      setErrors(Object.fromEntries(parsed.error.issues.map((i) => [String(i.path[0]), i.message])));
      return;
    }
    setErrors({});
    create.mutate(payload, {
      onSuccess: () => {
        setCode('');
        setName('');
        setLocation('');
        setCapacity('');
        setCategoryId('unset');
        setNotes('');
        onClose();
      },
      // Surface a server-side uniqueness conflict (409 on the `code`
      // field — e.g. the slug exists on a drum outside the loaded page)
      // inline under the slug input instead of only a toast.
      onError: (err) => {
        if (err instanceof ApiEnvelopeError) {
          const field = err.errors.find((e) => e.field === 'code');
          if (field !== undefined) {
            setErrors((prev) => ({ ...prev, code: field.message }));
            return;
          }
        }
        setErrors({});
      },
    });
  }

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <Plus className="size-4" /> New BMG drum
        </DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor={codeId}>Drum code (slug) *</Label>
            <Input
              id={codeId}
              value={code}
              onChange={(e) => onCodeChange(e.target.value)}
              onBlur={onCodeBlur}
              placeholder="drum-01"
              maxLength={32}
              aria-invalid={errors['code'] !== undefined}
              autoFocus
            />
            {errors['code'] !== undefined ? (
              <p role="alert" className="text-xs text-destructive">{errors['code']}</p>
            ) : (
              <p className="text-[10px] text-muted-foreground">URL-safe slug — lowercase, hyphen-separated (e.g. drum-01). Auto-filled from the name; appends a -2 suffix if the slug is taken. Read-only after creation.</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor={nameId}>Name *</Label>
            <Input id={nameId} value={name} onChange={(e) => onNameChange(e.target.value)} placeholder="Drum 01 - North Canopy" maxLength={128} aria-invalid={errors['display_name'] !== undefined} />
            {errors['display_name'] !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors['display_name']}</p>
            )}
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={locId}>Location</Label>
          <Input id={locId} value={location} onChange={(e) => setLocation(e.target.value)} placeholder="North campus, near the canteen" maxLength={64} />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor={capId}>Capacity (kg)</Label>
            <Input id={capId} type="number" min={0.01} step={0.01} value={capacity} onChange={(e) => setCapacity(e.target.value)} placeholder="120" />
          </div>
          <div className="space-y-1.5">
            <Label id={catId}>Default waste category</Label>
            <Select value={categoryId} onValueChange={setCategoryId}>
              <SelectTrigger aria-labelledby={catId} className="w-full">
                <SelectValue placeholder="Pick a category…" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="unset">— None —</SelectItem>
                {(cats.data ?? []).map((c) => (
                  <SelectItem key={c.id} value={String(c.id)}>
                    {c.name} <span className="font-mono text-xs text-muted-foreground">({c.code})</span>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-[10px] text-muted-foreground">Pre-fills the category on new batches started on this drum.</p>
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={notesId}>Notes</Label>
          <Textarea id={notesId} value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} maxLength={512} placeholder="Optional" />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button onClick={submit} disabled={create.isPending}>
          {create.isPending && <Loader2 className="animate-spin" />}
          <Plus /> Create drum
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

/**
 * EditUnitDialog — update an existing drum's mutable fields.
 *
 * Mirrors the legacy `bmg/drums/edit` form. The drum code is
 * intentionally not editable here (matches the legacy "Drum code cannot
 * be changed" rule) — it is shown disabled. The state machine status
 * is owned by the Actions rail, not by this form.
 */
function EditUnitDialog({ unit, onClose }: { unit: BmgUnit; onClose: () => void }) {
  const update = useUpdateUnit();
  const cats = useWasteCategories(true);
  const [name, setName] = useState(unit.display_name);
  const [location, setLocation] = useState(unit.location_code ?? '');
  const [capacity, setCapacity] = useState(
    unit.spec_capacity_kg !== null ? String(unit.spec_capacity_kg) : '',
  );
  const [categoryId, setCategoryId] = useState<string>(
    unit.default_category_id !== null && unit.default_category_id !== undefined
      ? String(unit.default_category_id)
      : 'unset',
  );
  const [notes, setNotes] = useState(unit.notes ?? '');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const nameId = useId();
  const locId = useId();
  const capId = useId();
  const catId = useId();
  const notesId = useId();

  function submit() {
    const input = {
      display_name: name,
      location_code: location,
      spec_capacity_kg: capacity === '' ? undefined : Number(capacity),
      default_category_id: categoryId === 'unset' || categoryId === '' ? undefined : Number(categoryId),
      notes,
    };
    const parsed = updateUnitSchema.safeParse({ ...input, spec_capacity_kg: capacity === '' ? '' : capacity, default_category_id: categoryId === 'unset' || categoryId === '' ? '' : categoryId });
    if (!parsed.success) {
      setErrors(Object.fromEntries(parsed.error.issues.map((i) => [String(i.path[0]), i.message])));
      return;
    }
    setErrors({});
    update.mutate({ unitId: unit.id, input }, { onSuccess: () => onClose() });
  }

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <Pencil className="size-4" /> Edit {unit.code}
        </DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label>Drum code</Label>
          <Input value={unit.code} disabled className="font-mono" />
          <p className="text-[10px] text-muted-foreground">Drum code cannot be changed.</p>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={nameId}>Name *</Label>
          <Input id={nameId} value={name} onChange={(e) => setName(e.target.value)} maxLength={128} aria-invalid={errors['display_name'] !== undefined} />
          {errors['display_name'] !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors['display_name']}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={locId}>Location</Label>
          <Input id={locId} value={location} onChange={(e) => setLocation(e.target.value)} maxLength={64} />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor={capId}>Capacity (kg)</Label>
            <Input id={capId} type="number" min={0.01} step={0.01} value={capacity} onChange={(e) => setCapacity(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label id={catId}>Default waste category</Label>
            <Select value={categoryId} onValueChange={setCategoryId}>
              <SelectTrigger aria-labelledby={catId} className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="unset">— None —</SelectItem>
                {(cats.data ?? []).map((c) => (
                  <SelectItem key={c.id} value={String(c.id)}>
                    {c.name} <span className="font-mono text-xs text-muted-foreground">({c.code})</span>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-[10px] text-muted-foreground">Pre-fills the category on new batches started on this drum.</p>
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={notesId}>Notes</Label>
          <Textarea id={notesId} value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} maxLength={512} />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button onClick={submit} disabled={update.isPending}>
          {update.isPending && <Loader2 className="animate-spin" />}
          Save changes
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

/**
 * ArchiveUnitDialog — confirm-driven soft archive. The button is
 * disabled while the unit has an active batch (server-side enforces
 * this too — 409 `statemachine.bmg.unit_has_active_batch`).
 */
function ArchiveUnitDialog({ unit, onClose }: { unit: BmgUnit; onClose: () => void }) {
  const archive = useArchiveUnit();
  const hasActiveBatch =
    unit.active_batch_id !== null && unit.active_batch_id !== undefined;

  function submit() {
    archive.mutate({ unitId: unit.id }, { onSuccess: () => onClose() });
  }

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2 text-destructive">
          <Archive className="size-4" /> Archive {unit.code}?
        </DialogTitle>
      </DialogHeader>
      <div className="space-y-2 text-sm text-muted-foreground">
        <p>
          The drum will be soft-archived (<code className="font-mono">archived_at</code> set)
          and removed from the active list. Audit history is preserved.
        </p>
        {hasActiveBatch && (
          <p className="rounded-md border border-destructive/30 bg-destructive/5 p-2 text-destructive">
            This drum still has an active batch. Finish or cancel it before archiving.
          </p>
        )}
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button
          variant="destructive"
          onClick={submit}
          disabled={archive.isPending || hasActiveBatch}
        >
          {archive.isPending && <Loader2 className="animate-spin" />}
          <Archive className="size-4" /> Archive drum
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

/**
 * ReleaseBatchDialog — the final QA gate (audit #4). Only shown for a
 * batch that reached AwaitingOutput or Curing: the operator records the
 * finished compost's quality grade + maturity level, which become the
 * batch's certificate fields and flip it to the terminal `released`
 * state (unit returns to Idle).
 */
function ReleaseBatchDialog({ unit, batchId, onClose }: { unit: BmgUnit; batchId: number; onClose: () => void }) {
  const release = useReleaseBatch();
  const [grade, setGrade] = useState<BmgQualityGrade | 'unset'>('unset');
  const [maturity, setMaturity] = useState<BmgMaturityLevel | 'unset'>('unset');
  const [notes, setNotes] = useState('');

  function submit() {
    if (grade === 'unset' || maturity === 'unset') {
      toast.error('Select a quality grade and maturity level.');
      return;
    }
    release.mutate(
      { unitId: unit.id, batchId, input: { quality_grade: grade, maturity_level: maturity, notes } },
      { onSuccess: () => onClose() },
    );
  }

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <ShieldCheck className="size-4 text-primary" /> Release batch #{batchId} on {unit.code}
        </DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <p className="text-xs text-muted-foreground">
          Final QA gate. Releasing makes the batch terminal and returns the drum to Idle.
          The grade + maturity are saved on the batch certificate.
        </p>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label id="quality-grade-label">Quality grade</Label>
            <Select value={grade} onValueChange={(v) => setGrade(v as BmgQualityGrade | 'unset')}>
              <SelectTrigger aria-labelledby="quality-grade-label"><SelectValue placeholder="Select…" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="unset">—</SelectItem>
                {BMG_QUALITY_GRADES.map((g) => <SelectItem key={g} value={g}>{titleCase(g)}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label id="maturity-label">Maturity level</Label>
            <Select value={maturity} onValueChange={(v) => setMaturity(v as BmgMaturityLevel | 'unset')}>
              <SelectTrigger aria-labelledby="maturity-label"><SelectValue placeholder="Select…" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="unset">—</SelectItem>
                {BMG_MATURITY_LEVELS.map((m) => <SelectItem key={m} value={m}>{titleCase(m)}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="release-notes">Notes (optional)</Label>
          <Textarea id="release-notes" rows={2} maxLength={512} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button onClick={submit} disabled={release.isPending}>
          {release.isPending && <Loader2 className="animate-spin" />}
          <ShieldCheck className="size-4" /> Release batch
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

/**
 * ComplianceDialog — the printable batch certificate (audit #2).
 * Shows the PFRP temperature evidence (thermophilic days, peak temp,
 * consecutive PFRP days), the mass-balance reconciliation, and the
 * final QA fields when released.
 */
function ComplianceDialog({ unit, batchId, onClose }: { unit: BmgUnit; batchId: number; onClose: () => void }) {
  const compliance = useBatchCompliance(batchId);
  const c = compliance.data;

  return (
    <DialogContent className="max-w-lg">
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <FileCheck2 className="size-4 text-primary" /> Batch certificate — #{batchId} on {unit.code}
        </DialogTitle>
      </DialogHeader>
      {compliance.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
      {c !== undefined && (
        <div className="space-y-3">
          <div className="flex items-center justify-between rounded-md border bg-muted/40 px-3 py-2 text-sm">
            <span className="font-mono">{c.reference_code}</span>
            <Badge variant={c.pfrp_met ? 'success' : 'warning'}>
              {c.pfrp_met ? 'PFRP met' : 'PFRP not met'}
            </Badge>
          </div>

          <div className="rounded-md border p-3">
            <p className="mb-2 text-xs font-medium text-muted-foreground">Pathogen-reduction evidence (PFRP)</p>
            <div className="grid grid-cols-2 gap-2 text-sm">
              <div>Thermophilic days (≥55°C): <span className="font-mono">{c.thermophilic_days}</span></div>
              <div>Peak temperature: <span className="font-mono">{c.max_temperature_c !== null ? `${c.max_temperature_c}°C` : '—'}</span></div>
              <div>Consecutive PFRP days: <span className="font-mono">{c.consecutive_pfrp_days}</span></div>
              <div>Status: <span className="font-mono">{titleCase(c.status)}</span></div>
            </div>
          </div>

          <div className="rounded-md border p-3">
            <p className="mb-2 text-xs font-medium text-muted-foreground">Mass balance</p>
            <div className="grid grid-cols-2 gap-2 text-sm">
              <div>Input: <span className="font-mono">{c.input_kg} kg</span></div>
              <div>Output: <span className="font-mono">{c.output_kg} kg</span></div>
              <div>Losses: <span className="font-mono">{c.loss_kg} kg</span></div>
              <div>In-process: <span className="font-mono">{c.in_process_kg} kg</span></div>
              <div>Yield: <span className="font-mono">{c.yield_pct !== null ? `${c.yield_pct}%` : '—'}</span></div>
              <div>Unaccounted: <span className="font-mono">{c.unaccounted_kg} kg</span></div>
            </div>
          </div>

          <div className="flex items-center gap-2 rounded-md border p-3 text-sm">
            <span className="text-xs font-medium text-muted-foreground">Final QA:</span>
            <Badge variant="secondary">{c.quality_grade !== null ? titleCase(c.quality_grade) : '—'}</Badge>
            <Badge variant="secondary">{c.maturity_level !== null ? titleCase(c.maturity_level) : '—'}</Badge>
            {c.released_at !== null && <span className="ml-auto text-xs text-muted-foreground">Released {fmtUtcToApp(c.released_at)}</span>}
          </div>
        </div>
      )}
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
      </DialogFooter>
    </DialogContent>
  );
}

/**
 * BatchHistoryDialog — audit surface (item #7): every finished /
 * cancelled / released batch across all units (or a single unit),
 * keyset-paginated. Read-only.
 */
function BatchHistoryDialog({ unitId, onClose }: { unitId: number | null; onClose: () => void }) {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const status = statusFilter === 'all' ? null : statusFilter;
  const batches = useBatchHistory(unitId, status, cursor, 25);

  function nextPage() {
    if (batches.data?.next !== null && batches.data?.next !== undefined) {
      const n = batches.data.next;
      setHistory((h) => [...h, n]);
      setCursor(n);
    }
  }
  function prevPage() {
    if (history.length < 2) return;
    const next = history.slice(0, -1);
    setHistory(next);
    setCursor(next[next.length - 1] ?? null);
  }

  return (
    <DialogContent className="max-w-5xl sm:max-w-5xl">
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <History className="size-4 text-primary" /> Batch history
          {unitId !== null && <span className="text-sm font-normal text-muted-foreground">— drum #{unitId}</span>}
        </DialogTitle>
      </DialogHeader>
      <div className="mb-2 flex items-center justify-between gap-2">
        <Select value={statusFilter} onValueChange={(v) => { setStatusFilter(v); setCursor(null); setHistory([null]); }}>
          <SelectTrigger aria-label="Filter by status" className="w-44"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            <SelectItem value="released">Released</SelectItem>
            <SelectItem value="idle">Finished</SelectItem>
            <SelectItem value="cancelled">Cancelled</SelectItem>
          </SelectContent>
        </Select>
        <span className="text-xs text-muted-foreground">Page {history.length}</span>
      </div>
      <div className="max-h-[60vh] overflow-auto rounded-md border">
        <Table>
          <TableHeader className="bg-muted/50 sticky top-0">
            <TableRow>
              <TableHead className="px-3">Ref</TableHead>
              <TableHead className="px-3">Drum</TableHead>
              <TableHead className="px-3">Status</TableHead>
              <TableHead className="px-3">Input</TableHead>
              <TableHead className="px-3">Output</TableHead>
              <TableHead className="px-3">QA</TableHead>
              <TableHead className="px-3">Started</TableHead>
              <TableHead className="px-3">Ended</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {batches.isLoading && (
              <TableRow><TableCell colSpan={8} className="px-3 py-6 text-center"><Loader2 className="mx-auto size-4 animate-spin" /></TableCell></TableRow>
            )}
            {!batches.isLoading && (batches.data?.data.length ?? 0) === 0 && (
              <TableRow><TableCell colSpan={8} className="px-3 py-6 text-center text-muted-foreground">No batches in history.</TableCell></TableRow>
            )}
            {batches.data?.data.map((b) => (
              <TableRow key={b.id}>
                <TableCell className="px-3 font-mono text-xs">{b.reference_code}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{b.unit_code}</TableCell>
                <TableCell className="px-3"><Badge variant={b.status === 'released' ? 'success' : b.status === 'cancelled' ? 'destructive' : 'secondary'}>{titleCase(b.status)}</Badge></TableCell>
                <TableCell className="px-3 font-mono text-xs">{b.total_input_weight_kg} kg</TableCell>
                <TableCell className="px-3 font-mono text-xs">{b.output_weight_kg !== null ? `${b.output_weight_kg} kg` : '—'}</TableCell>
                <TableCell className="px-3 text-xs">
                  {b.quality_grade !== null && <Badge variant="secondary" className="mr-1">{titleCase(b.quality_grade)}</Badge>}
                  {b.maturity_level !== null && <Badge variant="secondary">{titleCase(b.maturity_level)}</Badge>}
                  {b.quality_grade === null && b.maturity_level === null && <span className="text-muted-foreground">—</span>}
                </TableCell>
                <TableCell className="px-3 text-xs text-muted-foreground">{fmtUtcToApp(b.started_at)}</TableCell>
                <TableCell className="px-3 text-xs text-muted-foreground">
                  {b.released_at !== null ? fmtUtcToApp(b.released_at) : b.finished_at !== null ? fmtUtcToApp(b.finished_at) : b.cancelled_at !== null ? fmtUtcToApp(b.cancelled_at) : '—'}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      <div className="flex items-center justify-between">
        <Button variant="outline" size="sm" onClick={prevPage} disabled={history.length < 2}><ChevronLeft /> Prev</Button>
        <Button variant="outline" size="sm" onClick={nextPage} disabled={batches.data?.next === null || batches.data?.next === undefined}>Next <ChevronRight /></Button>
      </div>
      <DialogFooter><Button variant="outline" onClick={onClose}>Close</Button></DialogFooter>
    </DialogContent>
  );
}

/**
 * OpenAlertsBanner — global "at-risk" surface (audit #3). Shows any
 * unacknowledged warning/critical alert across live batches so
 * operators notice process trouble without opening each drum.
 */
function OpenAlertsBanner() {
  const open = useOpenAlerts();
  const items = (open.data ?? []).filter((a) => a.severity !== 'info');
  if (items.length === 0) return null;
  return (
    <div className="flex items-start gap-2 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm">
      <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
      <div className="space-y-1">
        <p className="font-medium text-destructive">{items.length} open BMG alert{items.length > 1 ? 's' : ''} on live batches</p>
        <ul className="space-y-0.5 text-xs text-muted-foreground">
          {items.slice(0, 3).map((a) => (
            <li key={a.alert_id}>
              <span className="font-mono">{a.reference_code}</span> · <Badge variant={a.severity === 'critical' ? 'destructive' : 'warning'}>{a.severity}</Badge> · {a.message}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

export default function FacilitiesPage() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [showArchived, setShowArchived] = useState(false);
  const units = useBmgUnits(cursor, 50, showArchived);
  const finish = useFinishBatch();
  const cancel = useCancelBatch();
  const unarchiveUnit = useUnarchiveUnit();
  const [openStart, setOpenStart] = useState<BmgUnit | null>(null);
  const [openOutput, setOpenOutput] = useState<BmgUnit | null>(null);
  const [openUpdate, setOpenUpdate] = useState<BmgUnit | null>(null);
  const [openUpdates, setOpenUpdates] = useState<BmgUnit | null>(null);
  const [openFinish, setOpenFinish] = useState<BmgUnit | null>(null);
  const [openStatus, setOpenStatus] = useState<BmgUnit | null>(null);
  const [openLogs, setOpenLogs] = useState<BmgUnit | null>(null);
  const [openAnalytics, setOpenAnalytics] = useState<BmgUnit | null>(null);
  const [openRelease, setOpenRelease] = useState<BmgUnit | null>(null);
  const [openCompliance, setOpenCompliance] = useState<{ unit: BmgUnit; batchId: number } | null>(null);
  const [openHistory, setOpenHistory] = useState<number | 'all' | null>(null);
  const [openCreate, setOpenCreate] = useState(false);
  const [openEdit, setOpenEdit] = useState<BmgUnit | null>(null);
  const [openArchive, setOpenArchive] = useState<BmgUnit | null>(null);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);

  // Legacy deep-link — the old sidebar entry pointed here with
  // `?open=waste-categories` to auto-open a dialog. Waste categories
  // now have their own screen, so redirect stale links there.
  const [searchParams] = useSearchParams();
  if (searchParams.get('open') === 'waste-categories') {
    return <Navigate to="/facilities/waste-categories" replace />;
  }

  function nextPage() {
    if (units.data?.next !== null && units.data?.next !== undefined) {
      const n = units.data.next;
      setHistory((h) => [...h, n]);
      setCursor(n);
    }
  }
  function prevPage() {
    if (history.length < 2) return;
    const next = history.slice(0, -1);
    setHistory(next);
    setCursor(next[next.length - 1] ?? null);
  }

  // Shared compact menu for desktop rows and mobile cards.
  const unitActions = (u: BmgUnit) => {
    const activeBatch = u.active_batch_id ?? null;
    const archived = u.archived_at !== null && u.archived_at !== undefined;
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button className="min-h-11" size="sm" variant="outline" aria-label={`Actions for ${u.code}`}>
            Actions <ChevronDown className="size-3.5" aria-hidden />
          </Button>
        </DropdownMenuTrigger>
        {/* The BMG unit menu grew to ~15 actions (Start / Finish /
            Release / logs / analytics / compliance / history / …), taller
            than most viewports. Bound it and let it scroll instead of
            cropping at the screen edge. */}
        <DropdownMenuContent align="end" className="max-h-[min(24rem,60vh)] w-52 overflow-y-auto">
          <DropdownMenuItem className="min-h-11" onSelect={() => setOpenEdit(u)}>
            <Pencil /> Edit drum
          </DropdownMenuItem>
        {archived ? (
          <DropdownMenuItem className="min-h-11" disabled={unarchiveUnit.isPending} onSelect={() => unarchiveUnit.mutate({ unitId: u.id })}>
            <ArchiveRestore /> Restore
          </DropdownMenuItem>
        ) : (
          <DropdownMenuItem className="min-h-11" onSelect={() => setOpenArchive(u)}>
            <Archive /> Archive
          </DropdownMenuItem>
        )}
          <DropdownMenuSeparator />
          <DropdownMenuItem className="min-h-11" disabled={u.status !== 'idle'} onSelect={() => setOpenStart(u)}>
            <Play /> Start batch
          </DropdownMenuItem>
          {/* 2. Add update — ONE action; output/curing/log internally. */}
          <DropdownMenuItem className="min-h-11" disabled={!['processing', 'awaiting_output', 'curing'].includes(u.status) || activeBatch === null} onSelect={() => activeBatch !== null && setOpenUpdate(u)}>
            <SquarePen /> Add update
          </DropdownMenuItem>
          {/* 6a. Combined append-only Updates feed. */}
          <DropdownMenuItem className="min-h-11" disabled={activeBatch === null} onSelect={() => activeBatch !== null && setOpenUpdates(u)}>
            <List /> View updates
          </DropdownMenuItem>
          {/* 3. Finish = graded release (requires QA). */}
          <DropdownMenuItem
          className="min-h-11"
          disabled={!['processing', 'awaiting_output', 'curing'].includes(u.status) || activeBatch === null || finish.isPending}
          onSelect={() => activeBatch !== null && setOpenFinish(u)}
        >
            <StopCircle /> Finish batch
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          {/* 6b. View history (read-only). */}
          <DropdownMenuItem className="min-h-11" onSelect={() => setOpenHistory(u.id)}>
            <History /> Batch history
          </DropdownMenuItem>
          <DropdownMenuItem className="min-h-11" disabled={activeBatch === null} onSelect={() => activeBatch !== null && setOpenCompliance({ unit: u, batchId: activeBatch })}>
            <FileCheck2 /> Certificate / compliance
          </DropdownMenuItem>
          <DropdownMenuItem className="min-h-11" disabled={activeBatch === null} onSelect={() => setOpenAnalytics(u)}>
            <LineChart /> Analytics
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          {/* 5. Drum status (no active batch only). */}
          <DropdownMenuItem
          className="min-h-11"
          disabled={activeBatch !== null}
          onSelect={() => setOpenStatus(u)}
        >
            <Wrench /> Drum status
          </DropdownMenuItem>
          <DropdownMenuItem
          className="min-h-11 text-destructive focus:text-destructive"
          disabled={!['processing', 'awaiting_output', 'curing'].includes(u.status) || activeBatch === null || cancel.isPending}
          onSelect={() => activeBatch !== null && setConfirm({
            title: `Cancel batch #${activeBatch} on ${u.code}?`,
            description: 'The batch will be cancelled and the drum returned to Idle. This cannot be undone.',
            confirmLabel: 'Cancel batch',
            run: () => cancel.mutate({ unitId: u.id, batchId: activeBatch, input: { reason_code: 'unspecified' } }),
          })}
        >
            <Ban /> Cancel batch
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    );
  };

  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Facilities — BMG</h1>
          <p className="text-sm text-muted-foreground">
            State machine: Idle → Processing → Awaiting output → Idle (or Cancelled). Units can be set to Maintenance.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant={showArchived ? 'secondary' : 'outline'}
            aria-pressed={showArchived}
            onClick={() => { setShowArchived((v) => !v); setCursor(null); setHistory([null]); }}
          >
            <Archive /> {showArchived ? 'Hide archived' : 'Show archived'}
          </Button>
          <Button variant="outline" onClick={() => setOpenHistory('all')}>
            <History /> Batch history
          </Button>
          {/* The primary "New drum" action sits ALONE at the far right,
              visually separated from the utility buttons by a divider,
              so the prominent CTA reads as its own group. (Waste
              categories was removed — it's already in the sidebar.) */}
          <div className="mx-1 hidden h-6 w-px bg-border sm:block" aria-hidden />
          <Dialog open={openCreate} onOpenChange={setOpenCreate}>
            <Button onClick={() => setOpenCreate(true)}>
              <Plus /> New drum
            </Button>
            {openCreate && (
              <CreateUnitDialog
                onClose={() => setOpenCreate(false)}
                existingCodes={units.data?.data.map((u) => u.code) ?? []}
              />
            )}
          </Dialog>
        </div>
      </header>

      <OpenAlertsBanner />

      <ProcessingDrumsCard />

      <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">Code</TableHead>
              <TableHead className="px-3">Name</TableHead>
              <TableHead className="px-3">Status</TableHead>
              <TableHead className="px-3">Active batch</TableHead>
              <TableHead className="px-3">Utilization</TableHead>
              <TableHead className="px-3">Location</TableHead>
              <TableHead className="px-3">Created</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {units.isLoading && (
              <TableRow>
                <TableCell colSpan={8} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!units.isLoading && (units.data?.data.length ?? 0) === 0 && (
              <TableRow>
                <TableCell colSpan={8} className="px-3 py-6 text-center text-muted-foreground">
                  No drums yet. Create one to start composting.
                </TableCell>
              </TableRow>
            )}
            {units.data?.data.map((u) => {
              const activeBatch = u.active_batch_id ?? null;
              return (
                <TableRow key={u.id} id={`unit-${u.id}`} className="scroll-mt-24">
                  <TableCell className="px-3 font-mono text-xs">{u.code}</TableCell>
                  <TableCell className="px-3">{u.display_name}</TableCell>
                  <TableCell className="px-3">
                    <Badge variant={unitStatusVariant(u.status)}>{statusLabel(u.status)}</Badge>
                    {u.archived_at !== null && u.archived_at !== undefined && (
                      <Badge variant="secondary" className="ml-1.5">Archived</Badge>
                    )}
                  </TableCell>
                  <TableCell className="px-3 font-mono text-xs text-muted-foreground">
                    {activeBatch === null ? '—' : `#${activeBatch}`}
                  </TableCell>
                  <TableCell className="px-3">
                    {activeBatch === null || u.utilization_pct === undefined ? (
                      <span className="text-xs text-muted-foreground">—</span>
                    ) : (
                      <div className="flex items-center gap-2">
                        <div className="h-1.5 w-16 overflow-hidden rounded-full bg-muted">
                          <div
                            className={`h-full rounded-full ${u.utilization_pct >= 90 ? 'bg-destructive' : u.utilization_pct >= 70 ? 'bg-warning' : 'bg-success'}`}
                            style={{ width: `${Math.min(u.utilization_pct, 100)}%` }}
                          />
                        </div>
                        <span className="font-mono text-xs text-muted-foreground">{u.utilization_pct}%</span>
                      </div>
                    )}
                  </TableCell>
                  <TableCell className="px-3 text-xs text-muted-foreground">
                    <div className="flex flex-col gap-0.5">
                      <span>{u.location_code ?? '—'}</span>
                      {u.default_category_name !== null && u.default_category_name !== undefined && (
                        <span className="inline-flex w-fit items-center gap-1 rounded-md bg-secondary px-1.5 py-0.5 text-[10px] font-medium text-secondary-foreground">
                          <Boxes className="size-2.5" /> {u.default_category_name}
                        </span>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="px-3 text-xs text-muted-foreground">{fmtUtcToApp(u.created_at)}</TableCell>
                  <TableCell className="px-3 text-right">
                    {unitActions(u)}
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </section>

      {/* Mobile: drum cards from the same rows. Actions carry visible
          labels (touch-friendly) instead of the desktop icon rail. */}
      {units.isLoading && (
        <p className="py-6 text-center text-sm text-muted-foreground md:hidden" role="status">
          <Loader2 className="mx-auto size-4 animate-spin" />
        </p>
      )}
      {!units.isLoading && (units.data?.data.length ?? 0) === 0 && (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground md:hidden">
          No drums yet. Create one to start composting.
        </p>
      )}
      <MobileCardList>
        {units.data?.data.map((u) => {
          const activeBatch = u.active_batch_id ?? null;
          const archived = u.archived_at !== null && u.archived_at !== undefined;
          return (
            <MobileCard key={u.id} aria-label={`Drum ${u.code}`}>
              <div className="mb-1 flex items-center justify-between gap-2">
                <span className="font-mono text-sm font-medium text-foreground">{u.code}</span>
                <div className="flex flex-wrap justify-end gap-1.5">
                  <Badge variant={unitStatusVariant(u.status)}>{statusLabel(u.status)}</Badge>
                  {archived && <Badge variant="secondary">Archived</Badge>}
                </div>
              </div>
              <p className="text-sm text-foreground">{u.display_name}</p>
              <MobileCardField label="Active batch">
                <span className="font-mono text-xs text-muted-foreground">{activeBatch === null ? '—' : `#${activeBatch}`}</span>
              </MobileCardField>
              {activeBatch !== null && u.utilization_pct !== undefined && (
                <MobileCardField label="Utilization">
                  <div className="flex items-center gap-2">
                    <div className="h-1.5 w-16 overflow-hidden rounded-full bg-muted">
                      <div
                        className={`h-full rounded-full ${u.utilization_pct >= 90 ? 'bg-destructive' : u.utilization_pct >= 70 ? 'bg-warning' : 'bg-success'}`}
                        style={{ width: `${Math.min(u.utilization_pct, 100)}%` }}
                      />
                    </div>
                    <span className="font-mono text-xs text-muted-foreground">{u.utilization_pct}%</span>
                  </div>
                </MobileCardField>
              )}
              <MobileCardField label="Location">
                <span className="text-xs text-muted-foreground">{u.location_code ?? '—'}</span>
              </MobileCardField>
              {u.default_category_name !== null && u.default_category_name !== undefined && (
                <MobileCardField label="Default category">
                  <span className="inline-flex items-center gap-1 text-xs"><Boxes className="size-3" /> {u.default_category_name}</span>
                </MobileCardField>
              )}
              <MobileCardActions>{unitActions(u)}</MobileCardActions>
            </MobileCard>
          );
        })}
      </MobileCardList>

      <nav className="flex items-center justify-between" aria-label="pagination">
        <p className="text-xs text-muted-foreground">Page {history.length}</p>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={prevPage} disabled={history.length < 2}>
            <ChevronLeft /> Prev
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={nextPage}
            disabled={units.data?.next === null || units.data?.next === undefined}
          >
            Next <ChevronRight />
          </Button>
        </div>
      </nav>

      {openStart !== null && (
        <Dialog open onOpenChange={(open) => !open && setOpenStart(null)}>
          <StartBatchDialog unit={openStart} onClose={() => setOpenStart(null)} />
        </Dialog>
      )}

      {openOutput !== null && openOutput.active_batch_id !== null && openOutput.active_batch_id !== undefined && (
        <Dialog open onOpenChange={(o) => !o && setOpenOutput(null)}>
          <RecordOutputDialog
            unit={openOutput}
            batchId={openOutput.active_batch_id}
            onClose={() => setOpenOutput(null)}
          />
        </Dialog>
      )}

      {openUpdate !== null && openUpdate.active_batch_id !== null && openUpdate.active_batch_id !== undefined && (
        <Dialog open onOpenChange={(o) => !o && setOpenUpdate(null)}>
          <AddUpdateDialog
            unit={openUpdate}
            batchId={openUpdate.active_batch_id}
            onClose={() => setOpenUpdate(null)}
          />
        </Dialog>
      )}

      {openUpdates !== null && openUpdates.active_batch_id !== null && openUpdates.active_batch_id !== undefined && (
        <Dialog open onOpenChange={(o) => !o && setOpenUpdates(null)}>
          <UpdatesFeedDialog
            unit={openUpdates}
            batchId={openUpdates.active_batch_id}
            onClose={() => setOpenUpdates(null)}
          />
        </Dialog>
      )}

      {openFinish !== null && openFinish.active_batch_id !== null && openFinish.active_batch_id !== undefined && (
        <Dialog open onOpenChange={(o) => !o && setOpenFinish(null)}>
          <FinishBatchDialog
            unit={openFinish}
            batchId={openFinish.active_batch_id}
            onClose={() => setOpenFinish(null)}
          />
        </Dialog>
      )}

      {openStatus !== null && (
        <Dialog open onOpenChange={(o) => !o && setOpenStatus(null)}>
          <DrumStatusDialog unit={openStatus} onClose={() => setOpenStatus(null)} />
        </Dialog>
      )}

      {openLogs !== null && openLogs.active_batch_id !== null && openLogs.active_batch_id !== undefined && (
        <Dialog open onOpenChange={(o) => !o && setOpenLogs(null)}>
          <ProcessLogsDialog
            unit={openLogs}
            batchId={openLogs.active_batch_id}
            onClose={() => setOpenLogs(null)}
          />
        </Dialog>
      )}

      {openAnalytics !== null && openAnalytics.active_batch_id !== null && openAnalytics.active_batch_id !== undefined && (
        <Dialog open onOpenChange={(o) => !o && setOpenAnalytics(null)}>
          <AnalyticsDialog
            unit={openAnalytics}
            batchId={openAnalytics.active_batch_id}
            onClose={() => setOpenAnalytics(null)}
          />
        </Dialog>
      )}

      {openRelease !== null && openRelease.active_batch_id !== null && openRelease.active_batch_id !== undefined && (
        <Dialog open onOpenChange={(o) => !o && setOpenRelease(null)}>
          <ReleaseBatchDialog
            unit={openRelease}
            batchId={openRelease.active_batch_id}
            onClose={() => setOpenRelease(null)}
          />
        </Dialog>
      )}

      {openCompliance !== null && (
        <Dialog open onOpenChange={(o) => !o && setOpenCompliance(null)}>
          <ComplianceDialog
            unit={openCompliance.unit}
            batchId={openCompliance.batchId}
            onClose={() => setOpenCompliance(null)}
          />
        </Dialog>
      )}

      {openHistory !== null && (
        <Dialog open onOpenChange={(o) => !o && setOpenHistory(null)}>
          <BatchHistoryDialog unitId={openHistory === 'all' ? null : openHistory} onClose={() => setOpenHistory(null)} />
        </Dialog>
      )}

      {openEdit !== null && (
        <Dialog open onOpenChange={(o) => !o && setOpenEdit(null)}>
          <EditUnitDialog unit={openEdit} onClose={() => setOpenEdit(null)} />
        </Dialog>
      )}

      {openArchive !== null && (
        <Dialog open onOpenChange={(o) => !o && setOpenArchive(null)}>
          <ArchiveUnitDialog unit={openArchive} onClose={() => setOpenArchive(null)} />
        </Dialog>
      )}

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        pending={finish.isPending || cancel.isPending}
        onConfirm={() => {
          confirm?.run();
          setConfirm(null);
        }}
        onCancel={() => setConfirm(null)}
      />
    </main>
  );
}
