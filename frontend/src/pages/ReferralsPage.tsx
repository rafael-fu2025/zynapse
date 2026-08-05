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
import { CalendarPlus, Camera, ChevronDown, ChevronLeft, ChevronRight, CircleAlert, ClipboardPaste, Loader2, Plus, QrCode, ShieldCheck, UserRound, X } from 'lucide-react';
import { QRCodeCanvas } from 'qrcode.react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { Html5Qrcode } from 'html5-qrcode';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { CopyButton } from '@/components/CopyButton';
import { QueryErrorRow } from '@/components/QueryErrorState';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { DatePicker } from '@/components/ui/date-picker';
import { TimePicker } from '@/components/ui/time-picker';
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
  useReferralPatientLookup,
  useReferrals,
  useReviewReferral,
  useRevokeReferralQr,
  useVerifyQr,
} from '@/hooks/useReferrals';
import { useAvailability, useBookAppointment } from '@/hooks/useSchedule';
import { useMe } from '@/hooks/useAuth';
import type { KioskLookupResult } from '@/hooks/usePatientLookup';
import { useDebouncedValue } from '@/hooks/useDebouncedValue';
import { bookAppointmentSchema, type BookAppointmentInput } from '@/schemas/schedule';
import {
  createReferralSchema,
  type CreateReferralInput,
  type Referral,
  type VerifyResult,
} from '@/schemas/referrals';
import { fmtUtcToApp } from '@/utils/date';
import { statusLabel } from '@/utils/status';

const REFERRAL_MODULES = ['clinic', 'counselling'] as const;
type ReferralModule = (typeof REFERRAL_MODULES)[number];
const MODULE_LABEL: Record<ReferralModule, string> = {
  clinic: 'Clinic',
  counselling: 'Counselling',
};
// Direction-aware artifact presets (match the contract/seed usage).
const PRESET_ARTIFACT: Record<ReferralModule, string> = {
  clinic: 'intake_pass',       // clinic → counselling hands an intake pass
  counselling: 'referral_letter', // counselling → clinic hands a referral letter
};

/**
 * PatientAutocomplete — typeahead for the referral's patient field.
 * Searching by number OR name (combined student+employee lookup); the
 * submitted value is always the exact school id, but the operator can
 * still type a raw id if the lookup misses. Mirrors the kiosk combobox.
 */
