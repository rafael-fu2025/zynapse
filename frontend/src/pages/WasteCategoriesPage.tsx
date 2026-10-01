/**
 * WasteCategoriesPage — dedicated management screen for BMG waste
 * categories (previously a dialog inside `FacilitiesPage`).
 *
 * Routed at `/facilities/waste-categories`. A full-width table with
 * per-row inline edit / archive / restore / delete, and "Add category"
 * as a modal (the old always-visible inline form).
 *
 * Read surface: `facilities.units.read` (route guard). Mutations hit
 * `facilities.categories.manage` — the add/edit/delete affordances are
 * hidden from operators (the backend re-checks everything).
 */
import { Archive, ArchiveRestore, ArrowLeft, Boxes, Check, ChevronDown, LineChart, Loader2, Pencil, Plus, Save, Trash2 as TrashIcon, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/PageHeader';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { TableStateRows } from '@/components/TableStates';
import { MobileCardList, MobileCard, MobileCardActions, MobileCardListState } from '@/components/MobileCardList';
import {
  useArchiveWasteCategory,
  useCreateWasteCategory,
  useDeleteWasteCategory,
  useUnarchiveWasteCategory,
  useUpdateWasteCategory,
  useWasteCategories,
  useWasteCategoryDeviation,
} from '@/hooks/useFacilities';
import { useUrlFilter } from '@/hooks/useUrlFilter';
import { createWasteCategorySchema, type WasteCategory } from '@/schemas/facilities';
import { hasPermission, useAuthStore } from '@/store/auth';

/** Add-category modal — the old always-visible inline form. */
function AddCategoryDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateWasteCategory();
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [yieldPct, setYieldPct] = useState('');
  const [refDays, setRefDays] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const codeId = useId();
  const nameId = useId();
  const yieldId = useId();
  const daysId = useId();

  function submit() {
    const parsed = createWasteCategorySchema.safeParse({
      code: code.trim().toLowerCase(),
      name: name.trim(),
      expected_yield_pct: yieldPct === '' ? '' : yieldPct,
      reference_duration_days: refDays === '' ? '' : refDays,
    });
    if (!parsed.success) {
      setErrors(Object.fromEntries(parsed.error.issues.map((i) => [String(i.path[0]), i.message])));
      return;
    }
    setErrors({});
    create.mutate(parsed.data, { onSuccess: onClose });
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Plus className="size-4 text-primary" /> Add category
          </DialogTitle>
          <DialogDescription>
            Expected yield and reference duration drive batch ETAs and progress for every drum loaded with this waste type.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div className="space-y-1.5">
            <Label htmlFor={codeId}>Code *</Label>
            <Input
              id={codeId}
              className="font-mono"
              value={code}
              onChange={(e) => setCode(e.target.value.toLowerCase())}
              placeholder="veg-scrp"
              aria-invalid={errors.code !== undefined}
            />
            {errors.code !== undefined && <p className="text-xs text-destructive">{errors.code}</p>}
            <p className="text-xs text-muted-foreground">Lowercase letters/digits separated by hyphens.</p>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor={nameId}>Name *</Label>
            <Input
              id={nameId}
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Vegetable Scraps"
              maxLength={100}
              aria-invalid={errors.name !== undefined}
            />
            {errors.name !== undefined && <p className="text-xs text-destructive">{errors.name}</p>}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor={yieldId}>Expected yield %</Label>
              <Input
                id={yieldId}
                type="number"
                min={0}
                max={100}
                step={0.1}
                value={yieldPct}
                onChange={(e) => setYieldPct(e.target.value)}
                aria-invalid={errors.expected_yield_pct !== undefined}
              />
              {errors.expected_yield_pct !== undefined && <p className="text-xs text-destructive">{errors.expected_yield_pct}</p>}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor={daysId}>Reference days</Label>
              <Input
                id={daysId}
                type="number"
                min={1}
                step={1}
                value={refDays}
                onChange={(e) => setRefDays(e.target.value)}
                aria-invalid={errors.reference_duration_days !== undefined}
              />
              {errors.reference_duration_days !== undefined && <p className="text-xs text-destructive">{errors.reference_duration_days}</p>}
            </div>
          </div>
          <DialogFooter>
            <Button onClick={submit} disabled={create.isPending}>
              {create.isPending ? <Loader2 className="animate-spin" /> : <Plus />} Add category
            </Button>
          </DialogFooter>
        </div>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Shared edit fields for the inline edit modes (table row on desktop,
 * card on mobile). Code is immutable — the legacy `bmg/categories/edit`
 * contract — so only name + yield % + ref days + active are editable.
 */
function CategoryEditFields({
  cat,
  name,
  setName,
  yieldPct,
  setYieldPct,
  refDays,
  setRefDays,
  isActive,
  setIsActive,
}: {
  cat: WasteCategory;
  name: string;
  setName: (v: string) => void;
  yieldPct: string;
  setYieldPct: (v: string) => void;
  refDays: string;
  setRefDays: (v: string) => void;
  isActive: boolean;
  setIsActive: (v: boolean) => void;
}) {
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
      <div className="space-y-1">
        <Label htmlFor={`wc-name-${cat.id}`} className="text-xs">Name *</Label>
        <Input id={`wc-name-${cat.id}`} className="h-8" value={name} onChange={(e) => setName(e.target.value)} maxLength={100} />
      </div>
      <div className="space-y-1">
        <Label htmlFor={`wc-yield-${cat.id}`} className="text-xs">Exp. yield %</Label>
        <Input id={`wc-yield-${cat.id}`} type="number" min={0} max={100} step={0.1} className="h-8" value={yieldPct} onChange={(e) => setYieldPct(e.target.value)} />
      </div>
      <div className="space-y-1">
        <Label htmlFor={`wc-days-${cat.id}`} className="text-xs">Ref. days</Label>
        <Input id={`wc-days-${cat.id}`} type="number" min={1} step={1} className="h-8" value={refDays} onChange={(e) => setRefDays(e.target.value)} />
      </div>
      <div className="space-y-1">
        <Label className="text-xs">Status</Label>
        <label className="flex h-8 items-center gap-2 rounded-md border bg-background px-2 text-xs">
          <input
            type="checkbox"
            checked={isActive}
            onChange={(e) => setIsActive(e.target.checked)}
            className="size-3.5"
            aria-label="Active"
          />
          Active
        </label>
      </div>
    </div>
  );
}
/**
 * useCategoryRowState — the read/edit state machine shared by the table
 * row and the mobile card so both surfaces edit identically.
 */
function useCategoryRowState(cat: WasteCategory) {
  const update = useUpdateWasteCategory();
  const archive = useArchiveWasteCategory();
  const unarchive = useUnarchiveWasteCategory();
  const del = useDeleteWasteCategory();
  const [editing, setEditing] = useState(false);
  const [confirming, setConfirming] = useState<null | 'archive' | 'delete'>(null);
  const [name, setName] = useState(cat.name);
  const [yieldPct, setYieldPct] = useState(
    cat.expected_yield_pct !== null ? String(cat.expected_yield_pct) : '',
  );
  const [refDays, setRefDays] = useState(
    cat.reference_duration_days !== null ? String(cat.reference_duration_days) : '',
  );
  const [isActive, setIsActive] = useState(cat.is_active);

  // Reset the local form whenever the row switches back into read mode.
  useEffect(() => {
    if (!editing) {
      setName(cat.name);
      setYieldPct(cat.expected_yield_pct !== null ? String(cat.expected_yield_pct) : '');
      setRefDays(cat.reference_duration_days !== null ? String(cat.reference_duration_days) : '');
      setIsActive(cat.is_active);
    }
  }, [editing, cat]);

  function save(onDone: () => void) {
    update.mutate(
      {
        categoryId: cat.id,
        input: {
          name,
          expected_yield_pct: yieldPct === '' ? '' : Number(yieldPct),
          reference_duration_days: refDays === '' ? '' : Number(refDays),
          is_active: isActive,
        },
      },
      { onSuccess: () => { setEditing(false); onDone(); } },
    );
  }

  return { update, archive, unarchive, del, editing, setEditing, confirming, setConfirming, save, fields: { name, setName, yieldPct, setYieldPct, refDays, setRefDays, isActive, setIsActive } };
}

function CategoryActions({ cat, state }: { cat: WasteCategory; state: ReturnType<typeof useCategoryRowState> }) {
  const { archive, unarchive, del, setConfirming } = state;
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button className="min-h-11" size="sm" variant="outline" aria-label={`Actions for ${cat.code}`}>
          Actions <ChevronDown className="size-3.5" aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-48">
        <DropdownMenuItem className="min-h-11" onSelect={() => state.setEditing(true)}>
          <Pencil /> Edit category
        </DropdownMenuItem>
        {cat.is_active ? (
          <DropdownMenuItem className="min-h-11" disabled={archive.isPending} onSelect={() => setConfirming('archive')}>
            <Archive /> Archive category
          </DropdownMenuItem>
        ) : (
          <DropdownMenuItem className="min-h-11" disabled={unarchive.isPending} onSelect={() => unarchive.mutate({ categoryId: cat.id })}>
            <ArchiveRestore /> Restore category
          </DropdownMenuItem>
        )}
        <DropdownMenuSeparator />
        <DropdownMenuItem
          className="min-h-11 text-destructive focus:text-destructive"
          disabled={del.isPending}
          onSelect={() => setConfirming('delete')}
        >
          <TrashIcon /> Delete permanently
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function InlineConfirm({ cat, state }: { cat: WasteCategory; state: ReturnType<typeof useCategoryRowState> }) {
  const { archive, del, confirming, setConfirming } = state;
  if (confirming === null) return null;
  return (
    <span className="inline-flex items-center gap-1.5">
      <span className={confirming === 'delete' ? 'text-xs text-destructive' : 'text-xs text-muted-foreground'}>
        {confirming === 'archive' ? 'Archive?' : 'Delete forever?'}
      </span>
      <Button size="sm" variant="ghost" onClick={() => setConfirming(null)} aria-label="Cancel">
        <X className="size-3.5" />
      </Button>
      <Button
        size="sm"
        variant={confirming === 'delete' ? 'destructive' : 'default'}
        onClick={() =>
          (confirming === 'archive' ? archive : del).mutate(
            { categoryId: cat.id },
            { onSuccess: () => setConfirming(null) },
          )
        }
        disabled={confirming === 'archive' ? archive.isPending : del.isPending}
      >
        {confirming === 'archive'
          ? (archive.isPending ? <Loader2 className="animate-spin" /> : <Check className="size-3.5" />)
          : (del.isPending ? <Loader2 className="animate-spin" /> : <TrashIcon className="size-3.5" />)}
        {confirming === 'archive' ? 'Yes' : 'Delete'}
      </Button>
    </span>
  );
}

/** Desktop table row — read mode, or inline edit spread across the cells. */
function WasteCategoryTableRow({ cat, canManage }: { cat: WasteCategory; canManage: boolean }) {
  const state = useCategoryRowState(cat);

  if (state.editing) {
    return (
      <TableRow className="bg-muted/30">
        <TableCell className="px-3">
          <p className="text-sm font-medium">{cat.name}</p>
          <p className="font-mono text-xs text-muted-foreground">{cat.code}</p>
        </TableCell>
        <TableCell className="px-3" colSpan={4}>
          <CategoryEditFields cat={cat} {...state.fields} />
        </TableCell>
        <TableCell className="px-3">
          <div className="flex justify-end gap-1.5">
            <Button size="sm" variant="outline" onClick={() => state.setEditing(false)}>
              <X className="size-3.5" /> Cancel
            </Button>
            <Button size="sm" onClick={() => state.save(() => {})} disabled={state.update.isPending}>
              {state.update.isPending ? <Loader2 className="animate-spin" /> : <Save className="size-3.5" />} Save
            </Button>
          </div>
        </TableCell>
      </TableRow>
    );
  }

  return (
    <TableRow className="scroll-mt-24">
      <TableCell className="px-3">
        {/* Stacked like the Devices table's name/code cell — one rhythm
            across all BMG tables, every row exactly two text lines. */}
        <p className="text-sm font-medium">{cat.name}</p>
        <p className="font-mono text-xs text-muted-foreground">{cat.code}</p>
      </TableCell>
      <TableCell className="px-3">
        <Badge variant={cat.is_active ? 'success' : 'secondary'}>{cat.is_active ? 'Active' : 'Archived'}</Badge>
      </TableCell>
      <TableCell className="px-3 text-sm tabular-nums">
        {cat.expected_yield_pct !== null ? `${cat.expected_yield_pct}%` : '—'}
      </TableCell>
      <TableCell className="px-3 text-sm tabular-nums">
        {cat.reference_duration_days !== null ? `${cat.reference_duration_days}d` : '—'}
      </TableCell>
      <TableCell className="px-3">
        {cat.expected_days !== null ? (
          <>
            <p className="text-sm font-medium tabular-nums">{cat.expected_days}d</p>
            {cat.sample_count > 0 && (
              <p className="text-xs text-muted-foreground">
                Avg {cat.historical_avg_days ?? 0}d · {cat.sample_count} trial{cat.sample_count === 1 ? '' : 's'}
              </p>
            )}
          </>
        ) : (
          <span className="text-xs text-muted-foreground">No history</span>
        )}
      </TableCell>
      {canManage && (
        <TableCell className="px-3">
          <div className="flex items-center justify-end gap-2">
            <InlineConfirm cat={cat} state={state} />
            {state.confirming === null && <CategoryActions cat={cat} state={state} />}
          </div>
        </TableCell>
      )}
    </TableRow>
  );
}

export default function WasteCategoriesPage() {
  // Archived categories (`is_active = 0`) are hidden by default — the
  // toggle refetches with the server-side `?active=1` filter dropped,
  // mirroring the "Show archived" affordance on the Facilities table.
  // It lives in the URL so the archived view is linkable and survives a
  // reload.
  const [archivedParam, setArchivedParam] = useUrlFilter('archived');
  const showArchived = archivedParam === '1';
  const [adding, setAdding] = useState(false);
  const cats = useWasteCategories(!showArchived);
  const auth = useAuthStore();
  const canManage = hasPermission(auth, 'facilities.categories.manage');

  const rows = cats.data ?? [];

  return (
    <main className="space-y-4 p-6">
      <PageHeader
        title={
          <span className="flex items-center gap-2">
            <Boxes className="size-5 text-primary" /> Waste categories
          </span>
        }
        description="Manage the waste types accepted by BMG drums — expected yield and reference decomposition duration drive batch ETAs and progress."
        actions={
          <>
            <Button
              variant={showArchived ? 'secondary' : 'outline'}
              aria-pressed={showArchived}
              onClick={() => setArchivedParam(showArchived ? '' : '1')}
            >
              <Archive /> {showArchived ? 'Hide archived' : 'Show archived'}
            </Button>
            <Button variant="outline" asChild>
              <Link to="/facilities">
                <ArrowLeft /> Back to Facilities
              </Link>
            </Button>
            {canManage && (
              <Button onClick={() => setAdding(true)}>
                <Plus /> Add category
              </Button>
            )}
          </>
        }
      />

      {/* Desktop table */}
      <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
        <Table
          ariaLabel="BMG waste categories"
          wrapperClassName="max-h-[65vh] overflow-y-auto"
          className="[&_td]:py-1.5"
        >
          <TableHeader className="sticky top-0 z-10 bg-muted shadow-[0_1px_0_0_var(--border)]">
            <TableRow>
              <TableHead className="px-3">Category</TableHead>
              <TableHead className="px-3">Status</TableHead>
              <TableHead className="px-3">Expected yield</TableHead>
              <TableHead className="px-3">Reference days</TableHead>
              <TableHead className="px-3">Expected days</TableHead>
              {canManage && <TableHead className="px-3 text-right">Actions</TableHead>}
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableStateRows
              colSpan={canManage ? 6 : 5}
              isLoading={cats.isLoading}
              isError={cats.isError}
              isEmpty={rows.length === 0}
              onRetry={() => void cats.refetch()}
              pending={cats.isFetching}
              errorMessage="Failed to load waste categories."
              loadingLabel="Loading waste categories"
              empty={{
                title: showArchived ? 'No categories yet.' : 'No active categories.',
                description: canManage
                  ? 'Add one with the button above.'
                  : 'Ask a BMG administrator to add one.',
              }}
            />
            {rows.map((c) => (
              <WasteCategoryTableRow key={c.id} cat={c} canManage={canManage} />
            ))}
          </TableBody>
        </Table>
      </section>

      {/* Mobile cards */}
      <MobileCardList className="md:hidden">
        <MobileCardListState
          isLoading={cats.isLoading}
          isError={cats.isError}
          isEmpty={rows.length === 0}
          onRetry={() => void cats.refetch()}
          pending={cats.isFetching}
          errorMessage="Failed to load waste categories."
          loadingLabel="Loading waste categories"
          empty={{
            title: showArchived ? 'No categories yet.' : 'No active categories.',
            description: canManage
              ? 'Add one with the button above.'
              : 'Ask a BMG administrator to add one.',
          }}
        />
        {rows.map((c) => (
          <MobileCategoryCard key={c.id} cat={c} canManage={canManage} />
        ))}
      </MobileCardList>

      {adding && <AddCategoryDialog onClose={() => setAdding(false)} />}

      {/* Audit #10: actual vs expected yield/duration per category */}
      <DeviationReport />
    </main>
  );
}

/** Mobile card — same read/edit state machine as the table row. */
function MobileCategoryCard({ cat, canManage }: { cat: WasteCategory; canManage: boolean }) {
  const state = useCategoryRowState(cat);

  return (
    <MobileCard>
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate text-sm font-medium">{cat.name}</p>
          <p className="truncate font-mono text-xs text-muted-foreground">{cat.code}</p>
        </div>
        <Badge variant={cat.is_active ? 'success' : 'secondary'}>{cat.is_active ? 'Active' : 'Archived'}</Badge>
      </div>
      {state.editing ? (
        <div className="mt-2 space-y-2">
          <CategoryEditFields cat={cat} {...state.fields} />
          <div className="flex justify-end gap-1.5">
            <Button size="sm" variant="outline" onClick={() => state.setEditing(false)}>
              <X className="size-3.5" /> Cancel
            </Button>
            <Button size="sm" onClick={() => state.save(() => {})} disabled={state.update.isPending}>
              {state.update.isPending ? <Loader2 className="animate-spin" /> : <Save className="size-3.5" />} Save
            </Button>
          </div>
        </div>
      ) : (
        <>
          <p className="mt-1.5 text-xs text-muted-foreground">
            {cat.expected_yield_pct !== null ? `${cat.expected_yield_pct}% yield` : 'No yield reference'}
            {cat.expected_days !== null ? ` · ${cat.expected_days}d expected` : ''}
          </p>
          {canManage && (
            <MobileCardActions>
              {state.confirming !== null ? (
                <InlineConfirm cat={cat} state={state} />
              ) : (
                <CategoryActions cat={cat} state={state} />
              )}
            </MobileCardActions>
          )}
        </>
      )}
    </MobileCard>
  );
}

/**
 * DeviationReport — audit #10. Compares finished/released batches
 * against each category's reference yield + duration to surface
 * chronic under- or over-performance. Read-only.
 */
function DeviationReport() {
  const { data, isLoading, isError, refetch, isFetching } = useWasteCategoryDeviation();
  const rows = (data ?? []).filter((r) => r.batch_count > 0);
  // Hide the card entirely when there is nothing to say — but never when the
  // read failed, or the failure would be invisible.
  if (!isLoading && !isError && rows.length === 0) return null;
  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <LineChart className="size-4 text-primary" /> Yield &amp; duration deviation
        </CardTitle>
      </CardHeader>
      <CardContent>
        {isLoading && (
          <div className="flex items-center justify-center py-4 text-muted-foreground" role="status" aria-label="Loading yield deviations">
            <Loader2 className="size-4 animate-spin" />
          </div>
        )}
        {isError && !isLoading && (
          <div role="alert" className="flex flex-col items-center gap-2 text-destructive">
            <p className="text-sm">Failed to load yield deviations.</p>
            <Button size="sm" variant="outline" onClick={() => void refetch()} disabled={isFetching}>
              Retry
            </Button>
          </div>
        )}
        {!isLoading && !isError && rows.length === 0 && (
          <p className="text-sm text-muted-foreground">No finished/released batches yet — deviations appear once batches complete.</p>
        )}
        {!isLoading && !isError && rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <caption className="sr-only">Yield and duration deviation by waste category</caption>
              <thead>
                <tr className="border-b text-left text-xs text-muted-foreground">
                  <th scope="col" className="py-1.5 pr-3 font-medium">Category</th>
                  <th scope="col" className="py-1.5 pr-3 font-medium">Batches</th>
                  <th scope="col" className="py-1.5 pr-3 font-medium">Yield (actual / exp)</th>
                  <th scope="col" className="py-1.5 pr-3 font-medium">Δ yield</th>
                  <th scope="col" className="py-1.5 font-medium">Duration (actual / exp)</th>
                  <th scope="col" className="py-1.5 pl-3 font-medium">Δ days</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.category_id} className="border-b last:border-0">
                    <td className="py-1.5 pr-3">{r.name}</td>
                    <td className="py-1.5 pr-3 tabular-nums text-xs">{r.batch_count}</td>
                    <td className="py-1.5 pr-3 tabular-nums text-xs">
                      {r.actual_yield_pct !== null ? `${r.actual_yield_pct}%` : '—'} / {r.expected_yield_pct !== null ? `${r.expected_yield_pct}%` : '—'}
                    </td>
                    <td className="py-1.5 pr-3">
                      {r.yield_delta_pp !== null && (
                        <Badge variant={r.yield_delta_pp >= 0 ? 'success' : 'warning'} className="tabular-nums">
                          {r.yield_delta_pp >= 0 ? '+' : ''}{r.yield_delta_pp} pp
                        </Badge>
                      )}
                    </td>
                    <td className="py-1.5 pr-3 tabular-nums text-xs">
                      {r.actual_days !== null ? `${r.actual_days}d` : '—'} / {r.expected_days !== null ? `${r.expected_days}d` : '—'}
                    </td>
                    <td className="py-1.5">
                      {r.days_delta !== null && (
                        <Badge variant={r.days_delta <= 0 ? 'success' : 'warning'} className="tabular-nums">
                          {r.days_delta > 0 ? '+' : ''}{r.days_delta}d
                        </Badge>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
