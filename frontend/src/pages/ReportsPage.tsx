/**
 * ReportsPage — module analytics + audited CSV export (Phase 18,
 * recycled from legacy synapse_ag Reports).
 *
 * A shared [start, end] date range feeds every tab. KPI cards mirror
 * the legacy landing page; each module tab shows its breakdown tables
 * and offers a CSV export (no patient identifiers in export surfaces).
 */
import { Download, Loader2, Play, Plus, Sparkles, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
  useArchiveReportConfig,
  useClinicReport,
  useCounsellingReport,
  useCreateReportConfig,
  useDownloadGeneratedReport,
  useGeneratedReports,
  useInventoryReport,
  useReportConfigs,
  useReportExport,
  useReportNarrative,
  useReportSummary,
  useRunReportConfig,
} from '@/hooks/useReports';
import { REPORT_MODULES, type ReportModule } from '@/schemas/reports';

function BreakdownTable({
  title,
  columns,
  rows,
  loading,
}: {
  title: string;
  columns: string[];
  rows: Array<Array<string | number>>;
  loading: boolean;
}) {
  return (
    <article className="overflow-hidden rounded-xl border bg-card">
      <header className="border-b px-3 py-2 text-sm font-semibold text-foreground">{title}</header>
      <Table>
        <TableHeader className="bg-muted/50">
          <TableRow>
            {columns.map((c) => (
              <TableHead key={c} className="px-3">{c}</TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {loading && (
            <TableRow>
              <TableCell colSpan={columns.length} className="px-3 py-6 text-center text-muted-foreground">
                <Loader2 className="mx-auto size-4 animate-spin" />
              </TableCell>
            </TableRow>
          )}
          {!loading && rows.length === 0 && (
            <TableRow>
              <TableCell colSpan={columns.length} className="px-3 py-6 text-center text-muted-foreground">
                No data in range.
              </TableCell>
            </TableRow>
          )}
          {rows.map((r, i) => (
            <TableRow key={i}>
              {r.map((v, j) => (
                <TableCell key={j} className={`px-3 text-xs ${j === r.length - 1 ? 'font-mono' : ''}`}>
                  {v}
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </article>
  );
}

function KpiCard({ label, value, hint }: { label: string; value: number | string; hint?: string }) {
  return (
    <article className="rounded-xl border bg-card p-4">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-1 text-2xl font-bold text-foreground">{value}</p>
      {hint !== undefined && <p className="text-xs text-muted-foreground">{hint}</p>}
    </article>
  );
}

function SavedReportsSection() {
  const configs = useReportConfigs();
  const generated = useGeneratedReports();
  const create = useCreateReportConfig();
  const run = useRunReportConfig();
  const archive = useArchiveReportConfig();
  const download = useDownloadGeneratedReport();
  const [name, setName] = useState('');
  const [module, setModule] = useState<ReportModule>('clinic');
  const [summarize, setSummarize] = useState(true);

  function save() {
    if (name.trim() === '') {
      toast.error('Name is required.');
      return;
    }
    create.mutate({ name: name.trim(), module, summarize }, { onSuccess: () => setName('') });
  }

  return (
    <section className="space-y-3">
      <h2 className="text-lg font-semibold text-foreground">Saved &amp; generated reports</h2>
      <div className="grid gap-4 lg:grid-cols-2">
        <article className="overflow-hidden rounded-xl border bg-card">
          <header className="border-b px-3 py-2 text-sm font-semibold text-foreground">Configurations</header>
          <div className="flex flex-wrap items-end gap-2 border-b p-3">
            <div className="space-y-1">
              <Label htmlFor="rc-name" className="text-xs">Name</Label>
              <Input id="rc-name" className="h-8 w-40" value={name} onChange={(e) => setName(e.target.value)} />
            </div>
            <div className="space-y-1">
              <Label id="rc-module-label" className="text-xs">Module</Label>
              <Select value={module} onValueChange={(v) => setModule(v as ReportModule)}>
                <SelectTrigger aria-labelledby="rc-module-label" className="h-8 w-32"><SelectValue /></SelectTrigger>
                <SelectContent>{REPORT_MODULES.map((m) => <SelectItem key={m} value={m}>{m}</SelectItem>)}</SelectContent>
              </Select>
            </div>
            <label className="flex items-center gap-1.5 pb-1.5 text-xs">
              <input type="checkbox" className="size-4" checked={summarize} onChange={(e) => setSummarize(e.target.checked)} />
              Narrative
            </label>
            <Button size="sm" onClick={save} disabled={create.isPending}>
              {create.isPending ? <Loader2 className="animate-spin" /> : <Plus />} Save
            </Button>
          </div>
          <ul className="max-h-56 divide-y overflow-auto text-sm">
            {(configs.data ?? []).map((c) => (
              <li key={c.id} className="flex items-center justify-between gap-2 px-3 py-2">
                <span className="truncate">{c.name} <Badge variant="secondary">{c.module}</Badge></span>
                <div className="flex shrink-0 gap-1">
                  <Button size="sm" variant="outline" aria-label={`Run ${c.name}`} disabled={run.isPending} onClick={() => run.mutate(c.id)}>
                    <Play /> Run
                  </Button>
                  <Button size="sm" variant="outline" aria-label={`Archive ${c.name}`} disabled={archive.isPending} onClick={() => archive.mutate(c.id)}>
                    <Trash2 />
                  </Button>
                </div>
              </li>
            ))}
            {(configs.data?.length ?? 0) === 0 && (
              <li className="px-3 py-4 text-center text-muted-foreground">No saved configurations.</li>
            )}
          </ul>
        </article>

        <article className="overflow-hidden rounded-xl border bg-card">
          <header className="border-b px-3 py-2 text-sm font-semibold text-foreground">Generated history</header>
          <Table>
            <TableHeader className="bg-muted/50">
              <TableRow>
                <TableHead className="px-3">Module</TableHead>
                <TableHead className="px-3">Generated</TableHead>
                <TableHead className="px-3">Rows</TableHead>
                <TableHead className="px-3 text-right">File</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {(generated.data ?? []).map((g) => (
                <TableRow key={g.id}>
                  <TableCell className="px-3 text-xs"><Badge variant="secondary">{g.module}</Badge></TableCell>
                  <TableCell className="px-3 font-mono text-xs text-muted-foreground">{g.generated_at}</TableCell>
                  <TableCell className="px-3 text-xs">{g.row_count ?? '—'}</TableCell>
                  <TableCell className="px-3 text-right">
                    <Button size="sm" variant="outline" aria-label={`Download report #${g.id}`} disabled={download.isPending} onClick={() => download.mutate(g.id)}>
                      <Download /> CSV
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
              {(generated.data?.length ?? 0) === 0 && (
                <TableRow>
                  <TableCell colSpan={4} className="px-3 py-4 text-center text-muted-foreground">
                    No generated reports yet.
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </article>
      </div>
    </section>
  );
}

export default function ReportsPage() {
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');

  const summary = useReportSummary(start, end);
  const clinic = useClinicReport(start, end);
  const counselling = useCounsellingReport(start, end);
  const inventory = useInventoryReport(start, end);
  const exporter = useReportExport();
  const narrative = useReportNarrative();
  const [tab, setTab] = useState<ReportModule>('clinic');
  const [narratives, setNarratives] = useState<Partial<Record<ReportModule, string>>>({});

  const range = summary.data?.range;

  function exportCsv(module: ReportModule) {
    exporter.mutate({ module, start, end });
  }

  function generateSummary() {
    narrative.mutate(
      { module: tab, start, end },
      { onSuccess: (n) => setNarratives((s) => ({ ...s, [tab]: n })) },
    );
  }

  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Reports & Analytics</h1>
          <p className="text-sm text-muted-foreground">
            Read-only aggregates{range !== undefined ? ` — ${range.start} to ${range.end}` : ''}. Exports are audited and carry no patient identifiers.
          </p>
        </div>
        <div className="flex items-end gap-2">
          <div className="space-y-1">
            <Label htmlFor="report-start" className="text-xs">Start</Label>
            <Input id="report-start" type="date" value={start} onChange={(e) => setStart(e.target.value)} className="h-8" />
          </div>
          <div className="space-y-1">
            <Label htmlFor="report-end" className="text-xs">End</Label>
            <Input id="report-end" type="date" value={end} onChange={(e) => setEnd(e.target.value)} className="h-8" />
          </div>
        </div>
      </header>

      <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <KpiCard label="Clinic encounters" value={summary.data?.clinic.encounters ?? '—'} hint={`${summary.data?.clinic.checkins ?? 0} kiosk check-ins`} />
        <KpiCard label="Counselling appointments" value={summary.data?.counselling.appointments ?? '—'} hint={`${summary.data?.counselling.sessions ?? 0} sessions opened`} />
        <KpiCard label="Active medicine batches" value={summary.data?.inventory.active_batches ?? '—'} hint={`${summary.data?.inventory.dispensed_qty ?? 0} units dispensed`} />
        <KpiCard label="Referrals created" value={summary.data?.referrals.created ?? '—'} />
      </section>

      <Tabs value={tab} onValueChange={(v) => setTab(v as ReportModule)}>
        <div className="flex items-center justify-between gap-2">
          <TabsList>
            <TabsTrigger value="clinic">Clinic</TabsTrigger>
            <TabsTrigger value="counselling">Counselling</TabsTrigger>
            <TabsTrigger value="inventory">Inventory</TabsTrigger>
          </TabsList>
          <Button size="sm" variant="outline" onClick={generateSummary} disabled={narrative.isPending}>
            {narrative.isPending ? <Loader2 className="animate-spin" /> : <Sparkles />} Generate summary
          </Button>
        </div>

        {narratives[tab] !== undefined && (
          <div className="mt-3 rounded-md border bg-muted/40 p-3 text-sm text-foreground" aria-live="polite">
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              {tab} narrative (template NLG)
            </p>
            {narratives[tab]}
          </div>
        )}

        <TabsContent value="clinic" className="mt-4 space-y-4">
          <div className="flex items-center justify-between">
            <p className="text-sm text-muted-foreground">
              {clinic.data?.total_encounters ?? 0} encounters in range.
            </p>
            <Button size="sm" variant="outline" onClick={() => exportCsv('clinic')} disabled={exporter.isPending}>
              {exporter.isPending ? <Loader2 className="animate-spin" /> : <Download />} Export CSV
            </Button>
          </div>
          <div className="grid gap-4 lg:grid-cols-2">
            <BreakdownTable
              title="Encounters by status"
              columns={['Status', 'Count']}
              loading={clinic.isLoading}
              rows={(clinic.data?.status_breakdown ?? []).map((r) => [r.status, r.cnt])}
            />
            <BreakdownTable
              title="Kiosk check-in outcomes"
              columns={['Outcome', 'Count']}
              loading={clinic.isLoading}
              rows={(clinic.data?.checkin_outcomes ?? []).map((r) => [r.outcome.replace(/_/g, ' '), r.cnt])}
            />
            <BreakdownTable
              title="Top chief complaints"
              columns={['Complaint', 'Count']}
              loading={clinic.isLoading}
              rows={(clinic.data?.top_complaints ?? []).map((r) => [r.chief_complaint, r.cnt])}
            />
            <BreakdownTable
              title="Encounters per day"
              columns={['Day', 'Count']}
              loading={clinic.isLoading}
              rows={(clinic.data?.daily_trend ?? []).map((r) => [r.day, r.cnt])}
            />
          </div>
        </TabsContent>

        <TabsContent value="counselling" className="mt-4 space-y-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <p className="text-sm text-muted-foreground">
                {counselling.data?.total_appointments ?? 0} appointments in range.
              </p>
              <Badge variant={(counselling.data?.no_show_rate ?? 0) > 20 ? 'destructive' : 'secondary'}>
                No-show rate {counselling.data?.no_show_rate ?? 0}%
              </Badge>
            </div>
            <Button size="sm" variant="outline" onClick={() => exportCsv('counselling')} disabled={exporter.isPending}>
              {exporter.isPending ? <Loader2 className="animate-spin" /> : <Download />} Export CSV
            </Button>
          </div>
          <div className="grid gap-4 lg:grid-cols-2">
            <BreakdownTable
              title="Appointments by status"
              columns={['Status', 'Count']}
              loading={counselling.isLoading}
              rows={(counselling.data?.status_breakdown ?? []).map((r) => [r.status.replace(/_/g, '-'), r.cnt])}
            />
            <BreakdownTable
              title="Appointments by type"
              columns={['Type', 'Count']}
              loading={counselling.isLoading}
              rows={(counselling.data?.type_breakdown ?? []).map((r) => [r.type.replace(/_/g, ' '), r.cnt])}
            />
            <BreakdownTable
              title="Appointments per day"
              columns={['Day', 'Count']}
              loading={counselling.isLoading}
              rows={(counselling.data?.daily_trend ?? []).map((r) => [r.day, r.cnt])}
            />
          </div>
        </TabsContent>

        <TabsContent value="inventory" className="mt-4 space-y-4">
          <div className="flex items-center justify-between">
            <p className="text-sm text-muted-foreground">
              {inventory.data?.total_medicines ?? 0} medicines · {inventory.data?.total_dispensed ?? 0} units dispensed in range.
            </p>
            <Button size="sm" variant="outline" onClick={() => exportCsv('inventory')} disabled={exporter.isPending}>
              {exporter.isPending ? <Loader2 className="animate-spin" /> : <Download />} Export CSV
            </Button>
          </div>
          <div className="grid gap-4 lg:grid-cols-2">
            <BreakdownTable
              title="Low stock (≤ reorder threshold)"
              columns={['Medicine', 'On hand', 'Threshold']}
              loading={inventory.isLoading}
              rows={(inventory.data?.low_stock ?? []).map((r) => [
                `${r.generic_name}${r.brand_name !== null ? ` (${r.brand_name})` : ''}`,
                `${r.total_stock} ${r.unit}`,
                r.reorder_threshold,
              ])}
            />
            <BreakdownTable
              title="Expiring within 90 days"
              columns={['Medicine', 'Batch', 'Remaining', 'Expires']}
              loading={inventory.isLoading}
              rows={(inventory.data?.expiring ?? []).map((r) => [
                r.generic_name,
                r.batch_number,
                `${r.quantity_remaining} ${r.unit}`,
                r.expiration_date,
              ])}
            />
            <BreakdownTable
              title="Top dispensed medicines"
              columns={['Medicine', 'Quantity']}
              loading={inventory.isLoading}
              rows={(inventory.data?.top_dispensed ?? []).map((r) => [
                `${r.generic_name}${r.brand_name !== null ? ` (${r.brand_name})` : ''}`,
                `${r.qty} ${r.unit}`,
              ])}
            />
            <BreakdownTable
              title="Dispensing per day"
              columns={['Day', 'Quantity']}
              loading={inventory.isLoading}
              rows={(inventory.data?.dispensing_trend ?? []).map((r) => [r.day, r.qty])}
            />
          </div>
        </TabsContent>
      </Tabs>

      <SavedReportsSection />
    </main>
  );
}
