/**
 * Facilities hooks — BMG state machine operations.
 *
 * Uses TanStack Query for both reads and mutations. Each transition
 * mutation uses `onMutate` to optimistically flip the unit's `status`
 * in the cache, `onError` to roll back, and `onSettled` to invalidate
 * the cache so the server's truth is reconciled.
 *
 * Mutation signatures:
 *   - startBatch({ unitId, input })
 *   - recordOutput({ unitId, batchId, input })
 *   - finishBatch({ unitId, batchId })
 *   - cancelBatch({ unitId, batchId, input })
 *
 * `unitId` is supplied by the caller so the hook can patch the right
 * row in the unit cache without coupling to a specific component.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/api/client';
import { ApiEnvelopeError } from '@/api/envelope';
import { toast } from 'sonner';
import {
  addProcessLogSchema,
  activeBatchSchema,
  batchAnalyticsSchema,
  bmgBatchSchema,
  bmgUnitSchema,
  cancelBatchSchema,
  createUnitSchema,
  createWasteCategorySchema,
  processLogSchema,
  updateUnitSchema,
  updateWasteCategorySchema,
  wasteCategorySchema,
  type ActiveBatch,
  type AddProcessLogInput,
  type BatchAnalytics,
  type BmgBatch,
  type BmgUnit,
  type CancelBatchInput,
  type CreateUnitInput,
  type CreateWasteCategoryInput,
  type ProcessLog,
  type UpdateUnitInput,
  type UpdateWasteCategoryInput,
  type WasteCategory,
  type RecordOutputInput,
  type StartBatchInput,
} from '@/schemas/facilities';

const UNITS_KEY = ['facilities', 'units'] as const;
const ACTIVE_BATCHES_KEY = ['facilities', 'batches', 'active'] as const;

function unitsQueryKey(cursor: string | null, limit: number) {
  return [...UNITS_KEY, { cursor, limit }] as const;
}

/**
 * Invalidate EVERY Facilities query that could have changed as a result
 * of a transition mutation. Centralized so a new transition can't
 * forget to refresh the "Processing Drums" widget or the unit list.
 *
 * The dashboard, the list page, and the analytics card all read from
 * these keys — invalidating `['facilities', ...]` covers them all in
 * one call.
 *
 * @param batchId When provided, also drops the per-batch analytics
 *                cache (a finished/cancelled batch may surface new
 *                yield / duration values).
 */
function invalidateFacilities(
  qc: ReturnType<typeof useQueryClient>,
  batchId: number | null = null,
): void {
  void qc.invalidateQueries({ queryKey: UNITS_KEY });
  void qc.invalidateQueries({ queryKey: ACTIVE_BATCHES_KEY });
  if (batchId !== null) {
    void qc.invalidateQueries({ queryKey: ['facilities', 'analytics', batchId] });
    void qc.invalidateQueries({ queryKey: ['facilities', 'process-logs', batchId] });
  }
}

function patchUnitInCache(
  qc: ReturnType<typeof useQueryClient>,
  unitId: number,
  patch: Partial<BmgUnit>,
) {
  qc.setQueriesData<{ data: BmgUnit[]; next: string | null }>(
    { queryKey: UNITS_KEY },
    (old) => {
      if (old === undefined) return old;
      return {
        ...old,
        data: old.data.map((u) => (u.id === unitId ? { ...u, ...patch } : u)),
      };
    },
  );
}

function snapshotUnits(
  qc: ReturnType<typeof useQueryClient>,
): Array<[readonly unknown[], { data: BmgUnit[]; next: string | null } | undefined]> {
  return qc.getQueriesData<{ data: BmgUnit[]; next: string | null }>({
    queryKey: UNITS_KEY,
  });
}

export function useBmgUnits(cursor: string | null, limit = 25) {
  return useQuery<{ data: BmgUnit[]; next: string | null }, ApiEnvelopeError>({
    queryKey: unitsQueryKey(cursor, limit),
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      const res = await apiClient.get<{ data: BmgUnit[]; next: string | null }>(
        `/facilities/units?${params.toString()}`,
      );
      return {
        data: z.array(bmgUnitSchema).parse(res.data),
        next: res.data?.next ?? null,
      };
    },
  });
}

/** Optimistic-update context shared by all BMG mutations. */
interface UnitsMutationCtx {
  snapshots: ReturnType<typeof snapshotUnits>;
}

export interface UseStartBatchVars {
  unitId: number;
  input: StartBatchInput;
}

