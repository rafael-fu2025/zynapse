/**
 * WasteCategoriesPage — dedicated management screen for BMG waste
 * categories (previously a dialog inside `FacilitiesPage`).
 *
 * Routed at `/facilities/waste-categories`. Same behavior as the old
 * `WasteCategoriesDialog`: an add form on top and a list of rows with
 * inline edit / archive / restore / delete actions.
 */
import { Archive, ArchiveRestore, ArrowLeft, Boxes, Check, ChevronDown, Loader2, Pencil, Plus, Save, Trash2 as TrashIcon, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
  useArchiveWasteCategory,
  useCreateWasteCategory,
  useDeleteWasteCategory,
  useUnarchiveWasteCategory,
  useUpdateWasteCategory,
  useWasteCategories,
} from '@/hooks/useFacilities';
import type { WasteCategory } from '@/schemas/facilities';

/**
 * WasteCategoryRow — read mode (default) + edit mode (inline).
 *
 * - Click `Edit` → switches to inline edit form (matches the legacy
 *   `bmg/categories/edit` form: code is immutable, name + yield % +
 *   ref days are editable, is_active toggle).
 * - Click `Archive` → soft-archives (sets `is_active = 0`). The
 *   service refuses if any unit still references this category as
 *   its default; the toast surfaces that error.
 * - Click `Delete` → hard-delete with confirm. The service refuses
 *   if any batch or unit still references the category.
 */
function WasteCategoryRow({ cat }: { cat: WasteCategory }) {
  const [editing, setEditing] = useState(false);
  const [confirming, setConfirming] = useState<null | 'archive' | 'delete'>(null);
  const update = useUpdateWasteCategory();
  const archive = useArchiveWasteCategory();
  const unarchive = useUnarchiveWasteCategory();
  const del = useDeleteWasteCategory();

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

  function save() {
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
      { onSuccess: () => setEditing(false) },
    );
  }

  if (editing) {
    return (
      <li className="space-y-2 rounded-md border bg-muted/30 p-2">
        <div className="flex items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <span className="font-medium">{cat.name}</span>
            <span className="font-mono text-xs text-muted-foreground">({cat.code})</span>
            {!cat.is_active && <Badge variant="secondary">archived</Badge>}
          </div>
        </div>
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
        <div className="flex justify-end gap-1.5">
          <Button size="sm" variant="outline" onClick={() => setEditing(false)}>
            <X className="size-3.5" /> Cancel
          </Button>
          <Button size="sm" onClick={save} disabled={update.isPending}>
            {update.isPending ? <Loader2 className="animate-spin" /> : <Save className="size-3.5" />} Save
          </Button>
        </div>
      </li>
    );
  }

  return (
    <li className="flex items-center justify-between gap-2 rounded-md border px-2 py-1.5">
      <div className="flex min-w-0 flex-1 items-center gap-2">
        <span className="truncate font-medium">{cat.name}</span>
        <span className="shrink-0 font-mono text-xs text-muted-foreground">({cat.code})</span>
        {!cat.is_active && <Badge variant="secondary" className="shrink-0">archived</Badge>}
        <span className="ml-auto shrink-0 text-xs text-muted-foreground">
          {cat.expected_yield_pct !== null ? `${cat.expected_yield_pct}% yield` : '—'}
          {' · '}
          {cat.expected_days !== null
            ? `${cat.expected_days} expected days ${cat.sample_count > 0 ? `(from ${cat.sample_count} trial${cat.sample_count === 1 ? '' : 's'})` : '(reference)'}`
            : 'no expected days'}
        </span>
      </div>
      <div className="flex shrink-0 items-center gap-1">
        {confirming === null && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button className="min-h-11" size="sm" variant="outline" aria-label={`Actions for ${cat.code}`}>
                Actions <ChevronDown className="size-3.5" aria-hidden />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
              <DropdownMenuItem className="min-h-11" onSelect={() => setEditing(true)}>
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
        )}
        {confirming === 'archive' && (
          <>
            <span className="text-xs text-muted-foreground">Archive?</span>
            <Button size="sm" variant="ghost" onClick={() => setConfirming(null)} aria-label="Cancel">
              <X className="size-3.5" />
            </Button>
            <Button
              size="sm"
              variant="destructive"
              onClick={() => archive.mutate({ categoryId: cat.id }, { onSuccess: () => setConfirming(null) })}
              disabled={archive.isPending}
            >
              {archive.isPending ? <Loader2 className="animate-spin" /> : <Check className="size-3.5" />} Yes
            </Button>
          </>
        )}
        {confirming === 'delete' && (
          <>
            <span className="text-xs text-destructive">Delete forever?</span>
            <Button size="sm" variant="ghost" onClick={() => setConfirming(null)} aria-label="Cancel">
              <X className="size-3.5" />
            </Button>
            <Button
              size="sm"
              variant="destructive"
              onClick={() => del.mutate({ categoryId: cat.id }, { onSuccess: () => setConfirming(null) })}
              disabled={del.isPending}
            >
              {del.isPending ? <Loader2 className="animate-spin" /> : <TrashIcon className="size-3.5" />} Delete
            </Button>
          </>
        )}
      </div>
    </li>
  );
}

