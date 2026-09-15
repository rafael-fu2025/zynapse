import {
  Archive,
  ArchiveRestore,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Loader2,
  Pencil,
  Plus,
  Wrench,
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
import { useKeysetPagination } from '@/hooks/useKeysetPagination';
import { useUrlFilter } from '@/hooks/useUrlFilter';
import { useTableRowKeyboardNav } from '@/hooks/useTableRowKeyboardNav';
import { useCan } from '@/hooks/useCan';
import {
  useArchiveEquipment,
  useEquipmentItems,
  useUnarchiveEquipment,
} from '@/hooks/useEquipment';
import type { EquipmentItem } from '@/schemas/equipment';
import { AddUnitsDialog } from './AddUnitsDialog';
import { CreateEquipmentDialog } from './CreateEquipmentDialog';
import { EditEquipmentDialog } from './EditEquipmentDialog';
import { EquipmentUnitsDialog } from './EquipmentUnitsDialog';
import { EquipmentStatusChips } from './badges';

export function EquipmentTab() {
  const [openCreate, setOpenCreate] = useState(false);
  const [unitsItem, setUnitsItem] = useState<EquipmentItem | null>(null);
  const [addUnitsItem, setAddUnitsItem] = useState<EquipmentItem | null>(null);
  const [editItem, setEditItem] = useState<EquipmentItem | null>(null);
  const [archiveItem, setArchiveItem] = useState<EquipmentItem | null>(null);
  // ?q= / ?archived=1 live in the URL (PRODUCT principle 5) so a
  // filtered equipment list survives a refresh and can be shared.
  const [q, setQ, qDraft] = useUrlFilter('q', { debounceMs: 300 });
  const [showArchivedRaw, setShowArchived] = useUrlFilter('archived', { default: '' });
  const showArchived = showArchivedRaw === '1';
  const { cursor, history, nextPage, prevPage, reset } = useKeysetPagination(q);
  const list = useEquipmentItems(cursor, 25, q === '' ? null : q, showArchived);
  const archive = useArchiveEquipment();
  const unarchive = useUnarchiveEquipment();
  // Archive/delete is admin-only (`clinic.inventory.delete`) — the
  // primary clinic_staff role would always 403 (mirrors the other tabs).
  const canDelete = useCan('clinic.inventory.delete');
  const canWrite = useCan('clinic.inventory.write');

  const rows = list.data?.data ?? [];

  // Keyboard navigation between equipment rows (Gap 12 parity).
  const equipmentRowNav = useTableRowKeyboardNav(rows.length);

  // Actions shared by the desktop row and the mobile card.
  const equipmentActions = (it: (typeof rows)[number]) => (
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
            <DropdownMenuItem onSelect={() => setUnitsItem(it)}>
              <Wrench /> Manage units
            </DropdownMenuItem>
            {canWrite && (
              <DropdownMenuItem onSelect={() => setAddUnitsItem(it)}>
                <Plus /> Add units
              </DropdownMenuItem>
            )}
            <DropdownMenuSeparator />
            {canWrite && (
              <DropdownMenuItem onSelect={() => setEditItem(it)}>
                <Pencil /> Edit
              </DropdownMenuItem>
            )}
            {canDelete && (
              <DropdownMenuItem
                className="text-muted-foreground"
                onSelect={() => setArchiveItem(it)}
              >
                <Archive /> Archive
              </DropdownMenuItem>
            )}
          </>
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );

  return (
    <div className="space-y-4">
      <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3">
        <div className="flex flex-1 flex-wrap items-center gap-3">
          <SearchBox
            value={qDraft}
            onValueChange={setQ}
            placeholder="Search by name, category, or location"
            inputId="equipment-search"
            ariaLabel="Search equipment by name, category, or location"
            isFetching={list.isFetching && list.data !== undefined}
            className="w-full sm:w-72 lg:w-96"
          />
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant={showArchived ? 'secondary' : 'outline'}
            aria-pressed={showArchived}
            onClick={() => { setShowArchived(showArchived ? '' : '1'); reset(); }}
          >
            <Archive /> {showArchived ? 'Hide archived' : 'Show archived'}
          </Button>
          {canWrite && (
            <Dialog open={openCreate} onOpenChange={setOpenCreate}>
              <Button onClick={() => setOpenCreate(true)}>
                <Plus /> New equipment
              </Button>
              {openCreate && <CreateEquipmentDialog onClose={() => setOpenCreate(false)} />}
            </Dialog>
          )}
        </div>
      </section>

      <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">Name</TableHead>
              <TableHead className="px-3">Category</TableHead>
              <TableHead className="px-3">Location</TableHead>
              <TableHead className="px-3">Units</TableHead>
              <TableHead className="px-3">Status</TableHead>
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
                  {q !== '' ? `No equipment matches "${q}".` : 'No equipment.'}
                </TableCell>
              </TableRow>
            )}
            {list.isError && !list.isLoading && (
              <QueryErrorRow colSpan={6} message="Failed to load equipment." onRetry={() => void list.refetch()} pending={list.isFetching} />
            )}
            {rows.map((it, idx) => (
              <TableRow key={it.id} {...equipmentRowNav.getRowProps(idx)}>
                <TableCell className="px-3">
                  {highlightMatch(it.name, q)}
                  {it.notes !== null && (
                    <p className="mt-0.5 max-w-xs truncate text-[11px] text-muted-foreground">{it.notes}</p>
                  )}
                </TableCell>
                <TableCell className="px-3 text-sm">{it.category ?? '—'}</TableCell>
                <TableCell className="px-3 text-sm">{it.location ?? '—'}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{it.total_units}</TableCell>
                <TableCell className="px-3">
                  <EquipmentStatusChips item={it} />
                </TableCell>
                <TableCell className="px-3 text-right">
                  <div className="flex flex-wrap justify-end gap-1">
                    {equipmentActions(it)}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

      {/* Mobile: equipment cards from the same rows. */}
      {list.isLoading && (
        <p className="py-6 text-center text-sm text-muted-foreground md:hidden" role="status">
          <Loader2 className="mx-auto size-4 animate-spin" />
        </p>
      )}
      {list.isError && !list.isLoading && (
        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-center text-sm text-destructive md:hidden">
          <p>Failed to load equipment.</p>
          <Button variant="outline" size="sm" className="mt-2" onClick={() => void list.refetch()} disabled={list.isFetching}>Retry</Button>
        </div>
      )}
      {!list.isLoading && !list.isError && rows.length === 0 && (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground md:hidden">
          {q !== '' ? `No equipment matches "${q}".` : 'No equipment.'}
        </p>
      )}
      <MobileCardList>
        {rows.map((it) => (
          <MobileCard key={it.id} aria-label={`Equipment ${it.name}`}>
            <div className="mb-1 flex items-center justify-between gap-2">
              <span className="text-sm font-medium text-foreground">{highlightMatch(it.name, q)}</span>
            </div>
            <EquipmentStatusChips item={it} />
            <MobileCardField label="Category"><span className="text-sm">{it.category ?? '—'}</span></MobileCardField>
            <MobileCardField label="Location"><span className="text-sm">{it.location ?? '—'}</span></MobileCardField>
            <MobileCardField label="Units"><span className="font-mono text-xs">{it.total_units}</span></MobileCardField>
            <MobileCardActions>{equipmentActions(it)}</MobileCardActions>
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

      {unitsItem !== null && (
        <Dialog open onOpenChange={(o) => !o && setUnitsItem(null)}>
          <EquipmentUnitsDialog item={unitsItem} onClose={() => setUnitsItem(null)} />
        </Dialog>
      )}
      {addUnitsItem !== null && (
        <Dialog open onOpenChange={(o) => !o && setAddUnitsItem(null)}>
          <AddUnitsDialog item={addUnitsItem} onClose={() => setAddUnitsItem(null)} />
        </Dialog>
      )}
      {editItem !== null && (
        <Dialog open onOpenChange={(o) => !o && setEditItem(null)}>
          <EditEquipmentDialog item={editItem} onClose={() => setEditItem(null)} />
        </Dialog>
      )}
      <ConfirmDialog
        open={archiveItem !== null}
        title={archiveItem !== null ? `Archive ${archiveItem.name}?` : ''}
        description="The equipment will be hidden from the list. Its units and status history are kept for the record. You can restore it later."
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
