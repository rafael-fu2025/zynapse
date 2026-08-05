/**
 * ClinicPage — queue-first clinic surface (panel revision, August 2026).
 *
 * Tabs: **Queue (today)** → **Closed** → **Staff schedules**. The legacy
 * "Open" tab is gone — every encounter is queued at creation (walk-in)
 * or auto-checked-in at appointment time, so the Queue tab IS the open
 * encounters view. Action buttons (Care, Record vitals, Close encounter,
 * Mark no-show) live on each queue row.
 *
 * Lazy side effects on `/clinic/queue` staff read:
 *   1. Auto-check-in today's `scheduled` appointments
 *   2. Auto-close stale `open` encounters from prior days
 * Both run server-side in `QueueService::today()`; the page does not
 * trigger them directly.
 */
import { zodResolver } from '@hookform/resolvers/zod';
import {
  Archive,
  ArchiveRestore,
  CalendarClock,
  CheckCheck,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ClipboardPlus,
  Loader2,
  Megaphone,
  Pencil,
  Play,
  Plus,
  SkipForward,
  Sparkles,
  Stethoscope,
  Trash2,
  Upload,
  UserX,
  X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useForm } from 'react-hook-form';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog, type ConfirmAction } from '@/components/ConfirmDialog';
import { QueryErrorRow } from '@/components/QueryErrorState';
import { MobileCardList, MobileCard, MobileCardField, MobileCardActions } from '@/components/MobileCardList';
import { PatientIdCell } from '@/components/PatientIdCell';
import { PatientPicker } from '@/components/PatientPicker';
import { useTabParam } from '@/hooks/useTabParam';
import { DatePicker } from '@/components/ui/date-picker';
import { TimePicker } from '@/components/ui/time-picker';
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
  useEncounterNoShow,
  useEncounters,
  useEncounterVitals,
  useImportEncounters,
  useRecordVitals,
  useSetAssessment,
  useSuggestTriage,
  useTreatments,
} from '@/hooks/useClinic';
import { useMedicines } from '@/hooks/useMedicines';
import { useCallNext, useQueueToday, useQueueTransition } from '@/hooks/useQueue';
import {
  useArchiveStaffSchedule,
  useCreateStaffSchedule,
  useStaffSchedules,
  useUnarchiveStaffSchedule,
  useUpdateStaffSchedule,
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
import { type QueueEntry } from '@/schemas/queue';
import { DAY_NAMES } from '@/schemas/schedule';
import {
  createStaffScheduleSchema,
  SCHEDULE_TYPES,
  updateStaffScheduleSchema,
  type ScheduleType,
  type StaffSchedule,
} from '@/schemas/staffSchedule';
import { fmtUtcToApp } from '@/utils/date';
import { statusLabel } from '@/utils/status';

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
    setValue,
    watch,
    formState: { errors },
    reset,
  } = useForm<CreateEncounterInput>({ resolver: zodResolver(createEncounterSchema) });

  const patientId = watch('patient_school_id') ?? '';

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
        <PatientPicker
          value={patientId}
          invalid={errors.patient_school_id !== undefined}
          onChange={(v) => setValue('patient_school_id', v, { shouldValidate: true })}
        />
        {errors.patient_school_id !== undefined && (
          <p role="alert" className="text-xs text-destructive">{errors.patient_school_id.message}</p>
        )}
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
      <form onSubmit={(e) => void onSubmit(e)} className="grid grid-cols-1 gap-3 sm:grid-cols-2" noValidate>
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
        <div className="space-y-1.5 sm:col-span-2">
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
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
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
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
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

/**
 * Read-only review of a finalized encounter (Closed tab). Shows the
 * full record — summary, assessment, vitals history and treatments —
 * with all editing disabled. Terminal states (closed/referred) cannot
 * be modified, but staff still need to inspect them.
 */
