/**
 * ReferralsPage — bridge contract UI.
 *
 * - List referrals with keyset pagination + status filter (shadcn Select).
 * - Create referral (shadcn Dialog + RHF + Zod).
 * - Lifecycle buttons (Acknowledge / Review / Close).
 * - Issue QR (qrcode.react renders the plaintext token; the backend
 *   stores only the HMAC hash).
 * - Verify endpoint (PUBLIC) — minimum-disclosure envelope.
 */
import { zodResolver } from '@hookform/resolvers/zod';
import { Camera, ChevronLeft, ChevronRight, Loader2, Plus, QrCode, ShieldCheck, X } from 'lucide-react';
import { QRCodeCanvas } from 'qrcode.react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { Html5Qrcode } from 'html5-qrcode';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  useAcknowledgeReferral,
  useCloseReferral,
  useCreateReferral,
  useIssueQr,
  useReferrals,
  useReviewReferral,
  useVerifyQr,
} from '@/hooks/useReferrals';
import {
  createReferralSchema,
  type CreateReferralInput,
  type Referral,
  type VerifyResult,
} from '@/schemas/referrals';
import { fmtUtcToApp } from '@/utils/date';

function CreateReferralDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateReferral();
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<CreateReferralInput>({ resolver: zodResolver(createReferralSchema) });

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  const source = watch('source_module');
  const target = watch('target_module');

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>New referral</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-2 gap-3">
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="patient_school_id">Patient school ID</Label>
          <Input id="patient_school_id" aria-invalid={errors.patient_school_id !== undefined} {...register('patient_school_id')} />
          {errors.patient_school_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.patient_school_id.message}</p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label id="source-module-label">Source module</Label>
          <Select value={source ?? ''} onValueChange={(v) => setValue('source_module', v as 'clinic' | 'counselling')}>
            <SelectTrigger aria-labelledby="source-module-label"><SelectValue placeholder="Select…" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="clinic">Clinic</SelectItem>
              <SelectItem value="counselling">Counselling</SelectItem>
            </SelectContent>
          </Select>
          {errors.source_module !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.source_module.message}</p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label id="target-module-label">Target module</Label>
          <Select value={target ?? ''} onValueChange={(v) => setValue('target_module', v as 'clinic' | 'counselling')}>
            <SelectTrigger aria-labelledby="target-module-label"><SelectValue placeholder="Select…" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="clinic">Clinic</SelectItem>
              <SelectItem value="counselling">Counselling</SelectItem>
            </SelectContent>
          </Select>
          {errors.target_module !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.target_module.message}</p>
          )}
        </div>

        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="artifact_type">Artifact type</Label>
          <Input id="artifact_type" aria-invalid={errors.artifact_type !== undefined} {...register('artifact_type')} />
          {errors.artifact_type !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.artifact_type.message}</p>
          )}
        </div>

        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="reason_code">Reason code (optional)</Label>
          <Input id="reason_code" {...register('reason_code')} />
        </div>

        <DialogFooter className="col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Create
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function QrDialog({ referral, onClose }: { referral: Referral; onClose: () => void }) {
  const issue = useIssueQr();
  const [token, setToken] = useState<string | null>(null);
  const [expiresAt, setExpiresAt] = useState<string | null>(null);
  const [artifactType, setArtifactType] = useState<string | null>(null);

  function go() {
    issue.mutate(
      { id: referral.id, ttlSeconds: 3600 },
      {
        onSuccess: (res) => {
          setToken(res.token);
          setExpiresAt(res.expires_at);
          setArtifactType(res.artifact_type);
          toast.success('QR issued. Token shown once.');
        },
      },
    );
  }

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <QrCode className="size-4" /> QR — referral #{referral.id}
        </DialogTitle>
      </DialogHeader>
      {token === null && (
        <div className="space-y-3">
          <p className="text-sm text-muted-foreground">
            Issue a 1-hour QR token. Only the HMAC-SHA256 hash is stored; the
            plaintext token is shown ONCE below.
          </p>
          <Button onClick={go} disabled={issue.isPending}>
            {issue.isPending && <Loader2 className="animate-spin" />} Issue QR
          </Button>
        </div>
      )}
      {token !== null && (
        <div className="flex flex-col items-center gap-3">
          <QRCodeCanvas value={token} size={192} includeMargin />
          <p className="break-all font-mono text-xs text-foreground">{token}</p>
          <p className="text-xs text-muted-foreground">
            Expires {fmtUtcToApp(expiresAt ?? new Date().toISOString())} · artifact {artifactType}
          </p>
        </div>
      )}
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
      </DialogFooter>
    </DialogContent>
  );
}

