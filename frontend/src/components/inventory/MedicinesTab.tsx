import {
  Archive,
  ArchiveRestore,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Layers,
  Loader2,
  PackagePlus,
  Pencil,
  Pill,
  ScrollText,
  Syringe,
  TrendingUp,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { MobileCardList, MobileCard, MobileCardField, MobileCardActions } from '@/components/MobileCardList';
import { QueryErrorRow } from '@/components/QueryErrorState';
import { SearchBox, highlightMatch } from '@/components/ui/SearchBox';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { useKeysetPagination } from '@/hooks/useKeysetPagination';
import { useUrlFilter } from '@/hooks/useUrlFilter';
import { useTableRowKeyboardNav } from '@/hooks/useTableRowKeyboardNav';
import {
  useArchiveMedicine,
  useMedicines,
  useUnarchiveMedicine,
} from '@/hooks/useMedicines';
import type { Medicine } from '@/schemas/medicines';
import { AddBatchDialog } from './AddBatchDialog';
import { BatchesDialog } from './BatchesDialog';
import { CreateMedicineDialog } from './CreateMedicineDialog';
import { DispenseDialog } from './DispenseDialog';
import { EditMedicineDialog } from './EditMedicineDialog';
import { ForecastDialog } from './ForecastDialog';
import { MedicineLedgerDialog } from './MedicineLedgerDialog';
import { ExpiryChip, LastMovementHint, StockBadge } from './badges';
import { daysUntil } from './format';

export function MedicinesTab() {
  const [openCreate, setOpenCreate] = useState(false);
  const [receiveFor, setReceiveFor] = useState<Medicine | null>(null);
  const [dispenseFor, setDispenseFor] = useState<Medicine | null>(null);
  const [ledgerFor, setLedgerFor] = useState<Medicine | null>(null);
  const [batchesFor, setBatchesFor] = useState<number | null>(null);
  const [editFor, setEditFor] = useState<Medicine | null>(null);
  const [archiveFor, setArchiveFor] = useState<Medicine | null>(null);
  const [forecastFor, setForecastFor] = useState<Medicine | null>(null);
  // ?q= / ?archived=1 live in the URL (PRODUCT principle 5) so a
  // filtered medicines list survives a refresh and can be shared.
  const [q, setQ, qDraft] = useUrlFilter('q', { debounceMs: 300 });
  const [showArchivedRaw, setShowArchived] = useUrlFilter('archived', { default: '' });
  const showArchived = showArchivedRaw === '1';
  const { cursor, history, nextPage, prevPage, reset } = useKeysetPagination(q);
  const list = useMedicines(cursor, 25, q === '' ? null : q, showArchived);
  const archive = useArchiveMedicine();
  const unarchive = useUnarchiveMedicine();

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
              <ScrollText /> Transactions
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => setForecastFor(m)}>
              <TrendingUp /> Forecast
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
          value={qDraft}
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
            onClick={() => { setShowArchived(showArchived ? '' : '1'); reset(); }}
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
                  {q !== '' ? `No medicines match "${q}".` : 'No medicines in the catalog.'}
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
                      {highlightMatch(m.generic_name, q)}
                    </span>
                    <span className="ml-1 text-xs text-muted-foreground">
                      {m.brand_name !== null && m.brand_name !== '' ? (
                        <>
                          {highlightMatch(m.brand_name, q)}
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
                    {m.category === null ? '—' : highlightMatch(m.category, q)}
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
                      target={m.target_stock}
                      stockStatus={m.stock_status}
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
          {q !== '' ? `No medicines match "${q}".` : 'No medicines in the catalog.'}
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
                  {highlightMatch(m.generic_name, q)}
                </span>
                <StockBadge
                  onHand={m.quantity_on_hand}
                  threshold={m.reorder_threshold}
                  target={m.target_stock}
                  stockStatus={m.stock_status}
                  archived={m.archived}
                />
              </div>
              {m.brand_name !== null && m.brand_name !== '' ? (
                <p className="text-xs text-muted-foreground">
                  {highlightMatch(m.brand_name, q)}
                  {m.dosage_strength !== null && m.dosage_strength !== '' && ` · ${m.dosage_strength}`}
                </p>
              ) : (
                m.dosage_strength !== null && m.dosage_strength !== '' && (
                  <p className="text-xs text-muted-foreground">{m.dosage_strength}</p>
                )
              )}
              <MobileCardField label="Category">
                {m.category === null ? '—' : highlightMatch(m.category, q)}
              </MobileCardField>
              <MobileCardField label="On hand"><span className="font-mono text-xs">{m.quantity_on_hand} {m.unit}</span></MobileCardField>
              <MobileCardField label="Reorder threshold"><span className="font-mono text-xs">{m.reorder_threshold}</span></MobileCardField>
              <MobileCardField label="Target stock"><span className="font-mono text-xs">{m.target_stock ?? 'Not configured'}</span></MobileCardField>
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
            onClick={() => nextPage(list.data?.next)}
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
      {forecastFor !== null && (
        <Dialog open onOpenChange={(o) => !o && setForecastFor(null)}>
          <ForecastDialog medicine={forecastFor} onClose={() => setForecastFor(null)} />
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
