/**
 * Materials — the manual mass ledgers for a batch: waste loaded, compost
 * harvested and categorised losses. Each is a status card with a button
 * that opens its record form on demand.
 */
import { Boxes, Plus, Scale, Trash2, TriangleAlert } from 'lucide-react';
import { useId, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
  useAddBatchInput,
  useAddBatchLoss,
  useAddBatchOutput,
  useBatchInputs,
  useBatchOutputs,
  useUnitMonitor,
  useVoidBatchIo,
} from '@/hooks/useFacilities';
import { useCan } from '@/hooks/useCan';
import { formatIoTotals, formatKg } from '@/lib/bmgFormat';
import { titleCase } from '@/lib/utils';
import { BMG_LOSS_CATEGORIES, type BmgLossCategory } from '@/schemas/facilities';
import { FormDialog } from './FormDialog';
import { LedgerCard, type LedgerRow } from './LedgerCard';

// Staff-wide: anyone holding the io permission may enter, void and read
// the manual ledgers (mirrors BmgPolicy `io_record` / `losses_record`).
const IO_PERMISSION = 'facilities.bmg.io.record';

function isVoided(row: { voided_at?: string | null | undefined }): boolean {
  return row.voided_at !== null && row.voided_at !== undefined;
}

/**
 * Shown only when the unit's ledger is out of balance (more compost out
 * than waste in) — almost always a mistyped weight. Silent otherwise, so
 * a healthy ledger adds no chrome.
 */
export function LedgerBalanceNotice({ unitId }: { unitId: number }) {
  const monitor = useUnitMonitor(unitId);
  if (monitor.data === undefined) return null;
  const totals = formatIoTotals(monitor.data.totals.input_kg, monitor.data.totals.output_kg);
  if (totals.balance.tone !== 'danger') return null;
  return (
    <p
      role="alert"
      className="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/5 p-3 text-sm text-amber-600"
    >
      <TriangleAlert className="mt-0.5 size-4 shrink-0" />
      <span>
        Ledger out of balance: {totals.output} harvested against {totals.input} loaded ({totals.balance.label}).
        Check the entries below for a mistyped weight.
      </span>
    </p>
  );
}