function ScanDialog({ onClose, onResult }: { onClose: () => void; onResult: (r: VerifyResult) => void }) {
  const ref = useRef<Html5Qrcode | null>(null);
  const [running, setRunning] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const verify = useVerifyQr();

  useEffect(() => {
    const inst = new Html5Qrcode('synapse-qr-reader');
    ref.current = inst;
    setRunning(true);
    inst
      .start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 240, height: 240 } },
        (decoded) => {
          void (async () => {
            try { await inst.stop(); } catch { /* noop */ }
            setRunning(false);
            verify.mutate(decoded, {
              onSuccess: (r) => {
                onResult(r);
                onClose();
              },
              onError: (e) => setErr(e.errors[0]?.message ?? 'Verify failed.'),
            });
          })();
        },
        () => { /* ignore frame errors */ },
      )
      .catch((e: unknown) => {
        setErr((e as Error).message);
        setRunning(false);
      });

    return () => {
      inst.stop().catch(() => undefined).finally(() => inst.clear());
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <Camera className="size-4" /> Scan QR
        </DialogTitle>
      </DialogHeader>
      <div id="synapse-qr-reader" className="rounded-md border" />
      {running && <p className="mt-2 text-xs text-muted-foreground">Point your camera at the QR…</p>}
      {err !== null && <p role="alert" className="mt-2 text-xs text-destructive">{err}</p>}
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
      </DialogFooter>
    </DialogContent>
  );
}

function VerifyResultBadge({ result }: { result: VerifyResult }) {
  const variant =
    result.status === 'Valid' ? 'success'
      : result.status === 'Expired' ? 'warning'
      : 'destructive';
  return (
    <Badge variant={variant}>
      <ShieldCheck className="mr-1 size-3" />
      {result.status} · {result.artifact_type ?? '—'}
      {result.issuer !== null ? ` · issuer=${result.issuer}` : ''}
    </Badge>
  );
}

