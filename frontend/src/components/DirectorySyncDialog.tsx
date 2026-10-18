import { useEffect, useState } from 'react';
import { Loader2, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import {
  directorySyncActive, useDirectorySyncJob, useDirectorySyncLatest, useSyncDirectory,
  type DirectorySyncJob, type SyncDirectoryInput,
} from '@/hooks/useAdminUsers';

const KIND_LABELS = { all: 'All students & employees', student: 'Students', employee: 'Employees' };

function elapsed(job: DirectorySyncJob, now: number): string {
  // API timestamps are UTC; SQL timestamps do not carry a zone suffix.
  const timestamp = (value: string) => Date.parse(/[zZ]|[+-]\d\d:\d\d$/.test(value) ? value : `${value.replace(' ', 'T')}Z`);
  const start = timestamp(job.started_at ?? job.created_at);
  const end = job.finished_at ? timestamp(job.finished_at) : now;
  if (!Number.isFinite(start) || !Number.isFinite(end)) return 'Unavailable';
  const seconds = Math.max(0, Math.floor((end - start) / 1000));
  return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

function JobResults({ job }: { job: DirectorySyncJob }) {
  const [now, setNow] = useState(Date.now);
  useEffect(() => {
    if (!directorySyncActive(job)) return;
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [job]);
  const changed = Object.values(job.kinds).some((counts) => counts.created > 0 || counts.updated > 0);
  const title = job.status === 'completed' ? (job.dry_run ? 'Preview complete' : 'Sync complete')
    : job.status === 'failed' ? (!job.dry_run && changed ? 'Sync partially applied — failed' : 'Sync failed')
    : job.status === 'interrupted' ? 'Sync interrupted'
    : job.status === 'queued' ? 'Queued — waiting for worker' : 'Running';
  return (
    <section aria-label="Sync job results" className="min-w-0 space-y-3 rounded-lg border p-3 text-sm">
      <div role="status" aria-live="polite" className="space-y-1">
        <p className="flex items-center gap-2 font-medium">{directorySyncActive(job) && <Loader2 className="size-4 shrink-0 animate-spin" aria-hidden />}{title}</p>
        <p className="text-xs text-muted-foreground">Job #{job.id} · {job.dry_run ? 'Preview' : 'Apply'} · {KIND_LABELS[job.kind]}</p>
        <p className="text-xs">Elapsed: {elapsed(job, now)}</p>
        {job.status !== 'queued' && <p className="text-xs">
          {job.current_kind ? `${KIND_LABELS[job.current_kind]} · ` : ''}Page {job.current_page}
          {job.total_pages === null ? ' · total pages unknown' : ` of ${job.total_pages}`}
        </p>}
      </div>
      {job.error && <p role="alert" className="break-words text-destructive">{job.error.message} ({job.error.code})</p>}
      {job.dry_run ? <p className="text-xs text-muted-foreground">Preview only. No local changes are made; created and updated counts are proposed changes.</p>
        : !directorySyncActive(job) && <p className="text-xs text-muted-foreground">{changed ? 'Committed changes are retained, including when a later page fails or the job is interrupted.' : 'No created or updated records were reported.'}</p>}
      {Object.keys(job.kinds).length > 0 && (
        <div role="region" aria-label="Directory sync counts (scroll horizontally)" tabIndex={0} className="max-w-full overflow-x-auto rounded border focus-visible:outline focus-visible:outline-2 focus-visible:outline-ring">
          <table className="w-full min-w-[620px] text-left text-xs">
            <caption className="p-2 text-left text-muted-foreground">{job.dry_run ? 'Preview counts for processed pages' : 'Committed counts for processed pages'}. Fetched counts include skipped and duplicate entries.</caption>
            <thead><tr>{['Kind', 'Pages', 'Fetched', 'Created', 'Updated', 'Unchanged', 'Skipped', 'Duplicates'].map((label) => <th key={label} scope="col" className="p-2 font-medium">{label}</th>)}</tr></thead>
            <tbody>{Object.entries(job.kinds).map(([kind, counts]) => <tr key={kind} className="border-t">
              <th scope="row" className="p-2 capitalize">{kind}</th>
              <td className="p-2">{counts.pages}{counts.total_pages != null ? ` / ${counts.total_pages}` : ' / unknown'}</td>
              {[counts.fetched, counts.created, counts.updated, counts.unchanged, counts.skipped, counts.duplicates].map((count, index) => <td key={index} className="p-2">{count}</td>)}
            </tr>)}</tbody>
          </table>
        </div>
      )}
    </section>
  );
}

/** Always mounted by Users; monitoring survives closing the dialog. */
export function DirectorySyncDialog() {
  const [open, setOpen] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [kind, setKind] = useState<SyncDirectoryInput['kind']>('all');
  const latest = useDirectorySyncLatest();
  const sync = useSyncDirectory();
  const status = useDirectorySyncJob(latest.data?.id);
  const job = status.data ?? latest.data;
  const unreconciledSubmit = sync.isError && latest.dataUpdatedAt <= sync.submittedAt;
  const unknown = latest.isPending || latest.isError || status.isError || unreconciledSubmit;
  const locked = unknown || latest.isFetching || status.isPending && latest.data != null || sync.isPending || directorySyncActive(job);

  function recheck() {
    void latest.refetch();
    if (latest.data) void status.refetch();
  }
  function changeOpen(value: boolean) {
    setOpen(value);
    if (value) recheck();
    else setConfirmOpen(false);
  }
  function submit(dryRun: boolean) {
    if (locked) return;
    sync.mutate({ kind, dryRun });
  }

  return <>
    <Button variant="outline" onClick={() => changeOpen(true)}><RefreshCw aria-hidden /> Sync from MIS</Button>
    <Dialog open={open} onOpenChange={changeOpen}>
      <DialogContent size="xl" className="flex max-h-[90dvh] min-w-0 flex-col gap-3 overflow-hidden p-4 sm:max-h-[85dvh] sm:p-5">
        <DialogHeader className="shrink-0 pr-6">
          <DialogTitle>Sync from MIS</DialogTitle>
          <DialogDescription>Preview or apply university directory profiles in the background.</DialogDescription>
        </DialogHeader>
        <div role="region" aria-label="MIS sync details" tabIndex={0} className="min-h-0 min-w-0 space-y-4 overflow-y-auto overscroll-contain pr-1">
          <p className="text-xs text-muted-foreground">Nothing is deleted or removed from existing roles. Archived and deactivated accounts are skipped. Closing this dialog does not cancel a queued or running job.</p>
          <div className="space-y-2">
            <Label htmlFor="mis-sync-kind">Directory kind</Label>
            <Select value={kind} disabled={locked} onValueChange={(value) => setKind(value as SyncDirectoryInput['kind'])}>
              <SelectTrigger id="mis-sync-kind" className="w-full"><SelectValue /></SelectTrigger>
              <SelectContent>{Object.entries(KIND_LABELS).map(([value, label]) => <SelectItem key={value} value={value}>{label}</SelectItem>)}</SelectContent>
            </Select>
          </div>
          {sync.isError && <p role="alert" className="break-words text-sm text-destructive">The submission could not be verified. {sync.error.message} {unreconciledSubmit || latest.isError ? 'Checking the latest job before allowing another submission.' : 'Latest job status has been recovered; review the results below before submitting again.'}</p>}
          {(latest.isError || status.isError) && <div role="alert" className="space-y-2 rounded border border-destructive p-3 text-sm">
            <p className="break-words">Job status is unknown. {latest.error?.message ?? status.error?.message} Last known results are shown below. Retrying automatically; submissions remain disabled until status is recovered.</p>
            <Button variant="outline" size="sm" onClick={recheck}>Recheck status</Button>
          </div>}
          {latest.isPending && <p role="status" className="text-sm">Checking latest job…</p>}
          {sync.isPending && <p role="status" className="text-sm">Submitting job…</p>}
          {job && <JobResults job={job} />}
          {latest.data === null && !latest.isError && <p className="text-sm text-muted-foreground">No previous sync job.</p>}
          <p className="text-xs text-muted-foreground">Queued jobs wait for the campus-side sync worker. The worker’s server must be able to reach MIS on the campus network; your browser does not need campus connectivity.</p>
          <p className="text-xs text-muted-foreground">Preview and Apply each process the selected directory. Apply is not limited to the pages in a previous preview.</p>
        </div>
        <DialogFooter className="shrink-0 gap-2 border-t pt-3 sm:space-x-0">
          <Button variant="outline" disabled={locked} onClick={() => submit(true)}>Preview</Button>
          <Button disabled={locked} onClick={() => setConfirmOpen(true)}>Apply sync</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
    <ConfirmDialog open={confirmOpen} title="Apply directory sync?" description={`Apply ${KIND_LABELS[kind].toLowerCase()} from MIS? This queues account creation and profile updates. Existing roles are retained and nothing is deleted. Preview first to review proposed changes.`} confirmLabel="Confirm apply" destructive={false} onCancel={() => setConfirmOpen(false)} onConfirm={() => { setConfirmOpen(false); submit(false); }} />
  </>;
}
