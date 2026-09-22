import {
  Check,
  ChevronLeft,
  ChevronRight,
  Loader2,
  PackageCheck,
  Plus,
  RefreshCw,
  ShoppingCart,
  X,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { MobileCardList, MobileCard, MobileCardField, MobileCardActions } from '@/components/MobileCardList';
import { TableStateRows } from '@/components/TableStates';
import { SearchBox, highlightMatch } from '@/components/ui/SearchBox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
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
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { useKeysetPagination } from '@/hooks/useKeysetPagination';
import { useTableRowKeyboardNav } from '@/hooks/useTableRowKeyboardNav';
import {
  useReorderAutoCheck,
  useReorders,
  useReorderTransition,
} from '@/hooks/useReorders';
import { titleCase } from '@/lib/utils';
import { CreateReorderDialog } from './CreateReorderDialog';
import { OrderReorderDialog } from './OrderReorderDialog';
import { EtaBadge } from './badges';
import { REORDER_STATUS_LABEL, REORDER_STATUS_VARIANT, URGENCY_VARIANT } from './constants';

/** Lowercase display label for a raw status value (used in empty states). */
function statusLabel(status: string): string {
  return ((REORDER_STATUS_LABEL as Record<string, string>)[status] ?? status).toLowerCase();
}

export function ReordersTab() {
  const [openCreate, setOpenCreate] = useState(false);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  const [orderingId, setOrderingId] = useState<number | null>(null);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [q, setQ] = useState('');
  const debouncedQ = useDebouncedValue(q, 300);
  const { cursor, history, nextPage, prevPage, reset } = useKeysetPagination(debouncedQ);
  const list = useReorders(
    cursor,
    statusFilter === 'all' ? null : statusFilter,
    25,
    debouncedQ === '' ? null : debouncedQ,
  );
  const autoCheck = useReorderAutoCheck();
  const transition = useReorderTransition();

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
          <ShoppingCart /> Mark bought
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
            title: `Cancel purchase request #${r.id}?`,
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
      <section className="flex flex-wrap items-end justify-between gap-3 rounded-xl border bg-card p-3">
        <div className="flex flex-1 flex-wrap items-end gap-3">
          <SearchBox
            value={q}
            onValueChange={setQ}
            placeholder="Search by medicine or note"
            inputId="reorders-search"
            label="Search"
            ariaLabel="Search purchase requests by medicine or note"
            isFetching={list.isFetching && list.data !== undefined}
            className="w-full sm:w-64 lg:w-96"
          />
          <p className="hidden text-xs text-muted-foreground sm:block">
            Auto-check files a request when stock falls to the threshold.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select
            value={statusFilter}
            onValueChange={(v) => { setStatusFilter(v); reset(); }}
          >
            <SelectTrigger aria-label="Filter by status" className="h-10 w-36 md:h-9">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
              <SelectItem value="approved">Approved</SelectItem>
              <SelectItem value="ordered">Purchased</SelectItem>
              <SelectItem value="received">Delivered</SelectItem>
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
        <Table ariaLabel="Purchase requests with urgency and status">
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">#</TableHead>
              <TableHead className="px-3">Item</TableHead>
              <TableHead className="px-3">Qty to buy</TableHead>
              <TableHead className="px-3">Threshold</TableHead>
              <TableHead className="px-3">Urgency</TableHead>
              <TableHead className="px-3">Status</TableHead>
              <TableHead className="px-3">Dates</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableStateRows
              colSpan={8}
              isLoading={list.isLoading}
              isError={list.isError}
              isEmpty={rows.length === 0}
              onRetry={() => void list.refetch()}
              pending={list.isFetching}
              errorMessage="Failed to load purchase requests."
              loadingLabel="Loading purchase requests"
              empty={{
                title: 'No purchase requests.',
                description: 'Requests are filed automatically when stock falls to the reorder threshold.',
              }}
              noResults={{
                title:
                  debouncedQ !== ''
                    ? `No purchase requests match "${debouncedQ}".`
                    : `No ${statusLabel(statusFilter)} requests.`,
                description: 'Adjust or clear the filters to see more.',
                action: (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                      setQ('');
                      setStatusFilter('all');
                      reset();
                    }}
                  >
                    Clear filters
                  </Button>
                ),
              }}
              hasFilters={debouncedQ !== '' || statusFilter !== 'all'}
            />
            {rows.map((r, idx) => (
              <TableRow key={r.id} {...reorderRowNav.getRowProps(idx)}>
                <TableCell className="px-3 font-mono text-xs">
                  {r.id}
                  {r.auto_triggered && <Badge variant="outline" className="ml-1.5">Auto</Badge>}
                </TableCell>
                <TableCell className="px-3">
                  {r.item_name === null
                    ? `#${r.medicine_id ?? r.supply_item_id ?? '?'}`
                    : highlightMatch(r.item_name, debouncedQ)}
                  {r.item_type === 'supply' && <Badge variant="outline" className="ml-1.5">Supply</Badge>}
                </TableCell>
                <TableCell className="px-3 font-mono text-xs">{r.requested_quantity} {r.unit ?? ''}</TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">
                  {r.reorder_level}
                </TableCell>
                <TableCell className="px-3">
                  <Badge variant={URGENCY_VARIANT[r.urgency]}>{titleCase(r.urgency)}</Badge>
                </TableCell>
                <TableCell className="px-3">
                  <Badge variant={REORDER_STATUS_VARIANT[r.status]}>{REORDER_STATUS_LABEL[r.status]}</Badge>
                </TableCell>
                <TableCell className="px-3 text-xs text-muted-foreground">
                  {r.order_date !== null && <>bought {r.order_date}<br /></>}
                  {r.expected_delivery_date !== null && (
                    <>
                      due {r.expected_delivery_date}
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
          <p>Failed to load purchase requests.</p>
          <Button variant="outline" size="sm" className="mt-2" onClick={() => void list.refetch()} disabled={list.isFetching}>Retry</Button>
        </div>
      )}
      {!list.isLoading && !list.isError && rows.length === 0 && (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground md:hidden">
          {debouncedQ !== '' ? `No purchase requests match "${debouncedQ}".` : statusFilter === 'all' ? 'No purchase requests.' : `No ${statusLabel(statusFilter)} requests.`}
        </p>
      )}
      <MobileCardList>
        {rows.map((r) => (
          <MobileCard key={r.id} aria-label={`Purchase request ${r.id}`}>
            <div className="mb-1 flex items-center justify-between gap-2">
              <span className="text-sm font-medium text-foreground">
                {r.item_name === null
                  ? `#${r.medicine_id ?? r.supply_item_id ?? '?'}`
                  : highlightMatch(r.item_name, debouncedQ)}
              </span>
              <Badge variant={REORDER_STATUS_VARIANT[r.status]}>{REORDER_STATUS_LABEL[r.status]}</Badge>
            </div>
            <div className="mb-1 flex flex-wrap gap-1.5">
              {r.auto_triggered && <Badge variant="outline">Auto</Badge>}
              {r.item_type === 'supply' && <Badge variant="outline">Supply</Badge>}
              <Badge variant={URGENCY_VARIANT[r.urgency]}>{titleCase(r.urgency)}</Badge>
            </div>
            <MobileCardField label="Qty to buy"><span className="font-mono text-xs">{r.requested_quantity} {r.unit ?? ''}</span></MobileCardField>
            <MobileCardField label="Threshold"><span className="font-mono text-xs text-muted-foreground">{r.reorder_level}</span></MobileCardField>
            <MobileCardField label="Dates">
              <span className="text-xs text-muted-foreground">
                {r.order_date !== null && <>bought {r.order_date}<br /></>}
                {r.expected_delivery_date !== null && (
                  <>
                    due {r.expected_delivery_date}
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
            onClick={() => nextPage(list.data?.next)}
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
