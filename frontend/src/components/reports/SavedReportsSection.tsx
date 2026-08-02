import {
  Archive,
  ArchiveRestore,
  ChevronLeft,
  ChevronRight,
  Download,
  Loader2,
  Pencil,
  Play,
  Plus,
  Save,
  X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { QueryErrorState } from '@/components/QueryErrorState';
import { Badge, type BadgeProps } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
  useArchiveReportConfig,
  useCreateReportConfig,
  useDownloadGeneratedReport,
  useGeneratedReports,
  useReportConfigs,
  useRunReportConfig,
  useUnarchiveReportConfig,
  useUpdateReportConfig,
} from '@/hooks/useReports';
import {
  REPORT_MODULES,
  reportModuleSchema,
  type GeneratedReport,
  type ReportConfig,
  type ReportModule,
} from '@/schemas/reports';
import { fmtUtcToApp } from '@/utils/date';

interface Props {
  start: string;
  end: string;
  canConfigure: boolean;
  canExport: boolean;
}

function moduleLabel(module: ReportModule): string {
  return module.charAt(0).toUpperCase() + module.slice(1);
}

function Pager({
  page,
  totalPages,
  total,
  onChange,
}: {
  page: number;
  totalPages: number;
  total: number;
  onChange: (page: number) => void;
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-2 border-t px-4 py-3 text-xs text-muted-foreground">
      <span>{total} total</span>
      <div className="flex items-center gap-2">
        <Button size="icon-sm" variant="outline" aria-label="Previous page" disabled={page <= 1} onClick={() => onChange(page - 1)}>
          <ChevronLeft />
        </Button>
        <span className="tabular-nums">Page {page} of {totalPages}</span>
        <Button size="icon-sm" variant="outline" aria-label="Next page" disabled={page >= totalPages} onClick={() => onChange(page + 1)}>
          <ChevronRight />
        </Button>
      </div>
    </div>
  );
}

function statusVariant(status: GeneratedReport['status']): BadgeProps['variant'] {
  if (status === 'completed') return 'success';
  if (status === 'failed' || status === 'expired') return 'destructive';
  if (status === 'processing') return 'info';
  return 'warning';
}