export function WasteLoadedCard({ batchId }: { batchId: number }) {
  const list = useBatchInputs(batchId);
  const add = useAddBatchInput(batchId);
  const voidRow = useVoidBatchIo(batchId);
  const canRecord = useCan(IO_PERMISSION);
  const [open, setOpen] = useState(false);
  const [weight, setWeight] = useState('');
  const [note, setNote] = useState('');
  const [recordedAt, setRecordedAt] = useState('');
  const weightId = useId();
  const atId = useId();
  const noteId = useId();

  const source = list.data ?? [];
  const total = source.filter((r) => !isVoided(r)).reduce((sum, r) => sum + r.weight_kg, 0);
  const rows: LedgerRow[] = source.map((r) => ({
    id: r.id,
    weightLabel: formatKg(r.weight_kg),
    recordedAt: r.recorded_at,
    detail: r.note ?? null,
    voided: isVoided(r),
    voidReason: r.void_reason ?? null,
  }));

  function submit() {
    const w = Number(weight);
    if (!Number.isFinite(w) || w <= 0) {
      toast.error('Weight must be a positive number (kg).');
      return;
    }
    add.mutate(
      { weight_kg: w, note, recorded_at: recordedAt },
      {
        onSuccess: () => {
          setWeight('');
          setNote('');
          setRecordedAt('');
          setOpen(false);
        },
      },
    );
  }

  return (
    <>
      <LedgerCard
        title="Waste loaded"
        icon={<Scale className="size-4 text-primary" />}
        totalLabel={formatKg(total)}
        rows={rows}
        loading={list.isLoading}
        error={list.isError}
        emptyText="No waste loaded yet."
        canRecord={canRecord}
        recordLabel="Record input"
        onRecord={() => setOpen(true)}
        voidTitle="Void this input?"
        voidConfirmLabel="Void input"
        voidPending={voidRow.isPending}
        onVoid={(row, reason, done) =>
          voidRow.mutate({ kind: 'input', rowId: row.id, reason }, { onSuccess: done })
        }
      />
      <FormDialog
        open={open}
        onOpenChange={setOpen}
        title="Record waste loaded"
        description="Staff-entered feedstock for this batch. Entries are voided, never edited or deleted, so the ledger keeps the correction trail."
        submitLabel="Record input"
        pending={add.isPending}
        onSubmit={submit}
      >
        <div className="space-y-1.5">
          <Label htmlFor={weightId}>Weight (kg)</Label>
          <Input id={weightId} type="number" min={0} step={0.01} value={weight} onChange={(e) => setWeight(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={atId}>Loaded at (optional)</Label>
          <Input id={atId} type="datetime-local" value={recordedAt} onChange={(e) => setRecordedAt(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={noteId}>Note (optional)</Label>
          <Input id={noteId} maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} />
        </div>
      </FormDialog>
    </>
  );
}

type Grade = 'unset' | 'excellent' | 'good' | 'fair';

export function CompostHarvestedCard({ batchId }: { batchId: number }) {
  const list = useBatchOutputs(batchId);
  const add = useAddBatchOutput(batchId);
  const voidRow = useVoidBatchIo(batchId);
  const canRecord = useCan(IO_PERMISSION);
  const [open, setOpen] = useState(false);
  const [weight, setWeight] = useState('');
  const [note, setNote] = useState('');
  const [grade, setGrade] = useState<Grade>('unset');
  const weightId = useId();
  const gradeId = useId();
  const noteId = useId();

  const source = list.data ?? [];
  const total = source.filter((r) => !isVoided(r)).reduce((sum, r) => sum + r.output_weight_kg, 0);
  const rows: LedgerRow[] = source.map((r) => ({
    id: r.id,
    weightLabel: formatKg(r.output_weight_kg),
    recordedAt: r.recorded_at,
    detail: [r.quality_grade != null && r.quality_grade !== '' ? titleCase(r.quality_grade) : null, r.note]
      .filter((part): part is string => part != null && part !== '')
      .join(' · '),
    voided: isVoided(r),
    voidReason: r.void_reason ?? null,
  }));

  function submit() {
    const w = Number(weight);
    if (!Number.isFinite(w) || w <= 0) {
      toast.error('Weight must be a positive number (kg).');
      return;
    }
    add.mutate(
      {
        output_weight_kg: w,
        note,
        ...(grade !== 'unset' ? { quality_grade: grade } : {}),
      },
      {
        onSuccess: () => {
          setWeight('');
          setNote('');
          setGrade('unset');
          setOpen(false);
        },
      },
    );
  }

  return (
    <>
      <LedgerCard
        title="Compost harvested"
        icon={<Boxes className="size-4 text-primary" />}
        totalLabel={formatKg(total)}
        rows={rows}
        loading={list.isLoading}
        error={list.isError}
        emptyText="No compost harvested yet."
        canRecord={canRecord}
        recordLabel="Record output"
        onRecord={() => setOpen(true)}
        voidTitle="Void this harvest?"
        voidConfirmLabel="Void output"
        voidPending={voidRow.isPending}
        onVoid={(row, reason, done) =>
          voidRow.mutate({ kind: 'output', rowId: row.id, reason }, { onSuccess: done })
        }
      />
      <FormDialog
        open={open}
        onOpenChange={setOpen}
        title="Record compost harvested"
        description="Finished compost taken out of this batch. Entries are voided, never edited or deleted."
        submitLabel="Record output"
        pending={add.isPending}
        onSubmit={submit}
      >
        <div className="space-y-1.5">
          <Label htmlFor={weightId}>Weight (kg)</Label>
          <Input id={weightId} type="number" min={0} step={0.01} value={weight} onChange={(e) => setWeight(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={gradeId}>Grade (optional)</Label>
          <Select value={grade} onValueChange={(v) => setGrade(v as Grade)}>
            <SelectTrigger id={gradeId}><SelectValue placeholder="—" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="unset">—</SelectItem>
              <SelectItem value="excellent">Excellent</SelectItem>
              <SelectItem value="good">Good</SelectItem>
              <SelectItem value="fair">Fair</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={noteId}>Note (optional)</Label>
          <Input id={noteId} maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} />
        </div>
      </FormDialog>
    </>
  );
}

/**
 * Losses have no on-page list (the server sums them into the analytics),
 * so this is a compact action row rather than a ledger card.
 */
export function LossCard({ batchId }: { batchId: number }) {
  const addLoss = useAddBatchLoss();
  const canRecord = useCan(IO_PERMISSION);
  const [open, setOpen] = useState(false);
  const [category, setCategory] = useState<BmgLossCategory | 'unset'>('unset');
  const [weight, setWeight] = useState('');
  const [note, setNote] = useState('');
  const categoryId = useId();
  const weightId = useId();
  const noteId = useId();

  function submit() {
    if (category === 'unset') {
      toast.error('Pick a loss category.');
      return;
    }
    const w = Number(weight);
    if (!Number.isFinite(w) || w <= 0) {
      toast.error('Weight must be a positive number (kg).');
      return;
    }
    addLoss.mutate(
      {
        batchId,
        input: {
          category_code: category,
          weight_kg: w,
          ...(note.trim() !== '' ? { note: note.trim() } : {}),
        },
      },
      {
        onSuccess: () => {
          setWeight('');
          setNote('');
          setCategory('unset');
          setOpen(false);
        },
      },
    );
  }

  return (
    <Card>
      <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
        <div className="flex min-w-0 items-start gap-3">
          <Trash2 className="mt-0.5 size-4 shrink-0 text-primary" />
          <div className="min-w-0">
            <p className="text-sm font-semibold">Losses</p>
            <p className="text-xs text-muted-foreground">
              Mass that leaves the batch other than finished compost — evaporation, off-gas, sampling.
            </p>
          </div>
        </div>
        {canRecord && (
          <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
            <Plus /> Record loss
          </Button>
        )}
      </CardContent>

      <FormDialog
        open={open}
        onOpenChange={setOpen}
        title="Record loss"
        description="Categorised mass that left the batch for reasons other than finished output. The total is summed server-side."
        submitLabel="Record loss"
        pending={addLoss.isPending}
        onSubmit={submit}
      >
        <div className="space-y-1.5">
          <Label htmlFor={categoryId}>Category</Label>
          <Select value={category} onValueChange={(v) => setCategory(v as BmgLossCategory | 'unset')}>
            <SelectTrigger id={categoryId}><SelectValue placeholder="Pick…" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="unset">—</SelectItem>
              {BMG_LOSS_CATEGORIES.map((c) => (
                <SelectItem key={c} value={c}>{titleCase(c)}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={weightId}>Weight (kg)</Label>
          <Input id={weightId} type="number" min={0} step={0.01} value={weight} onChange={(e) => setWeight(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor={noteId}>Note (optional)</Label>
          <Input id={noteId} maxLength={255} value={note} onChange={(e) => setNote(e.target.value)} />
        </div>
      </FormDialog>
    </Card>
  );
}
