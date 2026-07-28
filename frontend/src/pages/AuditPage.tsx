/**
 * AuditPage — append-only audit reader + CSV export (Phase 4).
 *
 * Built on TanStack Table (headless) rendered through shadcn Table
 * primitives with keyset pagination. The query key embeds the cursor +
 * filters so back/forward navigation through the cache is stable.
 */
import { type ColumnDef, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { ChevronLeft, ChevronRight, Download, Loader2, Search } from 'lucide-react';
import { useState } from 'react';
import { useAuditEvents } from '@/hooks/useAudit';
import { useAuditExport } from '@/hooks/useAuditExport';
import { useAuthStore } from '@/store/auth';
import { fmtUtcToApp } from '@/utils/date';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import type { AuditEvent } from '@/schemas/audit';

export default function AuditPage() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [action, setAction] = useState('');
  const [entityType, setEntityType] = useState('');
  const hasExport = useAuthStore((s) => s.permissions.includes('audit.export'));
  const export_ = useAuditExport();

  const limit = 50;
  const q = useAuditEvents(cursor, limit, { action, entity_type: entityType });

  const columns: ColumnDef<AuditEvent>[] = [
    {
      header: 'When',
      accessorKey: 'committed_at',
      cell: ({ getValue }) => (
        <span className="font-mono text-xs text-foreground">{fmtUtcToApp(String(getValue()))}</span>
      ),
    },
    {
      header: 'Action',
      accessorKey: 'action_code',
      cell: ({ getValue }) => <Badge variant="info">{String(getValue())}</Badge>,
    },
    {
      header: 'Entity',
      cell: ({ row }) => (
        <span className="text-xs text-foreground">
          {row.original.entity_type}#{row.original.entity_id ?? '—'}
        </span>
      ),
    },
    {
      header: 'Actor',
      cell: ({ row }) => (
        <span className="text-xs text-foreground">{row.original.actor_user_id ?? '—'}</span>
      ),
    },
    {
      header: 'Hash',
      accessorKey: 'commit_hash',
      cell: ({ getValue }) => (
        <span className="font-mono text-[10px] text-muted-foreground" title={String(getValue())}>
          {String(getValue()).slice(0, 12)}…
        </span>
      ),
    },
  ];

  const table = useReactTable({
    data: q.data?.data ?? [],
    columns,
    getCoreRowModel: getCoreRowModel(),
  });

  function applyFilter() {
    setCursor(null);
    setHistory([null]);
  }

  function nextPage() {
    if (q.data?.next !== null && q.data?.next !== undefined) {
      setHistory((h) => [...h, q.data!.next ?? null]);
      setCursor(q.data.next);
    }
  }
  function prevPage() {
    if (history.length < 2) return;
    const next = history.slice(0, -1);
    setHistory(next);
    setCursor(next[next.length - 1] ?? null);
  }

  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Audit</h1>
          <p className="text-sm text-muted-foreground">
            Immutable, hash-chained audit log. Read-only.
          </p>
        </div>
        {hasExport && (
          <Button
            variant="secondary"
            onClick={() => export_.mutate({ cursor, limit: 5000 })}
            disabled={export_.isPending}
          >
            {export_.isPending ? <Loader2 className="animate-spin" /> : <Download />}
            Export CSV
          </Button>
        )}
      </header>

      <section
        aria-label="filters"
        className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-4"
      >
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="action">Action</Label>
          <Input id="action" value={action} onChange={(e) => setAction(e.target.value)} placeholder="bmg.batch_started" />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="entity">Entity</Label>
          <Input id="entity" value={entityType} onChange={(e) => setEntityType(e.target.value)} placeholder="facilities_bmg_batches" />
        </div>
        <Button onClick={applyFilter} variant="secondary">
          <Search /> Apply
        </Button>
      </section>

      <section className="overflow-hidden rounded-xl border bg-card">
        <Table className="table-fixed">
          <TableHeader className="bg-muted/50">
            {table.getHeaderGroups().map((hg) => (
              <TableRow key={hg.id}>
                {hg.headers.map((h) => (
                  <TableHead key={h.id} className="px-3">
                    {flexRender(h.column.columnDef.header, h.getContext())}
                  </TableHead>
                ))}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {q.isLoading && (
              <TableRow>
                <TableCell colSpan={columns.length} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" /> Loading…
                </TableCell>
              </TableRow>
            )}
            {!q.isLoading && table.getRowModel().rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={columns.length} className="px-3 py-6 text-center text-muted-foreground">
                  No events.
                </TableCell>
              </TableRow>
            )}
            {table.getRowModel().rows.map((row) => (
              <TableRow key={row.id}>
                {row.getVisibleCells().map((cell) => (
                  <TableCell key={cell.id} className="px-3 align-top">
                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

      <nav className="flex items-center justify-between" aria-label="pagination">
        <p className="text-xs text-muted-foreground">
          Page {history.length} · {table.getRowModel().rows.length} rows
        </p>
        <div className="flex gap-2">
          <Button variant="outline" onClick={prevPage} disabled={history.length < 2}>
            <ChevronLeft /> Previous
          </Button>
          <Button
            variant="outline"
            onClick={nextPage}
            disabled={q.data?.next === null || q.data?.next === undefined}
          >
            Next <ChevronRight />
          </Button>
        </div>
      </nav>
    </main>
  );
}