export default function ReferralsPage() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [openCreate, setOpenCreate] = useState(false);
  const [openQr, setOpenQr] = useState<Referral | null>(null);
  const [openScan, setOpenScan] = useState(false);
  const [verifyResult, setVerifyResult] = useState<VerifyResult | null>(null);

  const list = useReferrals(cursor, statusFilter === 'all' ? null : statusFilter, 25);
  const ack = useAcknowledgeReferral();
  const rev = useReviewReferral();
  const close = useCloseReferral();

  function nextPage() {
    if (list.data?.next !== null && list.data?.next !== undefined) {
      const n = list.data.next;
      setHistory((h) => [...h, n]);
      setCursor(n);
    }
  }
  function prevPage() {
    if (history.length < 2) return;
    const next = history.slice(0, -1);
    setHistory(next);
    setCursor(next[next.length - 1] ?? null);
  }

  const rows = useMemo(() => list.data?.data ?? [], [list.data]);

  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Referrals</h1>
          <p className="text-sm text-muted-foreground">
            Bridge contract between clinic and counselling modules. No SQL joins across them.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={() => setOpenScan(true)}>
            <Camera /> Verify (scan)
          </Button>
          <Button onClick={() => setOpenCreate(true)}>
            <Plus /> New referral
          </Button>
        </div>
      </header>

      <section className="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3">
        <Label htmlFor="status">Status</Label>
        <Select value={statusFilter} onValueChange={(v) => { setStatusFilter(v); setCursor(null); setHistory([null]); }}>
          <SelectTrigger id="status" className="w-48" aria-label="Filter by status"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All</SelectItem>
            <SelectItem value="Submitted">Submitted</SelectItem>
            <SelectItem value="Acknowledged">Acknowledged</SelectItem>
            <SelectItem value="UnderReview">UnderReview</SelectItem>
            <SelectItem value="Closed">Closed</SelectItem>
          </SelectContent>
        </Select>
        {verifyResult !== null && <VerifyResultBadge result={verifyResult} />}
      </section>

      <section className="overflow-hidden rounded-xl border bg-card">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">#</TableHead>
              <TableHead className="px-3">Patient</TableHead>
              <TableHead className="px-3">Source</TableHead>
              <TableHead className="px-3">Target</TableHead>
              <TableHead className="px-3">Artifact</TableHead>
              <TableHead className="px-3">Status</TableHead>
              <TableHead className="px-3">Updated</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.isLoading && (
              <TableRow>
                <TableCell colSpan={8} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!list.isLoading && rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={8} className="px-3 py-6 text-center text-muted-foreground">
                  No referrals.
                </TableCell>
              </TableRow>
            )}
            {rows.map((r) => (
              <TableRow key={r.id}>
                <TableCell className="px-3 font-mono text-xs">{r.id}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{r.patient_school_id}</TableCell>
                <TableCell className="px-3">{r.source_module}</TableCell>
                <TableCell className="px-3">{r.target_module}</TableCell>
                <TableCell className="px-3 text-xs">{r.artifact_type}</TableCell>
                <TableCell className="px-3">
                  <Badge variant={r.status === 'Closed' ? 'success' : r.status === 'UnderReview' ? 'warning' : 'info'}>{r.status}</Badge>
                </TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">{fmtUtcToApp(r.updated_at)}</TableCell>
                <TableCell className="px-3 text-right">
                  <div className="flex justify-end gap-2">
                    {r.status === 'Submitted' && (
                      <Button size="sm" variant="secondary" onClick={() => ack.mutate(r.id)}>Acknowledge</Button>
                    )}
                    {r.status === 'Acknowledged' && (
                      <Button size="sm" variant="secondary" onClick={() => rev.mutate(r.id)}>Review</Button>
                    )}
                    {(r.status === 'UnderReview' || r.status === 'Acknowledged') && (
                      <Button size="sm" variant="outline" onClick={() => setOpenQr(r)}>
                        <QrCode /> QR
                      </Button>
                    )}
                    {r.status !== 'Closed' && (
                      <Button size="sm" variant="outline" onClick={() => close.mutate(r.id)}>
                        <X /> Close
                      </Button>
                    )}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

      <nav className="flex items-center justify-between" aria-label="pagination">
        <p className="text-xs text-muted-foreground">Page {history.length}</p>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={prevPage} disabled={history.length < 2}>
            <ChevronLeft /> Prev
          </Button>
          <Button variant="outline" size="sm" onClick={nextPage} disabled={list.data?.next === null || list.data?.next === undefined}>
            Next <ChevronRight />
          </Button>
        </div>
      </nav>

      {openCreate && (
        <Dialog open onOpenChange={(o) => !o && setOpenCreate(false)}>
          <CreateReferralDialog onClose={() => setOpenCreate(false)} />
        </Dialog>
      )}
      {openQr !== null && (
        <Dialog open onOpenChange={(o) => !o && setOpenQr(null)}>
          <QrDialog referral={openQr} onClose={() => setOpenQr(null)} />
        </Dialog>
      )}
      {openScan && (
        <Dialog open onOpenChange={(o) => !o && setOpenScan(false)}>
          <ScanDialog onClose={() => setOpenScan(false)} onResult={(r) => setVerifyResult(r)} />
        </Dialog>
      )}
    </main>
  );
}