export function useStartBatch() {
  const qc = useQueryClient();
  return useMutation<BmgBatch, ApiEnvelopeError, UseStartBatchVars, UnitsMutationCtx>({
    mutationFn: async ({ unitId, input }) => {
      const res = await apiClient.post<BmgBatch>(`/facilities/units/${unitId}/start`, input);
      return bmgBatchSchema.parse(res.data);
    },
    onMutate: async ({ unitId }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      // Optimistic flip. We don't know the new `active_batch_id` until
      // the server responds, so we drop the field — the `onSettled`
      // refetch below will repopulate it.
      patchUnitInCache(qc, unitId, { status: 'Processing', active_batch_id: null });
      return { snapshots };
    },
    onError: (err, _input, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to start batch.');
    },
    onSettled: () => {
      // No batchId yet at settle time; the unit refetch alone is enough
      // to fix the active_batch_id, and the next `useActiveBatches`
      // refetch will surface the new batch.
      invalidateFacilities(qc);
    },
    onSuccess: () => {
      toast.success('Batch started.');
    },
  });
}

export interface UseRecordOutputVars {
  unitId: number;
  batchId: number;
  input: RecordOutputInput;
}

export function useRecordOutput() {
  const qc = useQueryClient();
  return useMutation<BmgBatch, ApiEnvelopeError, UseRecordOutputVars, UnitsMutationCtx>({
    mutationFn: async ({ batchId, input }) => {
      const res = await apiClient.post<BmgBatch>(`/facilities/batches/${batchId}/output`, input);
      return bmgBatchSchema.parse(res.data);
    },
    onMutate: async ({ unitId }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      patchUnitInCache(qc, unitId, { status: 'AwaitingOutput' });
      return { snapshots };
    },
    onError: (err, _input, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to record output.');
    },
    onSettled: (_d, _e, vars) => {
      // The batch is still active (now AwaitingOutput) but its yield /
      // inputs have changed — drop both the unit and the per-batch
      // analytics so the dashboard + analytics card stay honest.
      invalidateFacilities(qc, vars.batchId);
    },
    onSuccess: () => {
      toast.success('Output recorded.');
    },
  });
}

export interface UseFinishBatchVars {
  unitId: number;
  batchId: number;
}

export function useFinishBatch() {
  const qc = useQueryClient();
  return useMutation<BmgBatch, ApiEnvelopeError, UseFinishBatchVars, UnitsMutationCtx>({
    mutationFn: async ({ batchId }) => {
      const res = await apiClient.post<BmgBatch>(`/facilities/batches/${batchId}/finish`);
      return bmgBatchSchema.parse(res.data);
    },
    onMutate: async ({ unitId }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      patchUnitInCache(qc, unitId, { status: 'Idle', active_batch_id: null });
      return { snapshots };
    },
    onError: (err, _input, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to finish batch.');
    },
    onSettled: (_d, _e, vars) => {
      invalidateFacilities(qc, vars.batchId);
    },
    onSuccess: () => {
      toast.success('Batch finished.');
    },
  });
}

export interface UseCancelBatchVars {
  unitId: number;
  batchId: number;
  input: CancelBatchInput;
}

export function useCancelBatch() {
  const qc = useQueryClient();
  return useMutation<BmgBatch, ApiEnvelopeError, UseCancelBatchVars, UnitsMutationCtx>({
    mutationFn: async ({ batchId, input }) => {
      const valid = cancelBatchSchema.parse(input);
      const res = await apiClient.post<BmgBatch>(`/facilities/batches/${batchId}/cancel`, valid);
      return bmgBatchSchema.parse(res.data);
    },
    onMutate: async ({ unitId }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      // Server: cancel puts the UNIT back to Idle; only the BATCH row
      // is marked Cancelled. The "Processing Drums" widget (which reads
      // active batches, not unit.status) will drop the row once the
      // invalidate completes.
      patchUnitInCache(qc, unitId, { status: 'Idle', active_batch_id: null });
      return { snapshots };
    },
    onError: (err, _input, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to cancel batch.');
    },
    onSettled: (_d, _e, vars) => {
      invalidateFacilities(qc, vars.batchId);
    },
    onSuccess: () => {
      toast.success('Batch cancelled.');
    },
  });
}

// ------------------------------------------------------ process logs

