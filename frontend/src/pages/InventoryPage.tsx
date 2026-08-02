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
  BarChart3,
  CalendarClock,
  Check,
  ChevronDown,
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
  Syringe,
  TrendingDown,
  TrendingUp,
  Truck,
  X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ComboboxField } from '@/components/ComboboxField';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { QueryErrorRow } from '@/components/QueryErrorState';
import { MobileCardList, MobileCard, MobileCardField, MobileCardActions } from '@/components/MobileCardList';
import { DatePicker } from '@/components/ui/date-picker';
import { SearchBox, highlightMatch } from '@/components/ui/SearchBox';
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
import { Textarea } from '@/components/ui/textarea';
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
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { apiClient } from '@/api/client';
import { cn } from '@/lib/utils';
import { useTabParam } from '@/hooks/useTabParam';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useTableRowKeyboardNav } from '@/hooks/useTableRowKeyboardNav';
import { useEncounters } from '@/hooks/useClinic';
import { useArchiveItem, useCreateItem, useInventoryItems, useInventoryMovements, useMoveStock, useReceiveSupply, useUnarchiveItem, useUpdateItem } from '@/hooks/useInventory';
import {
  useAddBatch,
  useArchiveMedicine,
  useComputeForecast,
  useCreateMedicine,
  useDispense,
  useExpiringMedicines,
  useLowStockMedicines,
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
  medicineSchema,
  updateMedicineSchema,
  type AddBatchInput,
  type CreateMedicineInput,
  type DispenseInput,
  type Medicine,
  type MedicineLastMovement,
  type UpdateMedicineInput,
} from '@/schemas/medicines';
import {
  createReorderSchema,
  type CreateReorderInput,
  type Reorder,
} from '@/schemas/reorders';
import { fmtUtcToApp } from '@/utils/date';
import {
  MEDICINE_CATEGORIES,
  MEDICINE_DOSAGE_FORMS,
  MEDICINE_STRENGTHS,
  MEDICINE_STRENGTH_PATTERN,
  MEDICINE_UNITS,
  INVENTORY_UNITS,
  type TaxonomyEntry,
} from '@/data/taxonomy';

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
 * StockBadge — colored status chip for the catalog rows. Used by both
 * medicines and supplies. Renders one of four states:
 *
 *   - Archived  (secondary)            — soft-deleted, out of the live list
 *   - Out       (destructive, red)     — on_hand === 0
 *   - Low N/T   (warning, yellow)      — on_hand <= threshold, with ratio
 *   - OK        (success, green)       — comfortably above threshold
 *
 * Showing the ratio (`3/10`) inside the chip is the key UX bit — the
 * operator doesn't need to count digits in the on-hand column to see
 * how close they are to the reorder line. `threshold` accepts either
 * `reorder_threshold` (medicines) or `reorder_level` (supplies).
 */
function StockBadge({
  onHand,
  threshold,
  lowStock,
  archived,
}: {
  onHand: number;
  threshold: number;
  lowStock: boolean;
  archived: boolean;
}): JSX.Element {
  if (archived) return <Badge variant="secondary">Archived</Badge>;
  if (onHand === 0) return <Badge variant="destructive">Out</Badge>;
  if (lowStock) return <Badge variant="warning">Low {onHand}/{threshold}</Badge>;
  return <Badge variant="success">OK</Badge>;
}

/**
 * LastMovementHint — one-line mini-strip showing the most recent
 * transaction on a medicine. Rendered under the medicine name in the
 * catalog row so the clerk sees at a glance who last touched the stock
 * and when. Composer for "gap 13" — server joins `users.email`; we
 * format it as initials on display.
 *
 * Returns `null` when there are no movements yet (just-created row),
 * which collapses the row's vertical footprint naturally.
 */
function LastMovementHint({
  movement,
  unit,
}: {
  movement: MedicineLastMovement | null;
  unit: string;
}): JSX.Element | null {
  if (movement === null) return null;
  const sign     = movement.type === 'dispensed' ? '−' : '+';
  const tone     = movement.type === 'dispensed' ? 'text-rose-600 dark:text-rose-400'
              : movement.type === 'received'  ? 'text-emerald-600 dark:text-emerald-400'
              : 'text-muted-foreground';
  const agoText  = fmtRelativeFromNow(movement.created_at);
  const userText = movement.user_email !== null && movement.user_email !== ''
    ? `by ${initialsFromEmail(movement.user_email)}`
    : '';
  return (
    <p className={cn('mt-0.5 text-[11px] font-mono', tone)}>
      {movement.type === 'received' ? '↑ ' : movement.type === 'dispensed' ? '↓ ' : '· '}
      {sign}{movement.quantity} {unit}
      {userText !== '' && <> · {userText}</>}
      {agoText !== '' && <> · {agoText}</>}
    </p>
  );
}

