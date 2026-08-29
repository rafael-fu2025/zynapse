import {
  Archive,
  ArchiveRestore,
  ArrowDownUp,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Loader2,
  PackagePlus,
  Pencil,
  Plus,
  ScrollText,
  Syringe,
  TrendingDown,
} from 'lucide-react';
import { useEffect, useState } from 'react';
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
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useKeysetPagination } from '@/hooks/useKeysetPagination';
import { useTableRowKeyboardNav } from '@/hooks/useTableRowKeyboardNav';
import {
  useArchiveItem,
  useInventoryItems,
  useUnarchiveItem,
} from '@/hooks/useInventory';
import type { InventoryItem } from '@/schemas/inventory';
import { CreateItemDialog } from './CreateItemDialog';
import { DispenseSupplyDialog } from './DispenseSupplyDialog';
import { EditItemDialog } from './EditItemDialog';
import { MoveStockDialog } from './MoveStockDialog';
import { ReceiveSupplyDialog } from './ReceiveSupplyDialog';
import { SupplyLedgerDialog } from './SupplyLedgerDialog';
import { StockBadge, SupplyLastMovementHint } from './badges';

export function SuppliesTab() {
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
  const { cursor, history, nextPage, prevPage, reset } = useKeysetPagination(debouncedQ);
  const list = useInventoryItems(cursor, 25, debouncedQ === '' ? null : debouncedQ, showArchived, lowStockOnly);
  const archive = useArchiveItem();
  const unarchive = useUnarchiveItem();

  // Same reset on the low-stock toggle — flipping the filter chip
  // shouldn't leave the cursor pointing into the previous page set.
  useEffect(() => {
    reset();
  }, [lowStockOnly, reset]);

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
              <ScrollText /> Transactions
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
            <TrendingDown /> {lowStockOnly ? 'Showing reorder needs' : 'Needs reorder only'}
          </Button>
          <Button
            variant={showArchived ? 'secondary' : 'outline'}
            aria-pressed={showArchived}
            onClick={() => { setShowArchived((v) => !v); reset(); }}
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
              <TableHead className="px-3">Target stock</TableHead>
              <TableHead className="px-3">Stock</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.isLoading && (
              <TableRow>
                <TableCell colSpan={7} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!list.isLoading && rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={7} className="px-3 py-6 text-center text-muted-foreground">
                  {debouncedQ !== '' ? `No items match "${debouncedQ}".` : 'No items.'}
                </TableCell>
              </TableRow>
            )}
            {list.isError && !list.isLoading && (
              <QueryErrorRow colSpan={7} message="Failed to load supplies." onRetry={() => void list.refetch()} pending={list.isFetching} />
            )}
            {rows.map((it, idx) => (
              <TableRow key={it.id} {...supplyRowNav.getRowProps(idx)}>
                <TableCell className="px-3 font-mono text-xs">{highlightMatch(it.sku, debouncedQ)}</TableCell>
                <TableCell className="px-3">
                  {highlightMatch(it.name, debouncedQ)}
                  <SupplyLastMovementHint movement={it.last_movement ?? null} unit={it.unit} />
                </TableCell>
                <TableCell className="px-3 font-mono text-xs">{it.quantity_on_hand} {it.unit}</TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">{it.reorder_level}</TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">{it.target_stock ?? '—'}</TableCell>
                <TableCell className="px-3">
                  <StockBadge
                    onHand={it.quantity_on_hand}
                    threshold={it.reorder_level}
                    target={it.target_stock}
                    stockStatus={it.stock_status}
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
                target={it.target_stock}
                stockStatus={it.stock_status}
                archived={it.archived ?? false}
              />
            </div>
            <SupplyLastMovementHint movement={it.last_movement ?? null} unit={it.unit} />
            <MobileCardField label="SKU"><span className="font-mono text-xs">{highlightMatch(it.sku, debouncedQ)}</span></MobileCardField>
            <MobileCardField label="On hand"><span className="font-mono text-xs">{it.quantity_on_hand} {it.unit}</span></MobileCardField>
            <MobileCardField label="Reorder level"><span className="font-mono text-xs text-muted-foreground">{it.reorder_level}</span></MobileCardField>
            <MobileCardField label="Target stock"><span className="font-mono text-xs text-muted-foreground">{it.target_stock ?? 'Not configured'}</span></MobileCardField>
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
            onClick={() => nextPage(list.data?.next)}
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
        description="The item will be hidden from the supplies list. Every transaction is kept for the audit trail. You can re-create the item later with the same SKU."
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
