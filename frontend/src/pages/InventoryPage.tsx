/**
 * InventoryPage — clinic stock (Phases 8 + 12).
 *
 * Two tabs:
 *   - Medicines: batch-tracked catalog recycled from the legacy system.
 *     Lots carry expiry dates; dispensing is FEFO (earliest expiry
 *     first) on the backend. Receiving and dispensing happen in
 *     dialogs; the batches dialog shows per-lot status.
 *   - Supplies: the original generic item ledger (signed movements).
 */
import { zodResolver } from '@hookform/resolvers/zod';
import {
  ArrowDownUp,
  CalendarClock,
  Check,
  ChevronLeft,
  ChevronRight,
  Layers,
  Loader2,
  PackageCheck,
  PackagePlus,
  Pill,
  Plus,
  RefreshCw,
  Syringe,
  TrendingUp,
  Truck,
  X,
} from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCreateItem, useInventoryItems, useMoveStock } from '@/hooks/useInventory';
import { useAddBatch, useComputeForecast, useCreateMedicine, useDispense, useMedicine, useMedicines } from '@/hooks/useMedicines';
import {
  useCreateReorder,
  useReorderAutoCheck,
  useReorders,
  useReorderTransition,
} from '@/hooks/useReorders';
import {
  createItemSchema,
  moveStockSchema,
  type CreateItemInput,
  type InventoryItem,
  type MoveStockInput,
} from '@/schemas/inventory';
import {
  addBatchSchema,
  createMedicineSchema,
  dispenseSchema,
  type AddBatchInput,
  type CreateMedicineInput,
  type DispenseInput,
  type Medicine,
} from '@/schemas/medicines';
import {
  createReorderSchema,
  type CreateReorderInput,
} from '@/schemas/reorders';

const BATCH_STATUS_VARIANT = {
  active: 'success',
  depleted: 'secondary',
  expired: 'destructive',
  recalled: 'warning',
} as const;

const URGENCY_VARIANT = {
  low: 'secondary',
  medium: 'info',
  high: 'warning',
  critical: 'destructive',
} as const;

const REORDER_STATUS_VARIANT = {
  pending: 'info',
  approved: 'warning',
  ordered: 'default',
  received: 'success',
  cancelled: 'secondary',
} as const;

/** Days until a date (negative = past). */
function daysUntil(date: string): number {
  return Math.ceil((new Date(date).getTime() - Date.now()) / 864e5);
}

// ------------------------------------------------------------ medicines

function CreateMedicineDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateMedicine();
  const { register, handleSubmit, formState: { errors }, reset } =
    useForm<CreateMedicineInput>({
      resolver: zodResolver(createMedicineSchema),
      defaultValues: { unit: 'pc', reorder_threshold: 10 },
    });

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>New medicine</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-2 gap-3">
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="generic_name">Generic name</Label>
          <Input id="generic_name" aria-invalid={errors.generic_name !== undefined} {...register('generic_name')} />
          {errors.generic_name !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.generic_name.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="brand_name">Brand name</Label>
          <Input id="brand_name" {...register('brand_name')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="category">Category</Label>
          <Input id="category" placeholder="analgesic" {...register('category')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="dosage_form">Form</Label>
          <Input id="dosage_form" placeholder="tablet" {...register('dosage_form')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="dosage_strength">Strength</Label>
          <Input id="dosage_strength" placeholder="500mg" {...register('dosage_strength')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="med_unit">Unit</Label>
          <Input id="med_unit" {...register('unit')} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="reorder_threshold">Reorder threshold</Label>
          <Input
            id="reorder_threshold"
            type="number"
            min={0}
            aria-invalid={errors.reorder_threshold !== undefined}
            {...register('reorder_threshold', { valueAsNumber: true })}
          />
        </div>
        <DialogFooter className="col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Create
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function AddBatchDialog({ medicine, onClose }: { medicine: Medicine; onClose: () => void }) {
  const add = useAddBatch();
  const { register, handleSubmit, formState: { errors }, reset } =
    useForm<AddBatchInput>({ resolver: zodResolver(addBatchSchema) });

  const onSubmit = handleSubmit((values) => {
    add.mutate({ medicineId: medicine.id, input: values }, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Receive batch — {medicine.generic_name}</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-2 gap-3">
        <div className="space-y-1.5">
          <Label htmlFor="batch_number">Batch / lot number</Label>
          <Input id="batch_number" aria-invalid={errors.batch_number !== undefined} {...register('batch_number')} />
          {errors.batch_number !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.batch_number.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="quantity_received">Quantity</Label>
          <Input
            id="quantity_received"
            type="number"
            min={1}
            aria-invalid={errors.quantity_received !== undefined}
            {...register('quantity_received', { valueAsNumber: true })}
          />
          {errors.quantity_received !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.quantity_received.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="expiration_date">Expiration date</Label>
          <Input id="expiration_date" type="date" aria-invalid={errors.expiration_date !== undefined} {...register('expiration_date')} />
          {errors.expiration_date !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.expiration_date.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="supplier">Supplier</Label>
          <Input id="supplier" {...register('supplier')} />
        </div>
        <DialogFooter className="col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={add.isPending}>
            {add.isPending && <Loader2 className="animate-spin" />} Receive
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function DispenseDialog({ medicine, onClose }: { medicine: Medicine; onClose: () => void }) {
  const dispense = useDispense();
  const { register, handleSubmit, formState: { errors }, reset } =
    useForm<DispenseInput>({ resolver: zodResolver(dispenseSchema) });

  const onSubmit = handleSubmit((values) => {
    dispense.mutate({ medicineId: medicine.id, input: values }, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Dispense — {medicine.generic_name}</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <p className="text-xs text-muted-foreground">
          On hand: <span className="font-mono">{medicine.quantity_on_hand} {medicine.unit}</span>.
          Stock is drawn from the earliest-expiring lot first (FEFO).
        </p>
        <div className="space-y-1.5">
          <Label htmlFor="quantity">Quantity</Label>
          <Input
            id="quantity"
            type="number"
            min={1}
            aria-invalid={errors.quantity !== undefined}
            {...register('quantity', { valueAsNumber: true })}
          />
          {errors.quantity !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.quantity.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="note">Note (optional)</Label>
          <Input id="note" {...register('note')} />
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={dispense.isPending}>
            {dispense.isPending && <Loader2 className="animate-spin" />}
            <Syringe /> Dispense
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function BatchesDialog({ medicineId, onClose }: { medicineId: number; onClose: () => void }) {
  const detail = useMedicine(medicineId);
  const m = detail.data;

  return (
    <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>
          Batches {m !== undefined ? `— ${m.generic_name} (${m.quantity_on_hand} ${m.unit} on hand)` : ''}
        </DialogTitle>
      </DialogHeader>

      {detail.isLoading && <Loader2 className="mx-auto size-5 animate-spin text-muted-foreground" />}

      {m !== undefined && (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Lot</TableHead>
              <TableHead>Remaining</TableHead>
              <TableHead>Expires</TableHead>
              <TableHead>Supplier</TableHead>
              <TableHead>Status</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {(m.batches ?? []).length === 0 && (
              <TableRow>
                <TableCell colSpan={5} className="py-6 text-center text-muted-foreground">
                  No batches received yet.
                </TableCell>
              </TableRow>
            )}
            {(m.batches ?? []).map((b) => {
              const days = daysUntil(b.expiration_date);
              return (
                <TableRow key={b.id}>
                  <TableCell className="font-mono text-xs">{b.batch_number}</TableCell>
                  <TableCell className="font-mono text-xs">
                    {b.quantity_remaining}/{b.quantity_received}
                  </TableCell>
                  <TableCell className="text-xs">
                    {b.expiration_date}
                    {b.status === 'active' && days <= 30 && (
                      <Badge variant={days <= 7 ? 'destructive' : 'warning'} className="ml-1.5">
                        {days <= 0 ? 'expired' : `${days}d`}
                      </Badge>
                    )}
                  </TableCell>
                  <TableCell className="text-xs">{b.supplier ?? '—'}</TableCell>
                  <TableCell>
                    <Badge variant={BATCH_STATUS_VARIANT[b.status]}>{b.status}</Badge>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      )}

      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
      </DialogFooter>
    </DialogContent>
  );
}

function MedicinesTab() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [openCreate, setOpenCreate] = useState(false);
  const [receiveFor, setReceiveFor] = useState<Medicine | null>(null);
  const [dispenseFor, setDispenseFor] = useState<Medicine | null>(null);
  const [batchesFor, setBatchesFor] = useState<number | null>(null);
  const list = useMedicines(cursor, 25);
  const forecast = useComputeForecast();

  function nextPage() {
    if (list.data?.next !== null && list.data?.next !== undefined) {
      const n = list.data.next;
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

  const rows = list.data?.data ?? [];

  return (
    <div className="space-y-4">
      <section className="flex justify-end rounded-xl border bg-card p-3">
        <Dialog open={openCreate} onOpenChange={setOpenCreate}>
          <Button onClick={() => setOpenCreate(true)}>
            <Pill /> New medicine
          </Button>
          {openCreate && <CreateMedicineDialog onClose={() => setOpenCreate(false)} />}
        </Dialog>
      </section>

      <section className="overflow-hidden rounded-xl border bg-card">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">Medicine</TableHead>
              <TableHead className="px-3">Category</TableHead>
              <TableHead className="px-3">On hand</TableHead>
              <TableHead className="px-3">Earliest expiry</TableHead>
              <TableHead className="px-3">Stock</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.isLoading && (
              <TableRow>
                <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!list.isLoading && rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                  No medicines in the catalog.
                </TableCell>
              </TableRow>
            )}
            {rows.map((m) => {
              const days = m.earliest_expiry !== null ? daysUntil(m.earliest_expiry) : null;
              return (
                <TableRow key={m.id}>
                  <TableCell className="px-3">
                    <span className="font-medium">{m.generic_name}</span>
                    <span className="ml-1 text-xs text-muted-foreground">
                      {[m.brand_name, m.dosage_strength].filter(Boolean).join(' · ')}
                    </span>
                  </TableCell>
                  <TableCell className="px-3 text-xs">{m.category ?? '—'}</TableCell>
                  <TableCell className="px-3 font-mono text-xs">
                    {m.quantity_on_hand} {m.unit}
                  </TableCell>
                  <TableCell className="px-3 text-xs">
                    {m.earliest_expiry ?? '—'}
                    {days !== null && days <= 30 && (
                      <Badge variant={days <= 7 ? 'destructive' : 'warning'} className="ml-1.5">
                        {days}d
                      </Badge>
                    )}
                  </TableCell>
                  <TableCell className="px-3">
                    {m.low_stock ? <Badge variant="warning">Low</Badge> : <Badge variant="success">OK</Badge>}
                  </TableCell>
                  <TableCell className="px-3 text-right">
                    <div className="flex justify-end gap-1">
                      <Button size="sm" variant="secondary" onClick={() => setReceiveFor(m)}>
                        <PackagePlus /> Receive
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={m.quantity_on_hand === 0}
                        onClick={() => setDispenseFor(m)}
                      >
                        <Syringe /> Dispense
                      </Button>
                      <Button size="sm" variant="outline" onClick={() => setBatchesFor(m.id)}>
                        <Layers /> Batches
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        aria-label={`Forecast ${m.generic_name}`}
                        disabled={forecast.isPending}
                        onClick={() => forecast.mutate(m.id)}
                      >
                        <TrendingUp /> Forecast
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </section>

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
            disabled={list.data?.next === null || list.data?.next === undefined}
          >
            Next <ChevronRight />
          </Button>
        </div>
      </nav>

      {receiveFor !== null && (
        <Dialog open onOpenChange={(o) => !o && setReceiveFor(null)}>
          <AddBatchDialog medicine={receiveFor} onClose={() => setReceiveFor(null)} />
        </Dialog>
      )}
      {dispenseFor !== null && (
        <Dialog open onOpenChange={(o) => !o && setDispenseFor(null)}>
          <DispenseDialog medicine={dispenseFor} onClose={() => setDispenseFor(null)} />
        </Dialog>
      )}
      {batchesFor !== null && (
        <Dialog open onOpenChange={(o) => !o && setBatchesFor(null)}>
          <BatchesDialog medicineId={batchesFor} onClose={() => setBatchesFor(null)} />
        </Dialog>
      )}
    </div>
  );
}

// ------------------------------------------------------------- reorders

function CreateReorderDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateReorder();
  const medicines = useMedicines(null, 25);
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<CreateReorderInput>({
      resolver: zodResolver(createReorderSchema),
      defaultValues: { urgency: 'medium' },
    });

  const medicineId = watch('medicine_id');
  const urgency = watch('urgency');

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: () => {
        reset({ urgency: 'medium' });
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>New reorder request</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="space-y-1.5">
          <Label id="reorder-med-label">Medicine</Label>
          <Select
            value={medicineId !== undefined ? String(medicineId) : ''}
            onValueChange={(v) => setValue('medicine_id', Number(v), { shouldValidate: true })}
          >
            <SelectTrigger aria-labelledby="reorder-med-label">
              <SelectValue placeholder="Select…" />
            </SelectTrigger>
            <SelectContent>
              {(medicines.data?.data ?? []).map((m) => (
                <SelectItem key={m.id} value={String(m.id)}>
                  {m.generic_name} — {m.quantity_on_hand} {m.unit} on hand
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors.medicine_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.medicine_id.message}</p>
          )}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="reorder_quantity">Quantity</Label>
            <Input
              id="reorder_quantity"
              type="number"
              min={1}
              aria-invalid={errors.quantity !== undefined}
              {...register('quantity', { valueAsNumber: true })}
            />
            {errors.quantity !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.quantity.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label id="reorder-urgency-label">Urgency</Label>
            <Select
              value={urgency}
              onValueChange={(v) => setValue('urgency', v as CreateReorderInput['urgency'])}
            >
              <SelectTrigger aria-labelledby="reorder-urgency-label"><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="low">Low</SelectItem>
                <SelectItem value="medium">Medium</SelectItem>
                <SelectItem value="high">High</SelectItem>
                <SelectItem value="critical">Critical</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="reorder_note">Note (optional)</Label>
          <Input id="reorder_note" {...register('note')} />
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Request
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function ReordersTab() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [openCreate, setOpenCreate] = useState(false);
  const list = useReorders(cursor, null, 25);
  const autoCheck = useReorderAutoCheck();
  const transition = useReorderTransition();

  function nextPage() {
    if (list.data?.next !== null && list.data?.next !== undefined) {
      const n = list.data.next;
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

  const rows = list.data?.data ?? [];

  return (
    <div className="space-y-4">
      <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3">
        <p className="text-xs text-muted-foreground">
          Auto-check scans every medicine and files a request when unexpired stock falls to its threshold.
        </p>
        <div className="flex gap-2">
          <Button
            variant="secondary"
            disabled={autoCheck.isPending}
            onClick={() => autoCheck.mutate()}
          >
            {autoCheck.isPending ? <Loader2 className="animate-spin" /> : <RefreshCw />} Run auto-check
          </Button>
          <Dialog open={openCreate} onOpenChange={setOpenCreate}>
            <Button onClick={() => setOpenCreate(true)}>
              <Plus /> New request
            </Button>
            {openCreate && <CreateReorderDialog onClose={() => setOpenCreate(false)} />}
          </Dialog>
        </div>
      </section>

      <section className="overflow-hidden rounded-xl border bg-card">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">#</TableHead>
              <TableHead className="px-3">Medicine</TableHead>
              <TableHead className="px-3">Qty</TableHead>
              <TableHead className="px-3">Stock @ request</TableHead>
              <TableHead className="px-3">Urgency</TableHead>
              <TableHead className="px-3">Status</TableHead>
              <TableHead className="px-3">Dates</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.isLoading && (
              <TableRow>
                <TableCell colSpan={8} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!list.isLoading && rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={8} className="px-3 py-6 text-center text-muted-foreground">
                  No reorder requests.
                </TableCell>
              </TableRow>
            )}
            {rows.map((r) => (
              <TableRow key={r.id}>
                <TableCell className="px-3 font-mono text-xs">
                  {r.id}
                  {r.auto_triggered && <Badge variant="outline" className="ml-1.5">auto</Badge>}
                </TableCell>
                <TableCell className="px-3">{r.generic_name ?? `#${r.medicine_id}`}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{r.requested_quantity} {r.unit ?? ''}</TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">
                  {r.current_stock} / lvl {r.reorder_level}
                </TableCell>
                <TableCell className="px-3">
                  <Badge variant={URGENCY_VARIANT[r.urgency]}>{r.urgency}</Badge>
                </TableCell>
                <TableCell className="px-3">
                  <Badge variant={REORDER_STATUS_VARIANT[r.status]}>{r.status}</Badge>
                </TableCell>
                <TableCell className="px-3 text-xs text-muted-foreground">
                  {r.order_date !== null && <>ordered {r.order_date}<br /></>}
                  {r.expected_delivery_date !== null && <>eta {r.expected_delivery_date}<br /></>}
                  {r.actual_delivery_date !== null && <>delivered {r.actual_delivery_date}</>}
                  {r.order_date === null && r.actual_delivery_date === null && '—'}
                </TableCell>
                <TableCell className="px-3 text-right">
                  <div className="flex justify-end gap-1">
                    {r.status === 'pending' && (
                      <Button size="sm" variant="secondary" disabled={transition.isPending}
                        onClick={() => transition.mutate({ id: r.id, action: 'approve' })}>
                        <Check /> Approve
                      </Button>
                    )}
                    {r.status === 'approved' && (
                      <Button size="sm" variant="secondary" disabled={transition.isPending}
                        onClick={() => transition.mutate({ id: r.id, action: 'order' })}>
                        <Truck /> Order
                      </Button>
                    )}
                    {r.status === 'ordered' && (
                      <Button size="sm" variant="secondary" disabled={transition.isPending}
                        onClick={() => transition.mutate({ id: r.id, action: 'receive' })}>
                        <PackageCheck /> Receive
                      </Button>
                    )}
                    {(r.status === 'pending' || r.status === 'approved' || r.status === 'ordered') && (
                      <Button size="sm" variant="outline" disabled={transition.isPending}
                        onClick={() => transition.mutate({ id: r.id, action: 'cancel' })}>
                        <X /> Cancel
                      </Button>
                    )}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

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
            disabled={list.data?.next === null || list.data?.next === undefined}
          >
            Next <ChevronRight />
          </Button>
        </div>
      </nav>
    </div>
  );
}

// ------------------------------------------------------------- supplies

function CreateItemDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateItem();
  const {
    register,
    handleSubmit,
    formState: { errors },
    reset,
  } = useForm<CreateItemInput>({
    resolver: zodResolver(createItemSchema),
    defaultValues: { unit: 'pc', reorder_level: 0 },
  });

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>New inventory item</DialogTitle>
      </DialogHeader>
      <form onSubmit={(e) => void onSubmit(e)} className="space-y-3" noValidate>
        <div className="space-y-1.5">
          <Label htmlFor="sku">SKU</Label>
          <Input id="sku" aria-invalid={errors.sku !== undefined} {...register('sku')} />
          {errors.sku !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.sku.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="name">Name</Label>
          <Input id="name" aria-invalid={errors.name !== undefined} {...register('name')} />
          {errors.name !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.name.message}</p>
          )}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="unit">Unit</Label>
            <Input id="unit" {...register('unit')} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="reorder_level">Reorder level</Label>
            <Input id="reorder_level" type="number" {...register('reorder_level', { valueAsNumber: true })} />
          </div>
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Create
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function MoveStockDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const move = useMoveStock();
  const {
    register,
    handleSubmit,
    formState: { errors },
    reset,
    setValue,
    watch,
  } = useForm<MoveStockInput>({
    resolver: zodResolver(moveStockSchema),
    defaultValues: { reason_code: 'receive' },
  });

  const reasonCode = watch('reason_code');

  const onSubmit = handleSubmit((values) => {
    move.mutate(
      { itemId: item.id, input: values },
      {
        onSuccess: () => {
          reset();
          onClose();
        },
      },
    );
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Move stock — {item.sku}</DialogTitle>
      </DialogHeader>
      <form onSubmit={(e) => void onSubmit(e)} className="space-y-3" noValidate>
        <p className="text-xs text-muted-foreground">
          On hand: <span className="font-mono">{item.quantity_on_hand} {item.unit}</span>.
          Receipts are positive, dispenses negative.
        </p>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="qty_delta">Quantity delta</Label>
            <Input id="qty_delta" type="number" aria-invalid={errors.qty_delta !== undefined} {...register('qty_delta', { valueAsNumber: true })} />
            {errors.qty_delta !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.qty_delta.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="reason_code">Reason</Label>
            <Select
              value={reasonCode}
              onValueChange={(v) => setValue('reason_code', v as MoveStockInput['reason_code'], { shouldValidate: true })}
            >
              <SelectTrigger id="reason_code" aria-label="Reason">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="receive">Receive</SelectItem>
                <SelectItem value="dispense">Dispense</SelectItem>
                <SelectItem value="adjustment">Adjustment</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="note">Note (optional)</Label>
          <Input id="note" {...register('note')} />
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={move.isPending}>
            {move.isPending && <Loader2 className="animate-spin" />} Apply
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function SuppliesTab() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [openCreate, setOpenCreate] = useState(false);
  const [moveItem, setMoveItem] = useState<InventoryItem | null>(null);
  const list = useInventoryItems(cursor, 25);

  function nextPage() {
    if (list.data?.next !== null && list.data?.next !== undefined) {
      const n = list.data.next;
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

  const rows = list.data?.data ?? [];

  return (
    <div className="space-y-4">
      <section className="flex justify-end rounded-xl border bg-card p-3">
        <Dialog open={openCreate} onOpenChange={setOpenCreate}>
          <Button onClick={() => setOpenCreate(true)}>
            <Plus /> New item
          </Button>
          {openCreate && <CreateItemDialog onClose={() => setOpenCreate(false)} />}
        </Dialog>
      </section>

      <section className="overflow-hidden rounded-xl border bg-card">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">SKU</TableHead>
              <TableHead className="px-3">Name</TableHead>
              <TableHead className="px-3">On hand</TableHead>
              <TableHead className="px-3">Reorder level</TableHead>
              <TableHead className="px-3">Stock</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.isLoading && (
              <TableRow>
                <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!list.isLoading && rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                  No items.
                </TableCell>
              </TableRow>
            )}
            {rows.map((it) => (
              <TableRow key={it.id}>
                <TableCell className="px-3 font-mono text-xs">{it.sku}</TableCell>
                <TableCell className="px-3">{it.name}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{it.quantity_on_hand} {it.unit}</TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">{it.reorder_level}</TableCell>
                <TableCell className="px-3">
                  {it.low_stock ? <Badge variant="warning">Low</Badge> : <Badge variant="success">OK</Badge>}
                </TableCell>
                <TableCell className="px-3 text-right">
                  <Button size="sm" variant="secondary" onClick={() => setMoveItem(it)}>
                    <ArrowDownUp /> Move
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

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
            disabled={list.data?.next === null || list.data?.next === undefined}
          >
            Next <ChevronRight />
          </Button>
        </div>
      </nav>

      {moveItem !== null && (
        <Dialog open onOpenChange={(o) => !o && setMoveItem(null)}>
          <MoveStockDialog item={moveItem} onClose={() => setMoveItem(null)} />
        </Dialog>
      )}
    </div>
  );
}

// ----------------------------------------------------------------- page

export default function InventoryPage() {
  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header>
        <h1 className="text-xl font-semibold text-foreground">Inventory</h1>
        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
          <CalendarClock className="size-3.5" aria-hidden />
          Medicines are batch-tracked with expiry (FEFO dispensing); supplies use the signed movement ledger.
        </p>
      </header>

      <Tabs defaultValue="medicines">
        <TabsList>
          <TabsTrigger value="medicines">Medicines</TabsTrigger>
          <TabsTrigger value="reorders">Reorders</TabsTrigger>
          <TabsTrigger value="supplies">Supplies</TabsTrigger>
        </TabsList>
        <TabsContent value="medicines">
          <MedicinesTab />
        </TabsContent>
        <TabsContent value="reorders">
          <ReordersTab />
        </TabsContent>
        <TabsContent value="supplies">
          <SuppliesTab />
        </TabsContent>
      </Tabs>
    </main>
  );
}