export function SavedReportsSection({ start, end, canConfigure, canExport }: Props) {
  const [showArchived, setShowArchived] = useState(false);
  const [configPage, setConfigPage] = useState(1);
  const [generatedPage, setGeneratedPage] = useState(1);
  const [configModule, setConfigModule] = useState<ReportModule | 'all'>('all');
  const [generatedModule, setGeneratedModule] = useState<ReportModule | 'all'>('all');
  const [generatedStatus, setGeneratedStatus] = useState<GeneratedReport['status'] | 'all'>('all');
  const configs = useReportConfigs(configPage, showArchived, configModule === 'all' ? undefined : configModule);
  const generated = useGeneratedReports(
    generatedPage,
    generatedModule === 'all' ? undefined : generatedModule,
    generatedStatus === 'all' ? undefined : generatedStatus,
  );
  const create = useCreateReportConfig();
  const update = useUpdateReportConfig();
  const run = useRunReportConfig();
  const archive = useArchiveReportConfig();
  const unarchive = useUnarchiveReportConfig();
  const download = useDownloadGeneratedReport();
  const [editingId, setEditingId] = useState<number | null>(null);
  const [name, setName] = useState('');
  const [module, setModule] = useState<ReportModule>('clinic');
  const [configStart, setConfigStart] = useState(start);
  const [configEnd, setConfigEnd] = useState(end);
  const [summarize, setSummarize] = useState(true);
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);

  useEffect(() => {
    if (editingId === null) {
      setConfigStart(start);
      setConfigEnd(end);
    }
  }, [editingId, start, end]);

  useEffect(() => {
    const totalPages = configs.data?.pagination.total_pages;
    if (totalPages !== undefined && configPage > totalPages) setConfigPage(totalPages);
  }, [configPage, configs.data?.pagination.total_pages]);

  function resetForm(): void {
    setEditingId(null);
    setName('');
    setModule('clinic');
    setConfigStart(start);
    setConfigEnd(end);
    setSummarize(true);
  }

  function beginEdit(config: ReportConfig): void {
    setEditingId(config.id);
    setName(config.name);
    setModule(config.module);
    setConfigStart(config.parameters.start);
    setConfigEnd(config.parameters.end);
    setSummarize(config.parameters.summarize);
  }

  function submit(): void {
    if (name.trim() === '') {
      toast.error('Name is required.');
      return;
    }
    if (configStart === '' || configEnd === '') {
      toast.error('Choose a complete date range.');
      return;
    }
    const input = { name: name.trim(), module, start: configStart, end: configEnd, summarize };
    if (editingId === null) {
      create.mutate(input, { onSuccess: resetForm });
    } else {
      update.mutate({ id: editingId, ...input }, { onSuccess: resetForm });
    }
  }

  const configPending = create.isPending || update.isPending;
  const configItems = configs.data?.items ?? [];
  const generatedItems = generated.data?.items ?? [];

  return (
    <section className="space-y-4" aria-labelledby="saved-reports-heading">
      <div>
        <h2 id="saved-reports-heading" className="text-lg font-semibold text-foreground">Saved and generated reports</h2>
        <p className="text-sm text-muted-foreground">Saved configurations use fixed dates. Generated files are retained for 30 days.</p>
      </div>

      <div className="grid min-w-0 gap-4 xl:grid-cols-2">
        <section className="min-w-0 overflow-hidden rounded-xl border bg-card" aria-labelledby="configurations-heading">
          <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
            <h3 id="configurations-heading" className="text-sm font-semibold">Configurations</h3>
            <div className="flex flex-wrap items-center justify-end gap-2">
              <Select value={configModule} onValueChange={(value) => {
                const parsed = reportModuleSchema.safeParse(value);
                setConfigModule(parsed.success ? parsed.data : 'all');
                setConfigPage(1);
              }}>
                <SelectTrigger className="h-8 w-[145px]" aria-label="Filter configurations by module"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All modules</SelectItem>
                  {REPORT_MODULES.map((item) => <SelectItem key={item} value={item}>{moduleLabel(item)}</SelectItem>)}
                </SelectContent>
              </Select>
              <Button
                size="sm"
                variant={showArchived ? 'secondary' : 'outline'}
                aria-pressed={showArchived}
                onClick={() => {
                  setShowArchived((value) => !value);
                  setConfigPage(1);
                }}
              >
                <Archive /> {showArchived ? 'Hide archived' : 'Show archived'}
              </Button>
            </div>
          </div>

          {canConfigure && (
            <form
              className="grid gap-3 border-b p-4 sm:grid-cols-2"
              onSubmit={(event) => {
                event.preventDefault();
                submit();
              }}
            >
              <div className="space-y-1 sm:col-span-2">
                <p className="text-sm font-medium">{editingId === null ? 'Save this view' : 'Edit configuration'}</p>
                <p className="text-xs text-muted-foreground">The exact range is stored so the report can be reproduced.</p>
              </div>
              <div className="space-y-1">
                <Label htmlFor="report-config-name">Name</Label>
                <Input id="report-config-name" value={name} maxLength={120} onChange={(event) => setName(event.target.value)} />
              </div>
              <div className="space-y-1">
                <Label id="report-config-module-label">Module</Label>
                <Select value={module} onValueChange={(value) => {
                  const parsed = reportModuleSchema.safeParse(value);
                  if (parsed.success) setModule(parsed.data);
                }}>
                  <SelectTrigger aria-labelledby="report-config-module-label"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    {REPORT_MODULES.map((item) => <SelectItem key={item} value={item}>{moduleLabel(item)}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1 sm:col-span-2">
                <Label htmlFor="report-config-range">Fixed date range</Label>
                <DateRangePicker
                  id="report-config-range"
                  start={configStart}
                  end={configEnd}
                  onChange={({ start: nextStart, end: nextEnd }) => {
                    setConfigStart(nextStart);
                    setConfigEnd(nextEnd);
                  }}
                />
              </div>
              <label className="flex min-h-10 items-center gap-2 text-sm">
                <input type="checkbox" className="size-4" checked={summarize} onChange={(event) => setSummarize(event.target.checked)} />
                Include narrative
              </label>
              <div className="flex flex-wrap justify-end gap-2">
                {editingId !== null && (
                  <Button type="button" size="sm" variant="ghost" onClick={resetForm}><X /> Cancel</Button>
                )}
                <Button type="submit" size="sm" disabled={configPending}>
                  {configPending ? <Loader2 className="animate-spin" /> : editingId === null ? <Plus /> : <Save />}
                  {editingId === null ? 'Save' : 'Save changes'}
                </Button>
              </div>
            </form>
          )}

          {configs.isError ? (
            <div className="p-4">
              <QueryErrorState message="Failed to load saved configurations." onRetry={() => void configs.refetch()} pending={configs.isFetching} />
            </div>
          ) : configs.isLoading ? (
            <div className="space-y-3 p-4" role="status">
              <Skeleton className="h-12 w-full" />
              <Skeleton className="h-12 w-full" />
              <span className="sr-only">Loading saved configurations.</span>
            </div>
          ) : configItems.length === 0 ? (
            <p className="px-4 py-10 text-center text-sm text-muted-foreground">
              {canConfigure ? 'Save the current view to create the first reusable report.' : 'No saved configurations are available.'}
            </p>
          ) : (
            <ul className="divide-y">
              {configItems.map((config) => {
                const runPending = run.isPending && run.variables === config.id;
                const archivePending = archive.isPending && archive.variables === config.id;
                const restorePending = unarchive.isPending && unarchive.variables === config.id;
                return (
                  <li key={config.id} className="space-y-3 px-4 py-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="break-words text-sm font-medium">{config.name}</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                          {moduleLabel(config.module)} · {config.parameters.start} to {config.parameters.end}
                          {config.parameters.summarize ? ' · narrative included' : ''}
                        </p>
                      </div>
                      <div className="flex flex-wrap justify-end gap-1">
                        {!config.is_active && <Badge variant="secondary">Archived</Badge>}
                        {canConfigure && config.is_active && (
                          <>
                            <Button size="sm" variant="outline" onClick={() => beginEdit(config)}><Pencil /> Edit</Button>
                            <Button size="sm" variant="outline" disabled={runPending} onClick={() => run.mutate(config.id)}>
                              {runPending ? <Loader2 className="animate-spin" /> : <Play />} Run
                            </Button>
                            <Button
                              size="icon-sm"
                              variant="outline"
                              aria-label={'Archive ' + config.name}
                              disabled={archivePending}
                              onClick={() => setConfirm({
                                title: 'Archive ' + config.name + '?',
                                description: 'The configuration will remain available in archived results.',
                                confirmLabel: 'Archive',
                                run: () => archive.mutate(config.id),
                              })}
                            >
                              {archivePending ? <Loader2 className="animate-spin" /> : <Archive />}
                            </Button>
                          </>
                        )}
                        {canConfigure && !config.is_active && (
                          <Button size="sm" variant="outline" disabled={restorePending} onClick={() => unarchive.mutate(config.id)}>
                            {restorePending ? <Loader2 className="animate-spin" /> : <ArchiveRestore />} Restore
                          </Button>
                        )}
                      </div>
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
          {configs.data !== undefined && (
            <Pager
              page={configs.data.pagination.page}
              totalPages={configs.data.pagination.total_pages}
              total={configs.data.pagination.total}
              onChange={setConfigPage}
            />
          )}
        </section>

        <section className="min-w-0 overflow-hidden rounded-xl border bg-card" aria-labelledby="generated-heading">
          <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
            <h3 id="generated-heading" className="text-sm font-semibold">Generated history</h3>
            <div className="flex flex-wrap items-center justify-end gap-2">
              <Select value={generatedModule} onValueChange={(value) => {
                const parsed = reportModuleSchema.safeParse(value);
                setGeneratedModule(parsed.success ? parsed.data : 'all');
                setGeneratedPage(1);
              }}>
                <SelectTrigger className="h-8 w-[130px]" aria-label="Filter generated reports by module"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All modules</SelectItem>
                  {REPORT_MODULES.map((item) => <SelectItem key={item} value={item}>{moduleLabel(item)}</SelectItem>)}
                </SelectContent>
              </Select>
              <Select value={generatedStatus} onValueChange={(value) => {
                const next = ['queued', 'processing', 'completed', 'failed', 'expired'].includes(value)
                  ? value as GeneratedReport['status']
                  : 'all';
                setGeneratedStatus(next);
                setGeneratedPage(1);
              }}>
                <SelectTrigger className="h-8 w-[125px]" aria-label="Filter generated reports by status"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All statuses</SelectItem>
                  <SelectItem value="queued">Queued</SelectItem>
                  <SelectItem value="processing">Processing</SelectItem>
                  <SelectItem value="completed">Completed</SelectItem>
                  <SelectItem value="failed">Failed</SelectItem>
                  <SelectItem value="expired">Expired</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
          {generated.isError ? (
            <div className="p-4">
              <QueryErrorState message="Failed to load generated reports." onRetry={() => void generated.refetch()} pending={generated.isFetching} />
            </div>
          ) : generated.isLoading ? (
            <div className="space-y-3 p-4" role="status">
              <Skeleton className="h-20 w-full" />
              <Skeleton className="h-20 w-full" />
              <span className="sr-only">Loading generated report history.</span>
            </div>
          ) : generatedItems.length === 0 ? (
            <p className="px-4 py-10 text-center text-sm text-muted-foreground">Run a saved configuration to generate the first retained file.</p>
          ) : (
            <ul className="divide-y">
              {generatedItems.map((item) => {
                const downloading = download.isPending && download.variables === item.id;
                const range = item.parameters_used.range;
                return (
                  <li key={item.id} className="space-y-2 px-4 py-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div>
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-sm font-medium">{moduleLabel(item.module)}</span>
                          <Badge variant={statusVariant(item.status)}>{item.status}</Badge>
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">{range.start} to {range.end} · requested {fmtUtcToApp(item.generated_at)}</p>
                        <p className="text-xs text-muted-foreground">
                          {item.row_count === null ? 'Row count pending' : item.row_count + ' aggregate rows'}
                          {item.expires_at !== null ? ' · retained until ' + fmtUtcToApp(item.expires_at) : ''}
                        </p>
                      </div>
                      {canExport && item.status === 'completed' && (
                        <Button size="sm" variant="outline" disabled={downloading} onClick={() => download.mutate(item.id)}>
                          {downloading ? <Loader2 className="animate-spin" /> : <Download />} CSV
                        </Button>
                      )}
                    </div>
                    {item.error_message !== null && <p role="alert" className="text-xs text-destructive">{item.error_message}</p>}
                    {item.ai_summary !== null && <p className="text-xs leading-relaxed text-muted-foreground">{item.ai_summary}</p>}
                  </li>
                );
              })}
            </ul>
          )}
          {generated.data !== undefined && (
            <Pager
              page={generated.data.pagination.page}
              totalPages={generated.data.pagination.total_pages}
              total={generated.data.pagination.total}
              onChange={setGeneratedPage}
            />
          )}
        </section>
      </div>

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        pending={archive.isPending}
        onConfirm={() => {
          confirm?.run();
          setConfirm(null);
        }}
        onCancel={() => setConfirm(null)}
      />
    </section>
  );
}