function PatientAutocomplete({
  value,
  onValue,
  onPick,
  error,
}: {
  value: string;
  onValue: (v: string) => void;
  onPick: (p: KioskLookupResult) => void;
  error?: string | undefined;
}) {
  const [open, setOpen] = useState(false);
  const debounced = useDebouncedValue(value, 300);
  const lookup = useReferralPatientLookup(debounced);
  const results = lookup.data ?? [];
  const showList = open && debounced.trim().length >= 2;

  return (
    <div className="relative">
      <Input
        id="patient_school_id"
        autoComplete="off"
        role="combobox"
        aria-expanded={showList}
        aria-controls="referral-patient-suggestions"
        value={value}
        aria-invalid={error !== undefined}
        onChange={(e) => { onValue(e.target.value); setOpen(true); }}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
      />
      {showList && (
        <ul
          id="referral-patient-suggestions"
          role="listbox"
          className="absolute left-0 top-full z-20 mt-1 max-h-72 w-full overflow-auto rounded-xl border bg-popover p-1 shadow-lg"
        >
          {lookup.isError && (
            <li className="flex items-center gap-2 px-3 py-2.5 text-sm text-destructive">
              <CircleAlert className="size-4" /> Couldn't search patients — try typing the ID directly.
            </li>
          )}
          {lookup.isLoading && (
            <li className="flex items-center gap-2 px-3 py-2.5 text-sm text-muted-foreground">
              <Loader2 className="size-4 animate-spin" /> Searching…
            </li>
          )}
          {!lookup.isLoading && !lookup.isError && results.length === 0 && (
            <li className="px-3 py-2.5 text-sm text-muted-foreground">
              No matches — you can still type the ID manually.
            </li>
          )}
          {results.map((p) => (
            <li key={p.id}>
              <button
                type="button"
                role="option"
                aria-selected="false"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => { onPick(p); setOpen(false); }}
                className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left transition-colors hover:bg-accent"
              >
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium">{p.name}</span>
                  <span className="block font-mono text-xs text-muted-foreground">{p.school_id}</span>
                </span>
                <Badge variant={p.kind === 'student' ? 'info' : 'secondary'}>
                  {p.kind === 'student' ? 'Student' : 'Employee'}
                </Badge>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function CreateReferralDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateReferral();
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<CreateReferralInput>({ resolver: zodResolver(createReferralSchema) });
  // Friendly confirmation of the picked patient (cleared on manual edit).
  const [pickedPatient, setPickedPatient] = useState<string | null>(null);

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
  const artifactType = watch('artifact_type');
  // Custom artifact mode (free text) — off by default.
  const [customArtifact, setCustomArtifact] = useState(false);

  // Once the direction is known and the field is still empty, prefill a
  // sensible artifact so non-IT users don't have to guess.
  useEffect(() => {
    if (!customArtifact && source && target && (artifactType === undefined || artifactType === '')) {
      setValue('artifact_type', PRESET_ARTIFACT[source], { shouldValidate: true });
    }
  }, [customArtifact, source, target, artifactType, setValue]);

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>New referral</DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-2 gap-3">
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="patient_school_id">Patient</Label>
          <PatientAutocomplete
            value={watch('patient_school_id') ?? ''}
            onValue={(v) => { setValue('patient_school_id', v, { shouldValidate: true }); setPickedPatient(null); }}
            onPick={(p) => { setValue('patient_school_id', p.school_id, { shouldValidate: true }); setPickedPatient(`${p.name} (${p.kind === 'student' ? 'Student' : 'Employee'})`); }}
            error={errors.patient_school_id?.message}
          />
          {pickedPatient !== null && (
            <p className="text-xs text-emerald-600 dark:text-emerald-400">
              Selected: {pickedPatient}
            </p>
          )}
          {errors.patient_school_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.patient_school_id.message}</p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label id="source-module-label">Source module</Label>
          <Select
            value={source ?? ''}
            onValueChange={(v) => setValue('source_module', v as ReferralModule, { shouldValidate: true })}
          >
            <SelectTrigger aria-labelledby="source-module-label"><SelectValue placeholder="Select…" /></SelectTrigger>
            <SelectContent>
              {REFERRAL_MODULES.filter((m) => m !== target).map((m) => (
                <SelectItem key={m} value={m}>{MODULE_LABEL[m]}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors.source_module !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.source_module.message}</p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label id="target-module-label">Target module</Label>
          <Select
            value={target ?? ''}
            onValueChange={(v) => setValue('target_module', v as ReferralModule, { shouldValidate: true })}
          >
            <SelectTrigger aria-labelledby="target-module-label"><SelectValue placeholder="Select…" /></SelectTrigger>
            <SelectContent>
              {REFERRAL_MODULES.filter((m) => m !== source).map((m) => (
                <SelectItem key={m} value={m}>{MODULE_LABEL[m]}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors.target_module !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.target_module.message}</p>
          )}
        </div>

        <div className="col-span-2 space-y-1.5">
          <Label id="artifact-label">Artifact type</Label>
          {customArtifact ? (
            <div className="flex gap-2">
              <Input
                id="artifact_type"
                autoFocus
                placeholder="e.g. clearance, school_letter"
                value={artifactType ?? ''}
                aria-invalid={errors.artifact_type !== undefined}
                onChange={(e) => setValue('artifact_type', e.target.value, { shouldValidate: true })}
              />
              <Button type="button" variant="outline" onClick={() => {
                setCustomArtifact(false);
                setValue('artifact_type', PRESET_ARTIFACT[source ?? 'clinic'], { shouldValidate: true });
              }}>
                <X /> Use preset
              </Button>
            </div>
          ) : (
            <Select
              value={artifactType === 'intake_pass' || artifactType === 'referral_letter' ? artifactType : ''}
              onValueChange={(v) => {
                if (v === 'custom') {
                  setValue('artifact_type', '', { shouldValidate: false });
                  setCustomArtifact(true);
                } else {
                  setValue('artifact_type', v, { shouldValidate: true });
                }
              }}
            >
              <SelectTrigger id="artifact_type" aria-labelledby="artifact-label"><SelectValue placeholder="Select…" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="intake_pass">Intake pass</SelectItem>
                <SelectItem value="referral_letter">Referral letter</SelectItem>
                <SelectItem value="custom">Custom…</SelectItem>
              </SelectContent>
            </Select>
          )}
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
  const revoke = useRevokeReferralQr();
  const [token, setToken] = useState<string | null>(null);
  const [expiresAt, setExpiresAt] = useState<string | null>(null);
  const [artifactType, setArtifactType] = useState<string | null>(null);
  const [revoked, setRevoked] = useState(false);

  function go() {
    issue.mutate(
      { id: referral.id, ttlSeconds: 3600 },
      {
        onSuccess: (res) => {
          setToken(res.token);
          setExpiresAt(res.expires_at);
          setArtifactType(res.artifact_type);
          setRevoked(false);
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
      {token !== null && !revoked && (
        <div className="flex flex-col items-center gap-3">
          <QRCodeCanvas value={token} size={192} includeMargin />
          <div className="flex items-center gap-2">
            <p className="break-all font-mono text-xs text-foreground">{token}</p>
            <CopyButton value={token} label="Copy QR token" successMessage="QR token copied." />
          </div>
          <p className="text-xs text-muted-foreground">
            Expires {fmtUtcToApp(expiresAt ?? new Date().toISOString())} · artifact {artifactType}
          </p>
        </div>
      )}
      {revoked && (
        <div role="alert" className="flex items-center gap-2 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2.5 text-sm text-destructive">
          <CircleAlert className="size-4 shrink-0" /> This QR token has been revoked and can no longer be verified.
        </div>
      )}
      <DialogFooter>
        <div className="flex w-full justify-end gap-2">
          {token !== null && !revoked && (
            <Button variant="destructive" disabled={revoke.isPending} onClick={() => revoke.mutate(referral.id, { onSuccess: () => setRevoked(true) })}>
              {revoke.isPending && <Loader2 className="animate-spin" />} Revoke QR
            </Button>
          )}
          <Button variant="outline" onClick={onClose}>Close</Button>
        </div>
      </DialogFooter>
    </DialogContent>
  );
}

function ScanDialog({ onClose, onResult }: { onClose: () => void; onResult: (r: VerifyResult) => void }) {
  const instRef = useRef<Html5Qrcode | null>(null);
  // Paste is the default: the camera only starts on an explicit click,
  // which avoids a Radix-portal timing race (html5-qrcode needs its
  // target element present before `start`).
  const [mode, setMode] = useState<'camera' | 'paste'>('paste');
  const [running, setRunning] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [tokenInput, setTokenInput] = useState('');
  const verify = useVerifyQr();

  async function startCamera() {
    try {
      if (document.getElementById('synapse-qr-reader') === null) {
        throw new Error('Camera area is not ready — try again.');
      }
      const inst = new Html5Qrcode('synapse-qr-reader');
      instRef.current = inst;
      setRunning(true);
      await inst.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 240, height: 240 } },
        (decoded) => {
          void (async () => {
            await stopCamera();
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
      );
    } catch (e) {
      setErr((e as Error).message);
      setRunning(false);
    }
  }

  async function stopCamera() {
    const inst = instRef.current;
    instRef.current = null;
    if (inst) {
      try { await inst.stop(); } catch { /* noop */ }
      try { inst.clear(); } catch { /* noop */ }
    }
    setRunning(false);
  }

  // Stop the camera on unmount.
  useEffect(() => () => { void stopCamera(); }, []);

  function verifyToken(raw: string) {
    const token = raw.trim();
    if (token === '') {
      setErr('Paste or type the QR token first.');
      return;
    }
    setErr(null);
    verify.mutate(token, {
      onSuccess: (r) => {
        onResult(r);
        onClose();
      },
      onError: (e) => setErr(e.errors[0]?.message ?? 'Verify failed.'),
    });
  }

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <ShieldCheck className="size-4" /> Verify a referral
        </DialogTitle>
      </DialogHeader>

      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          size="sm"
          variant={mode === 'camera' ? 'default' : 'outline'}
          onClick={() => { setMode('camera'); setErr(null); void startCamera(); }}
        >
          <Camera className="size-3.5" aria-hidden /> Scan with camera
        </Button>
        <Button
          type="button"
          size="sm"
          variant={mode === 'paste' ? 'default' : 'outline'}
          onClick={() => { setMode('paste'); setErr(null); void stopCamera(); }}
        >
          <ClipboardPaste className="size-3.5" aria-hidden /> Paste token
        </Button>
      </div>

      {/* The reader div must stay in the DOM for html5-qrcode to find it
          (hidden only when the user is in paste mode). */}
      <div
        id="synapse-qr-reader"
        className={`rounded-md border ${mode !== 'camera' ? 'hidden' : ''}`}
      />
      {mode === 'camera' && running && (
        <p className="mt-2 text-xs text-muted-foreground">Point your camera at the QR…</p>
      )}

      {mode === 'paste' && (
        <div className="space-y-2">
          <Label htmlFor="verify-token">QR token</Label>
          <Input
            id="verify-token"
            autoFocus
            placeholder="Paste the copied QR token here…"
            value={tokenInput}
            onChange={(e) => setTokenInput(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); verifyToken(tokenInput); } }}
          />
          <Button className="w-full" onClick={() => verifyToken(tokenInput)} disabled={verify.isPending}>
            {verify.isPending && <Loader2 className="animate-spin" aria-hidden />}
            <ShieldCheck aria-hidden /> Verify token
          </Button>
        </div>
      )}

      {err !== null && <p role="alert" className="mt-2 text-xs text-destructive">{err}</p>}

      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
      </DialogFooter>
    </DialogContent>
  );
}

function VerifyResultBadge({ result }: { result: VerifyResult }) {
  const variant =
    result.status === 'valid' ? 'success'
      : result.status === 'expired' ? 'warning'
      : 'destructive';
  return (
    <Badge variant={variant}>
      <ShieldCheck className="mr-1 size-3" />
      {statusLabel(result.status)} · {result.artifact_type ?? '—'}
      {result.issuer !== null ? ` · issuer=${result.issuer}` : ''}
    </Badge>
  );
}

/**
 * ReferralBookingDialog — bridges an accepted clinic→counselling
 * referral into an actual counselling appointment (audit fix: ack-
 * nowledging a referral did NOT create one — the counsellor had to
 * remember to book it manually). Pre-fills the student, the assigned
 * provider, and a `referral_based` type; the slot must still fit the
 * provider's availability + capacity (enforced server-side).
 */
function ReferralBookingDialog({ referral, onClose }: { referral: Referral; onClose: () => void }) {
  const book = useBookAppointment();
  const availability = useAvailability();
  const { register, handleSubmit, formState: { errors }, reset, setValue, watch } =
    useForm<BookAppointmentInput>({
      resolver: zodResolver(bookAppointmentSchema),
      defaultValues: {
        patient_school_id: referral.patient_school_id,
        type: 'referral_based',
        reason: `Referred from ${referral.source_module} — referral #${referral.id}`,
        ...(referral.provider_user_id !== undefined && referral.provider_user_id !== null
          ? { counsellor_user_id: referral.provider_user_id }
          : {}),
      },
    });

  const counsellorId = watch('counsellor_user_id');
  const counsellorIds = [...new Set((availability.data ?? []).map((w) => w.counsellor_user_id))]
    .sort((a, b) => a - b);

  const onSubmit = handleSubmit((values) => {
    book.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <CalendarPlus className="size-4" /> Book counselling — referral #{referral.id}
        </DialogTitle>
      </DialogHeader>
      <form noValidate onSubmit={(e) => void onSubmit(e)} className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="rb-patient">Patient school ID</Label>
          <Input id="rb-patient" value={referral.patient_school_id} disabled />
          <input type="hidden" {...register('patient_school_id')} />
        </div>
        <div className="space-y-1.5">
          <Label id="rb-counsellor-label">Counsellor</Label>
          <Select
            value={counsellorId !== undefined ? String(counsellorId) : ''}
            onValueChange={(v) => setValue('counsellor_user_id', Number(v), { shouldValidate: true, shouldDirty: true })}
          >
            <SelectTrigger aria-labelledby="rb-counsellor-label" aria-invalid={errors.counsellor_user_id !== undefined}>
              <SelectValue placeholder={counsellorIds.length === 0 ? 'No counsellors with availability' : 'Select counsellor'} />
            </SelectTrigger>
            <SelectContent>
              {counsellorIds.map((id) => (
                <SelectItem key={id} value={String(id)}>Counsellor #{id}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors.counsellor_user_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.counsellor_user_id.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="rb-date">Date</Label>
          <DatePicker id="rb-date" aria-invalid={errors.appointment_date !== undefined} value={watch('appointment_date') ?? ''} onChange={(v) => setValue('appointment_date', v, { shouldValidate: true, shouldDirty: true })} />
          {errors.appointment_date !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.appointment_date.message}</p>
          )}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="rb-start">Start time</Label>
            <TimePicker id="rb-start" aria-invalid={errors.start_time !== undefined} value={watch('start_time') ?? ''} onChange={(v) => setValue('start_time', v, { shouldValidate: true, shouldDirty: true })} />
            {errors.start_time !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.start_time.message}</p>
            )}
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="rb-end">End time</Label>
            <TimePicker id="rb-end" aria-invalid={errors.end_time !== undefined} value={watch('end_time') ?? ''} onChange={(v) => setValue('end_time', v, { shouldValidate: true, shouldDirty: true })} />
            {errors.end_time !== undefined && (
              <p role="alert" className="text-xs text-destructive">{errors.end_time.message}</p>
            )}
          </div>
        </div>
        <p className="text-xs text-muted-foreground">
          Type: <span className="font-medium">referral-based</span> · Reason: {watch('reason') ?? ''}
        </p>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={book.isPending}>
            {book.isPending && <Loader2 className="animate-spin" />}
            <CalendarPlus /> Book appointment
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

export default function ReferralsPage() {
  const me = useMe();
  // Non-teaching staff can open the page but cannot create a clinic→
  // counselling referral (server enforces `is_teaching = 1`); show a
  // friendly hint instead of a confusing 403 on submit.
  const isNonTeachingEmployee =
    me.data?.person_kind === 'employee' && me.data?.is_teaching !== true;
  // Referrers (employee group) are scoped server-side to their own
  // referrals; handlers (clinic/counselling staff, admin) see all.
  const isReferrerScoped = me.data?.person_kind === 'employee';
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [openCreate, setOpenCreate] = useState(false);
  const [openQr, setOpenQr] = useState<Referral | null>(null);
  const [openScan, setOpenScan] = useState(false);
  const [verifyResult, setVerifyResult] = useState<VerifyResult | null>(null);
  const [closing, setClosing] = useState<Referral | null>(null);
  const [revoking, setRevoking] = useState<Referral | null>(null);
  // Audit fix: accepted counselling-bound referrals can be turned into
  // a counselling appointment right from this page.
  const [bookingFor, setBookingFor] = useState<Referral | null>(null);

  const revokeQr = useRevokeReferralQr();

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
          {isNonTeachingEmployee ? (
            <span className="inline-flex max-w-xs items-center gap-1.5 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
              <ShieldCheck className="size-3.5 shrink-0" /> Only teaching employees (faculty) can refer students to counselling.
            </span>
          ) : (
            <Button onClick={() => setOpenCreate(true)}>
              <Plus /> New referral
            </Button>
          )}
        </div>
      </header>

      <section className="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3">
        {isReferrerScoped && (
          <Badge variant="secondary" className="gap-1.5">
            <UserRound className="size-3.5" /> Showing your referrals
          </Badge>
        )}
        <Label htmlFor="status">Status</Label>
        <Select value={statusFilter} onValueChange={(v) => { setStatusFilter(v); setCursor(null); setHistory([null]); }}>
          <SelectTrigger id="status" className="w-48" aria-label="Filter by status"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All</SelectItem>
            <SelectItem value="submitted">Submitted</SelectItem>
            <SelectItem value="acknowledged">Acknowledged</SelectItem>
            <SelectItem value="under_review">Under review</SelectItem>
            <SelectItem value="closed">Closed</SelectItem>
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
              <TableHead className="px-3">Provider</TableHead>
              <TableHead className="px-3">Updated</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {list.isLoading && (
              <TableRow>
                <TableCell colSpan={9} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!list.isLoading && rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={9} className="px-3 py-6 text-center text-muted-foreground">
                  No referrals.
                </TableCell>
              </TableRow>
            )}
            {list.isError && !list.isLoading && (
              <QueryErrorRow colSpan={9} message="Failed to load referrals." onRetry={() => void list.refetch()} pending={list.isFetching} />
            )}
            {rows.map((r) => (
              <TableRow key={r.id}>
                <TableCell className="px-3 font-mono text-xs">{r.id}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{r.patient_school_id}</TableCell>
                <TableCell className="px-3">{r.source_module}</TableCell>
                <TableCell className="px-3">{r.target_module}</TableCell>
                <TableCell className="px-3 text-xs">{r.artifact_type}</TableCell>
                <TableCell className="px-3">
                  <Badge variant={r.status === 'closed' ? 'success' : r.status === 'under_review' ? 'warning' : 'info'}>{statusLabel(r.status)}</Badge>
                </TableCell>
                <TableCell className="px-3 text-xs">
                  {r.provider_name !== undefined && r.provider_name !== null ? r.provider_name : <span className="text-muted-foreground">—</span>}
                </TableCell>
                <TableCell className="px-3 text-xs text-muted-foreground">{fmtUtcToApp(r.updated_at)}</TableCell>
                <TableCell className="px-3 text-right">
                  <div className="flex justify-end gap-1">
                    {r.status === 'submitted' && (
                      <Button className="min-h-11" size="sm" variant="secondary" disabled={ack.isPending} onClick={() => ack.mutate(r.id)}>Acknowledge</Button>
                    )}
                    {r.status === 'acknowledged' && (
                      <Button className="min-h-11" size="sm" variant="secondary" disabled={rev.isPending} onClick={() => rev.mutate(r.id)}>Review</Button>
                    )}
                    {r.target_module === 'counselling' && (r.status === 'acknowledged' || r.status === 'under_review') && (
                      <Button className="min-h-11" size="sm" variant="secondary" onClick={() => setBookingFor(r)}>
                        <CalendarPlus /> Book
                      </Button>
                    )}
                    {r.status !== 'closed' && (
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button className="min-h-11" size="sm" variant="outline" aria-label={`Actions for referral #${r.id}`}>
                            Actions <ChevronDown className="size-3.5" aria-hidden />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                          {(r.status === 'under_review' || r.status === 'acknowledged') && (
                            <DropdownMenuItem className="min-h-11" onSelect={() => setOpenQr(r)}>
                              <QrCode /> Issue QR code
                            </DropdownMenuItem>
                          )}
                          {r.qr_expires_at !== null && (r.qr_revoked_at ?? null) === null && (
                            <DropdownMenuItem
                              className="min-h-11 text-destructive focus:text-destructive"
                              disabled={revokeQr.isPending}
                              onSelect={() => setRevoking(r)}
                            >
                              <X /> Revoke QR code
                            </DropdownMenuItem>
                          )}
                          {(r.status === 'under_review' || r.status === 'acknowledged') && <DropdownMenuSeparator />}
                          <DropdownMenuItem
                            className="min-h-11 text-destructive focus:text-destructive"
                            disabled={close.isPending}
                            onSelect={() => setClosing(r)}
                          >
                            <X /> Close referral
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
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
      {bookingFor !== null && (
        <Dialog open onOpenChange={(o) => !o && setBookingFor(null)}>
          <ReferralBookingDialog referral={bookingFor} onClose={() => setBookingFor(null)} />
        </Dialog>
      )}
      {openScan && (
        <Dialog open onOpenChange={(o) => !o && setOpenScan(false)}>
          <ScanDialog onClose={() => setOpenScan(false)} onResult={(r) => setVerifyResult(r)} />
        </Dialog>
      )}

      <ConfirmDialog
        open={closing !== null}
        title={closing !== null ? `Close referral #${closing.id}?` : ''}
        description="Closing is a final state transition and cannot be undone."
        confirmLabel="Close referral"
        pending={close.isPending}
        onConfirm={() => {
          if (closing !== null) close.mutate(closing.id, { onSuccess: () => setClosing(null) });
        }}
        onCancel={() => setClosing(null)}
      />

      <ConfirmDialog
        open={revoking !== null}
        title={revoking !== null ? `Revoke QR for referral #${revoking.id}?` : ''}
        description="The printed QR token becomes invalid immediately and can no longer be verified."
        confirmLabel="Revoke QR"
        pending={revokeQr.isPending}
        onConfirm={() => {
          if (revoking !== null) revokeQr.mutate(revoking.id, { onSuccess: () => setRevoking(null) });
        }}
        onCancel={() => setRevoking(null)}
      />
    </main>
  );
}