export function useProcessLogs(batchId: number | null) {
  return useQuery<ProcessLog[], ApiEnvelopeError>({
    queryKey: ['facilities', 'process-logs', batchId],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/facilities/batches/${batchId}/logs`);
      return z.array(processLogSchema).parse(res.data);
    },
    enabled: batchId !== null && batchId > 0,
  });
}

export function useAddProcessLog() {
  const qc = useQueryClient();
  return useMutation<ProcessLog, ApiEnvelopeError, { batchId: number; input: AddProcessLogInput }>({
    mutationFn: async ({ batchId, input }) => {
      const valid = addProcessLogSchema.parse(input);
      const payload: Record<string, unknown> = {};
      if (valid.observation_note !== undefined && valid.observation_note !== '') payload['observation_note'] = valid.observation_note;
      if (valid.temperature_celsius !== undefined && valid.temperature_celsius !== '') payload['temperature_celsius'] = valid.temperature_celsius;
      if (valid.moisture_level !== undefined) payload['moisture_level'] = valid.moisture_level;
      const res = await apiClient.post<unknown>(`/facilities/batches/${batchId}/logs`, payload);
      return processLogSchema.parse(res.data);
    },
    onSuccess: (_d, vars) => {
      // New log entry may nudge yield / progress — drop the analytics
      // for this batch and let the active-batches list refetch so the
      // days_active counter stays current.
      void qc.invalidateQueries({ queryKey: ['facilities', 'process-logs', vars.batchId] });
      void qc.invalidateQueries({ queryKey: ACTIVE_BATCHES_KEY });
      void qc.invalidateQueries({ queryKey: ['facilities', 'analytics', vars.batchId] });
      toast.success('Observation logged.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to log observation.');
    },
  });
}

// ------------------------------------------------------ Phase P4

export function useWasteCategories(activeOnly = false) {
  return useQuery<WasteCategory[], ApiEnvelopeError>({
    queryKey: ['facilities', 'waste-categories', { activeOnly }],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/facilities/waste-categories${activeOnly ? '?active=1' : ''}`);
      return z.array(wasteCategorySchema).parse(res.data);
    },
  });
}

export function useCreateWasteCategory() {
  const qc = useQueryClient();
  return useMutation<{ id: number }, ApiEnvelopeError, CreateWasteCategoryInput>({
    mutationFn: async (input) => {
      const valid = createWasteCategorySchema.parse(input);
      const res = await apiClient.post<{ id: number }>('/facilities/waste-categories', valid);
      return z.object({ id: z.number().int().positive() }).parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['facilities', 'waste-categories'] });
      void qc.invalidateQueries({ queryKey: ['facilities', 'units'] });
      toast.success('Waste category created.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to create category.');
    },
  });
}

export function useUpdateWasteCategory() {
  const qc = useQueryClient();
  return useMutation<WasteCategory, ApiEnvelopeError, { categoryId: number; input: UpdateWasteCategoryInput }>({
    mutationFn: async ({ categoryId, input }) => {
      const valid = updateWasteCategorySchema.parse(input);
      const res = await apiClient.post<WasteCategory>(`/facilities/waste-categories/${categoryId}`, valid);
      return wasteCategorySchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['facilities', 'waste-categories'] });
      void qc.invalidateQueries({ queryKey: ['facilities', 'units'] });
      toast.success('Waste category updated.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to update category.');
    },
  });
}

export function useArchiveWasteCategory() {
  const qc = useQueryClient();
  return useMutation<WasteCategory, ApiEnvelopeError, { categoryId: number }>({
    mutationFn: async ({ categoryId }) => {
      const res = await apiClient.post<WasteCategory>(`/facilities/waste-categories/${categoryId}/archive`);
      return wasteCategorySchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['facilities', 'waste-categories'] });
      void qc.invalidateQueries({ queryKey: ['facilities', 'units'] });
      toast.success('Waste category archived.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to archive category.');
    },
  });
}

/**
 * Specialised toast for delete-409. The server's contract is: a waste
 * category that has batch or unit references cannot be hard-deleted;
 * the user must archive it instead. The inline action button fires
 * `archive` so the user doesn't have to close the toast and click
 * again — the optimistic remove from `useArchiveWasteCategory` takes
 * care of the cache.
 *
 * @param err  The error returned from the delete mutation.
 * @param fallback  Called when the user clicks the toast action.
 */
function isDeleteConflict(err: unknown): boolean {
  return err instanceof ApiEnvelopeError && err.httpStatus === 409;
}

