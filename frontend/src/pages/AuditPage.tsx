import { type ColumnDef, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { addDays, format } from 'date-fns';
import {
  CheckCircle2,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ChevronUp,
  Clipboard,
  Download,
  Eye,
  FileSearch,
  Loader2,
  Search,
  ShieldCheck,
  TriangleAlert,
  X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';

import { ComboboxField } from '@/components/ComboboxField';
import {
  MobileCard,
  MobileCardActions,
  MobileCardField,
  MobileCardList,
} from '@/components/MobileCardList';
import { QueryErrorRow } from '@/components/QueryErrorState';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
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
import {
  type AuditFilters,
  useAuditEvent,
  useAuditEvents,
  useAuditFacets,
  useVerifyAuditChain,
} from '@/hooks/useAudit';
import { useAuditExport } from '@/hooks/useAuditExport';
import { hasPermission, useAuthStore } from '@/store/auth';
import type { AuditActor, AuditEvent } from '@/schemas/audit';
import { fmtUtcToApp } from '@/utils/date';

const FILTER_KEYS = [
  'action',
  'entity_type',
  'entity_id',
  'actor_user_id',
  'request_id',
  'from',
  'to',
  'q',
] as const;

// Power-user filters hidden behind the “Advanced filters” toggle.
const ADVANCED_FILTER_KEYS = ['entity_type', 'entity_id', 'actor_user_id', 'request_id'] as const;

type AdvancedFilterKey = (typeof ADVANCED_FILTER_KEYS)[number];

function defaultRange(): Pick<AuditFilters, 'from' | 'to'> {
  const today = new Date();
  return {
    from: format(addDays(today, -6), 'yyyy-MM-dd'),
    to: format(today, 'yyyy-MM-dd'),
  };
}

function filtersFromParams(params: URLSearchParams): AuditFilters {
  const filters: AuditFilters = {};
  for (const key of FILTER_KEYS) {
    const value = params.get(key)?.trim();
    if (value !== undefined && value !== '') filters[key] = value;
  }
  if (Object.keys(filters).length === 0 && params.get('range') !== 'all') {
    return defaultRange();
  }
  return filters;
}

function filterSignature(params: URLSearchParams): string {
  const scoped = new URLSearchParams();
  for (const key of FILTER_KEYS) {
    const value = params.get(key);
    if (value !== null) scoped.set(key, value);
  }
  const range = params.get('range');
  if (range !== null) scoped.set('range', range);
  return scoped.toString();
}

export default function AuditPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const signature = filterSignature(searchParams);
  const applied = useMemo(() => filtersFromParams(new URLSearchParams(signature)), [signature]);
  const [draft, setDraft] = useState<AuditFilters>(applied);
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [showAdvanced, setShowAdvanced] = useState(false);

  const selectedRaw = searchParams.get('event');
  const selectedId = selectedRaw !== null && /^\d+$/.test(selectedRaw) ? Number(selectedRaw) : null;
  const auth = useAuthStore();
  const canExport = hasPermission(auth, 'audit.export');

  const limit = 50;
  const events = useAuditEvents(cursor, limit, applied);
  const facets = useAuditFacets();
  const detail = useAuditEvent(selectedId);
  const verification = useVerifyAuditChain();
  const exporter = useAuditExport();

  useEffect(() => {
    if (searchParams.toString() === '') {
      const initial = defaultRange();
      setSearchParams({ from: initial.from ?? '', to: initial.to ?? '' }, { replace: true });
    }
  }, [searchParams, setSearchParams]);

  useEffect(() => {
    setDraft(applied);
    setCursor(null);
    setHistory([null]);
    // Auto-open the advanced section when a shared URL carries an
    // advanced filter so the applied state is never hidden.
    const advancedActive = (ADVANCED_FILTER_KEYS as readonly string[]).some(
      (key) => applied[key as AdvancedFilterKey] !== undefined,
    );
    setShowAdvanced(advancedActive);
  }, [applied, signature]);

  const actionOptions = useMemo(
    () => (facets.data?.action_codes ?? []).map((value) => ({ value })),
    [facets.data?.action_codes],
  );
  const entityOptions = useMemo(
    () => (facets.data?.entity_types ?? []).map((value) => ({ value })),
    [facets.data?.entity_types],
  );
  const actorOptions = useMemo(
    () => (facets.data?.actors ?? []).map((actor) => {
      const hint = actor.display_name !== null && actor.email !== actor.display_name
        ? { hint: actor.display_name }
        : {};
      return {
        value: String(actor.id),
        label: actor.email ?? actor.display_name ?? `User ${String(actor.id)}`,
        ...hint,
      };
    }),
    [facets.data?.actors],
  );

  function applyFilters(event?: React.FormEvent): void {
    event?.preventDefault();
    const params = new URLSearchParams();
    for (const key of FILTER_KEYS) {
      const value = draft[key]?.trim();
      if (value !== undefined && value !== '') params.set(key, value);
    }
    if ([...params.keys()].length === 0) params.set('range', 'all');
    setSearchParams(params);
  }

  function clearFilters(): void {
    setDraft({});
    setSearchParams({ range: 'all' });
  }

  function selectEvent(id: number | null): void {
    const params = new URLSearchParams(searchParams);
    if (id === null) params.delete('event');
    else params.set('event', String(id));
    setSearchParams(params, { replace: true });
  }

  function nextPage(): void {
    const next = events.data?.next;
    if (next === null || next === undefined) return;
    setHistory((current) => [...current, next]);
    setCursor(next);
  }

  function previousPage(): void {
    if (history.length < 2) return;
    const nextHistory = history.slice(0, -1);
    setHistory(nextHistory);
    setCursor(nextHistory[nextHistory.length - 1] ?? null);
  }

  const columns: ColumnDef<AuditEvent>[] = [
    {
      header: 'Occurred',
      accessorKey: 'occurred_at',
      cell: ({ row }) => (
        <span className="text-xs text-foreground">
          {fmtUtcToApp(String(row.original.occurred_at ?? row.original.committed_at))}
        </span>
      ),
    },
    {
      header: 'Action',
      accessorKey: 'action_code',
      cell: ({ getValue }) => <Badge variant="info">{String(getValue())}</Badge>,
    },
    {
      header: 'Entity',
      cell: ({ row }) => <EntityLabel event={row.original} />,
    },
    {
      header: 'Actor',
      cell: ({ row }) => <ActorLabel actor={row.original.actor} />,
    },
    {
      header: 'Request ID',
      cell: ({ row }) => <ShortCode value={row.original.request_id} />,
    },
    {
      id: 'inspect',
      header: () => <span className="sr-only">Inspect</span>,
      cell: ({ row }) => (
        <Button
          type="button"
          size="sm"
          variant={selectedId === row.original.id ? 'secondary' : 'ghost'}
          onClick={() => selectEvent(row.original.id)}
          aria-label={`Inspect audit event ${String(row.original.id)}`}
        >
          <Eye /> Inspect
        </Button>
      ),
    },
  ];

  const table = useReactTable({
    data: events.data?.data ?? [],
    columns,
    getCoreRowModel: getCoreRowModel(),
  });
  const activeCount = FILTER_KEYS.filter((key) => applied[key] !== undefined).length;

  return (
    <main className="mx-auto max-w-[1500px] space-y-5 p-4 sm:p-6">
      <header className="flex flex-wrap items-end justify-between gap-3 border-b pb-4">
        <div>
          <div className="mb-1 flex items-center gap-2">
            <ShieldCheck className="size-5 text-primary" aria-hidden />
            <h1 className="text-xl font-semibold text-foreground">Audit evidence</h1>
          </div>
          <p className="max-w-2xl text-sm text-muted-foreground">
            Inspect immutable, hash-chained administrative events. Detail payloads are redacted before display.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={() => verification.mutate()}
            disabled={verification.isPending}
          >
            {verification.isPending ? <Loader2 className="animate-spin" /> : <ShieldCheck />}
            Verify chain
          </Button>
          {canExport && (
            <Button
              type="button"
              variant="secondary"
              onClick={() => exporter.mutate({ cursor: null, limit: 5000, filters: applied })}
              disabled={exporter.isPending}
            >
              {exporter.isPending ? <Loader2 className="animate-spin" /> : <Download />}
              Export up to 5,000
            </Button>
          )}
        </div>
      </header>

      <VerificationStatus verification={verification} />

      <form aria-label="Audit filters" onSubmit={applyFilters} className="border bg-card p-4 shadow-sm">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <div>
              <h2 className="text-sm font-semibold text-foreground">Evidence filters</h2>
              <p className="text-xs text-muted-foreground">Applied filters are saved in the URL for review handoffs.</p>
            </div>
            {activeCount > 0 && <Badge variant="secondary">{activeCount} active</Badge>}
          </div>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => setShowAdvanced((current) => !current)}
            aria-expanded={showAdvanced}
            aria-controls="audit-advanced-filters"
            className="text-muted-foreground hover:text-foreground"
          >
            {showAdvanced ? <ChevronUp /> : <ChevronDown />}
            {showAdvanced ? 'Hide advanced filters' : 'Advanced filters'}
          </Button>
        </div>
        {/* Basic — the everyday filters, always visible. */}
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <FilterField label="Action" htmlFor="audit-action">
            <ComboboxField
              id="audit-action"
              value={draft.action ?? ''}
              onChange={(action) => setDraft((current) => ({ ...current, action }))}
              sourceKey="audit-actions"
              options={actionOptions}
              placeholder="Select or enter action"
              allowCreate
              normalize={false}
            />
          </FilterField>
          <FilterField label="Date range" htmlFor="audit-range">
            <DateRangePicker
              id="audit-range"
              start={draft.from ?? ''}
              end={draft.to ?? ''}
              onChange={({ start, end }) => setDraft((current) => ({ ...current, from: start, to: end }))}
            />
          </FilterField>
          <FilterField label="Search events" htmlFor="audit-payload-query" className="sm:col-span-2">
            <Input
              id="audit-payload-query"
              value={draft.q ?? ''}
              onChange={(event) => setDraft((current) => ({ ...current, q: event.target.value }))}
              placeholder="Search by action, actor, or anything in the event context"
              maxLength={120}
            />
          </FilterField>
        </div>
        {/* Advanced — power-user filters behind the toggle. */}
        {showAdvanced && (
          <div id="audit-advanced-filters" className="mt-3 grid gap-3 border-t pt-3 sm:grid-cols-2 lg:grid-cols-4">
            <FilterField label="Entity type" htmlFor="audit-entity-type">
              <ComboboxField
                id="audit-entity-type"
                value={draft.entity_type ?? ''}
                onChange={(entity_type) => setDraft((current) => ({ ...current, entity_type }))}
                sourceKey="audit-entity-types"
                options={entityOptions}
                placeholder="Select or enter entity"
                allowCreate
                normalize={false}
              />
            </FilterField>
            <FilterField label="Actor" htmlFor="audit-actor">
              <ComboboxField
                id="audit-actor"
                value={draft.actor_user_id ?? ''}
                onChange={(actor_user_id) => setDraft((current) => ({ ...current, actor_user_id }))}
                sourceKey="audit-actors"
                options={actorOptions}
                placeholder="Email or username"
                normalize={false}
              />
            </FilterField>
            <FilterField label="Entity ID" htmlFor="audit-entity-id">
              <Input
                id="audit-entity-id"
                inputMode="numeric"
                pattern="[1-9][0-9]*"
                value={draft.entity_id ?? ''}
                onChange={(event) => setDraft((current) => ({ ...current, entity_id: event.target.value }))}
                placeholder="Exact numeric ID"
              />
            </FilterField>
            <FilterField label="Request ID" htmlFor="audit-request-id">
              <Input
                id="audit-request-id"
                value={draft.request_id ?? ''}
                onChange={(event) => setDraft((current) => ({ ...current, request_id: event.target.value }))}
                placeholder="32-char ID or UUID"
                className="font-mono text-xs"
              />
            </FilterField>
          </div>
        )}
        <div className="mt-4 flex flex-wrap justify-end gap-2 border-t pt-3">
          <Button type="button" variant="ghost" onClick={clearFilters}>
            <X /> Clear all
          </Button>
          <Button type="submit">
            <Search /> Apply filters
          </Button>
        </div>
      </form>

      <div>
        <section aria-labelledby="audit-results-heading" className="min-w-0 space-y-3">
          <div className="flex items-center justify-between gap-3">
            <div>
              <h2 id="audit-results-heading" className="text-sm font-semibold text-foreground">Event stream</h2>
              <p className="text-xs text-muted-foreground">Newest event first.</p>
            </div>
            {events.isFetching && !events.isLoading && <Loader2 className="size-4 animate-spin text-muted-foreground" aria-label="Refreshing events" />}
          </div>

          <div className="hidden overflow-hidden border bg-card md:block">
            <Table>
              <TableHeader className="bg-muted/50">
                {table.getHeaderGroups().map((group) => (
                  <TableRow key={group.id}>
                    {group.headers.map((header) => (
                      <TableHead key={header.id} className="px-3">
                        {flexRender(header.column.columnDef.header, header.getContext())}
                      </TableHead>
                    ))}
                  </TableRow>
                ))}
              </TableHeader>
              <TableBody>
                {events.isLoading && (
                  <TableRow>
                    <TableCell colSpan={columns.length} className="h-28 text-center text-muted-foreground">
                      <Loader2 className="mx-auto mb-2 size-4 animate-spin" /> Loading evidence
                    </TableCell>
                  </TableRow>
                )}
                {events.isError && !events.isLoading && (
                  <QueryErrorRow colSpan={columns.length} message="Failed to load audit evidence." onRetry={() => void events.refetch()} pending={events.isFetching} />
                )}
                {!events.isLoading && !events.isError && table.getRowModel().rows.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={columns.length} className="h-28 text-center text-muted-foreground">
                      <FileSearch className="mx-auto mb-2 size-5" /> No events match these filters.
                    </TableCell>
                  </TableRow>
                )}
                {table.getRowModel().rows.map((row) => (
                  <TableRow key={row.id} data-state={selectedId === row.original.id ? 'selected' : undefined}>
                    {row.getVisibleCells().map((cell) => (
                      <TableCell key={cell.id} className="px-3 align-top">
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>

          {!events.isLoading && !events.isError && (
            <MobileCardList>
              {(events.data?.data ?? []).map((event) => (
                <MobileCard key={event.id} aria-label={`Audit event ${String(event.id)}`}>
                  <MobileCardField label="Occurred"><span className="text-xs">{fmtUtcToApp(event.occurred_at ?? event.committed_at)}</span></MobileCardField>
                  <MobileCardField label="Action"><Badge variant="info">{event.action_code}</Badge></MobileCardField>
                  <MobileCardField label="Entity"><EntityLabel event={event} /></MobileCardField>
                  <MobileCardField label="Actor"><ActorLabel actor={event.actor} /></MobileCardField>
                  <MobileCardField label="Request"><ShortCode value={event.request_id} /></MobileCardField>
                  <MobileCardActions>
                    <Button type="button" size="sm" variant="outline" onClick={() => selectEvent(event.id)}>
                      <Eye /> Inspect
                    </Button>
                  </MobileCardActions>
                </MobileCard>
              ))}
            </MobileCardList>
          )}

          <nav className="flex items-center justify-between gap-3" aria-label="Audit pagination">
            <p className="text-xs text-muted-foreground">
              Page {history.length} · {table.getRowModel().rows.length} events
            </p>
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={previousPage} disabled={history.length < 2}>
                <ChevronLeft /> Previous
              </Button>
              <Button type="button" variant="outline" onClick={nextPage} disabled={events.data?.next == null}>
                Next <ChevronRight />
              </Button>
            </div>
          </nav>
        </section>

      </div>

      <EventDetailDialog
        id={selectedId}
        detail={detail}
        onClose={() => selectEvent(null)}
      />
    </main>
  );
}

function FilterField({ label, htmlFor, className, children }: { label: string; htmlFor: string; className?: string; children: React.ReactNode }) {
  return (
    <div className={className}>
      <Label htmlFor={htmlFor} className="mb-1.5 block text-xs">{label}</Label>
      {children}
    </div>
  );
}

function ActorLabel({ actor }: { actor: AuditActor | null }) {
  if (actor === null) return <span className="text-xs text-muted-foreground">System process</span>;
  const primary = actor.email ?? actor.display_name ?? `User ${String(actor.id)}`;
  const initial = primary.slice(0, 1).toUpperCase();
  return (
    <span className="flex min-w-0 items-center gap-2">
      <span className="grid size-7 shrink-0 place-items-center rounded-full bg-muted text-[11px] font-semibold text-muted-foreground" aria-hidden>{initial}</span>
      <span className="min-w-0">
        <span className="block max-w-44 truncate text-xs font-medium text-foreground" title={primary}>{primary}</span>
        {actor.display_name !== null && actor.email !== null && <span className="block text-[11px] text-muted-foreground">{actor.display_name}</span>}
      </span>
    </span>
  );
}

function EntityLabel({ event }: { event: AuditEvent }) {
  return (
    <span className="text-xs text-foreground">
      <span className="block font-medium">{event.entity_type}</span>
      <span className="text-muted-foreground">ID {event.entity_id ?? 'not assigned'}</span>
    </span>
  );
}

function ShortCode({ value }: { value: string | null }) {
  if (value === null) return <span className="text-xs text-muted-foreground">Not captured</span>;
  return <span className="font-mono text-[11px] text-muted-foreground" title={value}>{value.slice(0, 10)}…</span>;
}

function VerificationStatus({ verification }: { verification: ReturnType<typeof useVerifyAuditChain> }) {
  if (verification.isError) {
    return (
      <div role="alert" className="flex items-start gap-2 border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
        Chain verification could not be completed. Try again or inspect the server logs.
      </div>
    );
  }
  if (verification.data === undefined) return null;
  if (verification.data.ok) {
    return (
      <div aria-live="polite" className="flex items-start gap-2 border border-emerald-500/40 bg-emerald-500/5 p-3 text-sm text-emerald-800 dark:text-emerald-300">
        <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
        <span><strong>Chain verified.</strong> {verification.data.checked} events checked through event {verification.data.verified_up_to ?? 'genesis'}.</span>
      </div>
    );
  }
  return (
    <div role="alert" className="flex items-start gap-2 border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
      <TriangleAlert className="mt-0.5 size-4 shrink-0" />
      <span><strong>Chain divergence detected.</strong> First affected event: {verification.data.first_divergence?.id ?? 'unknown'}.</span>
    </div>
  );
}

function EventDetailDialog({ id, detail, onClose }: { id: number | null; detail: ReturnType<typeof useAuditEvent>; onClose: () => void }) {
  const event = detail.data;
  return (
    <Dialog open={id !== null} onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContent className="grid-rows-[auto_minmax(0,1fr)] gap-0 overflow-hidden p-0 sm:max-h-[90dvh] sm:max-w-2xl sm:pb-0">
        <DialogHeader className="border-b px-5 py-4 pr-12 text-left">
          <DialogTitle>Evidence record {id}</DialogTitle>
          <DialogDescription>Immutable event metadata and redacted context.</DialogDescription>
        </DialogHeader>
        <div className="min-h-0 overflow-y-auto">
          {detail.isLoading && (
            <div className="grid min-h-64 place-items-center text-sm text-muted-foreground">
              <span className="flex items-center gap-2"><Loader2 className="size-5 animate-spin" /> Loading evidence record</span>
            </div>
          )}
          {detail.isError && (
            <div className="p-5 text-sm text-destructive" role="alert">
              Event detail could not be loaded.
              <Button type="button" variant="outline" size="sm" className="mt-3 w-full" onClick={() => void detail.refetch()}>Retry</Button>
            </div>
          )}
          {event !== undefined && (
            <div className="space-y-5 p-5">
              <div className="flex flex-wrap items-center gap-2">
                <Badge variant="info">{event.action_code}</Badge>
                <span className="text-xs text-muted-foreground">{fmtUtcToApp(event.occurred_at ?? event.committed_at)}</span>
              </div>
              <dl className="grid grid-cols-[110px_minmax(0,1fr)] gap-x-3 gap-y-3 text-xs">
                <dt className="text-muted-foreground">Entity</dt><dd className="break-all text-foreground">{event.entity_type} / {event.entity_id ?? 'not assigned'}</dd>
                <dt className="text-muted-foreground">Actor</dt><dd><ActorLabel actor={event.actor} /></dd>
                <dt className="text-muted-foreground">Previous event</dt><dd className="font-mono text-foreground">{event.prev_id ?? 'genesis'}</dd>
                <dt className="text-muted-foreground">Request ID</dt><dd><CopyableCode value={event.request_id} /></dd>
                <dt className="text-muted-foreground">Commit hash</dt><dd><CopyableCode value={event.commit_hash} /></dd>
              </dl>
              <div>
                <div className="mb-2 flex items-center justify-between gap-2 border-t pt-4">
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Redacted payload</h3>
                  <Button type="button" size="sm" variant="ghost" onClick={() => copyText(JSON.stringify(event.payload, null, 2), 'Payload copied')}>
                    <Clipboard /> Copy
                  </Button>
                </div>
                <pre className="max-h-[45dvh] overflow-auto border bg-muted/40 p-3 font-mono text-[11px] leading-5 text-foreground">
                  {JSON.stringify(event.payload, null, 2)}
                </pre>
              </div>
            </div>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}

function CopyableCode({ value }: { value: string | null }) {
  if (value === null) return <span className="text-muted-foreground">Not captured</span>;
  return (
    <span className="flex min-w-0 items-start gap-1">
      <code className="min-w-0 break-all text-[11px] text-foreground">{value}</code>
      <button type="button" className="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring" onClick={() => copyText(value, 'Value copied')} aria-label="Copy value">
        <Clipboard className="size-3.5" />
      </button>
    </span>
  );
}

function copyText(value: string, successMessage: string): void {
  void navigator.clipboard.writeText(value).then(
    () => toast.success(successMessage),
    () => toast.error('Copy failed. Select the value and copy it manually.'),
  );
}
