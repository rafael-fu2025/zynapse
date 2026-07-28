/**
 * ClinicPage — encounters list + create + vitals + close.
 *
 * shadcn Tabs split the screen into "Open" / "Closed". Encounter list
 * is keyset-paginated (shadcn Table). Vitals capture happens in a
 * shadcn Dialog with full RHF + Zod validation. Closed encounters show
 * a close-timestamp.
 */
import { zodResolver } from '@hookform/resolvers/zod';
import {
  CheckCheck,
  ChevronLeft,
  ChevronRight,
  ClipboardPlus,
  ListPlus,
  Loader2,
  Megaphone,
  Play,
  Plus,
  SkipForward,
  Sparkles,
  Stethoscope,
  Trash2,
  Upload,
  X,
} from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
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
import { Textarea } from '@/components/ui/textarea';
import {
  useAddTreatment,
  useCloseEncounter,
  useCreateEncounter,
  useDecideTriage,
  useEncounters,
  useImportEncounters,
  useRecordVitals,
  useSetAssessment,
  useSuggestTriage,
  useTreatments,
} from '@/hooks/useClinic';
import { useMedicines } from '@/hooks/useMedicines';
import { useCallNext, useEnqueue, useQueueToday, useQueueTransition } from '@/hooks/useQueue';
import {
  useArchiveStaffSchedule,
  useCreateStaffSchedule,
  useStaffSchedules,
} from '@/hooks/useStaffSchedules';
import {
  createEncounterSchema,
  recordVitalsSchema,
  TREATMENT_TYPES,
  TRIAGE_PRIORITIES,
  type CreateEncounterInput,
  type Encounter,
  type RecordVitalsInput,
  type TreatmentType,
  type TriagePriority,
} from '@/schemas/clinic';
import { DAY_NAMES } from '@/schemas/schedule';
import {
  createStaffScheduleSchema,
  SCHEDULE_TYPES,
  type ScheduleType,
} from '@/schemas/staffSchedule';
import { fmtUtcToApp } from '@/utils/date';

const TRIAGE_VARIANT: Record<TriagePriority, 'secondary' | 'info' | 'warning' | 'destructive'> = {
  low: 'secondary',
  medium: 'info',
  high: 'warning',
  urgent: 'destructive',
};

const TREATMENT_LABEL: Record<TreatmentType, string> = {
  medication: 'Medication',
  first_aid: 'First aid',
  procedure: 'Procedure',
  referral: 'Referral',
  other: 'Other',
};

function CreateEncounterDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateEncounter();
  const {
    register,
    handleSubmit,
    formState: { errors },
    reset,
  } = useForm<CreateEncounterInput>({ resolver: zodResolver(createEncounterSchema) });

  const onSubmit = handleSubmit((values) => {
    create.mutate(values, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>New encounter</DialogTitle>
      </DialogHeader>
      <form onSubmit={(e) => void onSubmit(e)} className="space-y-3" noValidate>
        <div className="space-y-1.5">
          <Label htmlFor="patient_school_id">Patient school ID</Label>
          <Input id="patient_school_id" aria-invalid={errors.patient_school_id !== undefined} {...register('patient_school_id')} />
          {errors.patient_school_id !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.patient_school_id.message}</p>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="chief_complaint">Chief complaint</Label>
          <Input id="chief_complaint" aria-invalid={errors.chief_complaint !== undefined} {...register('chief_complaint')} />
          {errors.chief_complaint !== undefined && (
            <p role="alert" className="text-xs text-destructive">{errors.chief_complaint.message}</p>
          )}
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={create.isPending}>
            {create.isPending && <Loader2 className="animate-spin" />} Create
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

/**
 * ImportEncountersDialog — bulk CSV upload. Mirrors the legacy
 * `ClinicController::importEncounters` endpoint:
 *   POST /clinic/encounters/import
 *   Content-Type: text/csv
 *   Body: patient_school_id,chief_complaint\n...
 * Cap is 500 rows per request; the backend is all-or-nothing and
 * returns the per-row error list in the envelope on any failure.
 *
 * The dialog lets the user either paste CSV text or pick a `.csv`
 * file. The first 5 lines are previewed so they can sanity-check
 * the header before sending.
 */
function ImportEncountersDialog({ onClose }: { onClose: () => void }) {
  const importEncounters = useImportEncounters();
  const [csv, setCsv] = useState<string>(
    'patient_school_id,chief_complaint\n2021-11111,Fever and cough\n',
  );
  const [fileName, setFileName] = useState<string | null>(null);
  const [errors, setErrors] = useState<string[]>([]);

  async function onPickFile(file: File) {
    setFileName(file.name);
    setErrors([]);
    setCsv(await file.text());
  }

  const previewLines = csv.split(/\r\n|\r|\n/).filter((l) => l.trim() !== '').slice(0, 5);
  const dataRowCount = Math.max(0, csv.split(/\r\n|\r|\n/).filter((l) => l.trim() !== '').length - 1);

  function submit() {
    setErrors([]);
    importEncounters.mutate(
      { csv },
      {
        onSuccess: () => onClose(),
        onError: (err) => {
          setErrors(err.errors.map((e) => e.message));
        },
      },
    );
  }

  return (
    <DialogContent className="max-w-2xl">
      <DialogHeader>
        <DialogTitle>Bulk import — encounters</DialogTitle>
      </DialogHeader>
      <div className="space-y-3">
        <p className="text-xs text-muted-foreground">
          CSV with header <code className="font-mono">patient_school_id,chief_complaint</code>.
          All-or-nothing: any invalid row rejects the entire batch (capped at 500 rows).
        </p>

        <div className="flex items-center gap-2">
          <label className="flex items-center gap-1.5 rounded-md border border-dashed px-3 py-1.5 text-xs cursor-pointer hover:bg-muted/50">
            <Upload className="size-3.5" />
            {fileName ?? 'Choose .csv file'}
            <input
              type="file"
              accept=".csv,text/csv"
              className="hidden"
              onChange={(e) => {
                const f = e.target.files?.[0];
                if (f !== undefined) void onPickFile(f);
              }}
            />
          </label>
          <span className="text-xs text-muted-foreground">{dataRowCount} data row(s)</span>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="csv" className="text-xs">CSV body (editable)</Label>
          <Textarea
            id="csv"
            rows={6}
            className="font-mono text-xs"
            value={csv}
            onChange={(e) => { setCsv(e.target.value); setFileName(null); setErrors([]); }}
          />
        </div>

        {previewLines.length > 0 && (
          <div className="space-y-1">
            <p className="text-xs text-muted-foreground">Preview (first {previewLines.length} lines)</p>
            <pre className="overflow-auto rounded-md border bg-muted/30 p-2 text-[11px] font-mono leading-snug">
              {previewLines.join('\n')}
            </pre>
          </div>
        )}

        {errors.length > 0 && (
          <div className="space-y-1 rounded-md border border-destructive/30 bg-destructive/5 p-2">
            <p className="text-xs font-semibold text-destructive">
              {errors.length} validation error{errors.length === 1 ? '' : 's'} — fix the CSV and retry:
            </p>
            <ul className="list-disc pl-5 text-xs text-destructive">
              {errors.slice(0, 10).map((m, i) => (<li key={i}>{m}</li>))}
              {errors.length > 10 && (<li>…and {errors.length - 10} more</li>)}
            </ul>
          </div>
        )}
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Cancel</Button>
        <Button onClick={submit} disabled={importEncounters.isPending || dataRowCount === 0}>
          {importEncounters.isPending && <Loader2 className="animate-spin" />}
          <Upload /> Import {dataRowCount > 0 ? `(${dataRowCount})` : ''}
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

function VitalsDialog({ encounter, onClose }: { encounter: Encounter; onClose: () => void }) {
  const record = useRecordVitals();
  const {
    register,
    handleSubmit,
    formState: { errors },
    reset,
  } = useForm<RecordVitalsInput>({ resolver: zodResolver(recordVitalsSchema) });

  const onSubmit = handleSubmit((values) => {
    record.mutate(
      { encounterId: encounter.id, input: values },
      {
        onSuccess: () => {
          reset();
          onClose();
          toast.success(`Vitals for encounter #${encounter.id} recorded.`);
        },
      },
    );
  });

  return (
    <DialogContent>
      <DialogHeader>
        <DialogTitle>Vitals — encounter #{encounter.id}</DialogTitle>
      </DialogHeader>
      <form onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-2 gap-3" noValidate>
        <div className="space-y-1.5">
          <Label htmlFor="bp_systolic" className="text-xs">Systolic (mmHg)</Label>
          <Input id="bp_systolic" type="number" {...register('bp_systolic', { valueAsNumber: true })} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="bp_diastolic" className="text-xs">Diastolic (mmHg)</Label>
          <Input id="bp_diastolic" type="number" {...register('bp_diastolic', { valueAsNumber: true })} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="pulse_bpm" className="text-xs">Pulse (bpm)</Label>
          <Input id="pulse_bpm" type="number" {...register('pulse_bpm', { valueAsNumber: true })} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="temp_c" className="text-xs">Temp (°C)</Label>
          <Input id="temp_c" type="number" step={0.1} {...register('temp_c', { valueAsNumber: true })} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="spo2_pct" className="text-xs">SpO2 (%)</Label>
          <Input id="spo2_pct" type="number" {...register('spo2_pct', { valueAsNumber: true })} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="weight_kg" className="text-xs">Weight (kg)</Label>
          <Input id="weight_kg" type="number" step={0.1} {...register('weight_kg', { valueAsNumber: true })} />
        </div>
        <div className="col-span-2 space-y-1.5">
          <Label htmlFor="height_cm" className="text-xs">Height (cm)</Label>
          <Input id="height_cm" type="number" step={0.1} {...register('height_cm', { valueAsNumber: true })} />
          {Object.keys(errors).length > 0 && (
            <p role="alert" className="text-xs text-destructive">
              One or more fields are out of range.
            </p>
          )}
        </div>
        <DialogFooter className="col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={record.isPending}>
            {record.isPending && <Loader2 className="animate-spin" />} Record
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}

function CareDialog({ encounter, onClose }: { encounter: Encounter; onClose: () => void }) {
  const setAssessment = useSetAssessment();
  const addTreatment = useAddTreatment();
  const treatments = useTreatments(encounter.id);
  const medicines = useMedicines(null, 100);
  const suggestTriage = useSuggestTriage();
  const decideTriage = useDecideTriage();

  const [priority, setPriority] = useState<TriagePriority | ''>(encounter.triage_priority ?? '');
  const [diagnosis, setDiagnosis] = useState<string>(encounter.diagnosis ?? '');
  const [suggestion, setSuggestion] = useState<{ id: number; predicted_priority: TriagePriority; confidence_score: number } | null>(null);

  const [tType, setTType] = useState<TreatmentType>('first_aid');
  const [tDesc, setTDesc] = useState('');
  const [medId, setMedId] = useState<string>('');
  const [qty, setQty] = useState<string>('');

  function saveAssessment() {
    setAssessment.mutate({
      encounterId: encounter.id,
      input: {
        ...(priority !== '' ? { triage_priority: priority } : {}),
        diagnosis,
      },
    });
  }

  function submitTreatment() {
    if (tDesc.trim() === '') {
      toast.error('Description is required.');
      return;
    }
    if (tType === 'medication' && (medId === '' || qty === '')) {
      toast.error('Medicine and quantity are required for a medication treatment.');
      return;
    }
    addTreatment.mutate(
      {
        encounterId: encounter.id,
        input: {
          treatment_type: tType,
          description: tDesc.trim(),
          ...(tType === 'medication' ? { medicine_id: Number(medId), quantity: Number(qty) } : {}),
        },
      },
      {
        onSuccess: () => {
          setTDesc('');
          setMedId('');
          setQty('');
        },
      },
    );
  }

  return (
    <DialogContent className="max-w-2xl">
      <DialogHeader>
        <DialogTitle>Care — encounter #{encounter.id}</DialogTitle>
      </DialogHeader>

      <section className="space-y-3 rounded-md border p-3">
        <div className="flex items-center justify-between">
          <p className="text-sm font-semibold text-foreground">Assessment</p>
          <Button
            size="sm"
            variant="outline"
            onClick={() => suggestTriage.mutate(encounter.id, { onSuccess: (s) => setSuggestion({ id: s.id, predicted_priority: s.predicted_priority, confidence_score: s.confidence_score }) })}
            disabled={suggestTriage.isPending}
          >
            {suggestTriage.isPending ? <Loader2 className="animate-spin" /> : <Sparkles />} Suggest priority
          </Button>
        </div>
        {suggestion !== null && (
          <div className="flex items-center justify-between rounded-md border border-dashed p-2 text-sm">
            <span className="flex items-center gap-2">
              Suggested:
              <Badge variant={TRIAGE_VARIANT[suggestion.predicted_priority]}>{suggestion.predicted_priority}</Badge>
              <span className="text-xs text-muted-foreground">{Math.round(suggestion.confidence_score * 100)}% confidence</span>
            </span>
            <span className="flex gap-1">
              <Button
                size="sm"
                variant="secondary"
                disabled={decideTriage.isPending}
                onClick={() =>
                  decideTriage.mutate(
                    { predictionId: suggestion.id, decision: 'accepted' },
                    { onSuccess: () => { setPriority(suggestion.predicted_priority); setSuggestion(null); } },
                  )
                }
              >
                Accept
              </Button>
              <Button
                size="sm"
                variant="outline"
                disabled={decideTriage.isPending || priority === ''}
                onClick={() =>
                  priority !== '' &&
                  decideTriage.mutate(
                    { predictionId: suggestion.id, decision: 'overridden', staff_priority: priority },
                    { onSuccess: () => setSuggestion(null) },
                  )
                }
              >
                Override with “{priority || '—'}”
              </Button>
            </span>
          </div>
        )}
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label id="care-triage-label" className="text-xs">Triage priority</Label>
            <Select {...(priority !== '' ? { value: priority } : {})} onValueChange={(v) => setPriority(v as TriagePriority)}>
              <SelectTrigger aria-labelledby="care-triage-label"><SelectValue placeholder="Select…" /></SelectTrigger>
              <SelectContent>
                {TRIAGE_PRIORITIES.map((p) => (
                  <SelectItem key={p} value={p}>{p}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="care-dx" className="text-xs">Diagnosis</Label>
          <Textarea id="care-dx" rows={2} maxLength={5000} value={diagnosis} onChange={(e) => setDiagnosis(e.target.value)} />
        </div>
        <div className="flex justify-end">
          <Button size="sm" onClick={saveAssessment} disabled={setAssessment.isPending}>
            {setAssessment.isPending && <Loader2 className="animate-spin" />} Save assessment
          </Button>
        </div>
      </section>

      <section className="space-y-3 rounded-md border p-3">
        <p className="text-sm font-semibold text-foreground">Treatments</p>
        <div className="max-h-40 space-y-1.5 overflow-auto">
          {treatments.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
          {!treatments.isLoading && (treatments.data?.length ?? 0) === 0 && (
            <p className="text-sm text-muted-foreground">No treatments yet.</p>
          )}
          {treatments.data?.map((t) => (
            <div key={t.id} className="flex items-center justify-between rounded border px-2 py-1 text-xs">
              <span>
                <Badge variant="secondary">{TREATMENT_LABEL[t.treatment_type]}</Badge>
                <span className="ml-2">{t.description}</span>
              </span>
              {t.quantity_used !== null && (
                <span className="font-mono text-muted-foreground">
                  {t.quantity_used} {t.unit ?? ''} {t.medicine_name ?? ''}
                </span>
              )}
            </div>
          ))}
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label id="care-ttype-label" className="text-xs">Type</Label>
            <Select value={tType} onValueChange={(v) => setTType(v as TreatmentType)}>
              <SelectTrigger aria-labelledby="care-ttype-label"><SelectValue /></SelectTrigger>
              <SelectContent>
                {TREATMENT_TYPES.map((t) => (
                  <SelectItem key={t} value={t}>{TREATMENT_LABEL[t]}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          {tType === 'medication' && (
            <>
              <div className="space-y-1.5">
                <Label id="care-med-label" className="text-xs">Medicine</Label>
                <Select value={medId} onValueChange={setMedId}>
                  <SelectTrigger aria-labelledby="care-med-label"><SelectValue placeholder="Select…" /></SelectTrigger>
                  <SelectContent>
                    {(medicines.data?.data ?? []).map((m) => (
                      <SelectItem key={m.id} value={String(m.id)}>
                        {m.generic_name} — {m.quantity_on_hand} {m.unit}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="care-qty" className="text-xs">Quantity</Label>
                <Input id="care-qty" type="number" min={1} value={qty} onChange={(e) => setQty(e.target.value)} />
              </div>
            </>
          )}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="care-tdesc" className="text-xs">Description</Label>
          <Input id="care-tdesc" value={tDesc} onChange={(e) => setTDesc(e.target.value)} />
        </div>
        <div className="flex justify-end">
          <Button size="sm" variant="secondary" onClick={submitTreatment} disabled={addTreatment.isPending}>
            {addTreatment.isPending && <Loader2 className="animate-spin" />} Add treatment
          </Button>
        </div>
      </section>

      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
      </DialogFooter>
    </DialogContent>
  );
}

const QUEUE_STATUS_VARIANT = {
  waiting: 'info',
  called: 'warning',
  in_session: 'default',
  done: 'success',
  skipped: 'secondary',
} as const;

function QueueTab() {
  const queue = useQueueToday();
  const callNext = useCallNext();
  const transition = useQueueTransition();

  const rows = queue.data ?? [];
  const hasWaiting = rows.some((q) => q.status === 'waiting');

  return (
    <div className="space-y-4">
      <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3">
        <p className="text-xs text-muted-foreground">
          Today's walk-in queue. The public board at <span className="font-mono">/queue-display</span> shows
          positions and first names only.
        </p>
        <Button disabled={callNext.isPending || !hasWaiting} onClick={() => callNext.mutate()}>
          {callNext.isPending ? <Loader2 className="animate-spin" /> : <Megaphone />} Call next
        </Button>
      </section>

      <section className="overflow-hidden rounded-xl border bg-card">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">Pos</TableHead>
              <TableHead className="px-3">Patient</TableHead>
              <TableHead className="px-3">Complaint</TableHead>
              <TableHead className="px-3">Status</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {queue.isLoading && (
              <TableRow>
                <TableCell colSpan={5} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!queue.isLoading && rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={5} className="px-3 py-6 text-center text-muted-foreground">
                  Queue is empty — use "Queue" on an open encounter.
                </TableCell>
              </TableRow>
            )}
            {rows.map((q) => (
              <TableRow key={q.id}>
                <TableCell className="px-3 font-mono text-sm font-semibold">{q.position}</TableCell>
                <TableCell className="px-3">
                  {q.display_name}
                  <span className="ml-1.5 font-mono text-xs text-muted-foreground">{q.patient_school_id}</span>
                </TableCell>
                <TableCell className="px-3 text-xs">{q.chief_complaint}</TableCell>
                <TableCell className="px-3">
                  <Badge variant={QUEUE_STATUS_VARIANT[q.status]}>{q.status.replace('_', ' ')}</Badge>
                </TableCell>
                <TableCell className="px-3 text-right">
                  <div className="flex justify-end gap-1">
                    {q.status === 'called' && (
                      <>
                        <Button size="sm" variant="secondary" disabled={transition.isPending}
                          onClick={() => transition.mutate({ id: q.id, action: 'start' })}>
                          <Play /> Start
                        </Button>
                        <Button size="sm" variant="outline" disabled={transition.isPending}
                          onClick={() => transition.mutate({ id: q.id, action: 'skip' })}>
                          <SkipForward /> Skip
                        </Button>
                      </>
                    )}
                    {q.status === 'in_session' && (
                      <Button size="sm" variant="secondary" disabled={transition.isPending}
                        onClick={() => transition.mutate({ id: q.id, action: 'complete' })}>
                        <CheckCheck /> Complete
                      </Button>
                    )}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>
    </div>
  );
}

function StaffSchedulesTab() {
  const schedules = useStaffSchedules();
  const create = useCreateStaffSchedule();
  const archive = useArchiveStaffSchedule();
  const [userId, setUserId] = useState('');
  const [dow, setDow] = useState('1');
  const [start, setStart] = useState('09:00');
  const [end, setEnd] = useState('17:00');
  const [type, setType] = useState<ScheduleType>('regular');

  function submit() {
    const parsed = createStaffScheduleSchema.safeParse({
      user_id: userId,
      day_of_week: dow,
      shift_start: start,
      shift_end: end,
      schedule_type: type,
    });
    if (!parsed.success) {
      toast.error(parsed.error.issues[0]?.message ?? 'Invalid input.');
      return;
    }
    create.mutate(parsed.data, { onSuccess: () => setUserId('') });
  }

  return (
    <div className="space-y-4">
      <section className="flex flex-wrap items-end gap-2 rounded-xl border bg-card p-3">
        <div className="space-y-1">
          <Label htmlFor="ss-user" className="text-xs">User ID</Label>
          <Input id="ss-user" className="h-8 w-24" value={userId} onChange={(e) => setUserId(e.target.value)} />
        </div>
        <div className="space-y-1">
          <Label id="ss-dow-label" className="text-xs">Day</Label>
          <Select value={dow} onValueChange={setDow}>
            <SelectTrigger aria-labelledby="ss-dow-label" className="h-8 w-32"><SelectValue /></SelectTrigger>
            <SelectContent>{DAY_NAMES.map((n, i) => <SelectItem key={n} value={String(i)}>{n}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        <div className="space-y-1">
          <Label htmlFor="ss-start" className="text-xs">Start</Label>
          <Input id="ss-start" type="time" className="h-8 w-28" value={start} onChange={(e) => setStart(e.target.value)} />
        </div>
        <div className="space-y-1">
          <Label htmlFor="ss-end" className="text-xs">End</Label>
          <Input id="ss-end" type="time" className="h-8 w-28" value={end} onChange={(e) => setEnd(e.target.value)} />
        </div>
        <div className="space-y-1">
          <Label id="ss-type-label" className="text-xs">Type</Label>
          <Select value={type} onValueChange={(v) => setType(v as ScheduleType)}>
            <SelectTrigger aria-labelledby="ss-type-label" className="h-8 w-28"><SelectValue /></SelectTrigger>
            <SelectContent>{SCHEDULE_TYPES.map((t) => <SelectItem key={t} value={t}>{t}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        <Button size="sm" onClick={submit} disabled={create.isPending}>
          {create.isPending ? <Loader2 className="animate-spin" /> : <Plus />} Add shift
        </Button>
      </section>

      <section className="overflow-hidden rounded-xl border bg-card">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">User</TableHead>
              <TableHead className="px-3">Day</TableHead>
              <TableHead className="px-3">Shift</TableHead>
              <TableHead className="px-3">Type</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {schedules.isLoading && (
              <TableRow>
                <TableCell colSpan={5} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!schedules.isLoading && (schedules.data?.length ?? 0) === 0 && (
              <TableRow>
                <TableCell colSpan={5} className="px-3 py-6 text-center text-muted-foreground">
                  No staff shifts yet.
                </TableCell>
              </TableRow>
            )}
            {schedules.data?.map((s) => (
              <TableRow key={s.id}>
                <TableCell className="px-3 font-mono text-xs">#{s.user_id}</TableCell>
                <TableCell className="px-3 text-xs">{DAY_NAMES[s.day_of_week]}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{s.shift_start.slice(0, 5)}–{s.shift_end.slice(0, 5)}</TableCell>
                <TableCell className="px-3">
                  <Badge variant={s.schedule_type === 'leave' ? 'warning' : s.schedule_type === 'on_call' ? 'info' : 'secondary'}>
                    {s.schedule_type}
                  </Badge>
                </TableCell>
                <TableCell className="px-3 text-right">
                  <Button
                    size="sm"
                    variant="outline"
                    aria-label={`Archive shift #${s.id}`}
                    disabled={archive.isPending}
                    onClick={() => archive.mutate(s.id)}
                  >
                    <Trash2 /> Archive
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>
    </div>
  );
}

export default function ClinicPage() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  const [openCreate, setOpenCreate] = useState(false);
  const [openImport, setOpenImport] = useState(false);
  const [openVitals, setOpenVitals] = useState<Encounter | null>(null);
  const [openCare, setOpenCare] = useState<Encounter | null>(null);
  const list = useEncounters(cursor, 25);
  const close = useCloseEncounter();
  const enqueue = useEnqueue();

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

  const open: Encounter[] = (list.data?.data ?? []).filter((e) => e.status === 'Open');
  const closed: Encounter[] = (list.data?.data ?? []).filter((e) => e.status === 'Closed');

  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Clinic</h1>
          <p className="text-sm text-muted-foreground">Encounters are isolated from counselling.</p>
        </div>
        <div className="flex items-center gap-2">
          <Dialog open={openImport} onOpenChange={setOpenImport}>
            <Button variant="outline" onClick={() => setOpenImport(true)}>
              <Upload /> Bulk import
            </Button>
            {openImport && <ImportEncountersDialog onClose={() => setOpenImport(false)} />}
          </Dialog>
          <Dialog open={openCreate} onOpenChange={setOpenCreate}>
            <Button onClick={() => setOpenCreate(true)}>
              <Plus /> New encounter
            </Button>
            {openCreate && <CreateEncounterDialog onClose={() => setOpenCreate(false)} />}
          </Dialog>
        </div>
      </header>

      <Tabs defaultValue="open">
        <TabsList>
          <TabsTrigger value="open">Open ({open.length})</TabsTrigger>
          <TabsTrigger value="closed">Closed ({closed.length})</TabsTrigger>
          <TabsTrigger value="queue">Queue (today)</TabsTrigger>
          <TabsTrigger value="staff">Staff schedules</TabsTrigger>
        </TabsList>

        <TabsContent value="open">
          <EncounterTable
            rows={open}
            isLoading={list.isLoading}
            page={history.length}
            onPrev={prevPage}
            onNext={nextPage}
            canPrev={history.length > 1}
            canNext={list.data?.next !== null && list.data?.next !== undefined}
            actions={(e) => (
              <div className="flex justify-end gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={enqueue.isPending}
                  onClick={() => enqueue.mutate(e.id)}
                >
                  <ListPlus /> Queue
                </Button>
                <Button size="sm" variant="secondary" onClick={() => setOpenVitals(e)}>
                  <Stethoscope /> Vitals
                </Button>
                <Button size="sm" variant="secondary" onClick={() => setOpenCare(e)}>
                  <ClipboardPlus /> Care
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={close.isPending}
                  onClick={() => close.mutate(e.id)}
                >
                  <X /> Close
                </Button>
              </div>
            )}
          />
        </TabsContent>

        <TabsContent value="closed">
          <EncounterTable
            rows={closed}
            isLoading={list.isLoading}
            page={history.length}
            onPrev={prevPage}
            onNext={nextPage}
            canPrev={history.length > 1}
            canNext={list.data?.next !== null && list.data?.next !== undefined}
            actions={() => null}
          />
        </TabsContent>

        <TabsContent value="queue">
          <QueueTab />
        </TabsContent>

        <TabsContent value="staff">
          <StaffSchedulesTab />
        </TabsContent>
      </Tabs>

      {openVitals !== null && (
        <Dialog open onOpenChange={(o) => !o && setOpenVitals(null)}>
          <VitalsDialog encounter={openVitals} onClose={() => setOpenVitals(null)} />
        </Dialog>
      )}

      {openCare !== null && (
        <Dialog open onOpenChange={(o) => !o && setOpenCare(null)}>
          <CareDialog encounter={openCare} onClose={() => setOpenCare(null)} />
        </Dialog>
      )}
    </main>
  );
}

interface EncounterTableProps {
  rows: Encounter[];
  isLoading: boolean;
  page: number;
  canPrev: boolean;
  canNext: boolean;
  onPrev: () => void;
  onNext: () => void;
  actions: (e: Encounter) => React.ReactNode;
}

function EncounterTable(props: EncounterTableProps) {
  return (
    <>
      <section className="overflow-hidden rounded-xl border bg-card">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">#</TableHead>
              <TableHead className="px-3">Patient</TableHead>
              <TableHead className="px-3">Chief complaint</TableHead>
              <TableHead className="px-3">Started</TableHead>
              <TableHead className="px-3">Closed</TableHead>
              <TableHead className="px-3 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {props.isLoading && (
              <TableRow>
                <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-4 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!props.isLoading && props.rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                  No encounters.
                </TableCell>
              </TableRow>
            )}
            {props.rows.map((e) => (
              <TableRow key={e.id}>
                <TableCell className="px-3 font-mono text-xs">{e.id}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{e.patient_school_id}</TableCell>
                <TableCell className="px-3">
                  {e.chief_complaint}
                  {(e.triage_priority ?? null) !== null && (
                    <Badge variant={TRIAGE_VARIANT[e.triage_priority as TriagePriority]} className="ml-2">
                      {e.triage_priority}
                    </Badge>
                  )}
                </TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">{fmtUtcToApp(e.started_at)}</TableCell>
                <TableCell className="px-3 font-mono text-xs text-muted-foreground">
                  {e.closed_at === null ? <Badge variant="info">Open</Badge> : fmtUtcToApp(e.closed_at)}
                </TableCell>
                <TableCell className="px-3 text-right">{props.actions(e)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>
      <nav className="mt-4 flex items-center justify-between" aria-label="pagination">
        <p className="text-xs text-muted-foreground">Page {props.page}</p>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={props.onPrev} disabled={!props.canPrev}>
            <ChevronLeft /> Prev
          </Button>
          <Button variant="outline" size="sm" onClick={props.onNext} disabled={!props.canNext}>
            Next <ChevronRight />
          </Button>
        </div>
      </nav>
    </>
  );
}