/**
 * Small date helpers — kept inline to avoid pulling dayjs/luxon for
 * this one display. Returns strings like "2d ago", "3h ago", "just now".
 */
function fmtRelativeFromNow(iso: string): string {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return '';
  const diffMs = Date.now() - then;
  const diffSec = Math.round(diffMs / 1000);
  if (diffSec < 60) return 'just now';
  const diffMin = Math.round(diffSec / 60);
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHr = Math.round(diffMin / 60);
  if (diffHr < 24) return `${diffHr}h ago`;
  const diffDay = Math.round(diffHr / 24);
  if (diffDay < 7) return `${diffDay}d ago`;
  const diffWk = Math.round(diffDay / 7);
  if (diffWk < 5) return `${diffWk}w ago`;
  // Beyond a month — show the local date the way the rest of the app
  // formats it (UTC + Asia/Manila default).
  try { return fmtUtcToApp(iso); } catch { return ''; }
}

function initialsFromEmail(email: string): string {
  const local = email.split('@')[0] ?? email;
  const parts = local.split(/[._-]/);
  if (parts.length >= 2 && parts[0] !== undefined && parts[1] !== undefined) {
    return ((parts[0][0] ?? '') + (parts[1][0] ?? '')).toUpperCase();
  }
  return local.slice(0, 2).toUpperCase();
}

/**
 * ExpiryChip — days-to-expiry badge next to an `earliest_expiry` date.
 * Same shape used by the medicine row + the per-batch list inside the
 * Batches dialog. Shows `expired` for non-positive days instead of
 * `-3d`, which was confusing in the previous build.
 */
function ExpiryChip({ days }: { days: number | null }): JSX.Element | null {
  if (days === null) return null;
  if (days > 30) return null;
  if (days <= 0) return <Badge variant="destructive" className="ml-1.5">expired</Badge>;
  if (days <= 7) return <Badge variant="destructive" className="ml-1.5">{days}d</Badge>;
  return <Badge variant="warning" className="ml-1.5">{days}d</Badge>;
}

/**
 * EtaBadge — countdown chip for in-flight reorders. The original
 * table just showed the raw `eta 2026-02-15` string, which forces the
 * operator to do the day-math in their head. This turns the date
 * into "arrives tomorrow" / "in 3d" / "overdue 2d" so a row with a
 * slipping ETA pops immediately.
 *
 * Returns null for terminal statuses (`completed`, `cancelled`) or
 * when no ETA has been set on the reorder yet — those rows just show
 * the raw date text.
 */
function EtaBadge({ status, expected }: { status: Reorder['status']; expected: string | null }): JSX.Element | null {
  if (expected === null) return null;
  if (status === 'completed' || status === 'cancelled') return null;
  const days = daysUntil(expected);
  if (days < 0) return <Badge variant="destructive" className="ml-1.5">overdue {Math.abs(days)}d</Badge>;
  if (days === 0) return <Badge variant="destructive" className="ml-1.5">arrives today</Badge>;
  if (days === 1) return <Badge variant="warning" className="ml-1.5">arrives tomorrow</Badge>;
  if (days <= 7) return <Badge variant="warning" className="ml-1.5">in {days}d</Badge>;
  return <Badge variant="info" className="ml-1.5">in {days}d</Badge>;
}

// ------------------------------------------------------------ medicines

function CreateMedicineDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateMedicine();
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<CreateMedicineInput>({
      resolver: zodResolver(createMedicineSchema),
      defaultValues: { unit: 'pc', reorder_threshold: 10 },
    });

  const genericName = watch('generic_name') ?? '';
  const category = watch('category') ?? '';
  const dosageForm = watch('dosage_form') ?? '';
  const dosageStrength = watch('dosage_strength') ?? '';
  const unit = watch('unit') ?? 'pc';

  // Gap 2: live-search the catalogue as the operator types the
  // generic name, so duplicate Paracetamol / Paracetemol / etc. are
  // flagged before they hit POST. Backend already supports ?q= on
  // /clinic/medicines — this just wires the existing search to the
  // autocomplete. AbortController cancels in-flight requests when a
  // newer keystroke supersedes them.
  const fetchMedicineOptions = useCallback(
    async (q: string, signal: AbortSignal): Promise<ReadonlyArray<TaxonomyEntry>> => {
      if (q.trim().length < 2) return [];
      const params = new URLSearchParams();
      params.set('q', q.trim());
      params.set('limit', '10');
      const res = await apiClient.get<{ data: unknown[] }>(
        `/clinic/medicines?${params.toString()}`,
        { signal },
      );
      const medicines = z.array(medicineSchema).parse(res.data);
      return medicines.map((m): TaxonomyEntry => ({
        value: m.generic_name,
        label: m.generic_name,
        hint: [
          m.dosage_strength,
          m.dosage_form,
          m.brand_name !== null && m.brand_name !== '' ? `(${m.brand_name})` : null,
          `${m.quantity_on_hand} ${m.unit} on hand`,
        ]
          .filter((s): s is string => s !== null && s !== '')
          .join(' · '),
      }));
    },
    [],
  );

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
          <ComboboxField
            id="generic_name"
            sourceKey="clinic.medicines.generic_name"
            options={[]}
            value={genericName}
            onChange={(v) => setValue('generic_name', v, { shouldValidate: true, shouldDirty: true })}
            placeholder="Type at least 2 letters…"
            allowCreate
            fetchOptions={fetchMedicineOptions}
            loadingLabel="Searching catalog…"
            emptyHintLabel="Type to search the existing catalog — matches will appear here."
          />
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
          <ComboboxField
            id="category"
            sourceKey="clinic.medicines.category"
            options={MEDICINE_CATEGORIES}
            value={category}
            onChange={(v) => setValue('category', v, { shouldValidate: true, shouldDirty: true })}
            placeholder="analgesic, antibiotic, vitamin …"
            allowCreate
          />
          {errors.category !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.category.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="dosage_form">Form</Label>
          <ComboboxField
            id="dosage_form"
            sourceKey="clinic.medicines.dosage_form"
            options={MEDICINE_DOSAGE_FORMS}
            value={dosageForm}
            onChange={(v) => setValue('dosage_form', v, { shouldValidate: true, shouldDirty: true })}
            placeholder="tablet, capsule, syrup …"
            allowCreate
          />
          {errors.dosage_form !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.dosage_form.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="dosage_strength">Strength</Label>
          <ComboboxField
            id="dosage_strength"
            sourceKey="clinic.medicines.dosage_strength"
            options={MEDICINE_STRENGTHS}
            value={dosageStrength}
            onChange={(v) => setValue('dosage_strength', v, { shouldValidate: true, shouldDirty: true })}
            placeholder="500mg, 5mg/mL, 0.5%, 100units/mL…"
            allowCreate
            pattern={MEDICINE_STRENGTH_PATTERN}
            patternTitle="Use a recognized FDA shape, e.g. 500mg, 5mg/mL, 100mg/5mL, 0.5%, or 100units/mL."
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="med_unit">Unit</Label>
          <ComboboxField
            id="med_unit"
            sourceKey="clinic.medicines.unit"
            options={MEDICINE_UNITS}
            value={unit}
            onChange={(v) => setValue('unit', v, { shouldValidate: true, shouldDirty: true })}
            placeholder="tab, mL, vial …"
            allowCreate
          />
          {errors.unit !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.unit.message}</p>
          )}
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
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="description">Notes / indications</Label>
          <Textarea
            id="description"
            rows={3}
            maxLength={2000}
            placeholder="Common uses, storage instructions, supply notes…"
            aria-invalid={errors.description !== undefined}
            {...register('description')}
          />
          {errors.description !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.description.message}</p>
          )}
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
 * AddBatchDialog — receive a delivered lot. The quantity defaults to
 * the medicine's `received` reorder request (procurement loop), but
 * the operator can lower it for partial deliveries (Gap 8) and add a
 * shortage reason that lands in the ledger. When no delivery has
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

  // Prefill the quantity field once the receivable order is known, so
  // the operator sees the ordered amount by default — and the shortage
  // block (below) only appears when they intentionally lower it.
  useEffect(() => {
    if (order !== null && watch('quantity') === undefined) {
      setValue('quantity', order.requested_quantity, { shouldDirty: false });
    }
  }, [order, setValue, watch]);

  const quantity = watch('quantity');

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
            ordered. Lower the quantity below for a partial delivery and explain the shortfall.
          </p>
          <div className="space-y-1.5">
            <Label htmlFor="batch_number">Batch / lot number</Label>
            <Input id="batch_number" aria-invalid={errors.batch_number !== undefined} {...register('batch_number')} />
            {errors.batch_number !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.batch_number.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="batch_quantity">Quantity received</Label>
            <Input
              id="batch_quantity"
              type="number"
              min={1}
              max={order.requested_quantity}
              aria-invalid={errors.quantity !== undefined}
              {...register('quantity', { valueAsNumber: true })}
            />
            {errors.quantity !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.quantity.message}</p>
            )}
          </div>
          {/* Shortage capture — only shown when the operator lowered the
              quantity below what was ordered. The note lands in the
              transaction ledger so the discrepancy is auditable. */}
          {(quantity ?? order.requested_quantity) < order.requested_quantity && (
            <div className="col-span-2 space-y-1.5">
              <Label htmlFor="shortage_note">Shortage reason</Label>
              <Textarea
                id="shortage_note"
                rows={2}
                maxLength={255}
                placeholder="e.g. supplier back-ordered 30, expected next week."
                aria-invalid={errors.shortage_note !== undefined}
                {...register('shortage_note')}
              />
              {errors.shortage_note !== undefined && (
                <p role="alert" className="text-xs text-destructive">{errors.shortage_note.message}</p>
              )}
              <p className="text-xs text-muted-foreground">
                Short by {order.requested_quantity - (quantity ?? order.requested_quantity)} {medicine.unit}.
                The reorder will stay open so you can chase the supplier or raise a follow-up.
              </p>
            </div>
          )}
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
          <Tooltip>
            <TooltipTrigger asChild>
              {/* Span wrapper so the tooltip fires on the disabled submit. */}
              <span className="inline-flex">
                <Button
                  type="submit"
                  disabled={dispense.isPending || openEncounters.length === 0 || medicine.quantity_on_hand === 0}
                >
                  {dispense.isPending && <Loader2 className="animate-spin" />}
                  <Syringe /> Dispense
                </Button>
              </span>
            </TooltipTrigger>
            {openEncounters.length === 0 ? (
              <TooltipContent>No open encounters — open one in Clinic first</TooltipContent>
            ) : medicine.quantity_on_hand === 0 ? (
              <TooltipContent>No stock — receive a batch first</TooltipContent>
            ) : null}
          </Tooltip>
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
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<UpdateItemInput>({
      resolver: zodResolver(updateItemSchema),
      defaultValues: {
        name: item.name,
        unit: item.unit,
        reorder_level: item.reorder_level,
      },
    });

  const unit = watch('unit') ?? item.unit;

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
            <ComboboxField
              id="edit_item_unit"
              sourceKey="clinic.inventory_items.unit"
              options={INVENTORY_UNITS}
              value={unit}
              onChange={(v) => setValue('unit', v, { shouldValidate: true, shouldDirty: true })}
              placeholder="pc, box, mL …"
              allowCreate
            />
            {errors.unit !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.unit.message}</p>
            )}
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

  // Gap 12 — ↑/↓/Home/End keyboard navigation between medicine rows.
  // The hook owns the active index; we spread the per-row props onto
  // each <tr> below.
  const medRowNav = useTableRowKeyboardNav(rows.length);

  // Action rail shared by the desktop row and the mobile card so the
  // two surfaces never drift. `size="sm"` buttons are 40px tall on
  // mobile (touch) and wrap inside the card footer.
  /**
   * medicineActions — one `Actions ▾` dropdown per row. Six inline
   * buttons don't fit comfortably on a 7-col table or a phone card;
   * the dropdown keeps the row tidy and groups the destructive
   * (Archive) under a separator from the read/write actions.
   *
   * Disabled-state hints are surfaced via Radix Tooltip on the item
   * itself: when there's no stock, hovering Dispense shows the reason
   * so the operator knows the next step is to receive a batch.
   */
  const medicineActions = (m: Medicine) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button size="sm" variant="outline" aria-label={`Actions for ${m.generic_name}`}>
          Actions <ChevronDown className="size-3.5" aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-44">
        {m.archived ? (
          <>
            <DropdownMenuItem onSelect={() => setBatchesFor(m.id)}>
              <Layers /> Batches
            </DropdownMenuItem>
            <DropdownMenuItem
              disabled={unarchive.isPending}
              onSelect={() => unarchive.mutate(m.id)}
            >
              <ArchiveRestore /> {unarchive.isPending ? 'Restoring…' : 'Restore'}
            </DropdownMenuItem>
          </>
        ) : (
          <>
            <DropdownMenuItem onSelect={() => setReceiveFor(m)}>
              <PackagePlus /> Receive
            </DropdownMenuItem>
            {/* Wrap the disabled Dispense in a Tooltip so hovering it
                surfaces the reason. Radix disables pointer-events on
                disabled menu items, so the TooltipTrigger wraps a
                full-width span that still catches the hover. */}
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="block w-full">
                  <DropdownMenuItem
                    disabled={m.quantity_on_hand === 0}
                    onSelect={() => setDispenseFor(m)}
                  >
                    <Syringe /> Dispense
                  </DropdownMenuItem>
                </span>
              </TooltipTrigger>
              {m.quantity_on_hand === 0 && (
                <TooltipContent side="left">
                  No stock — receive a batch first
                </TooltipContent>
              )}
            </Tooltip>
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={() => setBatchesFor(m.id)}>
              <Layers /> Batches
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => setLedgerFor(m)}>
              <ScrollText /> Ledger
            </DropdownMenuItem>
            <DropdownMenuItem
              disabled={forecast.isPending}
              onSelect={() => forecast.mutate(m.id)}
            >
              <TrendingUp /> {forecast.isPending ? 'Computing…' : 'Forecast'}
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={() => setEditFor(m)}>
              <Pencil /> Edit
            </DropdownMenuItem>
            <DropdownMenuItem
              className="text-muted-foreground"
              onSelect={() => setArchiveFor(m)}
            >
              <Archive /> Archive
            </DropdownMenuItem>
          </>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <div className="space-y-4">
      <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3">
        <SearchBox
          value={q}
          onValueChange={setQ}
          placeholder="Search by name, brand, or category…"
          inputId="medicines-search"
          ariaLabel="Search medicines by name, brand, or category"
          isFetching={list.isFetching && list.data !== undefined}
          className="w-full sm:w-64"
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
            {rows.map((m, idx) => {
              const days = m.earliest_expiry !== null ? daysUntil(m.earliest_expiry) : null;
              return (
                <TableRow key={m.id} {...medRowNav.getRowProps(idx)}>
                  <TableCell className="px-3">
                    {/* Native title tooltip surfaces the notes on hover — no
                        extra row, no popover; description is only shown when
                        actually populated. */}
                    <span
                      className="font-medium"
                      title={m.description !== null && m.description !== '' ? m.description : undefined}
                    >
                      {highlightMatch(m.generic_name, debouncedQ)}
                    </span>
                    <span className="ml-1 text-xs text-muted-foreground">
                      {m.brand_name !== null && m.brand_name !== '' ? (
                        <>
                          {highlightMatch(m.brand_name, debouncedQ)}
                          {m.dosage_strength !== null && m.dosage_strength !== '' && ` · ${m.dosage_strength}`}
                        </>
                      ) : (
                        m.dosage_strength
                      )}
                    </span>
                    {/* Gap 13 mini-strip — one line under the name, dim
                        when there's no movement yet. */}
                    <LastMovementHint movement={m.last_movement} unit={m.unit} />
                  </TableCell>
                  <TableCell className="px-3 text-xs">
                    {m.category === null ? '—' : highlightMatch(m.category, debouncedQ)}
                  </TableCell>
                  <TableCell className="px-3 font-mono text-xs">
                    {m.quantity_on_hand} {m.unit}
                  </TableCell>
                  <TableCell className="px-3 text-xs">
                    {m.earliest_expiry ?? '—'}
                    <ExpiryChip days={days} />
                  </TableCell>
                  <TableCell className="px-3">
                    <StockBadge
                      onHand={m.quantity_on_hand}
                      threshold={m.reorder_threshold}
                      lowStock={m.low_stock}
                      archived={m.archived}
                    />
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
                <span
                  className="text-sm font-medium text-foreground"
                  title={m.description !== null && m.description !== '' ? m.description : undefined}
                >
                  {highlightMatch(m.generic_name, debouncedQ)}
                </span>
                <StockBadge
                  onHand={m.quantity_on_hand}
                  threshold={m.reorder_threshold}
                  lowStock={m.low_stock}
                  archived={m.archived}
                />
              </div>
              {m.brand_name !== null && m.brand_name !== '' ? (
                <p className="text-xs text-muted-foreground">
                  {highlightMatch(m.brand_name, debouncedQ)}
                  {m.dosage_strength !== null && m.dosage_strength !== '' && ` · ${m.dosage_strength}`}
                </p>
              ) : (
                m.dosage_strength !== null && m.dosage_strength !== '' && (
                  <p className="text-xs text-muted-foreground">{m.dosage_strength}</p>
                )
              )}
              <MobileCardField label="Category">
                {m.category === null ? '—' : highlightMatch(m.category, debouncedQ)}
              </MobileCardField>
              <MobileCardField label="On hand"><span className="font-mono text-xs">{m.quantity_on_hand} {m.unit}</span></MobileCardField>
              <MobileCardField label="Earliest expiry">
                <span className="text-xs">
                  {m.earliest_expiry ?? '—'}
                  <ExpiryChip days={days} />
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

  // Gap 4: when an item is picked (medicine OR supply), prefill the
  // quantity with a sensible default so the operator doesn't have to
  // remember the math. Formula: `max(1, 2 × threshold − on_hand)`.
  // For a low-stock item this lands at ≥ `threshold`, so the
  // incoming delivery brings on-hand back up to `2 × threshold`.
  // The operator can still type a different number.
  useEffect(() => {
    if (itemType === 'medicine' && medicineId !== undefined) {
      const m = medicines.data?.data?.find((x) => x.id === medicineId);
      if (m !== undefined) {
        const suggested = Math.max(1, 2 * m.reorder_threshold - m.quantity_on_hand);
        setValue('quantity', suggested, { shouldValidate: true, shouldDirty: true });
      }
      return;
    }
    if (itemType === 'supply' && supplyItemId !== undefined) {
      const it = supplies.data?.data?.find((x) => x.id === supplyItemId);
      if (it !== undefined) {
        const suggested = Math.max(1, 2 * it.reorder_level - it.quantity_on_hand);
        setValue('quantity', suggested, { shouldValidate: true, shouldDirty: true });
      }
    }
  }, [itemType, medicineId, supplyItemId, medicines.data, supplies.data, setValue]);

  // Build the hint caption from the currently-selected item so the
  // operator can see the math at a glance.
  let qtyHint: string | null = null;
  if (itemType === 'medicine' && medicineId !== undefined) {
    const m = medicines.data?.data?.find((x) => x.id === medicineId);
    if (m !== undefined) {
      qtyHint = `On hand ${m.quantity_on_hand} · threshold ${m.reorder_threshold} · suggested ${Math.max(1, 2 * m.reorder_threshold - m.quantity_on_hand)}`;
    }
  } else if (itemType === 'supply' && supplyItemId !== undefined) {
    const it = supplies.data?.data?.find((x) => x.id === supplyItemId);
    if (it !== undefined) {
      qtyHint = `On hand ${it.quantity_on_hand} · reorder level ${it.reorder_level} · suggested ${Math.max(1, 2 * it.reorder_level - it.quantity_on_hand)}`;
    }
  }

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
            {qtyHint !== null ? (
              <p className="text-xs text-muted-foreground">{qtyHint}</p>
            ) : null}
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

  // Gap 12 — same ↑/↓ keyboard navigation as the medicines tab.
  const reorderRowNav = useTableRowKeyboardNav(rows.length);

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
            ariaLabel="Search reorder requests by medicine or note"
            isFetching={list.isFetching && list.data !== undefined}
            className="w-full sm:w-64"
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
            {rows.map((r, idx) => (
              <TableRow key={r.id} {...reorderRowNav.getRowProps(idx)}>
                <TableCell className="px-3 font-mono text-xs">
                  {r.id}
                  {r.auto_triggered && <Badge variant="outline" className="ml-1.5">auto</Badge>}
                </TableCell>
                <TableCell className="px-3">
                  {r.item_name === null
                    ? `#${r.medicine_id ?? r.supply_item_id ?? '?'}`
                    : highlightMatch(r.item_name, debouncedQ)}
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
                  {r.expected_delivery_date !== null && (
                    <>
                      eta {r.expected_delivery_date}
                      <EtaBadge status={r.status} expected={r.expected_delivery_date} />
                    </>
                  )}
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
                {r.item_name === null
                  ? `#${r.medicine_id ?? r.supply_item_id ?? '?'}`
                  : highlightMatch(r.item_name, debouncedQ)}
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
                {r.expected_delivery_date !== null && (
                  <>
                    eta {r.expected_delivery_date}
                    <EtaBadge status={r.status} expected={r.expected_delivery_date} />
                  </>
                )}
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
    setValue,
    watch,
  } = useForm<CreateItemInput>({
    resolver: zodResolver(createItemSchema),
    defaultValues: { unit: 'pc', reorder_level: 0 },
  });

  const unit = watch('unit') ?? 'pc';

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
            <ComboboxField
              id="unit"
              sourceKey="clinic.inventory_items.unit"
              options={INVENTORY_UNITS}
              value={unit}
              onChange={(v) => setValue('unit', v, { shouldValidate: true, shouldDirty: true })}
              placeholder="pc, box, mL …"
              allowCreate
            />
            {errors.unit !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.unit.message}</p>
            )}
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

// ---------------------------------------------------------------- insights

/**
 * InsightsTab — top-down view of the catalogue. Built from existing
 * analytics endpoints (`/medicines/low-stock`, `/medicines/expiring`,
 * `/reorders`) — no new backend surface. The "click a tile to dive
 * into the underlying tab" interaction uses the page-level tab state
 * so the four tabs still share the URL param.
 */
function InsightsTab({ onJumpToTab }: { onJumpToTab: (tab: 'medicines' | 'supplies' | 'reorders') => void }) {
  const lowStock = useLowStockMedicines();
  const expiring = useExpiringMedicines(30);
  // Fetch a small page of reorders just for the "in flight" count — we
  // only need the number, not the rows (the Reorders tab has the full
  // list with ETAs + actions from Gap 9).
  const reorders = useReorders(null, null, 50);

  const lowStockCount  = lowStock.data?.length ?? 0;
  const expiringCount  = expiring.data?.length ?? 0;
  const pendingReorderCount = (reorders.data?.data ?? []).filter((r) =>
    r.status === 'pending' || r.status === 'approved' || r.status === 'ordered',
  ).length;

  // Top-of-list slices — full data lives on the source tab.
  const lowStockTop = (lowStock.data ?? []).slice(0, 5);
  const expiringTop = (expiring.data ?? []).slice(0, 5);

  return (
    <div className="space-y-4">
      {/* Stat tiles — the morning stock-check dashboard. Color shifts
          from neutral → warning → destructive as the count grows. */}
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <StatTile
          icon={<Pill className="size-4" />}
          label="Low stock"
          value={lowStockCount}
          tone={lowStockCount === 0 ? 'success' : lowStockCount <= 3 ? 'warning' : 'destructive'}
          loading={lowStock.isLoading}
          onClick={() => onJumpToTab('medicines')}
        />
        <StatTile
          icon={<CalendarClock className="size-4" />}
          label="Expiring in 30d"
          value={expiringCount}
          tone={expiringCount === 0 ? 'success' : expiringCount <= 3 ? 'warning' : 'destructive'}
          loading={expiring.isLoading}
          onClick={() => onJumpToTab('medicines')}
        />
        <StatTile
          icon={<Truck className="size-4" />}
          label="Reorders in flight"
          value={pendingReorderCount}
          tone={pendingReorderCount === 0 ? 'success' : 'info'}
          loading={reorders.isLoading}
          onClick={() => onJumpToTab('reorders')}
        />
        <StatTile
          icon={<TrendingUp className="size-4" />}
          label="Avg daily use (30d)"
          value="—"
          tone="muted"
          loading={false}
        />
      </div>

      {/* Two side-by-side detail panels: low-stock + expiring. Each
          shows up to 5 rows; "View all" jumps to the full Medicines tab
          where the rest of the work happens. */}
      <div className="grid gap-3 md:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0 pb-3">
            <div>
              <CardTitle className="text-base">Low-stock medicines</CardTitle>
              <CardDescription>Below the reorder threshold</CardDescription>
            </div>
            <Button variant="ghost" size="sm" onClick={() => onJumpToTab('medicines')}>
              View all →
            </Button>
          </CardHeader>
          <CardContent>
            {lowStock.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
            {!lowStock.isLoading && lowStockCount === 0 && (
              <p className="text-sm text-muted-foreground">All medicines are above their reorder line.</p>
            )}
            {!lowStock.isLoading && lowStockCount > 0 && (
              <ul className="divide-y">
                {lowStockTop.map((m) => (
                  <li key={m.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                    <div className="min-w-0 flex-1">
                      <div className="truncate font-medium">{m.generic_name}</div>
                      <div className="truncate text-xs text-muted-foreground">
                        {[m.brand_name, m.dosage_strength].filter(Boolean).join(' · ')}
                      </div>
                    </div>
                    <StockBadge
                      onHand={m.quantity_on_hand}
                      threshold={m.reorder_threshold}
                      lowStock={m.low_stock}
                      archived={m.archived}
                    />
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0 pb-3">
            <div>
              <CardTitle className="text-base">Expiring in next 30 days</CardTitle>
              <CardDescription>Active batches with stock remaining</CardDescription>
            </div>
            <Button variant="ghost" size="sm" onClick={() => onJumpToTab('medicines')}>
              View all →
            </Button>
          </CardHeader>
          <CardContent>
            {expiring.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
            {!expiring.isLoading && expiringCount === 0 && (
              <p className="text-sm text-muted-foreground">Nothing expires in the next 30 days.</p>
            )}
            {!expiring.isLoading && expiringCount > 0 && (
              <ul className="divide-y">
                {expiringTop.map((b) => {
                  const days = daysUntil(b.expiration_date);
                  return (
                    <li key={b.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                      <div className="min-w-0 flex-1">
                        <div className="truncate font-medium">{b.generic_name}</div>
                        <div className="truncate text-xs text-muted-foreground">
                          batch {b.batch_number} · {b.quantity_remaining}/{b.quantity_received} {b.unit}
                        </div>
                      </div>
                      <ExpiryChip days={days} />
                    </li>
                  );
                })}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

/**
 * StatTile — single number + label, used by the Insights tab's top row.
 * The whole card is a button so clicking it jumps to the relevant tab.
 */
function StatTile({
  icon,
  label,
  value,
  tone,
  loading,
  onClick,
}: {
  icon: React.ReactNode;
  label: string;
  value: number | string;
  tone: 'success' | 'warning' | 'destructive' | 'info' | 'muted';
  loading: boolean;
  onClick?: () => void;
}) {
  const toneClass = {
    success:     'text-emerald-700 dark:text-emerald-300',
    warning:     'text-amber-700 dark:text-amber-300',
    destructive: 'text-rose-700 dark:text-rose-300',
    info:        'text-blue-700 dark:text-blue-300',
    muted:       'text-muted-foreground',
  }[tone];
  const Tag = onClick !== undefined ? 'button' : 'div';
  return (
    <Tag
      type={Tag === 'button' ? 'button' : undefined}
      onClick={onClick}
      className={cn(
        'rounded-xl border bg-card p-4 text-left shadow-sm transition-colors',
        onClick !== undefined && 'hover:bg-accent/40 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
      )}
    >
      <div className="mb-1 flex items-center gap-1.5 text-xs text-muted-foreground">
        {icon}
        <span>{label}</span>
      </div>
      <div className={cn('text-2xl font-semibold tabular-nums', toneClass)}>
        {loading ? <Loader2 className="size-5 animate-spin" /> : value}
      </div>
    </Tag>
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
  const [lowStockOnly, setLowStockOnly] = useState(false);
  const [q, setQ] = useState('');
  const debouncedQ = useDebouncedValue(q, 300);
  const list = useInventoryItems(cursor, 25, debouncedQ === '' ? null : debouncedQ, showArchived, lowStockOnly);
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

  // Same reset on the low-stock toggle — flipping the filter chip
  // shouldn't leave the cursor pointing into the previous page set.
  useEffect(() => {
    setCursor(null);
    setHistory([null]);
  }, [lowStockOnly]);

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

  // Gap 12 — keyboard navigation between supply rows.
  const supplyRowNav = useTableRowKeyboardNav(rows.length);

  // Actions shared by the desktop row and the mobile card.
  const supplyActions = (it: (typeof rows)[number]) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button size="sm" variant="outline" aria-label={`Actions for ${it.name}`}>
          Actions <ChevronDown className="size-3.5" aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-44">
        {it.archived ? (
          <DropdownMenuItem
            disabled={unarchive.isPending}
            onSelect={() => unarchive.mutate(it.id)}
          >
            <ArchiveRestore /> {unarchive.isPending ? 'Restoring…' : 'Restore'}
          </DropdownMenuItem>
        ) : (
          <>
            <DropdownMenuItem onSelect={() => setReceiveItem(it)}>
              <PackagePlus /> Receive
            </DropdownMenuItem>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="block w-full">
                  <DropdownMenuItem
                    disabled={it.quantity_on_hand === 0}
                    onSelect={() => setDispenseItem(it)}
                  >
                    <Syringe /> Dispense
                  </DropdownMenuItem>
                </span>
              </TooltipTrigger>
              {it.quantity_on_hand === 0 && (
                <TooltipContent side="left">
                  No stock — receive ordered delivery first
                </TooltipContent>
              )}
            </Tooltip>
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={() => setMoveItem(it)}>
              <ArrowDownUp /> Adjust
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => setLedgerItem(it)}>
              <ScrollText /> Ledger
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={() => setEditItem(it)}>
              <Pencil /> Edit
            </DropdownMenuItem>
            <DropdownMenuItem
              className="text-muted-foreground"
              onSelect={() => setArchiveItem(it)}
            >
              <Archive /> Archive
            </DropdownMenuItem>
          </>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <div className="space-y-4">
      <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3">
        <SearchBox
          value={q}
          onValueChange={setQ}
          placeholder="Search by SKU or name…"
          inputId="supplies-search"
          ariaLabel="Search supplies by SKU or name"
          isFetching={list.isFetching && list.data !== undefined}
          className="w-full sm:w-64"
        />
        <div className="flex flex-wrap items-center gap-2">
          {/* Low-stock filter chip — toggles server-side `low_stock=1`
              (quantity_on_hand <= reorder_level). One-tap triage for the
              morning stock-check: only the items that need reordering show. */}
          <Button
            variant={lowStockOnly ? 'secondary' : 'outline'}
            aria-pressed={lowStockOnly}
            onClick={() => setLowStockOnly((v) => !v)}
          >
            <TrendingDown /> {lowStockOnly ? 'Showing low stock' : 'Low stock only'}
          </Button>
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
            {rows.map((it, idx) => (
              <TableRow key={it.id} {...supplyRowNav.getRowProps(idx)}>
                <TableCell className="px-3 font-mono text-xs">{highlightMatch(it.sku, debouncedQ)}</TableCell>
                <TableCell className="px-3">{highlightMatch(it.name, debouncedQ)}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{it.quantity_on_hand} {it.unit}</TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">{it.reorder_level}</TableCell>
                <TableCell className="px-3">
                  <StockBadge
                    onHand={it.quantity_on_hand}
                    threshold={it.reorder_level}
                    lowStock={it.low_stock}
                    archived={it.archived ?? false}
                  />
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
              <span className="text-sm font-medium text-foreground">{highlightMatch(it.name, debouncedQ)}</span>
              <StockBadge
                onHand={it.quantity_on_hand}
                threshold={it.reorder_level}
                lowStock={it.low_stock}
                archived={it.archived ?? false}
              />
            </div>
            <MobileCardField label="SKU"><span className="font-mono text-xs">{highlightMatch(it.sku, debouncedQ)}</span></MobileCardField>
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
    // TooltipProvider covers every tooltip in the page (action-dropdown
    // disabled-state hints, dialog submit explanations). delayDuration=150
    // is short enough to feel snappy on a desktop click; skipDelayDuration
    // makes back-to-back hovers (moving from one row to the next) instant.
    <TooltipProvider delayDuration={150} skipDelayDuration={300}>
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
          <TabsTrigger value="insights">
            <BarChart3 className="size-3.5" /> Insights
          </TabsTrigger>
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
        <TabsContent value="insights">
          <InsightsTab onJumpToTab={setTab} />
        </TabsContent>
      </Tabs>
    </main>
    </TooltipProvider>
  );
}
