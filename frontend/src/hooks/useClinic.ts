/**
 * Clinic hooks — encounters + vitals.
 *
 * TanStack Query + keyset pagination for the list, mutations for the
 * writes. On error, the envelope's first error message is surfaced
 * via Sonner.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  addTreatmentSchema,
  createEncounterSchema,
  encounterSchema,
  importEncountersResultSchema,
  recordVitalsSchema,
  setAssessmentSchema,
  treatmentSchema,
  triagePredictionSchema,
  vitalsListSchema,
  vitalsSchema,
  type AddTreatmentInput,
  type CreateEncounterInput,
  type Encounter,
  type ImportEncountersResult,
  type RecordVitalsInput,
  type SetAssessmentInput,
  type Treatment,
  type TriagePrediction,
  type TriagePriority,
  type Vitals,
  type VitalsList,
} from '@/schemas/clinic';

interface EncounterPage {
  data: Encounter[];
  next: string | null;
}

export function useEncounters(
  cursor: string | null,
  limit = 25,
  status: 'open' | 'closed' | null = null,
) {
  return useQuery<EncounterPage, ApiEnvelopeError>({
    // Status is part of the cache key so switching tabs refetches the
    // correct server-side slice instead of re-filtering one page.
    queryKey: ['clinic', 'encounters', { cursor, limit, status }],
    // Multiple nurses share this list — encounters are opened/closed
    // from other sessions (and by the queue sweep), so poll to stay
    // current without a manual refresh.
    refetchInterval: 30_000,
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      if (status !== null) params.set('status', status);
      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/clinic/encounters?${params.toString()}`,
      );
      const data = z.array(encounterSchema).parse(res.data);
      return { data, next: res.data?.next ?? null };
    },
  });
}

export function useCreateEncounter() {
  const qc = useQueryClient();
  return useMutation<Encounter, ApiEnvelopeError, CreateEncounterInput>({
    mutationFn: async (input) => {
      const valid = createEncounterSchema.parse(input);
      const res = await apiClient.post<Encounter>('/clinic/encounters', valid);
      return encounterSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['clinic'] });
      toast.success('Encounter created.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to create encounter.');
    },
  });
}

export function useRecordVitals() {
  const qc = useQueryClient();
  return useMutation<Vitals, ApiEnvelopeError, { encounterId: number; input: RecordVitalsInput }>({
    mutationFn: async ({ encounterId, input }) => {
      const valid = recordVitalsSchema.parse(input);
      const res = await apiClient.post<Vitals>(
        `/clinic/encounters/${encounterId}/vitals`,
        valid,
      );
      return vitalsSchema.parse(res.data);
    },
    onSuccess: (_data, vars) => {
      void qc.invalidateQueries({ queryKey: ['clinic'] });
      toast.success(`Vitals recorded for encounter #${vars.encounterId}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to record vitals.');
    },
  });
}

/**
 * Read-only vitals history for an encounter — powers the "View" action
 * on the Closed tab so staff can review a finalized record.
 */
export function useEncounterVitals(encounterId: number | null) {
  return useQuery<VitalsList, ApiEnvelopeError>({
    queryKey: ['clinic', 'vitals', encounterId],
    enabled: encounterId !== null,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/clinic/encounters/${encounterId}/vitals`);
      return vitalsListSchema.parse(res.data);
    },
  });
}

export function useCloseEncounter() {
  const qc = useQueryClient();
  return useMutation<Encounter, ApiEnvelopeError, number>({
    mutationFn: async (encounterId) => {
      const res = await apiClient.post<Encounter>(`/clinic/encounters/${encounterId}/close`);
      return encounterSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['clinic'] });
      toast.success('Encounter closed.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to close encounter.');
    },
  });
}

/**
 * Mark an open encounter as a no-show (panel revision, August 2026).
 *
 * Cascades atomically on the server: encounter closes with
 * `outcome='no_show'`, the linked appointment (if any) advances to
 * `no_show`, and the linked queue entry (if any) lands on `done` +
 * `outcome='no_show'`. We invalidate the encounter, queue, and
 * appointment caches so every staff surface reflects the cascade.
 *
 *   POST /clinic/encounters/{id}/no-show
 */
export function useEncounterNoShow() {
  const qc = useQueryClient();
  return useMutation<Encounter, ApiEnvelopeError, number>({
    mutationFn: async (encounterId) => {
      const res = await apiClient.post<Encounter>(`/clinic/encounters/${encounterId}/no-show`);
      return encounterSchema.parse(res.data);
    },
    onSuccess: (data) => {
      void qc.invalidateQueries({ queryKey: ['clinic'] });
      void qc.invalidateQueries({ queryKey: ['appointments'] });
      // `queue` key is owned by `useQueue` and shared with the public
      // lobby feed; refreshing it keeps the staff queue table and the
      // kiosk TV in lock-step with the encounter close.
      void qc.invalidateQueries({ queryKey: ['queue'] });
      toast.success(`Marked encounter #${data.id} as no-show.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to mark encounter as no-show.');
    },
  });
}

