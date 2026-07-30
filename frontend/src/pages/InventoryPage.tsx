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
  Archive,
  ArchiveRestore,
  CalendarClock,
  Check,
  ChevronLeft,
  ChevronRight,
  Layers,
  Loader2,
  PackageCheck,
  PackagePlus,
  Pencil,
  Pill,
  Plus,
  RefreshCw,
  ScrollText,
  Search,
  Syringe,
  TrendingUp,
  Truck,
  X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { QueryErrorRow } from '@/components/QueryErrorState';
import { MobileCardList, MobileCard, MobileCardField, MobileCardActions } from '@/components/MobileCardList';
import { DatePicker } from '@/components/ui/date-picker';
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
import { useTabParam } from '@/hooks/useTabParam';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useEncounters } from '@/hooks/useClinic';
import { useArchiveItem, useCreateItem, useInventoryItems, useInventoryMovements, useMoveStock, useReceiveSupply, useUnarchiveItem, useUpdateItem } from '@/hooks/useInventory';
import {
  useAddBatch,
  useArchiveMedicine,
  useComputeForecast,
  useCreateMedicine,
  useDispense,
  useMedicine,
  useMedicineTransactions,
  useMedicines,
  useUnarchiveMedicine,
  useUpdateMedicine,
} from '@/hooks/useMedicines';
import {
  useCreateReorder,
  useReceivableReorder,
  useReorderAutoCheck,
  useReorders,
  useReorderTransition,
} from '@/hooks/useReorders';
import {
  createItemSchema,
  moveStockSchema,
  updateItemSchema,
  type CreateItemInput,
  type InventoryItem,
  type MoveStockInput,
  type UpdateItemInput,
} from '@/schemas/inventory';
import {
  addBatchSchema,
  createMedicineSchema,
  dispenseSchema,
  updateMedicineSchema,
  type AddBatchInput,
  type CreateMedicineInput,
  type DispenseInput,
  type Medicine,
  type UpdateMedicineInput,
} from '@/schemas/medicines';
import {
  createReorderSchema,
  type CreateReorderInput,
} from '@/schemas/reorders';
import { fmtUtcToApp } from '@/utils/date';

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
  completed: 'secondary',
  cancelled: 'secondary',
} as const;

/** Days until a date (negative = past). */
function daysUntil(date: string): number {
  return Math.ceil((new Date(date).getTime() - Date.now()) / 864e5);
}

/**
 * SearchBox — small debounced search field. The parent owns the
 * live `value` and a `debouncedValue` (300 ms trailing edge). The
 * debounced value is what gets sent to the server; the live value
 * is what the input shows.
 */