export default function WasteCategoriesPage() {
  // Archived categories (`is_active = 0`) are hidden by default — the
  // toggle refetches with the server-side `?active=1` filter dropped,
  // mirroring the "Show archived" affordance on the Facilities table.
  const [showArchived, setShowArchived] = useState(false);
  const cats = useWasteCategories(!showArchived);
  const create = useCreateWasteCategory();
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [yieldPct, setYieldPct] = useState('');
  const [refDays, setRefDays] = useState('');

  function submit() {
    if (code.trim() === '' || name.trim() === '') {
      toast.error('Code and name are required.');
      return;
    }
    create.mutate(
      {
        code: code.trim(),
        name: name.trim(),
        ...(yieldPct !== '' ? { expected_yield_pct: Number(yieldPct) } : {}),
        ...(refDays !== '' ? { reference_duration_days: Number(refDays) } : {}),
      },
      { onSuccess: () => { setCode(''); setName(''); setYieldPct(''); setRefDays(''); } },
    );
  }

  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold text-foreground">
            <Boxes className="size-5 text-primary" /> Waste categories
          </h1>
          <p className="text-sm text-muted-foreground">
            Manage the waste types accepted by BMG drums — expected yield and reference decomposition duration drive batch ETAs and progress.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant={showArchived ? 'secondary' : 'outline'}
            aria-pressed={showArchived}
            onClick={() => setShowArchived((v) => !v)}
          >
            <Archive /> {showArchived ? 'Hide archived' : 'Show archived'}
          </Button>
          <Button variant="outline" asChild>
            <Link to="/facilities">
              <ArrowLeft /> Back to Facilities
            </Link>
          </Button>
        </div>
      </header>

      {/* Add form */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="flex items-center gap-2 text-base">
            <Plus className="size-4 text-primary" /> Add category
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex flex-wrap items-end gap-2">
            <div className="space-y-1"><Label htmlFor="wc-code" className="text-xs">Code *</Label><Input id="wc-code" className="h-8 w-28" value={code} onChange={(e) => setCode(e.target.value)} placeholder="VEG-SCRP" /></div>
            <div className="space-y-1"><Label htmlFor="wc-name" className="text-xs">Name *</Label><Input id="wc-name" className="h-8 w-48" value={name} onChange={(e) => setName(e.target.value)} placeholder="Vegetable Scraps" /></div>
            <div className="space-y-1"><Label htmlFor="wc-yield" className="text-xs">Exp. yield %</Label><Input id="wc-yield" type="number" className="h-8 w-24" value={yieldPct} onChange={(e) => setYieldPct(e.target.value)} /></div>
            <div className="space-y-1"><Label htmlFor="wc-days" className="text-xs">Ref. days</Label><Input id="wc-days" type="number" className="h-8 w-20" value={refDays} onChange={(e) => setRefDays(e.target.value)} /></div>
            <Button size="sm" onClick={submit} disabled={create.isPending}>
              {create.isPending ? <Loader2 className="animate-spin" /> : <Plus />} Add
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* List with per-row Edit / Archive / Delete */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="flex items-center justify-between gap-2 text-base">
            <span>{showArchived ? 'All categories' : 'Active categories'}</span>
            <Badge variant="secondary" className="font-mono">{cats.data?.length ?? 0}</Badge>
          </CardTitle>
        </CardHeader>
        <CardContent>
          {cats.isLoading && (
            <div className="flex items-center justify-center py-8 text-muted-foreground">
              <Loader2 className="size-4 animate-spin" />
            </div>
          )}
          {cats.isError && (
            <p className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
              Failed to load waste categories.
            </p>
          )}
          {!cats.isLoading && !cats.isError && (
            <ul className="space-y-1.5">
              {(cats.data ?? []).map((c) => (
                <WasteCategoryRow key={c.id} cat={c} />
              ))}
              {(cats.data?.length ?? 0) === 0 && (
                <li className="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">
                  {showArchived
                    ? 'No categories yet. Add one above.'
                    : 'No active categories. Add one above, or click “Show archived” to see archived ones.'}
                </li>
              )}
            </ul>
          )}
        </CardContent>
      </Card>
    </main>
  );
}