export function useSetAssessment() {
  const qc = useQueryClient();
  return useMutation<Encounter, ApiEnvelopeError, { encounterId: number; input: SetAssessmentInput }>({
    mutationFn: async ({ encounterId, input }) => {
      const valid = setAssessmentSchema.parse(input);
      const payload: Record<string, unknown> = {};
      if (valid.triage_priority !== undefined) payload['triage_priority'] = valid.triage_priority;
      if (valid.diagnosis !== undefined) payload['diagnosis'] = valid.diagnosis;
      const res = await apiClient.post<Encounter>(`/clinic/encounters/${encounterId}/assessment`, payload);
      return encounterSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['clinic'] });
      toast.success('Assessment saved.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to save assessment.');
    },
  });
}

export function useTreatments(encounterId: number | null) {
  return useQuery<Treatment[], ApiEnvelopeError>({
    queryKey: ['clinic', 'treatments', encounterId],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/clinic/encounters/${encounterId}/treatments`);
      return z.array(treatmentSchema).parse(res.data);
    },
    enabled: encounterId !== null && encounterId > 0,
  });
}

export function useAddTreatment() {
  const qc = useQueryClient();
  return useMutation<Treatment, ApiEnvelopeError, { encounterId: number; input: AddTreatmentInput }>({
    mutationFn: async ({ encounterId, input }) => {
      const valid = addTreatmentSchema.parse(input);
      const res = await apiClient.post<unknown>(`/clinic/encounters/${encounterId}/treatments`, valid);
      return treatmentSchema.parse(res.data);
    },
    onSuccess: (_d, vars) => {
      void qc.invalidateQueries({ queryKey: ['clinic', 'treatments', vars.encounterId] });
      void qc.invalidateQueries({ queryKey: ['clinic'] });
      void qc.invalidateQueries({ queryKey: ['medicines'] });
      toast.success('Treatment recorded.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to record treatment.');
    },
  });
}

export function useSuggestTriage() {
  return useMutation<TriagePrediction, ApiEnvelopeError, number>({
    mutationFn: async (encounterId) => {
      const res = await apiClient.post<unknown>('/clinic/triage/suggest', { encounter_id: encounterId });
      return triagePredictionSchema.parse(res.data);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Triage suggestion failed.');
    },
  });
}

export function useDecideTriage() {
  const qc = useQueryClient();
  return useMutation<
    { id: number; encounter_id: number; staff_decision: string; final_priority: TriagePriority },
    ApiEnvelopeError,
    { predictionId: number; decision: 'accepted' | 'overridden'; staff_priority?: TriagePriority }
  >({
    mutationFn: async ({ predictionId, decision, staff_priority }) => {
      const res = await apiClient.post<{ id: number; encounter_id: number; staff_decision: string; final_priority: TriagePriority }>(
        `/clinic/triage/${predictionId}/decision`,
        { decision, ...(staff_priority !== undefined ? { staff_priority } : {}) },
      );
      return res.data;
    },
    onSuccess: (d) => {
      void qc.invalidateQueries({ queryKey: ['clinic'] });
      toast.success(`Triage ${d.staff_decision} → ${d.final_priority}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Triage decision failed.');
    },
  });
}

/**
 * Bulk-import encounters from a CSV string. The backend parses the
 * CSV server-side and is all-or-nothing: any invalid row rejects the
 * whole batch with the per-row error list in the envelope.
 *
 *   POST /clinic/encounters/import
 *   Content-Type: text/csv
 *   Body:         patient_school_id,chief_complaint\n...
 */
export function useImportEncounters() {
  const qc = useQueryClient();
  return useMutation<ImportEncountersResult, ApiEnvelopeError, { csv: string }>({
    mutationFn: async ({ csv }) => {
      const res = await apiClient.post<unknown>('/clinic/encounters/import', csv, {
        headers: { 'Content-Type': 'text/csv' },
      });
      return importEncountersResultSchema.parse(res.data);
    },
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['clinic', 'encounters'] });
      void qc.invalidateQueries({ queryKey: ['clinic'] });
      toast.success(`Imported ${r.imported} encounter(s) (#${r.first_id}–#${r.last_id}).`);
    },
    onError: (err) => {
      // The backend reports all bad rows in `errors[]` so the user can
      // fix the CSV in one pass. Surface a single message that points
      // them at the first failure, then list the rest in a multi-line
      // detail so the dialog can show a "fix this list" call-out.
      const first = err.errors[0]?.message ?? 'Import failed.';
      toast.error(`${first}${err.errors.length > 1 ? ` (+${err.errors.length - 1} more)` : ''}`, {
        duration: 12_000,
      });
    },
  });
}