export function useDeleteWasteCategory() {
  const qc = useQueryClient();
  const archive = useArchiveWasteCategory();
  return useMutation<{ id: number; deleted: true }, ApiEnvelopeError, { categoryId: number }>({
    mutationFn: async ({ categoryId }) => {
      const res = await apiClient.delete<{ id: number; deleted: true }>(`/facilities/waste-categories/${categoryId}`);
      return res.data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['facilities', 'waste-categories'] });
      void qc.invalidateQueries({ queryKey: UNITS_KEY });
      void qc.invalidateQueries({ queryKey: ACTIVE_BATCHES_KEY });
      toast.success('Waste category deleted.');
    },
    onError: (err, vars) => {
      if (isDeleteConflict(err)) {
        // 409: category is in use. Offer the user the only valid path
        // (archive) inline, so they don't have to close the toast and
        // hunt for the Archive button.
        toast.error(err.errors[0]?.message ?? 'Cannot delete this category.', {
          duration: 8_000,
          action: {
            label: 'Archive instead',
            onClick: () => {
              archive.mutate({ categoryId: vars.categoryId });
            },
          },
        });
        return;
      }
      toast.error(err.errors[0]?.message ?? 'Failed to delete category.');
    },
  });
}

export function useBatchAnalytics(batchId: number | null) {
  return useQuery<BatchAnalytics, ApiEnvelopeError>({
    queryKey: ['facilities', 'analytics', batchId],
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/facilities/batches/${batchId}/analytics`);
      return batchAnalyticsSchema.parse(res.data);
    },
    enabled: batchId !== null && batchId > 0,
  });
}

export function useAddBatchIo() {
  const qc = useQueryClient();
  return useMutation<
    unknown,
    ApiEnvelopeError,
    { batchId: number; kind: 'inputs' | 'outputs'; body: Record<string, unknown> }
  >({
    mutationFn: async ({ batchId, kind, body }) => {
      const res = await apiClient.post<unknown>(`/facilities/batches/${batchId}/${kind}`, body);
      return res.data;
    },
    onSuccess: (_d, vars) => {
      // Recording a batch I/O row can change the unit's active_batch_id
      // join result on the next refetch AND its analytics — drop both
      // so the list + dashboard stay aligned without a manual reload.
      void qc.invalidateQueries({ queryKey: ['facilities', 'analytics', vars.batchId] });
      void qc.invalidateQueries({ queryKey: UNITS_KEY });
      void qc.invalidateQueries({ queryKey: ACTIVE_BATCHES_KEY });
      toast.success(vars.kind === 'inputs' ? 'Input recorded.' : 'Output recorded.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to record.');
    },
  });
}

// ------------------------------------------------------ Drum CRUD

/**
 * Strip empty-string optional fields so the backend sees a clean patch
 * (it treats '' as null via `permit_empty`).
 */
function cleanOptional<T extends Record<string, unknown>>(input: T): Partial<T> {
  const out: Partial<T> = {};
  for (const [k, v] of Object.entries(input)) {
    if (v === '' || v === undefined) continue;
    out[k as keyof T] = v as T[keyof T];
  }
  return out;
}

export function useCreateUnit() {
  const qc = useQueryClient();
  return useMutation<BmgUnit, ApiEnvelopeError, CreateUnitInput, UnitsMutationCtx>({
    mutationFn: async (input) => {
      const valid = createUnitSchema.parse(input);
      const res = await apiClient.post<BmgUnit>('/facilities/units', cleanOptional(valid));
      return bmgUnitSchema.parse(res.data);
    },
    onMutate: async () => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      return { snapshots };
    },
    onError: (err, _input, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to create drum.');
    },
    onSuccess: (created) => {
      // Optimistically insert the new drum into every cached page so it
      // appears instantly, then reconcile with the server.
      qc.setQueriesData<{ data: BmgUnit[]; next: string | null }>(
        { queryKey: UNITS_KEY },
        (old) => {
          if (old === undefined) return old;
          if (old.data.some((u) => u.id === created.id)) return old;
          return { ...old, data: [created, ...old.data] };
        },
      );
      invalidateFacilities(qc);
      toast.success('Drum created.');
    },
  });
}

export function useUpdateUnit() {
  const qc = useQueryClient();
  return useMutation<BmgUnit, ApiEnvelopeError, { unitId: number; input: UpdateUnitInput }, UnitsMutationCtx>({
    mutationFn: async ({ unitId, input }) => {
      const valid = updateUnitSchema.parse(input);
      const res = await apiClient.post<BmgUnit>(`/facilities/units/${unitId}`, cleanOptional(valid));
      return bmgUnitSchema.parse(res.data);
    },
    onMutate: async ({ unitId, input }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      patchUnitInCache(qc, unitId, input as Partial<BmgUnit>);
      return { snapshots };
    },
    onError: (err, _vars, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to update drum.');
    },
    onSuccess: () => {
      invalidateFacilities(qc);
      toast.success('Drum updated.');
    },
  });
}

export function useArchiveUnit() {
  const qc = useQueryClient();
  return useMutation<BmgUnit, ApiEnvelopeError, { unitId: number }, UnitsMutationCtx>({
    mutationFn: async ({ unitId }) => {
      // The mutation may run on a row the server has already archived
      // (e.g. two clicks in quick succession, or another tab archived
      // it). A 404 here is a no-op for the user — we swallow it and let
      // `onSettled` reconcile. Anything else propagates to onError.
      try {
        const res = await apiClient.delete<BmgUnit>(`/facilities/units/${unitId}`);
        return bmgUnitSchema.parse(res.data);
      } catch (err) {
        if (err instanceof ApiEnvelopeError && err.httpStatus === 404) {
          // Return a minimal placeholder; the snapshot + invalidate will
          // ensure the row is gone from the cache. Caller does not
          // parse the return on 404.
          return { id: unitId, code: '', display_name: '', status: 'Idle', created_at: '' } as unknown as BmgUnit;
        }
        throw err;
      }
    },
    onMutate: async ({ unitId }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      // Optimistically remove the archived row so the user sees it
      // disappear immediately; the server reconcile re-asserts truth.
      qc.setQueriesData<{ data: BmgUnit[]; next: string | null }>(
        { queryKey: UNITS_KEY },
        (old) => {
          if (old === undefined) return old;
          return { ...old, data: old.data.filter((u) => u.id !== unitId) };
        },
      );
      return { snapshots };
    },
    onError: (err, _vars, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to archive drum.');
    },
    onSettled: () => {
      // Always reconcile, even on 404 — the optimistic remove may have
      // raced with a refetch that re-introduced the row.
      invalidateFacilities(qc);
    },
    onSuccess: () => {
      toast.success('Drum archived.');
    },
  });
}

// ------------------------------------------------------ Processing Drums

/**
 * One row per active batch (Processing or AwaitingOutput) enriched with
 * unit info, optional waste category, and computed days_active /
 * expected_completion_date / progress_pct. Powers the "Processing Drums"
 * dashboard widget — a single round-trip keeps the dashboard snappy
 * behind the single-threaded dev server.
 */
export function useActiveBatches() {
  return useQuery<ActiveBatch[], ApiEnvelopeError>({
    queryKey: ['facilities', 'batches', 'active'],
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/facilities/batches/active');
      return z.array(activeBatchSchema).parse(res.data);
    },
    // Cheap query (small result set, but every page re-mounts). Refresh
    // when the tab regains focus so an operator always sees fresh state.
    staleTime: 30_000,
  });
}

export function useSetUnitMaintenance() {
  const qc = useQueryClient();
  return useMutation<{ id: number; status: string }, ApiEnvelopeError, { unitId: number; maintenance: boolean }, UnitsMutationCtx>({
    mutationFn: async ({ unitId, maintenance }) => {
      const res = await apiClient.post<{ id: number; status: string }>(`/facilities/units/${unitId}/maintenance`, { maintenance });
      return res.data;
    },
    onMutate: async ({ unitId, maintenance }) => {
      await qc.cancelQueries({ queryKey: UNITS_KEY });
      const snapshots = snapshotUnits(qc);
      patchUnitInCache(qc, unitId, {
        status: maintenance ? 'Maintenance' : 'Idle',
        active_batch_id: maintenance ? null : undefined,
      });
      return { snapshots };
    },
    onError: (err, _vars, ctx) => {
      for (const [key, snap] of ctx?.snapshots ?? []) {
        qc.setQueryData(key, snap);
      }
      toast.error(err.errors[0]?.message ?? 'Failed to change unit status.');
    },
    onSettled: () => {
      // A unit in Maintenance should not surface in the "Processing
      // Drums" widget even if it had a leftover active_batch_id, so
      // always refresh the active-batches list alongside the units.
      invalidateFacilities(qc);
    },
    onSuccess: (d) => {
      toast.success(`Unit → ${d.status}.`);
    },
  });
}