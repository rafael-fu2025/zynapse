/**
 * FacilitiesPage — BMG state machine control surface.
 *
 * Lists units (with their `active_batch_id` joined in) and lets the
 * operator start, log an update, finish, or cancel a batch. TanStack
 * Query mutations apply optimistic state transitions to the unit's
 * status badge and roll back on error. shadcn Table / Dialog /
 * Textarea primitives.
 */
import { Play, StopCircle, Loader2, Ban, ChevronDown, ChevronLeft, ChevronRight, Boxes, Cpu, Info, LineChart, Plus, Wrench, Pencil, Archive, ArchiveRestore, FileCheck2, TriangleAlert, History, Sparkles, SquarePen, List } from 'lucide-react';
import { useId, useState, type ReactNode } from 'react';
import { Link, Navigate, useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { useKeysetPagination } from '@/hooks/useKeysetPagination';
import { useUrlFilter } from '@/hooks/useUrlFilter';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { CopyButton } from '@/components/CopyButton';
import { MobileCardList, MobileCard, MobileCardField, MobileCardActions, MobileCardListState } from '@/components/MobileCardList';
import { TableStateRows } from '@/components/TableStates';
import { ProcessingDrums } from '@/components/facilities/ProcessingDrums';
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
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import {
  useAcknowledgeAlert,
  useActiveBatches,
  useAddBatchIo,
  useAddBatchUpdate,
  useArchiveUnit,
  useBatchAnalytics,
  useBatchCompliance,
  useBatchHistory,
  useBatchUpdates,
  useBlendCn,
  useBmgUnits,
  useBmgDevices,
  useCancelBatch,
  useCreateUnit,
  useFinishBatch,
  useOpenAlerts,
  useSetUnitMaintenance,
  useStartBatch,
  useUnarchiveUnit,
  useUpdateUnit,
  useWasteCategories,
} from '@/hooks/useFacilities';
import {
  BMG_MATURITY_LEVELS,
  BMG_PROCESS_EVENT_TYPES,
  BMG_QUALITY_GRADES,
  MOISTURE_LEVELS,
  addBatchUpdateSchema,
  createUnitSchema,
  startBatchSchema,
  updateUnitSchema,
  type BmgMaturityLevel,
  type BmgProcessEventType,
  type BmgQualityGrade,
  type BmgUnit,
  type MoistureLevel,
} from '@/schemas/facilities';
import { ApiEnvelopeError } from '@/api/envelope';
import { fmtHumanDate, fmtUtcToApp } from '@/utils/date';
import { slugify, uniqueSlug } from '@/utils/slug';
import { statusLabel } from '@/utils/status';
import { titleCase } from '@/lib/utils';
import { bmgActionAvailability, availableBmgDevices } from '@/lib/bmgActions';

function unitStatusVariant(status: BmgUnit['status']): 'default' | 'info' | 'warning' | 'success' | 'destructive' | 'secondary' {
  switch (status) {
    case 'idle': return 'success';
    case 'processing': return 'info';
    case 'awaiting_output': return 'warning';
    case 'cancelled': return 'destructive';
    case 'maintenance': return 'secondary';
    default: return 'default';
  }
}

/**
 * FieldInfo — hoverable info icon that sits beside a form label and
 * carries the field's explanation. Keeps long helper text out of the
 * form body (same tooltip pattern as the PageHeader description);
 * the trigger is a real button so keyboard focus reveals it too.
 */
function FieldInfo({ label, children }: { label: string; children: ReactNode }) {
  return (
    <TooltipProvider delayDuration={150}>
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            type="button"
            aria-label={label}
            className="rounded-full text-muted-foreground/70 transition-colors hover:text-foreground focus-visible:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <Info aria-hidden className="size-3.5" />
          </button>
        </TooltipTrigger>
        <TooltipContent side="top" className="max-w-xs text-left leading-relaxed">
          {children}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}

function StartBatchDialog({ unit, onClose }: { unit: BmgUnit; onClose: () => void }) {
  const start = useStartBatch();
  // Starting the drum IS starting the batch — one field, no ceremony.
  // The waste mix was designated on the drum at setup; the backend
  // derives the ETA from the drum's first designated category (or the
  // platform default) and commands the drum's ESP32 to start.
  const [totalKg, setTotalKg] = useState('');
  const weightId = useId();

  function submit() {
    const total = Number(totalKg);
    if (!Number.isFinite(total) || total <= 0) {
      toast.error('Enter the total input weight (kg).');
      return;
    }
    if (unit.spec_capacity_kg !== null && unit.spec_capacity_kg > 0 && total > unit.spec_capacity_kg) {
      toast.error(`Total input weight (${total.toFixed(2)} kg) exceeds this unit's capacity (${unit.spec_capacity_kg} kg).`);
      return;
    }
    const parsed = startBatchSchema.safeParse({ total_input_weight_kg: total });
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
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle>Start batch on {unit.code}</DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <p className="text-xs text-muted-foreground">
          Starting this drum starts the batch and commands its ESP32. The waste mix was
          set on the drum — only the loaded weight is recorded here.
        </p>
        <div className="space-y-1.5">
          <Label htmlFor={weightId}>Total input weight (kg) *</Label>
          <Input
            id={weightId}
            type="number"
            min={0}
            step={0.01}
            value={totalKg}
            onChange={(e) => setTotalKg(e.target.value)}
            placeholder="e.g. 8"
            autoFocus
          />
          {unit.spec_capacity_kg !== null && unit.spec_capacity_kg > 0 && (
            <p className="text-[0.6875rem] text-muted-foreground">
              Unit capacity (both drums): {unit.spec_capacity_kg} kg
            </p>
          )}
        </div>
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

/**
 * 2. Add update — ONE plain-language action that appends an immutable
 * ledger observation. The final output (yield) is recorded when you
 * finish the batch; the transition to `awaiting_output` happens there
 * too. Mirrors the mobile `_addUpdate`.
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
    <DialogContent lockDismiss>
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
 * output / log entries, oldest → newest, plus any historical `curing`
 * rows predating that state's retirement. Read-only.
 */
function UpdatesFeedDialog({ unit, batchId, onClose }: { unit: BmgUnit; batchId: number; onClose: () => void }) {
  const updates = useBatchUpdates(batchId);
  return (
    <DialogContent className="max-h-[70vh] overflow-y-auto">
      <DialogHeader>
        <DialogTitle>Updates — batch #{batchId} on {unit.code}</DialogTitle>
        <p className="text-sm text-muted-foreground">
          Append-only record of every output and log entry for this batch.
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
    <DialogContent lockDismiss>
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
          <div>Input: <span className="tabular-nums">{a.input_kg} kg</span></div>
          <div>Output: <span className="tabular-nums">{a.output_kg} kg</span></div>
          <div>Yield: <Badge variant="info">{a.yield_pct}%</Badge> <span className="text-xs text-muted-foreground">({a.yield_class})</span></div>
          <div>Mass reduction: <span className="tabular-nums">{a.mass_reduction_pct}%</span></div>
          {a.expected_yield_pct !== null && <div>Expected: <span className="tabular-nums">{a.expected_yield_pct}%</span></div>}
          {a.expected_days !== null && <div>Expected days: <span className="tabular-nums">{a.expected_days}</span> <span className="text-xs text-muted-foreground">(mix-weighted)</span></div>}
          {a.expected_completion_date !== null && <div>ETA: <span>{fmtHumanDate(a.expected_completion_date)}</span></div>}
          {a.days_until_expected !== null && <div>Days left: <span className="tabular-nums">{a.days_until_expected}</span></div>}
          {a.progress_pct !== null && <div>Progress: <span className="tabular-nums">{a.progress_pct}%</span></div>}
        </div>
      )}

      {blend.data !== undefined && (
        <div className={`rounded-md border p-3 text-sm ${blend.data.status === 'optimal' ? 'border-emerald-500/30 bg-emerald-500/5' : blend.data.status === 'unknown' ? '' : 'border-amber-500/30 bg-amber-500/5'}`}>
          <p className="mb-1 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
            <Sparkles className="size-3.5" /> Feedstock C:N blend
          </p>
          {blend.data.blend_cn !== null ? (
            <div className="flex items-center gap-2">
              <span className="tabular-nums font-semibold">{blend.data.blend_cn}</span>
              <Badge variant={blend.data.status === 'optimal' ? 'success' : 'warning'}>
                {blend.data.status === 'optimal' ? 'Optimal (15–30)' : blend.data.status === 'high' ? 'Too high' : blend.data.status === 'low' ? 'Too low' : 'Unknown'}
              </Badge>
              <span className="text-xs text-muted-foreground">({blend.data.n_inputs} inputs)</span>
            </div>
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
                <span className="tabular-nums text-xs text-muted-foreground">
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
                {BMG_QUALITY_GRADES.map((g) => <SelectItem key={g} value={g}>{titleCase(g)}</SelectItem>)}
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
 * CreateUnitDialog — register a new BMG drum.
 *
 * Mirrors the legacy `bmg/drums/create` form: code, name, location,
 * capacity, notes — plus the REQUIRED ESP32 integration. Every drum
 * carries exactly one registered device, so when the whole fleet is
 * bound the form is blocked with a pointer at the Devices screen
 * (the backend refuses the create for the same reason).
 */
function CreateUnitDialog({ onClose, existingCodes }: { onClose: () => void; existingCodes: readonly string[] }) {
  const create = useCreateUnit();
  const cats = useWasteCategories(true);
  const devices = useBmgDevices(null, 100, false);
  const [code, setCode] = useState('');
  const [codeEdited, setCodeEdited] = useState(false);
  const [name, setName] = useState('');
  const [location, setLocation] = useState('');
  const [drumOne, setDrumOne] = useState('');
  const [drumTwo, setDrumTwo] = useState('');
  const [selectedCats, setSelectedCats] = useState<string[]>([]);
  const [deviceId, setDeviceId] = useState<string>('unset');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const codeId = useId();
  const nameId = useId();
  const locId = useId();
  const capOneId = useId();
  const capTwoId = useId();
  const deviceIdId = useId();
  const notesId = useId();

  const availableDevices = availableBmgDevices(devices.data?.data ?? []);
  // Fleet gate: a drum cannot be created while every registered ESP32
  // is bound to another drum. `isSuccess` (not `!isLoading`) so a
  // failed devices query never reads as "fleet exhausted".
  const fleetExhausted = devices.isSuccess && availableDevices.length === 0;

  // The unit's rated capacity is the SUM of its two drums — shown live
  // so the operator sees what the system will enforce.
  const drumSum = drumOne !== '' && drumTwo !== '' ? (Number(drumOne) + Number(drumTwo)).toFixed(2) : null;

  function toggleCat(id: string) {
    setSelectedCats((sel) => (sel.includes(id) ? sel.filter((c) => c !== id) : [...sel, id]));
  }

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
    if (deviceId === 'unset') {
      setErrors({ device_id: 'Select the ESP32 device to integrate.' });
      return;
    }
    // Drum capacities arrive as a pair — a lone value is a form bug.
    if ((drumOne === '') !== (drumTwo === '')) {
      setErrors({ drum_one_capacity_kg: 'Provide both drums\u2019 capacities — the unit capacity is their sum.' });
      return;
    }
    const payload = {
      code: cleanCode,
      display_name: name,
      location_code: location,
      drum_one_capacity_kg: drumOne === '' ? undefined : Number(drumOne),
      drum_two_capacity_kg: drumTwo === '' ? undefined : Number(drumTwo),
      category_ids: selectedCats.map(Number),
      notes,
      device_id: Number(deviceId),
    };
    // Validate client-side against the shared schema so empty/invalid
    // fields surface inline instead of only as a server-error toast.
    const parsed = createUnitSchema.safeParse({
      ...payload,
      drum_one_capacity_kg: drumOne,
      drum_two_capacity_kg: drumTwo,
    });
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
        setDrumOne('');
        setDrumTwo('');
        setSelectedCats([]);
        setDeviceId('unset');
        setNotes('');
        onClose();
      },
      // Surface a server-side conflict inline under its input instead
      // of only a toast — a slug that exists outside the loaded page
      // (409 on `code`), or an ESP32 that got bound between page load
      // and submit (409 on `device_id`).
      onError: (err) => {
        if (err instanceof ApiEnvelopeError) {
          const field = err.errors.find((e) =>
            ['code', 'device_id', 'category_ids', 'drum_one_capacity_kg', 'drum_two_capacity_kg'].includes(e.field ?? ''),
          );
          if (field !== undefined) {
            setErrors((prev) => ({ ...prev, [field.field ?? 'code']: field.message }));
            return;
          }
        }
        setErrors({});
      },
    });
  }

  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <Plus className="size-4" /> New BMG drum
        </DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <div className="flex items-center gap-1">
              <Label htmlFor={codeId}>Drum code (slug) *</Label>
              <FieldInfo label="About the drum code">
                URL-safe slug — lowercase, hyphen-separated (e.g. drum-01). Auto-filled from the name; appends a -2 suffix if the slug is taken. Read-only after creation.
              </FieldInfo>
            </div>
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
            {errors['code'] !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors['code']}</p>
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
        <div className="space-y-1.5">
          <div className="flex items-center gap-1">
            <Label>Drum capacities (kg)</Label>
            <FieldInfo label="About the capacities">
              Each unit has 2 drums — enter EACH drum&rsquo;s capacity (at least 4 kg each). The unit&rsquo;s rated capacity is their sum.
            </FieldInfo>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor={capOneId} className="text-xs text-muted-foreground">Drum 1</Label>
              <Input
                id={capOneId}
                type="number"
                min={4}
                step={0.01}
                value={drumOne}
                onChange={(e) => setDrumOne(e.target.value)}
                placeholder="at least 4"
                aria-invalid={errors['drum_one_capacity_kg'] !== undefined || errors['drum_two_capacity_kg'] !== undefined}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor={capTwoId} className="text-xs text-muted-foreground">Drum 2</Label>
              <Input
                id={capTwoId}
                type="number"
                min={4}
                step={0.01}
                value={drumTwo}
                onChange={(e) => setDrumTwo(e.target.value)}
                placeholder="at least 4"
                aria-invalid={errors['drum_one_capacity_kg'] !== undefined || errors['drum_two_capacity_kg'] !== undefined}
              />
            </div>
          </div>
          {drumSum !== null && (
            <p className="text-xs text-muted-foreground">
              Unit capacity (sum of both drums): <span className="tabular-nums font-medium text-foreground">{drumSum} kg</span>
            </p>
          )}
          {(errors['drum_one_capacity_kg'] !== undefined || errors['drum_two_capacity_kg'] !== undefined) && (
            <p role="alert" className="text-xs text-destructive">{errors['drum_one_capacity_kg'] ?? errors['drum_two_capacity_kg']}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label>Waste categories</Label>
          <div
            className="max-h-36 space-y-0.5 overflow-y-auto rounded-md border p-2"
            role="group"
            aria-label="Waste categories this drum is designated for"
          >
            {(cats.data ?? []).length === 0 && (
              <p className="px-1 py-0.5 text-xs text-muted-foreground">No waste categories yet — the drum will accept any mix.</p>
            )}
            {(cats.data ?? []).map((c) => (
              <label key={c.id} className="flex cursor-pointer items-center gap-2 rounded px-1 py-0.5 text-sm hover:bg-muted/50">
                <Checkbox
                  checked={selectedCats.includes(String(c.id))}
                  onCheckedChange={() => toggleCat(String(c.id))}
                  aria-label={`Designate ${c.name}`}
                />
                <span>{c.name}</span>
                <span className="tabular-nums text-xs text-muted-foreground">({c.code})</span>
              </label>
            ))}
          </div>
          <p className="text-[0.625rem] text-muted-foreground">
            Set at drum setup — batches on this drum may only mix these categories, and the start form pre-fills a weight row per category. Leave empty to accept any mix.
          </p>
          {errors['category_ids'] !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors['category_ids']}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={deviceIdId}>ESP32 device *</Label>
          {fleetExhausted ? (
            <div className="rounded-md border border-amber-500/40 bg-amber-500/5 p-3 text-xs" role="alert">
              <p className="flex items-center gap-1.5 font-medium text-foreground">
                <TriangleAlert className="size-3.5 text-amber-500" /> Every registered ESP32 is already bound to a drum.
              </p>
              <p className="mt-1 text-muted-foreground">
                A drum integrates exactly one device — register another ESP32 first.
              </p>
              <Link to="/facilities/devices" className="mt-1.5 inline-flex items-center gap-1 font-medium text-primary underline-offset-2 hover:underline">
                <Cpu className="size-3" /> Go to Devices
              </Link>
            </div>
          ) : (
            <>
              <Select value={deviceId} onValueChange={setDeviceId} disabled={devices.isLoading}>
                <SelectTrigger id={deviceIdId} aria-invalid={errors['device_id'] !== undefined}>
                  <SelectValue placeholder={devices.isLoading ? 'Loading devices…' : 'Pick the ESP32…'} />
                </SelectTrigger>
                <SelectContent>
                  {availableDevices.map((d) => (
                    <SelectItem key={d.id} value={String(d.id)}>
                      {d.display_name} <span className="font-mono text-xs text-muted-foreground">({d.code})</span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors['device_id'] !== undefined ? (
                <p role="alert" className="text-xs text-destructive">{errors['device_id']}</p>
              ) : (
                <p className="text-[0.625rem] text-muted-foreground">Required — the drum reports turning sessions through this device. Only unbound devices are listed.</p>
              )}
            </>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={notesId}>Notes</Label>
          <Textarea id={notesId} value={notes} onChange={(e) => setNotes(e.target.value)} rows={3} maxLength={512} placeholder="Optional" />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button onClick={submit} disabled={create.isPending || fleetExhausted}>
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
  const devices = useBmgDevices(null, 100, false);
  const [name, setName] = useState(unit.display_name);
  const [location, setLocation] = useState(unit.location_code ?? '');
  const [drumOne, setDrumOne] = useState(
    unit.drum_one_capacity_kg !== null && unit.drum_one_capacity_kg !== undefined
      ? String(unit.drum_one_capacity_kg) : '',
  );
  const [drumTwo, setDrumTwo] = useState(
    unit.drum_two_capacity_kg !== null && unit.drum_two_capacity_kg !== undefined
      ? String(unit.drum_two_capacity_kg) : '',
  );
  const [selectedCats, setSelectedCats] = useState<string[]>(
    (unit.categories ?? []).map((c) => String(c.id)),
  );
  const [deviceId, setDeviceId] = useState<string>(
    unit.device_id !== null && unit.device_id !== undefined ? String(unit.device_id) : 'unset',
  );
  const [notes, setNotes] = useState(unit.notes ?? '');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const nameId = useId();
  const locId = useId();
  const capOneId = useId();
  const capTwoId = useId();
  const deviceIdId = useId();
  const notesId = useId();

  const drumSum = drumOne !== '' && drumTwo !== '' ? (Number(drumOne) + Number(drumTwo)).toFixed(2) : null;

  function toggleCat(id: string) {
    setSelectedCats((sel) => (sel.includes(id) ? sel.filter((c) => c !== id) : [...sel, id]));
  }

  // Reassignment options: every free board plus the drum's current one
  // (re-picking it is a no-op, not a conflict). 'unset' = unbind, the
  // replacement path for a dead board.
  const deviceOptions = availableBmgDevices(devices.data?.data ?? [], unit.device_id ?? undefined);

  function submit() {
    if ((drumOne === '') !== (drumTwo === '')) {
      setErrors({ drum_one_capacity_kg: 'Provide both drums\u2019 capacities — the unit capacity is their sum.' });
      return;
    }
    const input = {
      display_name: name,
      location_code: location,
      // Capacities travel as a pair; the backend recomputes the unit
      // capacity as their sum. Blank pair = leave the profile unchanged.
      drum_one_capacity_kg: drumOne === '' ? undefined : Number(drumOne),
      drum_two_capacity_kg: drumTwo === '' ? undefined : Number(drumTwo),
      // Always sent — an empty array clears the designation.
      category_ids: selectedCats.map(Number),
      notes,
      device_id: deviceId === 'unset' ? null : Number(deviceId),
    };
    const parsed = updateUnitSchema.safeParse({
      ...input,
      drum_one_capacity_kg: drumOne,
      drum_two_capacity_kg: drumTwo,
    });
    if (!parsed.success) {
      setErrors(Object.fromEntries(parsed.error.issues.map((i) => [String(i.path[0]), i.message])));
      return;
    }
    setErrors({});
    update.mutate({ unitId: unit.id, input }, { onSuccess: () => onClose() });
  }

  return (
    <DialogContent lockDismiss>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <Pencil className="size-4" /> Edit {unit.code}
        </DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label>Drum code</Label>
          <Input value={unit.code} disabled className="tabular-nums" />
          <p className="text-[0.625rem] text-muted-foreground">Drum code cannot be changed.</p>
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
        <div className="space-y-1.5">
          <div className="flex items-center gap-1">
            <Label>Drum capacities (kg)</Label>
            <FieldInfo label="About the capacities">
              Each unit has 2 drums — enter EACH drum&rsquo;s capacity (at least 4 kg each). The unit&rsquo;s rated capacity is their sum.
            </FieldInfo>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor={capOneId} className="text-xs text-muted-foreground">Drum 1</Label>
              <Input
                id={capOneId}
                type="number"
                min={4}
                step={0.01}
                value={drumOne}
                onChange={(e) => setDrumOne(e.target.value)}
                placeholder="at least 4"
                aria-invalid={errors['drum_one_capacity_kg'] !== undefined || errors['drum_two_capacity_kg'] !== undefined}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor={capTwoId} className="text-xs text-muted-foreground">Drum 2</Label>
              <Input
                id={capTwoId}
                type="number"
                min={4}
                step={0.01}
                value={drumTwo}
                onChange={(e) => setDrumTwo(e.target.value)}
                placeholder="at least 4"
                aria-invalid={errors['drum_one_capacity_kg'] !== undefined || errors['drum_two_capacity_kg'] !== undefined}
              />
            </div>
          </div>
          {drumSum !== null && (
            <p className="text-xs text-muted-foreground">
              Unit capacity (sum of both drums): <span className="tabular-nums font-medium text-foreground">{drumSum} kg</span>
            </p>
          )}
          {(errors['drum_one_capacity_kg'] !== undefined || errors['drum_two_capacity_kg'] !== undefined) && (
            <p role="alert" className="text-xs text-destructive">{errors['drum_one_capacity_kg'] ?? errors['drum_two_capacity_kg']}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label>Waste categories</Label>
          <div
            className="max-h-36 space-y-0.5 overflow-y-auto rounded-md border p-2"
            role="group"
            aria-label="Waste categories this drum is designated for"
          >
            {(cats.data ?? []).length === 0 && (
              <p className="px-1 py-0.5 text-xs text-muted-foreground">No waste categories yet — the drum will accept any mix.</p>
            )}
            {(cats.data ?? []).map((c) => (
              <label key={c.id} className="flex cursor-pointer items-center gap-2 rounded px-1 py-0.5 text-sm hover:bg-muted/50">
                <Checkbox
                  checked={selectedCats.includes(String(c.id))}
                  onCheckedChange={() => toggleCat(String(c.id))}
                  aria-label={`Designate ${c.name}`}
                />
                <span>{c.name}</span>
                <span className="tabular-nums text-xs text-muted-foreground">({c.code})</span>
              </label>
            ))}
          </div>
          <p className="text-[0.625rem] text-muted-foreground">
            Batches on this drum may only mix these categories; the start form pre-fills a weight row per category. Empty = any mix.
          </p>
          {errors['category_ids'] !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors['category_ids']}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={deviceIdId}>ESP32 device</Label>
          <Select value={deviceId} onValueChange={setDeviceId} disabled={devices.isLoading}>
            <SelectTrigger id={deviceIdId} aria-invalid={errors['device_id'] !== undefined}>
              <SelectValue placeholder={devices.isLoading ? 'Loading devices…' : 'Pick the ESP32…'} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="unset">— None (unbound) —</SelectItem>
              {deviceOptions.map((d) => (
                <SelectItem key={d.id} value={String(d.id)}>
                  {d.display_name} <span className="font-mono text-xs text-muted-foreground">({d.code})</span>
                  {d.id === unit.device_id ? ' — current' : ''}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors['device_id'] !== undefined ? (
            <p role="alert" className="text-xs text-destructive">{errors['device_id']}</p>
          ) : (
            <p className="text-[0.625rem] text-muted-foreground">
              The drum's integrated controller — swap it by picking a free board, or unbind it (e.g. a dead board awaiting replacement).
            </p>
          )}
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
 *
 * When the drum carries an ESP32, the dialog offers to RELOCATE the
 * ArchiveUnitDialog — confirm-driven soft archive. The button is
 * disabled while the unit has an active batch (server-side enforces
 * this too — 409 `statemachine.bmg.unit_has_active_batch`).
 *
 * Archiving automatically releases the drum's ESP32 back to the
 * available pool — no relocation choice here; the board is assigned
 * to its next drum from that drum's Edit form.
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
          The drum will be soft-archived (<code className="tabular-nums">archived_at</code> set)
          and removed from the active list. Audit history is preserved.
        </p>
        {hasActiveBatch && (
          <p className="rounded-md border border-destructive/30 bg-destructive/5 p-2 text-destructive">
            This drum still has an active batch. Finish or cancel it before archiving.
          </p>
        )}
        {unit.device_code != null && (
          <p>
            Its ESP32 (<span className="font-mono text-xs">{unit.device_code}</span>) will be
            released and can be assigned to another drum from that drum&rsquo;s Edit form.
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
            <span className="tabular-nums">{c.reference_code}</span>
            <Badge variant={c.pfrp_met ? 'success' : 'warning'}>
              {c.pfrp_met ? 'PFRP met' : 'PFRP not met'}
            </Badge>
          </div>

          <div className="rounded-md border p-3">
            <p className="mb-2 text-xs font-medium text-muted-foreground">Pathogen-reduction evidence (PFRP)</p>
            <div className="grid grid-cols-2 gap-2 text-sm">
              <div>Thermophilic days (≥55°C): <span className="tabular-nums">{c.thermophilic_days}</span></div>
              <div>Peak temperature: <span className="tabular-nums">{c.max_temperature_c !== null ? `${c.max_temperature_c}°C` : '—'}</span></div>
              <div>Consecutive PFRP days: <span className="tabular-nums">{c.consecutive_pfrp_days}</span></div>
              <div>Status: <span className="tabular-nums">{titleCase(c.status)}</span></div>
            </div>
          </div>

          <div className="rounded-md border p-3">
            <p className="mb-2 text-xs font-medium text-muted-foreground">Mass balance</p>
            <div className="grid grid-cols-2 gap-2 text-sm">
              <div>Input: <span className="tabular-nums">{c.input_kg} kg</span></div>
              <div>Output: <span className="tabular-nums">{c.output_kg} kg</span></div>
              <div>Losses: <span className="tabular-nums">{c.loss_kg} kg</span></div>
              <div>In-process: <span className="tabular-nums">{c.in_process_kg} kg</span></div>
              <div>Yield: <span className="tabular-nums">{c.yield_pct !== null ? `${c.yield_pct}%` : '—'}</span></div>
              <div>Unaccounted: <span className="tabular-nums">{c.unaccounted_kg} kg</span></div>
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
  // Filter lives in the URL so a filtered history view is linkable and
  // survives a reload; the cursor stays in memory (the project-wide
  // convention) and snaps back to page 1 when the filter changes.
  const [statusFilter, setStatusFilter] = useUrlFilter('hist', { default: 'all' });
  const status = statusFilter === 'all' ? null : statusFilter;
  const { cursor, history, nextPage: nextCursor, prevPage } = useKeysetPagination(statusFilter);
  const batches = useBatchHistory(unitId, status, cursor, 25);
  const batchRows = batches.data?.data ?? [];

  function nextPage() {
    nextCursor(batches.data?.next);
  }

  return (
    <DialogContent lockDismiss className="max-w-5xl sm:max-w-5xl">
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <History className="size-4 text-primary" /> Batch history
          {unitId !== null && <span className="text-sm font-normal text-muted-foreground">— drum #{unitId}</span>}
        </DialogTitle>
      </DialogHeader>
      <div className="mb-2 flex items-center justify-between gap-2">
        <Select value={statusFilter} onValueChange={setStatusFilter}>
          <SelectTrigger aria-label="Filter by status" className="w-44"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            <SelectItem value="released">Released</SelectItem>
            <SelectItem value="idle">Finished</SelectItem>
            <SelectItem value="cancelled">Cancelled</SelectItem>
          </SelectContent>
        </Select>
        <span className="text-xs text-muted-foreground">Page {history.length} · {batchRows.length} batch{batchRows.length === 1 ? '' : 'es'} shown</span>
      </div>
      <div className="max-h-[60vh] overflow-auto rounded-md border">
        <Table ariaLabel="Batch history">
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
            <TableStateRows
              colSpan={8}
              isLoading={batches.isLoading}
              isError={batches.isError}
              isEmpty={batchRows.length === 0}
              onRetry={() => void batches.refetch()}
              pending={batches.isFetching}
              errorMessage="Failed to load batch history."
              loadingLabel="Loading batch history"
              empty={{
                title: 'No batches in history.',
                description: 'Batches appear here once a drum starts one.',
              }}
              noResults={{
                title: 'No batches match this status.',
                description: 'Choose a different status to see more.',
                action: (
                  <Button variant="outline" size="sm" onClick={() => setStatusFilter('all')}>
                    Clear filter
                  </Button>
                ),
              }}
              hasFilters={statusFilter !== 'all'}
            />
            {batchRows.map((b) => (
              <TableRow key={b.id}>
                <TableCell className="px-3 tabular-nums text-xs">{b.reference_code}</TableCell>
                <TableCell className="px-3 tabular-nums text-xs">{b.unit_code}</TableCell>
                <TableCell className="px-3"><Badge variant={b.status === 'released' ? 'success' : b.status === 'cancelled' ? 'destructive' : 'secondary'}>{titleCase(b.status)}</Badge></TableCell>
                <TableCell className="px-3 tabular-nums text-xs">{b.total_input_weight_kg} kg</TableCell>
                <TableCell className="px-3 tabular-nums text-xs">{b.output_weight_kg !== null ? `${b.output_weight_kg} kg` : '—'}</TableCell>
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
  const ack = useAcknowledgeAlert();
  const items = (open.data ?? []).filter((a) => a.severity !== 'info');
  if (items.length === 0) return null;
  const shown = items.slice(0, 3);
  const overflow = items.length - shown.length;
  return (
    <div
      role="alert"
      aria-live="polite"
      className="flex items-start gap-2 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm"
    >
      <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
      <div className="min-w-0 flex-1 space-y-2">
        <p className="font-medium text-destructive">
          {items.length} open BMG alert{items.length > 1 ? 's' : ''} on live batches
        </p>
        <ul className="space-y-1.5">
          {shown.map((a) => (
            <li key={a.alert_id} className="flex flex-wrap items-center gap-1.5 text-xs">
              <Badge variant={a.severity === 'critical' ? 'destructive' : 'warning'}>
                {a.severity}
              </Badge>
              <CopyButton value={a.code} label={`Copy alert code ${a.code}`} successMessage="Alert code copied." />
              {a.unit_id !== null ? (
                <Link
                  to={`/facilities/drums/${a.unit_id}`}
                  className="tabular-nums font-medium text-foreground underline-offset-2 hover:underline"
                >
                  {a.reference_code}
                  {a.unit_code !== null ? ` · ${a.unit_code}` : ''}
                </Link>
              ) : (
                <span className="tabular-nums font-medium text-foreground">{a.reference_code}</span>
              )}
              <span className="min-w-0 basis-full truncate text-muted-foreground">{a.message}</span>
              <Button
                size="sm"
                variant="ghost"
                className="h-7 px-2 text-xs"
                disabled={ack.isPending}
                onClick={() => ack.mutate({ alertId: a.alert_id, batchId: a.batch_id })}
              >
                Acknowledge
              </Button>
            </li>
          ))}
        </ul>
        {overflow > 0 && (
          <p className="text-xs text-muted-foreground">
            +{overflow} more open alert{overflow > 1 ? 's' : ''}. Open a drum above to review its full list.
          </p>
        )}
      </div>
    </div>
  );
}

export default function FacilitiesPage() {
  // The archived toggle is URL state so an operator can share a link to
  // the archived view, and so a reload lands where they left off. The
  // cursor stays in memory and snaps back to page 1 when the toggle flips.
  const [archivedParam, setArchivedParam] = useUrlFilter('archived');
  const showArchived = archivedParam === '1';
  const { cursor, history, nextPage: nextCursor, prevPage } = useKeysetPagination(archivedParam);
  const units = useBmgUnits(cursor, 50, showArchived);
  const unitRows = units.data?.data ?? [];
  // Powers the Processing Drums grid. Lifted out of the card component
  // so the page owns the query and the widget stays presentational.
  const activeBatches = useActiveBatches();
  const finish = useFinishBatch();
  const cancel = useCancelBatch();
  const unarchiveUnit = useUnarchiveUnit();
  const [openStart, setOpenStart] = useState<BmgUnit | null>(null);
  const [openUpdate, setOpenUpdate] = useState<BmgUnit | null>(null);
  const [openUpdates, setOpenUpdates] = useState<BmgUnit | null>(null);
  const [openFinish, setOpenFinish] = useState<BmgUnit | null>(null);
  const [openStatus, setOpenStatus] = useState<BmgUnit | null>(null);
  const [openAnalytics, setOpenAnalytics] = useState<BmgUnit | null>(null);
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
    nextCursor(units.data?.next);
  }

  // Shared compact menu for desktop rows and mobile cards.
  const unitActions = (u: BmgUnit) => {
    const activeBatch = u.active_batch_id ?? null;
    const archived = u.archived_at !== null && u.archived_at !== undefined;
    // All state rules live in `bmgActionAvailability` so they can be
    // tested directly; see lib/bmgActions.ts.
    const can = bmgActionAvailability(u, {
      finish: finish.isPending,
      cancel: cancel.isPending,
      restore: unarchiveUnit.isPending,
    });
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button className="min-h-11 md:min-h-0" size="sm" variant="outline" aria-label={`Actions for ${u.code}`}>
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
          <DropdownMenuItem className="min-h-11" disabled={!can.restore} onSelect={() => unarchiveUnit.mutate({ unitId: u.id })}>
            <ArchiveRestore /> Restore
          </DropdownMenuItem>
        ) : (
          <DropdownMenuItem className="min-h-11" disabled={!can.archive} onSelect={() => setOpenArchive(u)}>
            <Archive /> Archive
          </DropdownMenuItem>
        )}
          <DropdownMenuSeparator />
          <DropdownMenuItem className="min-h-11" disabled={!can.start} onSelect={() => setOpenStart(u)}>
            <Play /> Start batch
          </DropdownMenuItem>
          {/* 2. Add update — ONE action; appends an immutable log entry. */}
          <DropdownMenuItem className="min-h-11" disabled={!can.addUpdate} onSelect={() => activeBatch !== null && setOpenUpdate(u)}>
            <SquarePen /> Add update
          </DropdownMenuItem>
          {/* 6a. Combined append-only Updates feed. */}
          <DropdownMenuItem className="min-h-11" disabled={!can.viewUpdates} onSelect={() => activeBatch !== null && setOpenUpdates(u)}>
            <List /> View updates
          </DropdownMenuItem>
          {/* 3. Finish = graded release (requires QA). */}
          <DropdownMenuItem
          className="min-h-11"
          disabled={!can.finish}
          onSelect={() => activeBatch !== null && setOpenFinish(u)}
        >
            <StopCircle /> Finish batch
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          {/* 6b. View history (read-only). */}
          <DropdownMenuItem className="min-h-11" onSelect={() => setOpenHistory(u.id)}>
            <History /> Batch history
          </DropdownMenuItem>
          <DropdownMenuItem className="min-h-11" disabled={!can.certificate} onSelect={() => activeBatch !== null && setOpenCompliance({ unit: u, batchId: activeBatch })}>
            <FileCheck2 /> Certificate / compliance
          </DropdownMenuItem>
          <DropdownMenuItem className="min-h-11" disabled={!can.analytics} onSelect={() => setOpenAnalytics(u)}>
            <LineChart /> Analytics
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          {/* 5. Drum status (no active batch only). */}
          <DropdownMenuItem
          className="min-h-11"
          disabled={!can.drumStatus}
          onSelect={() => setOpenStatus(u)}
        >
            <Wrench /> Drum status
          </DropdownMenuItem>
          <DropdownMenuItem
          className="min-h-11 text-destructive focus:text-destructive"
          disabled={!can.cancel}
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
    // Natural document flow — the page scrolls with the app shell. The
    // old fixed-viewport flex column squeezed the drums table to zero
    // height whenever the Processing Drums card grew, so the table now
    // bounds itself with its own internal scroll instead.
    <main className="space-y-4 p-6">
      <PageHeader
        title="Facilities"
        description="Drums move Idle → Processing → Awaiting output → Released (or Cancelled), and can be placed in Maintenance."
        actions={
          <>
            <Button
              variant={showArchived ? 'secondary' : 'outline'}
              aria-pressed={showArchived}
              onClick={() => setArchivedParam(showArchived ? '' : '1')}
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
          </>
        }
      />

      <OpenAlertsBanner />

      <ProcessingDrums
        batches={activeBatches.data?.data ?? []}
        turningDueDays={activeBatches.data?.turning_due_days ?? null}
        isLoading={activeBatches.isLoading}
        isError={activeBatches.isError}
        isFetching={activeBatches.isFetching}
        onRetry={() => void activeBatches.refetch()}
      />

      <section className="hidden rounded-xl border bg-card md:block">
        <Table
          ariaLabel="Composting drums with status and utilization"
          wrapperClassName="max-h-[65vh] overflow-y-auto"
          className="[&_td]:py-1.5"
        >
          <TableHeader className="sticky top-0 z-10 bg-muted shadow-[0_1px_0_0_var(--border)]">
            <TableRow>
              <TableHead className="px-3">Code</TableHead>
              <TableHead className="px-3">Name</TableHead>
              <TableHead className="px-3">Status</TableHead>
              <TableHead className="px-3">Active batch</TableHead>
              <TableHead className="px-3">Utilization</TableHead>
              <TableHead className="px-3">Location</TableHead>
              <TableHead className="px-3">ESP32</TableHead>
              <TableHead className="px-3">Created</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableStateRows
              colSpan={9}
              isLoading={units.isLoading}
              isError={units.isError}
              isEmpty={unitRows.length === 0}
              onRetry={() => void units.refetch()}
              pending={units.isFetching}
              errorMessage="Failed to load drums."
              loadingLabel="Loading drums"
              empty={{
                title: 'No drums yet.',
                description: 'Create one to start composting.',
              }}
            />
            {units.data?.data.map((u) => {
              const activeBatch = u.active_batch_id ?? null;
              return (
                <TableRow key={u.id} id={`unit-${u.id}`} className="scroll-mt-24">
                  <TableCell className="px-3 tabular-nums text-xs">{u.code}</TableCell>
                  <TableCell className="px-3">{u.display_name}</TableCell>
                  <TableCell className="px-3">
                    <Badge variant={unitStatusVariant(u.status)}>{statusLabel(u.status)}</Badge>
                    {u.archived_at !== null && u.archived_at !== undefined && (
                      <Badge variant="secondary" className="ml-1.5">Archived</Badge>
                    )}
                  </TableCell>
                  <TableCell className="px-3 tabular-nums text-xs text-muted-foreground">
                    {activeBatch === null ? '—' : `#${activeBatch}`}
                  </TableCell>
                  <TableCell className="px-3">
                    {activeBatch === null || u.utilization_pct === undefined ? (
                      <span className="text-xs text-muted-foreground">—</span>
                    ) : (
                      <div className="flex items-center gap-2">
                        <div className="h-1.5 w-16 overflow-hidden rounded-full bg-muted">
                          <div
                            className={`h-full rounded-full ${u.utilization_pct >= 90 ? 'bg-destructive' : u.utilization_pct >= 70 ? 'bg-amber-500' : 'bg-emerald-500'}`}
                            style={{ width: `${Math.min(u.utilization_pct, 100)}%` }}
                          />
                        </div>
                        <span className="tabular-nums text-xs font-medium text-foreground">{u.utilization_pct}%</span>
                      </div>
                    )}
                  </TableCell>
                  <TableCell className="px-3 text-xs text-muted-foreground">
                    <div className="flex flex-col gap-0.5">
                      <span>{u.location_code ?? '—'}</span>
                      {(u.categories ?? []).length > 0 && (
                        <div className="flex flex-wrap gap-1">
                          {(u.categories ?? []).slice(0, 2).map((c) => (
                            <span key={c.id} className="inline-flex w-fit items-center gap-1 rounded-md bg-secondary px-1.5 py-0.5 text-[0.625rem] font-medium text-secondary-foreground">
                              <Boxes className="size-2.5" /> {c.name}
                            </span>
                          ))}
                          {(u.categories ?? []).length > 2 && (
                            <span className="self-center text-[0.625rem] text-muted-foreground">+{(u.categories ?? []).length - 2}</span>
                          )}
                        </div>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="px-3 text-xs">
                    {u.device_code != null ? (
                      <span
                        className="inline-flex items-center gap-1"
                        title={u.device_display_name ?? u.device_code}
                      >
                        <Cpu className={`size-3 ${u.device_status === 'disabled' ? 'text-muted-foreground' : 'text-primary'}`} />
                        <span className="font-mono">{u.device_code}</span>
                        {u.device_status === 'disabled' && <Badge variant="secondary">disabled</Badge>}
                      </span>
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
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
      <MobileCardList>
        <MobileCardListState
          isLoading={units.isLoading}
          isError={units.isError}
          isEmpty={unitRows.length === 0}
          onRetry={() => void units.refetch()}
          pending={units.isFetching}
          errorMessage="Failed to load drums."
          loadingLabel="Loading drums"
          empty={{
            title: 'No drums yet.',
            description: showArchived
              ? 'No drums have been registered yet.'
              : 'Create one to start composting.',
          }}
        />
        {units.data?.data.map((u) => {
          const activeBatch = u.active_batch_id ?? null;
          const archived = u.archived_at !== null && u.archived_at !== undefined;
          return (
            <MobileCard key={u.id} aria-label={`Drum ${u.code}`}>
              <div className="mb-1 flex items-center justify-between gap-2">
                <span className="tabular-nums text-sm font-medium text-foreground">{u.code}</span>
                <div className="flex flex-wrap justify-end gap-1.5">
                  <Badge variant={unitStatusVariant(u.status)}>{statusLabel(u.status)}</Badge>
                  {archived && <Badge variant="secondary">Archived</Badge>}
                </div>
              </div>
              <p className="text-sm text-foreground">{u.display_name}</p>
              <MobileCardField label="Active batch">
                <span className="tabular-nums text-xs text-muted-foreground">{activeBatch === null ? '—' : `#${activeBatch}`}</span>
              </MobileCardField>
              {activeBatch !== null && u.utilization_pct !== undefined && (
                <MobileCardField label="Utilization">
                  <div className="flex items-center gap-2">
                    <div className="h-1.5 w-16 overflow-hidden rounded-full bg-muted">
                      <div
                        className={`h-full rounded-full ${u.utilization_pct >= 90 ? 'bg-destructive' : u.utilization_pct >= 70 ? 'bg-amber-500' : 'bg-emerald-500'}`}
                        style={{ width: `${Math.min(u.utilization_pct, 100)}%` }}
                      />
                    </div>
                    <span className="tabular-nums text-xs font-medium text-foreground">{u.utilization_pct}%</span>
                  </div>
                </MobileCardField>
              )}
              <MobileCardField label="Location">
                <span className="text-xs text-muted-foreground">{u.location_code ?? '—'}</span>
              </MobileCardField>
              <MobileCardField label="ESP32">
                {u.device_code != null ? (
                  <span className="inline-flex items-center gap-1 font-mono text-xs">
                    <Cpu className="size-3" /> {u.device_code}
                    {u.device_status === 'disabled' ? ' (disabled)' : ''}
                  </span>
                ) : (
                  <span className="text-xs text-muted-foreground">—</span>
                )}
              </MobileCardField>
              {(u.categories ?? []).length > 0 && (
                <MobileCardField label="Waste categories">
                  <span className="inline-flex items-center gap-1 text-xs">
                    <Boxes className="size-3" /> {(u.categories ?? []).map((c) => c.name).join(', ')}
                  </span>
                </MobileCardField>
              )}
              <MobileCardActions>{unitActions(u)}</MobileCardActions>
            </MobileCard>
          );
        })}
      </MobileCardList>

      <nav className="flex items-center justify-between" aria-label="pagination">
        <p className="text-xs text-muted-foreground">Page {history.length} · {unitRows.length} unit{unitRows.length === 1 ? '' : 's'} shown</p>
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

      {openAnalytics !== null && openAnalytics.active_batch_id !== null && openAnalytics.active_batch_id !== undefined && (
        <Dialog open onOpenChange={(o) => !o && setOpenAnalytics(null)}>
          <AnalyticsDialog
            unit={openAnalytics}
            batchId={openAnalytics.active_batch_id}
            onClose={() => setOpenAnalytics(null)}
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