function EncounterViewDialog({ encounter, onClose }: { encounter: Encounter; onClose: () => void }) {
  const vitals = useEncounterVitals(encounter.id);
  const treatments = useTreatments(encounter.id);

  return (
    <DialogContent className="max-w-2xl">
      <DialogHeader>
        <DialogTitle>Encounter #{encounter.id}</DialogTitle>
      </DialogHeader>

      <section className="space-y-3 rounded-md border p-3">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant="secondary">{statusLabel(encounter.status)}</Badge>
          {encounter.outcome !== null && encounter.outcome !== undefined && (
            <Badge variant="secondary">{encounter.outcome.replace('_', ' ')}</Badge>
          )}
          {encounter.triage_priority !== null && encounter.triage_priority !== undefined && (
            <Badge variant={TRIAGE_VARIANT[encounter.triage_priority]}>{encounter.triage_priority}</Badge>
          )}
          {(encounter.appointment_id ?? null) !== null && (
            <Badge variant="secondary" className="gap-1">
              <CalendarClock className="size-3" /> From appointment #{encounter.appointment_id}
            </Badge>
          )}
        </div>
        <div className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
          <div>
            <p className="text-xs text-muted-foreground">Patient</p>
            <p className="font-mono">{encounter.patient_school_id}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Chief complaint</p>
            <p>{encounter.chief_complaint}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Started</p>
            <p>{fmtUtcToApp(encounter.started_at)}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Closed</p>
            <p>{encounter.closed_at === null ? '—' : fmtUtcToApp(encounter.closed_at)}</p>
          </div>
          <div className="sm:col-span-2">
            <p className="text-xs text-muted-foreground">Diagnosis</p>
            <p className="whitespace-pre-wrap">{encounter.diagnosis ?? '—'}</p>
          </div>
        </div>
      </section>

      <section className="space-y-3 rounded-md border p-3">
        <p className="text-sm font-semibold text-foreground">Vitals</p>
        {vitals.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
        {!vitals.isLoading && (vitals.data?.length ?? 0) === 0 && (
          <p className="text-sm text-muted-foreground">No vitals recorded.</p>
        )}
        {vitals.data?.map((v) => (
          <div key={v.recorded_at + String(v.encounter_id)} className="grid grid-cols-2 gap-1 rounded border px-2 py-1 text-xs sm:grid-cols-4">
            <span>BP {v.bp_systolic !== null ? `${v.bp_systolic}/${v.bp_diastolic ?? '—'}` : '—'}</span>
            <span>Pulse {v.pulse_bpm ?? '—'}</span>
            <span>Temp {v.temp_c !== null ? `${v.temp_c}°C` : '—'}</span>
            <span>SpO₂ {v.spo2_pct !== null ? `${v.spo2_pct}%` : '—'}</span>
            <span>Wt {v.weight_kg !== null ? `${v.weight_kg}kg` : '—'}</span>
            <span>Ht {v.height_cm !== null ? `${v.height_cm}cm` : '—'}</span>
            <span className="col-span-2 text-muted-foreground">{fmtUtcToApp(v.recorded_at)}</span>
          </div>
        ))}
      </section>

      <section className="space-y-3 rounded-md border p-3">
        <p className="text-sm font-semibold text-foreground">Treatments</p>
        {treatments.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
        {!treatments.isLoading && (treatments.data?.length ?? 0) === 0 && (
          <p className="text-sm text-muted-foreground">No treatments recorded.</p>
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

interface QueueTabProps {
  /** Open the Care dialog for the given encounter. */
  onOpenCare: (encounter: Encounter) => void;
  /** Open the Vitals dialog for the given encounter. */
  onOpenVitals: (encounter: Encounter) => void;
}

function QueueTab({ onOpenCare, onOpenVitals }: QueueTabProps) {
  const queue = useQueueToday();
  const callNext = useCallNext();
  const transition = useQueueTransition();
  const close = useCloseEncounter();
  const noShow = useEncounterNoShow();
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);

  // `queue.data` is a fresh array on every React Query refetch tick,
  // so `queue.data ?? []` would allocate a new reference each render
  // and invalidate the sort memo below. Stabilise `rows` first, then
  // derive the sorted view from the stable reference.
  const rows = useMemo(() => queue.data ?? [], [queue.data]);
  const hasWaiting = rows.some((q) => q.status === 'waiting');

  // Surface active entries first so the operator's eye lands on the
  // work that matters: in-session → called → waiting → done/skipped.
  // Within each group we keep the natural position ASC so the audit
  // trail (e.g. position 8 vs position 1) stays intact — the DB
  // positions don't change, only the display order.
  const sortedRows = useMemo(() => {
    const priority: Record<string, number> = {
      in_session: 0,
      called:     1,
      waiting:    2,
      done:       3,
      skipped:    4,
    };
    return [...rows].sort((a, b) => {
      const pa = priority[a.status] ?? 99;
      const pb = priority[b.status] ?? 99;
      if (pa !== pb) return pa - pb;
      return a.position - b.position;
    });
  }, [rows]);

  /**
   * Synthesise a minimal `Encounter`-shaped object from the queue row
   * so the existing `CareDialog` / `VitalsDialog` (which read only
   * `encounter.id`, `.triage_priority`, `.diagnosis`) can be opened
   * directly from the queue without a second round-trip.
   *
   * `attending_user_id` / `started_at` are placeholders — the dialogs
   * ignore them; full fields populate on the next encounter list refresh.
   */
  function rowEncounter(q: QueueEntry): Encounter {
    return {
      id: q.encounter_id,
      patient_school_id: q.patient_school_id,
      chief_complaint: q.chief_complaint,
      status: q.encounter_status,
      attending_user_id: 0,
      started_at: '',
      closed_at: null,
    };
  }

  // Destructive actions disable whenever a destructive mutation is
  // already in flight; harmless to share across rows because the
  // spinner only renders on the row that triggered it.
  const destructivePending = close.isPending || noShow.isPending;
  const anyPending = transition.isPending || destructivePending;

  return (
    <div className="space-y-4">
      <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3">
        <p className="text-xs text-muted-foreground">
          Today's walk-in queue + open encounters. Auto-checked-in appointments and walk-ins both land here. The
          public board at <span className="font-mono">/queue-display</span> shows positions and first names only.
        </p>
        <Button disabled={callNext.isPending || !hasWaiting} onClick={() => callNext.mutate()}>
          {callNext.isPending ? <Loader2 className="animate-spin" /> : <Megaphone />} Call next
        </Button>
      </section>

      <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
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
                  Queue is empty.
                </TableCell>
              </TableRow>
            )}
            {queue.isError && !queue.isLoading && (
              <QueryErrorRow colSpan={5} message="Failed to load the queue." onRetry={() => void queue.refetch()} pending={queue.isFetching} />
            )}
            {sortedRows.map((q) => {
              const canEncounterAct =
                q.encounter_status === 'open' &&
                (q.status === 'waiting' || q.status === 'called' || q.status === 'in_session');
              const canNoShow = q.encounter_status === 'open';
              const enc = rowEncounter(q);
              return (
                <TableRow key={q.id}>
                  <TableCell className="px-3 font-mono text-sm font-semibold">{q.position}</TableCell>
                  <TableCell className="px-3">
                    {q.display_name}
                    <span className="ml-1.5">
                      <PatientIdCell id={q.patient_school_id} name={q.patient_name} />
                    </span>
                  </TableCell>
                  <TableCell className="px-3 text-xs">{q.chief_complaint}</TableCell>
                  <TableCell className="px-3">
                    <Badge variant={QUEUE_STATUS_VARIANT[q.status]}>{q.status.replace('_', ' ')}</Badge>
                    {q.encounter_outcome !== undefined && q.encounter_outcome !== null && (
                      <Badge variant="secondary" className="ml-1.5">{q.encounter_outcome.replace('_', ' ')}</Badge>
                    )}
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
                            onClick={() => setConfirm({
                              title: `Skip #${q.position} in the queue?`,
                              description: 'Skipped entries are removed from the active queue and cannot be recovered from here.',
                              confirmLabel: 'Skip',
                              run: () => transition.mutate({ id: q.id, action: 'skip' }),
                            })}>
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
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button className="min-h-11" size="sm" variant="outline" aria-label={`Encounter actions for queue #${q.position}`}>
                            Actions <ChevronDown className="size-3.5" aria-hidden />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-52">
                          <DropdownMenuItem className="min-h-11" disabled={anyPending} onSelect={() => onOpenCare(enc)}>
                            <ClipboardPlus /> Care
                          </DropdownMenuItem>
                          <DropdownMenuItem className="min-h-11" disabled={anyPending} onSelect={() => onOpenVitals(enc)}>
                            <Stethoscope /> Record vitals
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            className="min-h-11 text-destructive focus:text-destructive"
                            disabled={!canEncounterAct || destructivePending}
                            onSelect={() => setConfirm({
                              title: `Close encounter #${q.encounter_id}?`,
                              description: 'Closing an encounter is final; it can no longer be edited or have vitals or treatments added.',
                              confirmLabel: 'Close encounter',
                              run: () => close.mutate(q.encounter_id),
                            })}
                          >
                            <X /> Close encounter
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            className="min-h-11 text-destructive focus:text-destructive"
                            disabled={!canNoShow || destructivePending}
                            onSelect={() => setConfirm({
                              title: `Mark encounter #${q.encounter_id} as no-show?`,
                              description: 'This closes the encounter with outcome=no_show, advances any linked appointment to no_show, and queues an in-app notification.',
                              confirmLabel: 'Mark no-show',
                              run: () => noShow.mutate(q.encounter_id),
                            })}
                          >
                            <UserX /> Mark no-show
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </section>

      {/* Mobile: queue cards from the same rows. */}
      {queue.isLoading && (
        <p className="py-6 text-center text-sm text-muted-foreground md:hidden" role="status">
          <Loader2 className="mx-auto size-4 animate-spin" />
        </p>
      )}
      {queue.isError && !queue.isLoading && (
        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-center text-sm text-destructive md:hidden">
          <p>Failed to load the queue.</p>
          <Button variant="outline" size="sm" className="mt-2" onClick={() => void queue.refetch()} disabled={queue.isFetching}>Retry</Button>
        </div>
      )}
      {!queue.isLoading && !queue.isError && rows.length === 0 && (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground md:hidden">Queue is empty.</p>
      )}
      <MobileCardList>
        {sortedRows.map((q) => {
          const canEncounterAct =
            q.encounter_status === 'open' &&
            (q.status === 'waiting' || q.status === 'called' || q.status === 'in_session');
          const canNoShow = q.encounter_status === 'open';
          const enc = rowEncounter(q);
          return (
            <MobileCard key={q.id} aria-label={`Queue position ${q.position}`}>
              <div className="mb-1 flex items-center justify-between gap-2">
                <span className="font-mono text-sm font-semibold text-foreground">#{q.position}</span>
                <div className="flex flex-wrap justify-end gap-1.5">
                  <Badge variant={QUEUE_STATUS_VARIANT[q.status]}>{q.status.replace('_', ' ')}</Badge>
                  {q.encounter_outcome !== undefined && q.encounter_outcome !== null && (
                    <Badge variant="secondary">{q.encounter_outcome.replace('_', ' ')}</Badge>
                  )}
                </div>
              </div>
              <p className="text-sm font-medium text-foreground">{q.display_name}</p>
              <p className="font-mono text-[10px] text-muted-foreground"><PatientIdCell id={q.patient_school_id} name={q.patient_name} /></p>
              <MobileCardField label="Complaint"><span className="text-xs">{q.chief_complaint}</span></MobileCardField>
              <MobileCardActions>
                {q.status === 'called' && (
                  <>
                    <Button size="sm" variant="secondary" disabled={transition.isPending}
                      onClick={() => transition.mutate({ id: q.id, action: 'start' })}>
                      <Play /> Start
                    </Button>
                    <Button size="sm" variant="outline" disabled={transition.isPending}
                      onClick={() => setConfirm({
                        title: `Skip #${q.position} in the queue?`,
                        description: 'Skipped entries are removed from the active queue and cannot be recovered from here.',
                        confirmLabel: 'Skip',
                        run: () => transition.mutate({ id: q.id, action: 'skip' }),
                      })}>
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
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button className="min-h-11" size="sm" variant="outline" aria-label={`Encounter actions for queue #${q.position}`}>
                      Actions <ChevronDown className="size-3.5" aria-hidden />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuItem className="min-h-11" disabled={anyPending} onSelect={() => onOpenCare(enc)}>
                      <ClipboardPlus /> Care
                    </DropdownMenuItem>
                    <DropdownMenuItem className="min-h-11" disabled={anyPending} onSelect={() => onOpenVitals(enc)}>
                      <Stethoscope /> Record vitals
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                      className="min-h-11 text-destructive focus:text-destructive"
                      disabled={!canEncounterAct || destructivePending}
                      onSelect={() => setConfirm({
                        title: `Close encounter #${q.encounter_id}?`,
                        description: 'Closing an encounter is final; it can no longer be edited or have vitals or treatments added.',
                        confirmLabel: 'Close encounter',
                        run: () => close.mutate(q.encounter_id),
                      })}
                    >
                      <X /> Close encounter
                    </DropdownMenuItem>
                    <DropdownMenuItem
                      className="min-h-11 text-destructive focus:text-destructive"
                      disabled={!canNoShow || destructivePending}
                      onSelect={() => setConfirm({
                        title: `Mark encounter #${q.encounter_id} as no-show?`,
                        description: 'This closes the encounter with outcome=no_show, advances any linked appointment to no_show, and queues an in-app notification.',
                        confirmLabel: 'Mark no-show',
                        run: () => noShow.mutate(q.encounter_id),
                      })}
                    >
                      <UserX /> Mark no-show
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </MobileCardActions>
            </MobileCard>
          );
        })}
      </MobileCardList>

      <ConfirmDialog
        open={confirm !== null}
        title={confirm?.title ?? ''}
        description={confirm?.description}
        confirmLabel={confirm?.confirmLabel}
        // Spinner covers whichever mutation the confirm is about to run.
        pending={anyPending}
        onConfirm={() => {
          confirm?.run();
          setConfirm(null);
        }}
        onCancel={() => setConfirm(null)}
      />
    </div>
  );
}

function StaffSchedulesTab({
  showArchived,
  openAddShift,
  onOpenAddShiftChange,
}: {
  showArchived: boolean;
  openAddShift: boolean;
  onOpenAddShiftChange: (open: boolean) => void;
}) {
  const schedules = useStaffSchedules(showArchived);
  const update = useUpdateStaffSchedule();
  const archive = useArchiveStaffSchedule();
  const unarchive = useUnarchiveStaffSchedule();
  const [confirm, setConfirm] = useState<ConfirmAction | null>(null);
  // Edit dialog — the row being edited, or null when closed.
  const [editing, setEditing] = useState<StaffSchedule | null>(null);

  return (
    <div className="space-y-4">
      <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
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
            {schedules.isError && !schedules.isLoading && (
              <QueryErrorRow colSpan={5} message="Failed to load staff schedules." onRetry={() => void schedules.refetch()} pending={schedules.isFetching} />
            )}
            {schedules.data?.map((s) => (
              <TableRow key={s.id}>
                <TableCell className="px-3">
                  {s.user_name ? (
                    <span className="text-sm text-foreground">{s.user_name}</span>
                  ) : (
                    <span className="text-xs text-muted-foreground">#{s.user_id}</span>
                  )}
                  <span className="ml-1.5"><PatientIdCell id={`#${s.user_id}`} name={s.user_name} /></span>
                </TableCell>
                <TableCell className="px-3 text-xs">{DAY_NAMES[s.day_of_week]}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{s.shift_start.slice(0, 5)}–{s.shift_end.slice(0, 5)}</TableCell>
                <TableCell className="px-3">
                  <Badge variant={s.schedule_type === 'leave' ? 'warning' : s.schedule_type === 'on_call' ? 'info' : 'secondary'}>
                    {s.schedule_type}
                  </Badge>
                  {!s.is_active && <Badge variant="secondary" className="ml-1.5">Archived</Badge>}
                  {s.effective_from !== null && (
                    <span className="ml-1.5 font-mono text-[10px] text-muted-foreground">
                      {s.effective_from.slice(0, 10)}{s.effective_to !== null ? ` → ${s.effective_to.slice(0, 10)}` : ' →'}
                    </span>
                  )}
                </TableCell>
                <TableCell className="px-3 text-right">
                  <div className="flex justify-end gap-1">
                    {s.is_active ? (
                      <>
                        <Button
                          size="sm"
                          variant="outline"
                          aria-label={`Edit shift #${s.id}`}
                          disabled={update.isPending}
                          onClick={() => setEditing(s)}
                        >
                          <Pencil /> Edit
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          aria-label={`Archive shift #${s.id}`}
                          disabled={archive.isPending}
                          onClick={() => setConfirm({
                            title: `Archive shift #${s.id}?`,
                            description: 'The staff shift will be archived and removed from the schedule.',
                            confirmLabel: 'Archive',
                            run: () => archive.mutate(s.id),
                          })}
                        >
                          <Trash2 /> Archive
                        </Button>
                      </>
                    ) : (
                      <Button
                        size="sm"
                        variant="outline"
                        aria-label={`Restore shift #${s.id}`}
                        disabled={unarchive.isPending}
                        onClick={() => unarchive.mutate(s.id)}
                      >
                        <ArchiveRestore /> Restore
                      </Button>
                    )}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

      {/* Mobile: staff-shift cards from the same rows. */}
      {schedules.isLoading && (
        <p className="py-6 text-center text-sm text-muted-foreground md:hidden" role="status">
          <Loader2 className="mx-auto size-4 animate-spin" />
        </p>
      )}
      {schedules.isError && !schedules.isLoading && (
        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-center text-sm text-destructive md:hidden">
          <p>Failed to load staff schedules.</p>
          <Button variant="outline" size="sm" className="mt-2" onClick={() => void schedules.refetch()} disabled={schedules.isFetching}>Retry</Button>
        </div>
      )}
      {!schedules.isLoading && !schedules.isError && (schedules.data?.length ?? 0) === 0 && (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground md:hidden">No staff shifts yet.</p>
      )}
      <MobileCardList>
        {schedules.data?.map((s) => (
          <MobileCard key={s.id} aria-label={`Shift ${s.id}`}>
            <div className="mb-1 flex items-center justify-between gap-2">
              <span className="text-sm font-medium text-foreground">{s.user_name ?? `#${s.user_id}`}</span>
              <div className="flex flex-wrap justify-end gap-1.5">
                <Badge variant={s.schedule_type === 'leave' ? 'warning' : s.schedule_type === 'on_call' ? 'info' : 'secondary'}>{s.schedule_type}</Badge>
                {!s.is_active && <Badge variant="secondary">Archived</Badge>}
              </div>
            </div>
            <MobileCardField label="User"><PatientIdCell id={`#${s.user_id}`} name={s.user_name} /></MobileCardField>
            <MobileCardField label="Day"><span className="text-xs">{DAY_NAMES[s.day_of_week]}</span></MobileCardField>
            <MobileCardField label="Shift"><span className="font-mono text-xs">{s.shift_start.slice(0, 5)}–{s.shift_end.slice(0, 5)}</span></MobileCardField>
            {s.effective_from !== null && (
              <MobileCardField label="Effective">
                <span className="font-mono text-xs text-muted-foreground">
                  {s.effective_from.slice(0, 10)}{s.effective_to !== null ? ` → ${s.effective_to.slice(0, 10)}` : ' →'}
                </span>
              </MobileCardField>
            )}
            <MobileCardActions>
              {s.is_active ? (
                <>
                  <Button size="sm" variant="outline" aria-label={`Edit shift #${s.id}`} disabled={update.isPending} onClick={() => setEditing(s)}>
                    <Pencil /> Edit
                  </Button>
                  <Button size="sm" variant="outline" aria-label={`Archive shift #${s.id}`} disabled={archive.isPending}
                    onClick={() => setConfirm({
                      title: `Archive shift #${s.id}?`,
                      description: 'The staff shift will be archived and removed from the schedule.',
                      confirmLabel: 'Archive',
                      run: () => archive.mutate(s.id),
                    })}>
                    <Trash2 /> Archive
                  </Button>
                </>
              ) : (
                <Button size="sm" variant="outline" aria-label={`Restore shift #${s.id}`} disabled={unarchive.isPending}
                  onClick={() => unarchive.mutate(s.id)}>
                  <ArchiveRestore /> Restore
                </Button>
              )}
            </MobileCardActions>
          </MobileCard>
        ))}
      </MobileCardList>

      {/* Add-shift dialog — the create form lives in a modal; the
          "Add shift" trigger sits in the tab bar. */}
      <Dialog open={openAddShift} onOpenChange={onOpenAddShiftChange}>
        {openAddShift && <AddShiftDialog onClose={() => onOpenAddShiftChange(false)} />}
      </Dialog>

      {/* Edit shift dialog — full CRUD is now reachable from the UI. */}
      <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
        {editing !== null && <EditShiftDialog schedule={editing} onClose={() => setEditing(null)} />}
      </Dialog>

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
    </div>
  );
}

/** Create a shift template (modal) — mirrors the former inline form. */
function AddShiftDialog({ onClose }: { onClose: () => void }) {
  const create = useCreateStaffSchedule();
  const [userId, setUserId] = useState('');
  const [dow, setDow] = useState('1');
  const [start, setStart] = useState('09:00');
  const [end, setEnd] = useState('17:00');
  const [type, setType] = useState<ScheduleType>('regular');
  const [effFrom, setEffFrom] = useState('');
  const [effTo, setEffTo] = useState('');

  function submit() {
    const parsed = createStaffScheduleSchema.safeParse({
      user_id: userId,
      day_of_week: dow,
      shift_start: start,
      shift_end: end,
      schedule_type: type,
      effective_from: effFrom === '' ? null : effFrom,
      effective_to: effTo === '' ? null : effTo,
    });
    if (!parsed.success) {
      toast.error(parsed.error.issues[0]?.message ?? 'Invalid input.');
      return;
    }
    create.mutate(parsed.data, {
      onSuccess: () => {
        setUserId('');
        setEffFrom('');
        setEffTo('');
        onClose();
      },
    });
  }

  return (
    <DialogContent className="max-w-md">
      <DialogHeader>
        <DialogTitle>Add staff shift</DialogTitle>
      </DialogHeader>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="ss-user" className="text-xs">User ID</Label>
          <Input id="ss-user" className="h-8 w-full" value={userId} onChange={(e) => setUserId(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label id="ss-dow-label" className="text-xs">Day</Label>
          <Select value={dow} onValueChange={setDow}>
            <SelectTrigger aria-labelledby="ss-dow-label" className="h-8 w-full"><SelectValue /></SelectTrigger>
            <SelectContent>{DAY_NAMES.map((n, i) => <SelectItem key={n} value={String(i)}>{n}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ss-start" className="text-xs">Start</Label>
          <TimePicker id="ss-start" value={start} onChange={setStart} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ss-end" className="text-xs">End</Label>
          <TimePicker id="ss-end" value={end} onChange={setEnd} />
        </div>
        <div className="space-y-1.5">
          <Label id="ss-type-label" className="text-xs">Type</Label>
          <Select value={type} onValueChange={(v) => setType(v as ScheduleType)}>
            <SelectTrigger aria-labelledby="ss-type-label" className="h-8 w-full"><SelectValue /></SelectTrigger>
            <SelectContent>{SCHEDULE_TYPES.map((t) => <SelectItem key={t} value={t}>{t}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ss-eff-from" className="text-xs">From (optional)</Label>
          <DatePicker id="ss-eff-from" value={effFrom} onChange={setEffFrom} className="h-8" />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ss-eff-to" className="text-xs">To (optional)</Label>
          <DatePicker id="ss-eff-to" value={effTo} onChange={setEffTo} className="h-8" />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose} disabled={create.isPending}>Cancel</Button>
        <Button onClick={submit} disabled={create.isPending}>
          {create.isPending && <Loader2 className="animate-spin" />} Add shift
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

/** Edit a shift template — partial update wired to the existing endpoint. */
function EditShiftDialog({ schedule, onClose }: { schedule: StaffSchedule; onClose: () => void }) {
  const update = useUpdateStaffSchedule();
  const [dow, setDow] = useState(String(schedule.day_of_week));
  const [start, setStart] = useState(schedule.shift_start.slice(0, 5));
  const [end, setEnd] = useState(schedule.shift_end.slice(0, 5));
  const [type, setType] = useState<ScheduleType>(schedule.schedule_type);
  const [effFrom, setEffFrom] = useState(schedule.effective_from?.slice(0, 10) ?? '');
  const [effTo, setEffTo] = useState(schedule.effective_to?.slice(0, 10) ?? '');

  function save() {
    const parsed = updateStaffScheduleSchema.safeParse({
      day_of_week: dow,
      shift_start: start,
      shift_end: end,
      schedule_type: type,
      effective_from: effFrom === '' ? null : effFrom,
      effective_to: effTo === '' ? null : effTo,
    });
    if (!parsed.success) {
      toast.error(parsed.error.issues[0]?.message ?? 'Invalid input.');
      return;
    }
    update.mutate(
      { id: schedule.id, input: parsed.data },
      { onSuccess: onClose },
    );
  }

  return (
    <DialogContent className="max-w-md">
      <DialogHeader>
        <DialogTitle>Edit shift #{schedule.id}</DialogTitle>
      </DialogHeader>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label id="ed-dow-label" className="text-xs">Day</Label>
          <Select value={dow} onValueChange={setDow}>
            <SelectTrigger aria-labelledby="ed-dow-label" className="h-8 w-full"><SelectValue /></SelectTrigger>
            <SelectContent>{DAY_NAMES.map((n, i) => <SelectItem key={n} value={String(i)}>{n}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label id="ed-type-label" className="text-xs">Type</Label>
          <Select value={type} onValueChange={(v) => setType(v as ScheduleType)}>
            <SelectTrigger aria-labelledby="ed-type-label" className="h-8 w-full"><SelectValue /></SelectTrigger>
            <SelectContent>{SCHEDULE_TYPES.map((t) => <SelectItem key={t} value={t}>{t}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ed-start" className="text-xs">Start</Label>
          <TimePicker id="ed-start" value={start} onChange={setStart} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ed-end" className="text-xs">End</Label>
          <TimePicker id="ed-end" value={end} onChange={setEnd} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ed-eff-from" className="text-xs">From (optional)</Label>
          <DatePicker id="ed-eff-from" value={effFrom} onChange={setEffFrom} className="h-8" />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="ed-eff-to" className="text-xs">To (optional)</Label>
          <DatePicker id="ed-eff-to" value={effTo} onChange={setEffTo} className="h-8" />
        </div>
      </div>
      <DialogFooter>
        <Button variant="outline" onClick={onClose} disabled={update.isPending}>Cancel</Button>
        <Button onClick={save} disabled={update.isPending}>
          {update.isPending && <Loader2 className="animate-spin" />} Save changes
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}

export default function ClinicPage() {
  const [cursor, setCursor] = useState<string | null>(null);
  const [history, setHistory] = useState<Array<string | null>>([null]);
  // Default landing surface is the live Queue (panel revision, August
  // 2026): appointments auto-check-in and auto-queue for the day, and
  // staff action buttons (Care / Vitals / Close / Mark no-show) live
  // on each queue row. The Closed tab is the only remaining slice that
  // drives from the encounter list.
  const [tabParam, setTab] = useTabParam('queue');
  const tab = tabParam as 'queue' | 'closed' | 'staff';
  const [openCreate, setOpenCreate] = useState(false);
  const [openImport, setOpenImport] = useState(false);
  const [openVitals, setOpenVitals] = useState<Encounter | null>(null);
  const [openCare, setOpenCare] = useState<Encounter | null>(null);
  const [openView, setOpenView] = useState<Encounter | null>(null);
  // Staff schedules: the "Add shift" + "Show archived" controls live in
  // the tab bar (right-aligned), so their state must be lifted here.
  const [openAddShift, setOpenAddShift] = useState(false);
  const [showArchived, setShowArchived] = useState(false);
  // Server-side status filter: the Closed tab fetches its own slice
  // instead of client-filtering one shared page (which made tab counts
  // misleading and could hide rows on later pages). The Queue tab
  // drives its data from the queue feed, not this list.
  const status = tab === 'closed' ? 'closed' : null;
  const list = useEncounters(cursor, 25, status);
  // Note: encounter close / no-show mutations live inside QueueTab now
  // — staff action buttons are per-row in the queue table (panel
  // revision, August 2026). This page shell only manages dialogs.

  // Deep-link from the Appointments page: `/clinic?encounter=<id>`
  // highlights the auto-opened visit so staff can pick up where the
  // check-in left off.
  const [searchParams] = useSearchParams();
  const focusId = searchParams.get('encounter') !== null ? Number(searchParams.get('encounter')) : null;

  function switchTab(next: string) {
    setTab(next);
    setCursor(null);
    setHistory([null]);
  }

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

  const rows: Encounter[] = list.data?.data ?? [];

  return (
    <main className="mx-auto max-w-7xl space-y-4 p-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-foreground">Clinic</h1>
          <p className="text-sm text-muted-foreground">Encounters are the anchor for clinic actions — isolated from counselling.</p>
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

      <Tabs value={tab} onValueChange={switchTab}>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <TabsList>
            <TabsTrigger value="queue">Queue (today)</TabsTrigger>
            <TabsTrigger value="closed">Closed</TabsTrigger>
            <TabsTrigger value="staff">Staff schedules</TabsTrigger>
          </TabsList>

          {tab === 'staff' && (
            <div className="flex items-center gap-2">
              <Button size="sm" onClick={() => setOpenAddShift(true)}>
                <Plus /> Add shift
              </Button>
              <Button
                size="sm"
                variant={showArchived ? 'secondary' : 'outline'}
                aria-pressed={showArchived}
                onClick={() => setShowArchived((v) => !v)}
              >
                <Archive /> {showArchived ? 'Hide archived' : 'Show archived'}
              </Button>
            </div>
          )}
        </div>

        <TabsContent value="queue">
          <QueueTab
            onOpenCare={(e) => setOpenCare(e)}
            onOpenVitals={(e) => setOpenVitals(e)}
          />
        </TabsContent>

        <TabsContent value="closed">
          <EncounterTable
            rows={rows}
            focusId={focusId}
            isLoading={list.isLoading}
            isError={list.isError}
            onRetry={() => void list.refetch()}
            retrying={list.isFetching}
            page={history.length}
            onPrev={prevPage}
            onNext={nextPage}
            canPrev={history.length > 1}
            canNext={list.data?.next !== null && list.data?.next !== undefined}
            actions={(e) => (
              <Button size="sm" variant="outline" onClick={() => setOpenView(e)}>
                <Stethoscope className="size-3.5" /> View
              </Button>
            )}
          />
        </TabsContent>

        <TabsContent value="staff">
          <StaffSchedulesTab
            showArchived={showArchived}
            openAddShift={openAddShift}
            onOpenAddShiftChange={setOpenAddShift}
          />
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

      {openView !== null && (
        <Dialog open onOpenChange={(o) => !o && setOpenView(null)}>
          <EncounterViewDialog encounter={openView} onClose={() => setOpenView(null)} />
        </Dialog>
      )}
    </main>
  );
}

interface EncounterTableProps {
  rows: Encounter[];
  focusId?: number | null;
  isLoading: boolean;
  isError?: boolean;
  onRetry?: () => void;
  retrying?: boolean;
  page: number;
  canPrev: boolean;
  canNext: boolean;
  onPrev: () => void;
  onNext: () => void;
  actions: (e: Encounter) => React.ReactNode;
}

function EncounterTable(props: EncounterTableProps) {
  const showEmpty = !props.isLoading && props.isError !== true && props.rows.length === 0;
  return (
    <>
      <section className="hidden overflow-hidden rounded-xl border bg-card md:block">
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
            {props.isError === true && !props.isLoading && props.onRetry !== undefined && (
              <QueryErrorRow colSpan={6} message="Failed to load encounters." onRetry={props.onRetry} pending={props.retrying === true} />
            )}
            {showEmpty && (
              <TableRow>
                <TableCell colSpan={6} className="px-3 py-6 text-center text-muted-foreground">
                  No encounters.
                </TableCell>
              </TableRow>
            )}
            {props.rows.map((e) => (
              <TableRow key={e.id} className={props.focusId === e.id ? 'bg-primary/5 outline outline-1 outline-primary/40' : undefined}>
                <TableCell className="px-3 font-mono text-xs">{e.id}</TableCell>
                <TableCell className="px-3"><PatientIdCell id={e.patient_school_id} name={e.patient_name} /></TableCell>
                <TableCell className="px-3">
                  {e.chief_complaint}
                  {(e.triage_priority ?? null) !== null && (
                    <Badge variant={TRIAGE_VARIANT[e.triage_priority as TriagePriority]} className="ml-2">
                      {e.triage_priority}
                    </Badge>
                  )}
                  {(e.appointment_id ?? null) !== null && (
                    <Badge variant="secondary" className="ml-2 gap-1">
                      <CalendarClock className="size-3" /> From appointment #{e.appointment_id}
                    </Badge>
                  )}
                </TableCell>
                <TableCell className="px-3 text-xs text-muted-foreground">{fmtUtcToApp(e.started_at)}</TableCell>
                <TableCell className="px-3 text-xs text-muted-foreground">
                  {e.closed_at === null ? <Badge variant="info">{statusLabel(e.status)}</Badge> : fmtUtcToApp(e.closed_at)}
                </TableCell>
                <TableCell className="px-3 text-right">{props.actions(e)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>

      {/* Mobile: stacked cards from the same rows. */}
      {props.isLoading && (
        <p className="py-6 text-center text-sm text-muted-foreground md:hidden" role="status">
          <Loader2 className="mx-auto size-4 animate-spin" />
        </p>
      )}
      {props.isError === true && !props.isLoading && props.onRetry !== undefined && (
        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-center text-sm text-destructive md:hidden">
          <p>Failed to load encounters.</p>
          <Button variant="outline" size="sm" className="mt-2" onClick={props.onRetry} disabled={props.retrying === true}>Retry</Button>
        </div>
      )}
      {showEmpty && (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground md:hidden">No encounters.</p>
      )}
      <MobileCardList>
        {props.rows.map((e) => (
          <MobileCard
            key={e.id}
            aria-label={`Encounter ${e.id}`}
            className={props.focusId === e.id ? 'outline outline-1 outline-primary/40' : undefined}
          >
            <div className="mb-1 flex items-center justify-between gap-2">
              <span className="font-mono text-xs text-muted-foreground">#{e.id}</span>
              {e.closed_at === null
                ? <Badge variant="info">{statusLabel(e.status)}</Badge>
                : <span className="text-xs text-muted-foreground">Closed {fmtUtcToApp(e.closed_at)}</span>}
            </div>
            <p className="text-sm font-medium text-foreground">{e.chief_complaint}</p>
            <div className="mt-1 flex flex-wrap gap-1.5">
              {(e.triage_priority ?? null) !== null && (
                <Badge variant={TRIAGE_VARIANT[e.triage_priority as TriagePriority]}>{e.triage_priority}</Badge>
              )}
              {(e.appointment_id ?? null) !== null && (
                <Badge variant="secondary" className="gap-1"><CalendarClock className="size-3" /> Appt #{e.appointment_id}</Badge>
              )}
            </div>
            <MobileCardField label="Patient"><PatientIdCell id={e.patient_school_id} name={e.patient_name} /></MobileCardField>
            <MobileCardField label="Started"><span className="text-xs text-muted-foreground">{fmtUtcToApp(e.started_at)}</span></MobileCardField>
            <MobileCardActions>{props.actions(e)}</MobileCardActions>
          </MobileCard>
        ))}
      </MobileCardList>

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