function SearchBox({
  value,
  onValueChange,
  placeholder,
  inputId,
}: {
  value: string;
  onValueChange: (next: string) => void;
  placeholder: string;
  inputId?: string;
}) {
  return (
    <div className="relative w-full sm:w-64">
      <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
      <Input
        id={inputId}
        type="search"
        className="h-9 pl-9 pr-9"
        value={value}
        onChange={(e) => onValueChange(e.target.value)}
        placeholder={placeholder}
        aria-label="Search"
      />
      {value !== '' && (
        <button
          type="button"
          aria-label="Clear search"
          className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-muted-foreground hover:bg-muted/50 hover:text-foreground"
          onClick={() => onValueChange('')}
        >
          <X className="size-3.5" />
        </button>
      )}
    </div>
  );
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
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
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

/**
 * AddBatchDialog — receive a delivered lot. The quantity is detected
 * from the medicine's `received` reorder request (procurement loop);
 * the operator only supplies the batch identity. When no delivery has
 * been marked received on the Reorders tab, receiving is blocked —
 * mirroring the backend's 409 gate.
 */
function AddBatchDialog({ medicine, onClose }: { medicine: Medicine; onClose: () => void }) {
  const add = useAddBatch();
  const receivable = useReceivableReorder('medicine', medicine.id);
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<AddBatchInput>({ resolver: zodResolver(addBatchSchema) });

  const onSubmit = handleSubmit((values) => {
    add.mutate({ medicineId: medicine.id, input: values }, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  const order = receivable.data ?? null;

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Receive batch — {medicine.generic_name}</DialogTitle>
      </DialogHeader>

      {receivable.isLoading && <Loader2 className="mx-auto size-5 animate-spin text-muted-foreground" />}

      {!receivable.isLoading && order === null && (
        <p role="alert" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          No received delivery for this medicine. Order it on the Reorders tab and mark
          the request as <span className="font-medium">received</span> when the delivery
          arrives — then the batch can be entered here.
        </p>
      )}

      {order !== null && (
        <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <p className="col-span-2 rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
            Receiving reorder <span className="font-mono">#{order.id}</span> —{' '}
            <span className="font-medium text-foreground">{order.requested_quantity} {medicine.unit}</span>{' '}
            (quantity is taken from the order and cannot be edited).
          </p>
          <div className="space-y-1.5">
            <Label htmlFor="batch_number">Batch / lot number</Label>
            <Input id="batch_number" aria-invalid={errors.batch_number !== undefined} {...register('batch_number')} />
            {errors.batch_number !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.batch_number.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="batch_qty_detected">Quantity (from order)</Label>
            <Input id="batch_qty_detected" value={`${order.requested_quantity} ${medicine.unit}`} readOnly disabled />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="expiration_date">Expiration date</Label>
            <DatePicker id="expiration_date" aria-invalid={errors.expiration_date !== undefined} value={watch('expiration_date') ?? ''} onChange={(v) => setValue('expiration_date', v, { shouldValidate: true, shouldDirty: true })} />
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
      )}

      {!receivable.isLoading && order === null && (
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Close</Button>
        </DialogFooter>
      )}
    </DialogContent>
  );
}

function DispenseDialog({ medicine, onClose }: { medicine: Medicine; onClose: () => void }) {
  const dispense = useDispense();
  // Panel revision: dispensing is anchored to an OPEN encounter (the
  // actual clinic visit), so the ledger records who received the stock.
  const encounters = useEncounters(null, 50, 'open');
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<DispenseInput>({ resolver: zodResolver(dispenseSchema) });
  const encounterId = watch('encounter_id');

  const onSubmit = handleSubmit((values) => {
    dispense.mutate({ medicineId: medicine.id, input: values }, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  const openEncounters = encounters.data?.data ?? [];

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
          <Label id="dispense-encounter-label">Open encounter</Label>
          <Select value={encounterId !== undefined ? String(encounterId) : ''} onValueChange={(v) => setValue('encounter_id', Number(v), { shouldValidate: true })}>
            <SelectTrigger aria-labelledby="dispense-encounter-label" aria-invalid={errors.encounter_id !== undefined}>
              <SelectValue placeholder={openEncounters.length === 0 ? 'No open encounters' : 'Select the visit…'} />
            </SelectTrigger>
            <SelectContent>
              {openEncounters.map((e) => (
                <SelectItem key={e.id} value={String(e.id)}>
                  #{e.id} · {e.patient_school_id} · {e.chief_complaint.slice(0, 40)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors.encounter_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.encounter_id.message}</p>
          )}
          {openEncounters.length === 0 && !encounters.isLoading && (
            <p className="text-[10px] text-muted-foreground">Open an encounter in Clinic first — dispensing must be tied to a visit.</p>
          )}
        </div>
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
          <Button type="submit" disabled={dispense.isPending || openEncounters.length === 0}>
            {dispense.isPending && <Loader2 className="animate-spin" />}
            <Syringe /> Dispense
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

/**
 * LedgerRow — one debit/credit line rendered by both the medicine and
 * supply ledgers (panel revision: in/out tracking with a running
 * balance). `qty_in` and `qty_out` are mutually exclusive.
 */
function LedgerBody({
  rows,
  isLoading,
  isError,
  emptyLabel,
}: {
  rows: Array<{ id: number; label: string; qty_in: number | null; qty_out: number | null; balance_after: number | null; note: string | null; created_at: string }>;
  isLoading: boolean;
  isError: boolean;
  emptyLabel: string;
}) {
  return (
    <div className="max-h-96 overflow-auto rounded-md border">
      <Table>
        <TableHeader className="sticky top-0 bg-muted/70">
          <TableRow>
            <TableHead className="px-3">Date</TableHead>
            <TableHead className="px-3">Reference</TableHead>
            <TableHead className="px-3 text-right">In</TableHead>
            <TableHead className="px-3 text-right">Out</TableHead>
            <TableHead className="px-3 text-right">Balance</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {isLoading && (
            <TableRow><TableCell colSpan={5} className="px-3 py-6 text-center text-muted-foreground"><Loader2 className="mx-auto size-4 animate-spin" /></TableCell></TableRow>
          )}
          {isError && !isLoading && (
            <TableRow><TableCell colSpan={5} className="px-3 py-6 text-center text-destructive">Failed to load the ledger.</TableCell></TableRow>
          )}
          {!isLoading && !isError && rows.length === 0 && (
            <TableRow><TableCell colSpan={5} className="px-3 py-6 text-center text-muted-foreground">{emptyLabel}</TableCell></TableRow>
          )}
          {rows.map((r) => (
            <TableRow key={r.id}>
              <TableCell className="px-3 font-mono text-xs text-muted-foreground">{fmtUtcToApp(r.created_at)}</TableCell>
              <TableCell className="px-3 text-xs">
                {r.label}
                {r.note !== null && r.note !== '' ? <span className="ml-1 text-muted-foreground">· {r.note}</span> : ''}
              </TableCell>
              <TableCell className="px-3 text-right font-mono text-xs text-emerald-600">{r.qty_in !== null ? `+${r.qty_in}` : ''}</TableCell>
              <TableCell className="px-3 text-right font-mono text-xs text-destructive">{r.qty_out !== null ? `-${r.qty_out}` : ''}</TableCell>
              <TableCell className="px-3 text-right font-mono text-xs font-semibold">{r.balance_after ?? '—'}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function MedicineLedgerDialog({ medicine, onClose }: { medicine: Medicine; onClose: () => void }) {
  const txns = useMedicineTransactions(medicine.id);
  const rows = (txns.data ?? []).map((t) => ({
    id: t.id,
    label: t.reference_type !== null ? `${t.type} · ${t.reference_type}#${t.reference_id ?? '?'}` : t.type,
    qty_in: t.qty_in,
    qty_out: t.qty_out,
    balance_after: t.balance_after,
    note: t.note,
    created_at: t.created_at,
  }));
  return (
    <DialogContent className="max-w-2xl">
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2"><ScrollText className="size-4" /> Ledger — {medicine.generic_name}</DialogTitle>
      </DialogHeader>
      <p className="text-xs text-muted-foreground">Every stock movement, oldest first. Balance is the on-hand total after each entry.</p>
      <LedgerBody rows={rows} isLoading={txns.isLoading} isError={txns.isError} emptyLabel="No transactions yet." />
      <DialogFooter><Button variant="outline" onClick={onClose}>Close</Button></DialogFooter>
    </DialogContent>
  );
}

function SupplyLedgerDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const moves = useInventoryMovements(item.id);
  const rows = (moves.data ?? []).map((m) => ({
    id: m.id,
    label: m.reason_code,
    qty_in: m.qty_in,
    qty_out: m.qty_out,
    balance_after: m.balance_after,
    note: m.note,
    created_at: m.created_at,
  }));
  return (
    <DialogContent className="max-w-2xl">
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2"><ScrollText className="size-4" /> Ledger — {item.name}</DialogTitle>
      </DialogHeader>
      <p className="text-xs text-muted-foreground">Every stock movement, oldest first. Balance is the on-hand total after each entry.</p>
      <LedgerBody rows={rows} isLoading={moves.isLoading} isError={moves.isError} emptyLabel="No movements yet." />
      <DialogFooter><Button variant="outline" onClick={onClose}>Close</Button></DialogFooter>
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

/**
 * EditMedicineDialog — the catalog identity (names, category, form,
 * strength, unit) is read-only after creation so the batch ledger and
 * forecasts keep describing the same product. Only the reorder
 * threshold is editable; the backend enforces the same restriction.
 */
function EditMedicineDialog({ medicine, onClose }: { medicine: Medicine; onClose: () => void }) {
  const update = useUpdateMedicine();
  const { register, handleSubmit, formState: { errors }, reset } =
    useForm<UpdateMedicineInput>({
      resolver: zodResolver(updateMedicineSchema),
      defaultValues: { reorder_threshold: medicine.reorder_threshold },
    });

  const onSubmit = handleSubmit((values) => {
    update.mutate(
      { medicineId: medicine.id, input: values },
      { onSuccess: () => { reset(); onClose(); } },
    );
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Edit — {medicine.generic_name}</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <p className="col-span-2 text-xs text-muted-foreground">
          Catalog details are locked after creation — only the reorder threshold can change.
        </p>
        <div className="space-y-1.5">
          <Label htmlFor="edit_brand_name">Brand name</Label>
          <Input id="edit_brand_name" value={medicine.brand_name ?? '—'} readOnly disabled />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit_category">Category</Label>
          <Input id="edit_category" value={medicine.category ?? '—'} readOnly disabled />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit_dosage_form">Form</Label>
          <Input id="edit_dosage_form" value={medicine.dosage_form ?? '—'} readOnly disabled />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit_dosage_strength">Strength</Label>
          <Input id="edit_dosage_strength" value={medicine.dosage_strength ?? '—'} readOnly disabled />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit_med_unit">Unit</Label>
          <Input id="edit_med_unit" value={medicine.unit} readOnly disabled />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="edit_reorder_threshold">Reorder threshold</Label>
          <Input
            id="edit_reorder_threshold"
            type="number"
            min={0}
            aria-invalid={errors.reorder_threshold !== undefined}
            {...register('reorder_threshold', { valueAsNumber: true })}
          />
          {errors.reorder_threshold !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.reorder_threshold.message}</p>
          )}
        </div>
        <DialogFooter className="col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={update.isPending}>
            {update.isPending && <Loader2 className="animate-spin" />} Save
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

/**
 * EditItemDialog — update a supply item (Supplies tab). SKU is
 * intentionally NOT editable; it backs the movement ledger.
 */
function EditItemDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const update = useUpdateItem();
  const { register, handleSubmit, formState: { errors }, reset } =
    useForm<UpdateItemInput>({
      resolver: zodResolver(updateItemSchema),
      defaultValues: {
        name: item.name,
        unit: item.unit,
        reorder_level: item.reorder_level,
      },
    });

  const onSubmit = handleSubmit((values) => {
    update.mutate(
      { itemId: item.id, input: values },
      { onSuccess: () => { reset(); onClose(); } },
    );
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Edit — {item.sku}</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <p className="text-xs text-muted-foreground">
          SKU is immutable (it backs the movement ledger). To rename it, archive and recreate.
        </p>
        <div className="space-y-1.5">
          <Label htmlFor="edit_item_name">Name</Label>
          <Input id="edit_item_name" aria-invalid={errors.name !== undefined} {...register('name')} />
          {errors.name !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.name.message}</p>
          )}
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="edit_item_unit">Unit</Label>
            <Input id="edit_item_unit" {...register('unit')} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="edit_item_reorder_level">Reorder level</Label>
            <Input
              id="edit_item_reorder_level"
              type="number"
              min={0}
              {...register('reorder_level', { valueAsNumber: true })}
            />
          </div>
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={update.isPending}>
            {update.isPending && <Loader2 className="animate-spin" />} Save
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function MedicinesTab() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [openCreate, setOpenCreate] = useState(false);
  const [receiveFor, setReceiveFor] = useState<Medicine | null>(null);
  const [dispenseFor, setDispenseFor] = useState<Medicine | null>(null);
  const [ledgerFor, setLedgerFor] = useState<Medicine | null>(null);
  const [batchesFor, setBatchesFor] = useState<number | null>(null);
  const [editFor, setEditFor] = useState<Medicine | null>(null);
  const [archiveFor, setArchiveFor] = useState<Medicine | null>(null);
  const [showArchived, setShowArchived] = useState(false);
  const [q, setQ] = useState('');
  const debouncedQ = useDebouncedValue(q, 300);
  const list = useMedicines(cursor, 25, debouncedQ === '' ? null : debouncedQ, showArchived);
  const forecast = useComputeForecast();
  const archive = useArchiveMedicine();
  const unarchive = useUnarchiveMedicine();

  // Reset to the first page whenever the debounced search changes.
  const lastQueryRef = useRef<string>('');
  useEffect(() => {
    if (debouncedQ !== lastQueryRef.current) {
      lastQueryRef.current = debouncedQ;
      setCursor(null);
      setHistory([null]);
    }
  }, [debouncedQ]);

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

  // Action rail shared by the desktop row and the mobile card so the
  // two surfaces never drift. `size="sm"` buttons are 40px tall on
  // mobile (touch) and wrap inside the card footer.
  const medicineActions = (m: Medicine) =>
    m.archived ? (
      <>
        <Button size="sm" variant="outline" onClick={() => setBatchesFor(m.id)}>
          <Layers /> Batches
        </Button>
        <Button size="sm" variant="outline" disabled={unarchive.isPending} onClick={() => unarchive.mutate(m.id)}>
          <ArchiveRestore /> Restore
        </Button>
      </>
    ) : (
      <>
        <Button size="sm" variant="secondary" onClick={() => setReceiveFor(m)}>
          <PackagePlus /> Receive
        </Button>
        <Button size="sm" variant="outline" disabled={m.quantity_on_hand === 0} onClick={() => setDispenseFor(m)}>
          <Syringe /> Dispense
        </Button>
        <Button size="sm" variant="outline" onClick={() => setBatchesFor(m.id)}>
          <Layers /> Batches
        </Button>
        <Button size="sm" variant="outline" onClick={() => setLedgerFor(m)}>
          <ScrollText /> Ledger
        </Button>
        <Button size="sm" variant="outline" aria-label={`Forecast ${m.generic_name}`} disabled={forecast.isPending} onClick={() => forecast.mutate(m.id)}>
          <TrendingUp /> Forecast
        </Button>
        <Button size="sm" variant="outline" onClick={() => setEditFor(m)}>
          <Pencil /> Edit
        </Button>
        <Button size="sm" variant="outline" className="text-muted-foreground" onClick={() => setArchiveFor(m)}>
          <Archive /> Archive
        </Button>
      </>
    );

  return (
    <div className="space-y-4">
      <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3">
        <SearchBox
          value={q}
          onValueChange={setQ}
          placeholder="Search by name, brand, or category…"
          inputId="medicines-search"
        />
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant={showArchived ? 'secondary' : 'outline'}
            aria-pressed={showArchived}
            onClick={() => { setShowArchived((v) => !v); setCursor(null); setHistory([null]); }}
          >
            <Archive /> {showArchived ? 'Hide archived' : 'Show archived'}
          </Button>
          <Dialog open={openCreate} onOpenChange={setOpenCreate}>
            <Button onClick={() => setOpenCreate(true)}>
              <Pill /> New medicine
            </Button>
            {openCreate && <CreateMedicineDialog onClose={() => setOpenCreate(false)} />}
          </Dialog>
        </div>
      </section>

      <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
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
                  {debouncedQ !== '' ? `No medicines match "${debouncedQ}".` : 'No medicines in the catalog.'}
                </TableCell>
              </TableRow>
            )}
            {list.isError && !list.isLoading && (
              <QueryErrorRow colSpan={6} message="Failed to load medicines." onRetry={() => void list.refetch()} pending={list.isFetching} />
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
                    {m.archived
                      ? <Badge variant="secondary">Archived</Badge>
                      : m.low_stock ? <Badge variant="warning">Low</Badge> : <Badge variant="success">OK</Badge>}
                  </TableCell>
                  <TableCell className="px-3 text-right">
                    <div className="flex flex-wrap justify-end gap-1">
                      {medicineActions(m)}
                    </div>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </section>

      {/* Mobile: medicine cards from the same rows. */}
      {list.isLoading && (
        <p className="py-6 text-center text-sm text-muted-foreground md:hidden" role="status">
          <Loader2 className="mx-auto size-4 animate-spin" />
        </p>
      )}
      {list.isError && !list.isLoading && (
        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-center text-sm text-destructive md:hidden">
          <p>Failed to load medicines.</p>
          <Button variant="outline" size="sm" className="mt-2" onClick={() => void list.refetch()} disabled={list.isFetching}>Retry</Button>
        </div>
      )}
      {!list.isLoading && !list.isError && rows.length === 0 && (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground md:hidden">
          {debouncedQ !== '' ? `No medicines match "${debouncedQ}".` : 'No medicines in the catalog.'}
        </p>
      )}
      <MobileCardList>
        {rows.map((m) => {
          const days = m.earliest_expiry !== null ? daysUntil(m.earliest_expiry) : null;
          return (
            <MobileCard key={m.id} aria-label={`Medicine ${m.generic_name}`}>
              <div className="mb-1 flex items-center justify-between gap-2">
                <span className="text-sm font-medium text-foreground">{m.generic_name}</span>
                {m.archived
                  ? <Badge variant="secondary">Archived</Badge>
                  : m.low_stock ? <Badge variant="warning">Low</Badge> : <Badge variant="success">OK</Badge>}
              </div>
              {[m.brand_name, m.dosage_strength].filter(Boolean).length > 0 && (
                <p className="text-xs text-muted-foreground">{[m.brand_name, m.dosage_strength].filter(Boolean).join(' · ')}</p>
              )}
              <MobileCardField label="Category">{m.category ?? '—'}</MobileCardField>
              <MobileCardField label="On hand"><span className="font-mono text-xs">{m.quantity_on_hand} {m.unit}</span></MobileCardField>
              <MobileCardField label="Earliest expiry">
                <span className="text-xs">
                  {m.earliest_expiry ?? '—'}
                  {days !== null && days <= 30 && (
                    <Badge variant={days <= 7 ? 'destructive' : 'warning'} className="ml-1.5">{days}d</Badge>
                  )}
                </span>
              </MobileCardField>
              <MobileCardActions>{medicineActions(m)}</MobileCardActions>
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
      {ledgerFor !== null && (
        <Dialog open onOpenChange={(o) => !o && setLedgerFor(null)}>
          <MedicineLedgerDialog medicine={ledgerFor} onClose={() => setLedgerFor(null)} />
        </Dialog>
      )}
      {editFor !== null && (
        <Dialog open onOpenChange={(o) => !o && setEditFor(null)}>
          <EditMedicineDialog medicine={editFor} onClose={() => setEditFor(null)} />
        </Dialog>
      )}
      <ConfirmDialog
        open={archiveFor !== null}
        title={archiveFor !== null ? `Archive ${archiveFor.generic_name}?` : ''}
        description="The medicine will be hidden from the catalog list. Batch and movement history are kept for the audit trail. You can re-create the medicine later with the same name."
        confirmLabel="Archive"
        pending={archive.isPending}
        onConfirm={() => {
          if (archiveFor !== null) {
            archive.mutate(archiveFor.id, { onSuccess: () => setArchiveFor(null) });
          }
        }}
        onCancel={() => setArchiveFor(null)}
      />
    </div>
  );
}

// ------------------------------------------------------------- reorders

function CreateReorderDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateReorder();
  const medicines = useMedicines(null, 25);
  const supplies = useInventoryItems(null, 100);
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<CreateReorderInput>({
      resolver: zodResolver(createReorderSchema),
      defaultValues: { item_type: 'medicine', urgency: 'medium' },
    });

  const itemType = watch('item_type');
  const medicineId = watch('medicine_id');
  const supplyItemId = watch('supply_item_id');
  const urgency = watch('urgency');

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: () => {
        reset({ item_type: 'medicine', urgency: 'medium' });
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
          <Label id="reorder-type-label">Item type</Label>
          <Select
            value={itemType}
            onValueChange={(v) => {
              setValue('item_type', v as CreateReorderInput['item_type']);
              // Switching type invalidates the previous pick.
              setValue('medicine_id', undefined);
              setValue('supply_item_id', undefined);
            }}
          >
            <SelectTrigger aria-labelledby="reorder-type-label"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="medicine">Medicine</SelectItem>
              <SelectItem value="supply">Supply</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label id="reorder-item-label">{itemType === 'supply' ? 'Supply item' : 'Medicine'}</Label>
          {itemType === 'supply' ? (
            <Select
              value={supplyItemId !== undefined ? String(supplyItemId) : ''}
              onValueChange={(v) => setValue('supply_item_id', Number(v), { shouldValidate: true })}
            >
              <SelectTrigger aria-labelledby="reorder-item-label">
                <SelectValue placeholder="Select…" />
              </SelectTrigger>
              <SelectContent>
                {(supplies.data?.data ?? []).map((it) => (
                  <SelectItem key={it.id} value={String(it.id)}>
                    {it.name} ({it.sku}) — {it.quantity_on_hand} {it.unit} on hand
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          ) : (
            <Select
              value={medicineId !== undefined ? String(medicineId) : ''}
              onValueChange={(v) => setValue('medicine_id', Number(v), { shouldValidate: true })}
            >
              <SelectTrigger aria-labelledby="reorder-item-label">
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
          )}
          {errors.medicine_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.medicine_id.message}</p>
          )}
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
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

/**
 * OrderReorderDialog — the 'order' transition, capturing an optional
 * expected delivery date (ETA) so the reorder's ETA column can populate.
 */
function OrderReorderDialog({ reorderId, onClose }: { reorderId: number; onClose: () => void }) {
  const transition = useReorderTransition();
  const [eta, setEta] = useState('');

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Mark reorder #{reorderId} as ordered</DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="reorder-eta">Expected delivery date (optional)</Label>
          <DatePicker id="reorder-eta" value={eta} onChange={setEta} className="w-full" placeholder="Pick an ETA" />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button
          disabled={transition.isPending}
          onClick={() =>
            transition.mutate(
              { id: reorderId, action: 'order', ...(eta !== '' ? { expected_delivery_date: eta } : {}) },
              { onSuccess: onClose },
            )
          }
        >
          {transition.isPending && <Loader2 className="animate-spin" />}
          <Truck /> Mark ordered
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

function ReordersTab() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [openCreate, setOpenCreate] = useState(false);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const [orderingId, setOrderingId] = useState<number | null>(null);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [q, setQ] = useState('');
  const debouncedQ = useDebouncedValue(q, 300);
  const list = useReorders(
    cursor,
    statusFilter === 'all' ? null : statusFilter,
    25,
    debouncedQ === '' ? null : debouncedQ,
  );
  const autoCheck = useReorderAutoCheck();
  const transition = useReorderTransition();

  // Reset to the first page whenever the debounced search changes.
  const lastQueryRef = useRef<string>('');
  useEffect(() => {
    if (debouncedQ !== lastQueryRef.current) {
      lastQueryRef.current = debouncedQ;
      setCursor(null);
      setHistory([null]);
    }
  }, [debouncedQ]);

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

  // Lifecycle actions shared by the desktop row and the mobile card.
  const reorderActions = (r: (typeof rows)[number]) => (
    <>
      {r.status === 'pending' && (
        <Button size="sm" variant="secondary" disabled={transition.isPending}
          onClick={() => transition.mutate({ id: r.id, action: 'approve' })}>
          <Check /> Approve
        </Button>
      )}
      {r.status === 'approved' && (
        <Button size="sm" variant="secondary" disabled={transition.isPending}
          onClick={() => setOrderingId(r.id)}>
          <Truck /> Order
        </Button>
      )}
      {r.status === 'ordered' && (
        <Button size="sm" variant="secondary" disabled={transition.isPending}
          onClick={() => transition.mutate({ id: r.id, action: 'receive' })}>
          <PackageCheck /> Mark delivered
        </Button>
      )}
      {r.status === 'received' && (
        <span className="text-xs text-muted-foreground">
          awaiting stock entry on the {r.item_type === 'supply' ? 'Supplies' : 'Medicines'} tab
        </span>
      )}
      {(r.status === 'pending' || r.status === 'approved' || r.status === 'ordered') && (
        <Button size="sm" variant="outline" disabled={transition.isPending}
          onClick={() => setConfirm({
            title: `Cancel reorder #${r.id}?`,
            description: 'The purchase request will be cancelled. This cannot be undone.',
            confirmLabel: 'Cancel request',
            run: () => transition.mutate({ id: r.id, action: 'cancel' }),
          })}>
          <X /> Cancel
        </Button>
      )}
    </>
  );

  return (
    <div className="space-y-4">
      <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3">
        <div className="flex flex-1 flex-wrap items-center gap-3">
          <SearchBox
            value={q}
            onValueChange={setQ}
            placeholder="Search by medicine or note…"
            inputId="reorders-search"
          />
          <p className="hidden text-xs text-muted-foreground sm:block">
            Auto-check files a request when stock falls to the threshold.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select
            value={statusFilter}
            onValueChange={(v) => { setStatusFilter(v); setCursor(null); setHistory([null]); }}
          >
            <SelectTrigger aria-label="Filter by status" className="h-10 w-36 md:h-9">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
              <SelectItem value="approved">Approved</SelectItem>
              <SelectItem value="ordered">Ordered</SelectItem>
              <SelectItem value="received">Received</SelectItem>
              <SelectItem value="completed">Completed</SelectItem>
              <SelectItem value="cancelled">Cancelled</SelectItem>
            </SelectContent>
          </Select>
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

      <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">#</TableHead>
              <TableHead className="px-3">Item</TableHead>
              <TableHead className="px-3">Qty to order</TableHead>
              <TableHead className="px-3">Threshold</TableHead>
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
                  {debouncedQ !== ''
                    ? `No reorder requests match "${debouncedQ}".`
                    : statusFilter === 'all'
                      ? 'No reorder requests.'
                      : `No ${statusFilter} requests.`}
                </TableCell>
              </TableRow>
            )}
            {list.isError && !list.isLoading && (
              <QueryErrorRow colSpan={8} message="Failed to load reorder requests." onRetry={() => void list.refetch()} pending={list.isFetching} />
            )}
            {rows.map((r) => (
              <TableRow key={r.id}>
                <TableCell className="px-3 font-mono text-xs">
                  {r.id}
                  {r.auto_triggered && <Badge variant="outline" className="ml-1.5">auto</Badge>}
                </TableCell>
                <TableCell className="px-3">
                  {r.item_name ?? `#${r.medicine_id ?? r.supply_item_id ?? '?'}`}
                  {r.item_type === 'supply' && <Badge variant="outline" className="ml-1.5">supply</Badge>}
                </TableCell>
                <TableCell className="px-3 font-mono text-xs">{r.requested_quantity} {r.unit ?? ''}</TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">
                  {r.reorder_level}
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
                    {reorderActions(r)}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

      {/* Mobile: reorder cards from the same rows. */}
      {list.isLoading && (
        <p className="py-6 text-center text-sm text-muted-foreground md:hidden" role="status">
          <Loader2 className="mx-auto size-4 animate-spin" />
        </p>
      )}
      {list.isError && !list.isLoading && (
        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-center text-sm text-destructive md:hidden">
          <p>Failed to load reorder requests.</p>
          <Button variant="outline" size="sm" className="mt-2" onClick={() => void list.refetch()} disabled={list.isFetching}>Retry</Button>
        </div>
      )}
      {!list.isLoading && !list.isError && rows.length === 0 && (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground md:hidden">
          {debouncedQ !== '' ? `No reorder requests match "${debouncedQ}".` : statusFilter === 'all' ? 'No reorder requests.' : `No ${statusFilter} requests.`}
        </p>
      )}
      <MobileCardList>
        {rows.map((r) => (
          <MobileCard key={r.id} aria-label={`Reorder ${r.id}`}>
            <div className="mb-1 flex items-center justify-between gap-2">
              <span className="text-sm font-medium text-foreground">
                {r.item_name ?? `#${r.medicine_id ?? r.supply_item_id ?? '?'}`}
              </span>
              <Badge variant={REORDER_STATUS_VARIANT[r.status]}>{r.status}</Badge>
            </div>
            <div className="mb-1 flex flex-wrap gap-1.5">
              {r.auto_triggered && <Badge variant="outline">auto</Badge>}
              {r.item_type === 'supply' && <Badge variant="outline">supply</Badge>}
              <Badge variant={URGENCY_VARIANT[r.urgency]}>{r.urgency}</Badge>
            </div>
            <MobileCardField label="Qty to order"><span className="font-mono text-xs">{r.requested_quantity} {r.unit ?? ''}</span></MobileCardField>
            <MobileCardField label="Threshold"><span className="font-mono text-xs text-muted-foreground">{r.reorder_level}</span></MobileCardField>
            <MobileCardField label="Dates">
              <span className="text-xs text-muted-foreground">
                {r.order_date !== null && <>ordered {r.order_date}<br /></>}
                {r.expected_delivery_date !== null && <>eta {r.expected_delivery_date}<br /></>}
                {r.actual_delivery_date !== null && <>delivered {r.actual_delivery_date}</>}
                {r.order_date === null && r.actual_delivery_date === null && '—'}
              </span>
            </MobileCardField>
            <MobileCardActions>{reorderActions(r)}</MobileCardActions>
          </MobileCard>
        ))}
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
            disabled={list.data?.next === null || list.data?.next === undefined}
          >
            Next <ChevronRight />
          </Button>
        </div>
      </nav>

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        pending={transition.isPending}
        onConfirm={() => {
          confirm?.run();
          setConfirm(null);
        }}
        onCancel={() => setConfirm(null)}
      />

      {orderingId !== null && (
        <Dialog open onOpenChange={(o) => !o && setOrderingId(null)}>
          <OrderReorderDialog reorderId={orderingId} onClose={() => setOrderingId(null)} />
        </Dialog>
      )}
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
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
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

/**
 * MoveStockDialog — free-form ledger correction (Adjust). Receipts are
 * NOT available here any more: delivered stock enters via the gated
 * Receive flow so every receipt traces back to a reorder request.
 */
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
    defaultValues: { reason_code: 'adjustment' },
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
        <DialogTitle>Adjust stock — {item.sku}</DialogTitle>
      </DialogHeader>
      <form onSubmit={(e) => void onSubmit(e)} className="space-y-3" noValidate>
        <p className="text-xs text-muted-foreground">
          On hand: <span className="font-mono">{item.quantity_on_hand} {item.unit}</span>.
          Dispenses are negative; adjustments may go either way. Deliveries are
          entered with the Receive button (procurement workflow).
        </p>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
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

/**
 * ReceiveSupplyDialog — supply-side twin of the medicine batch
 * receive: quantity is detected from the item's `received` reorder
 * request; receiving is blocked until a delivery has been marked
 * received on the Reorders tab (backend enforces the same 409 gate).
 */
function ReceiveSupplyDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const receive = useReceiveSupply();
  const receivable = useReceivableReorder('supply', item.id);
  const [note, setNote] = useState('');

  const order = receivable.data ?? null;

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Receive — {item.name}</DialogTitle>
      </DialogHeader>

      {receivable.isLoading && <Loader2 className="mx-auto size-5 animate-spin text-muted-foreground" />}

      {!receivable.isLoading && order === null && (
        <p role="alert" className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
          No received delivery for this item. Order it on the Reorders tab and mark the
          request as <span className="font-medium">received</span> when the delivery
          arrives — then the stock can be entered here.
        </p>
      )}

      {order !== null && (
        <div className="space-y-3">
          <p className="rounded-md bg-muted/50 p-2 text-xs text-muted-foreground">
            Receiving reorder <span className="font-mono">#{order.id}</span> —{' '}
            <span className="font-medium text-foreground">{order.requested_quantity} {item.unit}</span>{' '}
            (quantity is taken from the order and cannot be edited).
          </p>
          <div className="space-y-1.5">
            <Label htmlFor="receive_supply_note">Note (optional)</Label>
            <Input id="receive_supply_note" value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
          </div>
        </div>
      )}

      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button
          disabled={order === null || receive.isPending}
          onClick={() => receive.mutate({ itemId: item.id, note }, { onSuccess: onClose })}
        >
          {receive.isPending && <Loader2 className="animate-spin" />}
          <PackagePlus /> Receive
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

/**
 * DispenseSupplyDialog — supply-side twin of the medicine dispense:
 * a positive quantity is turned into a negative `dispense` movement
 * on the ledger.
 */
function DispenseSupplyDialog({ item, onClose }: { item: InventoryItem; onClose: () => void }) {
  const move = useMoveStock();
  const [qty, setQty] = useState('');
  const [note, setNote] = useState('');

  const parsed = Number(qty);
  const valid = Number.isInteger(parsed) && parsed >= 1 && parsed <= item.quantity_on_hand;

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Dispense — {item.name}</DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <p className="text-xs text-muted-foreground">
          On hand: <span className="font-mono">{item.quantity_on_hand} {item.unit}</span>.
        </p>
        <div className="space-y-1.5">
          <Label htmlFor="dispense_supply_qty">Quantity</Label>
          <Input
            id="dispense_supply_qty"
            type="number"
            min={1}
            max={item.quantity_on_hand}
            value={qty}
            aria-invalid={qty !== '' && !valid}
            onChange={(e) => setQty(e.target.value)}
          />
          {qty !== '' && !valid && (
            <p role="alert" className="text-xs text-destructive">Enter 1–{item.quantity_on_hand}.</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="dispense_supply_note">Note (optional)</Label>
          <Input id="dispense_supply_note" value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button
          disabled={!valid || move.isPending}
          onClick={() => move.mutate(
            { itemId: item.id, input: { qty_delta: -parsed, reason_code: 'dispense', ...(note !== '' ? { note } : {}) } },
            { onSuccess: onClose },
          )}
        >
          {move.isPending && <Loader2 className="animate-spin" />}
          <Syringe /> Dispense
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

function SuppliesTab() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [openCreate, setOpenCreate] = useState(false);
  const [moveItem, setMoveItem] = useState<InventoryItem | null>(null);
  const [receiveItem, setReceiveItem] = useState<InventoryItem | null>(null);
  const [ledgerItem, setLedgerItem] = useState<InventoryItem | null>(null);
  const [dispenseItem, setDispenseItem] = useState<InventoryItem | null>(null);
  const [editItem, setEditItem] = useState<InventoryItem | null>(null);
  const [archiveItem, setArchiveItem] = useState<InventoryItem | null>(null);
  const [showArchived, setShowArchived] = useState(false);
  const [q, setQ] = useState('');
  const debouncedQ = useDebouncedValue(q, 300);
  const list = useInventoryItems(cursor, 25, debouncedQ === '' ? null : debouncedQ, showArchived);
  const archive = useArchiveItem();
  const unarchive = useUnarchiveItem();

  // Reset to the first page whenever the debounced search changes.
  const lastQueryRef = useRef<string>('');
  useEffect(() => {
    if (debouncedQ !== lastQueryRef.current) {
      lastQueryRef.current = debouncedQ;
      setCursor(null);
      setHistory([null]);
    }
  }, [debouncedQ]);

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

  // Actions shared by the desktop row and the mobile card.
  const supplyActions = (it: (typeof rows)[number]) =>
    it.archived ? (
      <Button size="sm" variant="outline" disabled={unarchive.isPending} onClick={() => unarchive.mutate(it.id)}>
        <ArchiveRestore /> Restore
      </Button>
    ) : (
      <>
        <Button size="sm" variant="secondary" onClick={() => setReceiveItem(it)}>
          <PackagePlus /> Receive
        </Button>
        <Button size="sm" variant="outline" disabled={it.quantity_on_hand === 0} onClick={() => setDispenseItem(it)}>
          <Syringe /> Dispense
        </Button>
        <Button size="sm" variant="outline" onClick={() => setMoveItem(it)}>
          <ArrowDownUp /> Adjust
        </Button>
        <Button size="sm" variant="outline" onClick={() => setLedgerItem(it)}>
          <ScrollText /> Ledger
        </Button>
        <Button size="sm" variant="outline" onClick={() => setEditItem(it)}>
          <Pencil /> Edit
        </Button>
        <Button size="sm" variant="outline" className="text-muted-foreground" onClick={() => setArchiveItem(it)}>
          <Archive /> Archive
        </Button>
      </>
    );

  return (
    <div className="space-y-4">
      <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3">
        <SearchBox
          value={q}
          onValueChange={setQ}
          placeholder="Search by SKU or name…"
          inputId="supplies-search"
        />
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant={showArchived ? 'secondary' : 'outline'}
            aria-pressed={showArchived}
            onClick={() => { setShowArchived((v) => !v); setCursor(null); setHistory([null]); }}
          >
            <Archive /> {showArchived ? 'Hide archived' : 'Show archived'}
          </Button>
          <Dialog open={openCreate} onOpenChange={setOpenCreate}>
            <Button onClick={() => setOpenCreate(true)}>
              <Plus /> New item
            </Button>
            {openCreate && <CreateItemDialog onClose={() => setOpenCreate(false)} />}
          </Dialog>
        </div>
      </section>

      <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
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
                  {debouncedQ !== '' ? `No items match "${debouncedQ}".` : 'No items.'}
                </TableCell>
              </TableRow>
            )}
            {list.isError && !list.isLoading && (
              <QueryErrorRow colSpan={6} message="Failed to load supplies." onRetry={() => void list.refetch()} pending={list.isFetching} />
            )}
            {rows.map((it) => (
              <TableRow key={it.id}>
                <TableCell className="px-3 font-mono text-xs">{it.sku}</TableCell>
                <TableCell className="px-3">{it.name}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{it.quantity_on_hand} {it.unit}</TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">{it.reorder_level}</TableCell>
                <TableCell className="px-3">
                  {it.archived
                    ? <Badge variant="secondary">Archived</Badge>
                    : it.low_stock ? <Badge variant="warning">Low</Badge> : <Badge variant="success">OK</Badge>}
                </TableCell>
                <TableCell className="px-3 text-right">
                  <div className="flex flex-wrap justify-end gap-1">
                    {supplyActions(it)}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

      {/* Mobile: supply cards from the same rows. */}
      {list.isLoading && (
        <p className="py-6 text-center text-sm text-muted-foreground md:hidden" role="status">
          <Loader2 className="mx-auto size-4 animate-spin" />
        </p>
      )}
      {list.isError && !list.isLoading && (
        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-center text-sm text-destructive md:hidden">
          <p>Failed to load supplies.</p>
          <Button variant="outline" size="sm" className="mt-2" onClick={() => void list.refetch()} disabled={list.isFetching}>Retry</Button>
        </div>
      )}
      {!list.isLoading && !list.isError && rows.length === 0 && (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground md:hidden">
          {debouncedQ !== '' ? `No items match "${debouncedQ}".` : 'No items.'}
        </p>
      )}
      <MobileCardList>
        {rows.map((it) => (
          <MobileCard key={it.id} aria-label={`Supply ${it.name}`}>
            <div className="mb-1 flex items-center justify-between gap-2">
              <span className="text-sm font-medium text-foreground">{it.name}</span>
              {it.archived
                ? <Badge variant="secondary">Archived</Badge>
                : it.low_stock ? <Badge variant="warning">Low</Badge> : <Badge variant="success">OK</Badge>}
            </div>
            <MobileCardField label="SKU"><span className="font-mono text-xs">{it.sku}</span></MobileCardField>
            <MobileCardField label="On hand"><span className="font-mono text-xs">{it.quantity_on_hand} {it.unit}</span></MobileCardField>
            <MobileCardField label="Reorder level"><span className="font-mono text-xs text-muted-foreground">{it.reorder_level}</span></MobileCardField>
            <MobileCardActions>{supplyActions(it)}</MobileCardActions>
          </MobileCard>
        ))}
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
      {receiveItem !== null && (
        <Dialog open onOpenChange={(o) => !o && setReceiveItem(null)}>
          <ReceiveSupplyDialog item={receiveItem} onClose={() => setReceiveItem(null)} />
        </Dialog>
      )}
      {ledgerItem !== null && (
        <Dialog open onOpenChange={(o) => !o && setLedgerItem(null)}>
          <SupplyLedgerDialog item={ledgerItem} onClose={() => setLedgerItem(null)} />
        </Dialog>
      )}
      {dispenseItem !== null && (
        <Dialog open onOpenChange={(o) => !o && setDispenseItem(null)}>
          <DispenseSupplyDialog item={dispenseItem} onClose={() => setDispenseItem(null)} />
        </Dialog>
      )}
      {editItem !== null && (
        <Dialog open onOpenChange={(o) => !o && setEditItem(null)}>
          <EditItemDialog item={editItem} onClose={() => setEditItem(null)} />
        </Dialog>
      )}
      <ConfirmDialog
        open={archiveItem !== null}
        title={archiveItem !== null ? `Archive ${archiveItem.sku}?` : ''}
        description="The item will be hidden from the supplies list. Every movement in the ledger is kept for the audit trail. You can re-create the item later with the same SKU."
        confirmLabel="Archive"
        pending={archive.isPending}
        onConfirm={() => {
          if (archiveItem !== null) {
            archive.mutate(archiveItem.id, { onSuccess: () => setArchiveItem(null) });
          }
        }}
        onCancel={() => setArchiveItem(null)}
      />
    </div>
  );
}

// ----------------------------------------------------------------- page

export default function InventoryPage() {
  const [tab, setTab] = useTabParam('medicines');
  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header>
        <h1 className="text-xl font-semibold text-foreground">Inventory</h1>
        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
          <CalendarClock className="size-3.5" aria-hidden />
          Medicines are batch-tracked with expiry (FEFO dispensing); supplies use the signed movement ledger.
        </p>
      </header>

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="medicines">Medicines</TabsTrigger>
          <TabsTrigger value="supplies">Supplies</TabsTrigger>
          <TabsTrigger value="reorders">Reorders</TabsTrigger>
        </TabsList>
        <TabsContent value="medicines">
          <MedicinesTab />
        </TabsContent>
        <TabsContent value="supplies">
          <SuppliesTab />
        </TabsContent>
        <TabsContent value="reorders">
          <ReordersTab />
        </TabsContent>
      </Tabs>
    </main>
  );
}
